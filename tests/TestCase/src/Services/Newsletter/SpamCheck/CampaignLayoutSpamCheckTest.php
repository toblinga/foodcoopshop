<?php
declare(strict_types=1);

namespace App\Test\TestCase\src\Services\Newsletter\SpamCheck;

use App\Services\Newsletter\SpamCheck\CampaignSpamCheckService;
use App\Services\Newsletter\SpamCheck\NewsletterSpamCheckService;
use App\Services\Newsletter\SpamCheck\SpamCheckFinding;
use PHPUnit\Framework\TestCase;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 *
 * Prueft den Check gegen das HTML des FCS-Newsletter-Layouts
 * (templates/layout/email/html/newsletter.php, Stand Phase 1) ohne Datenbank.
 */
class CampaignLayoutSpamCheckTest extends TestCase
{

    private function service(): NewsletterSpamCheckService
    {
        $loaded = NewsletterSpamCheckService::loadConfig(CONFIG);
        return new NewsletterSpamCheckService($loaded['config'], $loaded['messages'], FakeDnsResolver::complete('news.example.org'));
    }

    public function testFcsLayout(): void
    {
        $html = (string)file_get_contents(__DIR__ . '/fixtures/fcs-layout.html');
        $result = $this->service()->check($html, 'Kürbiszeit und Käseverkostung am Freitag', 'Frisches Gemüse vom Biohof und die Verkostung am Freitag.', [
            'fromEmail' => 'newsletter@news.example.org',
            'textPart' => strip_tags($html),
            'headers' => ['List-Unsubscribe' => '<https://shop.example.test/newsletter/abmelden/x>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
        ]);
        $this->assertFalse($result->hasErrors(), implode(', ', $result->ruleIds()));
        $this->assertFalse($result->hasRule('UNSUBSCRIBE_LINK_MISSING'));
        $this->assertFalse($result->hasRule('IMPRINT_MISSING'), 'Adresse in der Fusszeile');
        $this->assertFalse($result->hasRule('PLACEHOLDER_UNREPLACED'), '{{first_name}} und {{unsubscribe_url}} sind bekannt');
        $this->assertFalse($result->hasRule('HIDDEN_TEXT'), 'Preheader wird erkannt');
        // Der Preheader des Layouts ist per Farbe = Hintergrund und 1px-Schrift versteckt
        $this->assertSame('color', $result->byRule('PREHEADER_INVISIBLE_FONT')[0]->params['method']);
        $this->assertSame(['PREHEADER_INVISIBLE_FONT'], array_values(array_filter(
            $result->ruleIds(),
            fn($id) => !in_array($id, ['IMAGE_SIZE_UNKNOWN'], true),
        )));
    }

    public function testAssignBlocksByContent(): void
    {
        $html = '<html><body><p>' . str_repeat('Gemüse vom Hof. ', 40) . '</p>'
            . '<img src="https://shop.example.test/files/kuerbis.jpg" width="536">'
            . '<p>Code: {{gutschein}}</p><a href="https://bit.ly/x">Shop</a></body></html>';
        $result = $this->service()->check($html, 'Kürbiszeit und Käseverkostung am Freitag', 'Frisches Gemüse vom Biohof und die Verkostung.', ['checkDns' => false]);
        $blocks = [
            ['type' => 'text', 'html' => '<p>Gemüse vom Hof.</p>'],
            ['type' => 'image', 'src' => 'https://shop.example.test/files/kuerbis.jpg', 'alt' => ''],
            ['id' => 'b-77', 'type' => 'text', 'html' => '<p>Code: {{gutschein}}</p>'],
            ['type' => 'button', 'label' => 'Shop', 'href' => 'https://bit.ly/x'],
        ];
        $mapped = (new CampaignSpamCheckService())->assignBlocks($result, $blocks);
        $byRule = [];
        foreach ($mapped->findings as $finding) {
            $byRule[$finding->ruleId] = $finding->blockId;
        }
        $this->assertSame('1', $byRule['IMAGE_ALT_MISSING']);
        $this->assertSame('b-77', $byRule['PLACEHOLDER_UNREPLACED']);
        $this->assertSame('3', $byRule['LINK_SHORTENER']);
        $this->assertNull($byRule['UNSUBSCRIBE_LINK_MISSING']);
        $this->assertSame($result->score, $mapped->score);
    }

    public function testEmptyAltOnContentImage(): void
    {
        $html = '<html><body><p>' . str_repeat('Gemüse vom Hof. ', 40) . '</p>'
            . '<img src="a.jpg" alt="" width="536"><img src="deko.png" alt="" width="40"></body></html>';
        $result = $this->service()->check($html, 'Kürbiszeit und Käseverkostung am Freitag', null, ['checkDns' => false]);
        $this->assertCount(1, $result->byRule('IMAGE_ALT_EMPTY'));
        $this->assertSame(SpamCheckFinding::LEVEL_HINT, $result->byRule('IMAGE_ALT_EMPTY')[0]->level);
    }

}
