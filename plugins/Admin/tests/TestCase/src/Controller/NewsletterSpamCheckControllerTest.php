<?php
declare(strict_types=1);

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
 */
use App\Test\TestCase\AppCakeTestCase;
use App\Test\TestCase\Traits\AppIntegrationTestTrait;
use App\Test\TestCase\Traits\LoginTrait;

class NewsletterSpamCheckControllerTest extends AppCakeTestCase
{

    use AppIntegrationTestTrait;
    use LoginTrait;

    private function createCampaign(): int
    {
        $table = $this->getTableLocator()->get('NewsletterCampaigns');
        $campaign = $table->newEntity([
            'subject' => 'Kürbiszeit und Käseverkostung am Freitag',
            'preheader' => 'Frisches Gemüse vom Biohof und die Verkostung am Freitag.',
            'blocks' => json_encode([
                ['type' => 'heading', 'text' => 'Hallo {{first_name}}', 'level' => 1],
                ['type' => 'text', 'html' => '<p>' . str_repeat('Diese Woche gibt es frisches Gemüse vom Biohof aus dem Mühlviertel. ', 8) . '</p>'],
                ['type' => 'image', 'src' => 'https://extern.example.org/bild.jpg', 'alt' => '', 'href' => ''],
                ['type' => 'button', 'label' => 'Zum Shop', 'href' => 'http://bit.ly/abc'],
            ]),
            'status' => 'draft',
        ]);
        $table->saveOrFail($campaign);
        return (int)$campaign->id;
    }

    private function url(int $campaignId): string
    {
        return '/admin/newsletter/campaigns/' . $campaignId . '/check';
    }

    public function testCheckReturnsJson(): void
    {
        $this->loginAsSuperadmin();
        $id = $this->createCampaign();
        $this->ajaxPost($this->url($id), ['checkLinks' => 0]);
        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $json = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(1, $json['status']);
        $this->assertSame($id, $json['campaignId']);
        $ruleIds = array_column($json['result']['findings'], 'ruleId');
        $this->assertContains('LINK_SHORTENER', $ruleIds);
        $this->assertContains('LINK_HTTP', $ruleIds);
        $this->assertContains('IMAGE_ALT_EMPTY', $ruleIds, 'Renderer setzt alt immer, leer = Hinweis');
        $this->assertNotContains('UNSUBSCRIBE_LINK_MISSING', $ruleIds, 'Fusszeile des Layouts hat den Abmeldelink');
        $this->assertNotContains('LIST_UNSUBSCRIBE_MISSING', $ruleIds, 'Header kommen aus buildMessage()');
        $this->assertNotContains('TEXT_PART_MISSING', $ruleIds);
        $this->assertNotContains('PLACEHOLDER_UNREPLACED', $ruleIds, '{{first_name}} ist bekannt');
        $shortener = array_values(array_filter($json['result']['findings'], fn($f) => $f['ruleId'] === 'LINK_SHORTENER'))[0];
        $this->assertSame('3', $shortener['blockId'], 'Zuordnung ueber den Link im Baustein (Index 3)');
    }

    public function testUnsavedEditorStateIsChecked(): void
    {
        $this->loginAsSuperadmin();
        $id = $this->createCampaign();
        $this->ajaxPost($this->url($id), [
            'subject' => 'GRATIS!!! JETZT KAUFEN',
            'blocks' => json_encode([['type' => 'text', 'html' => '<p>Hallo {{gutschein}}</p>']]),
        ]);
        $this->assertResponseOk();
        $json = json_decode((string)$this->_response->getBody(), true);
        $ruleIds = array_column($json['result']['findings'], 'ruleId');
        $this->assertContains('SUBJECT_SPAM_WORDS', $ruleIds);
        $this->assertContains('PLACEHOLDER_UNREPLACED', $ruleIds);
        $this->assertSame('red', $json['result']['traffic']);
        // nichts gespeichert
        $campaign = $this->getTableLocator()->get('NewsletterCampaigns')->get($id);
        $this->assertSame('Kürbiszeit und Käseverkostung am Freitag', $campaign->subject);
    }

    public function testEditorFormatWithPostedHtml(): void
    {
        $this->loginAsSuperadmin();
        $id = $this->createCampaign();
        $doc = ['format' => 'fcs-newsletter-blocks', 'version' => 1, 'blocks' => [
            ['id' => 'btext1', 'type' => 'text', 'html' => '<p>Gemüse</p>'],
            ['id' => 'bimg1', 'type' => 'image', 'src' => 'https://extern.example.org/x.jpg'],
        ]];
        $html = '<html><body><div data-block-id="btext1"><p>' . str_repeat('Diese Woche gibt es frisches Gemüse vom Biohof. ', 12) . '</p></div>'
            . '<div data-block-id="bimg1"><img src="https://extern.example.org/x.jpg" width="536"></div>'
            . '<p>Beispiel-Foodcoop, Musterstraße 1, 12345 Musterstadt · <a href="{{unsubscribe_url}}">Abmelden</a></p></body></html>';
        $this->ajaxPost($this->url($id), ['blocks' => json_encode($doc), 'html' => $html]);
        $this->assertResponseOk();
        $json = json_decode((string)$this->_response->getBody(), true);
        $this->assertTrue($json['ok']);
        $alt = array_values(array_filter($json['messages'], fn($m) => $m['code'] === 'IMAGE_ALT_MISSING'));
        $this->assertSame('bimg1', $alt[0]['blockId']);
        $this->assertSame('warning', $alt[0]['level']);
    }

    public function testUnknownCampaign(): void
    {
        $this->loginAsSuperadmin();
        $this->ajaxPost($this->url(999999));
        $this->assertResponseCode(404);
    }

    public function testGetIsNotAllowed(): void
    {
        $this->loginAsSuperadmin();
        $id = $this->createCampaign();
        $this->get($this->url($id));
        $this->assertResponseCode(404, 'nur POST ist geroutet');
    }

    public function testCustomerHasNoAccess(): void
    {
        $this->loginAsCustomer();
        $id = $this->createCampaign();
        $this->ajaxPost($this->url($id));
        $this->assertResponseNotContains('"findings"');
        $this->assertNotSame(200, $this->_response->getStatusCode());
    }

}
