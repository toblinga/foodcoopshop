<?php
declare(strict_types=1);

namespace App\Test\TestCase\src\Services\Newsletter\SpamCheck;

use App\Services\Newsletter\SpamCheck\LocalImageInfoProvider;
use App\Services\Newsletter\SpamCheck\NewsletterSpamCheckService;
use App\Services\Newsletter\SpamCheck\SpamAssassinClient;
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
 */
class SpamCheckHelpersTest extends TestCase
{

    public function testLocalImageInfoProviderReadsOnlyInsideWebroot(): void
    {
        $root = sys_get_temp_dir() . '/spamcheck-img-' . bin2hex(random_bytes(4));
        mkdir($root . '/files', 0777, true);
        $image = imagecreatetruecolor(1300, 100);
        imagejpeg($image, $root . '/files/bild.jpg');
        file_put_contents(dirname($root) . '/geheim.txt', 'x');

        $provider = new LocalImageInfoProvider(['https://shop.example.test/' => $root, '/' => $root]);
        $info = $provider->info('https://shop.example.test/files/bild.jpg?v=3');
        $this->assertNotNull($info);
        $this->assertSame(1300, $info['width']);
        $this->assertSame(filesize($root . '/files/bild.jpg'), $info['bytes']);
        $this->assertSame(1300, $provider->info('/files/bild.jpg')['width'] ?? null);

        $this->assertNull($provider->info('https://shop.example.test/../geheim.txt'));
        $this->assertNull($provider->info('https://shop.example.test/files/%2e%2e/%2e%2e/geheim.txt'));
        $this->assertNull($provider->info('https://fremd.example.org/files/bild.jpg'), 'externe Hosts werden nicht geladen');

        unlink($root . '/files/bild.jpg');
        rmdir($root . '/files');
        rmdir($root);
        unlink(dirname($root) . '/geheim.txt');
    }

    /**
     * Nur wenn ein lokales spamd laeuft: SPAMD_HOST=127.0.0.1 SPAMD_PORT=7830
     */
    public function testAgainstLocalSpamd(): void
    {
        $host = getenv('SPAMD_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('SPAMD_HOST nicht gesetzt');
        }
        $client = new SpamAssassinClient($host, (int)(getenv('SPAMD_PORT') ?: 783), 15);
        $gtube = 'XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X';
        $result = $client->check(SpamAssassinClient::buildMessage('<p>' . $gtube . '</p>', $gtube, 'Test', 'test@example.test'));
        $this->assertTrue($result['isSpam']);
        $this->assertContains('GTUBE', array_column($result['rules'], 'name'));

        $loaded = NewsletterSpamCheckService::loadConfig(CONFIG);
        $service = new NewsletterSpamCheckService($loaded['config'], $loaded['messages'], new FakeDnsResolver(), null, null, $client);
        $check = $service->check('<p>Hallo, frisches Gemüse ist da.</p>', 'Gemüse', null, ['spamAssassin' => true]);
        $this->assertTrue($check->spamAssassin['available']);
        $this->assertTrue($check->hasRule('SPAMASSASSIN'));
    }

}
