<?php
declare(strict_types=1);

use App\Model\Entity\NewsletterCampaign;
use App\Model\Entity\NewsletterSubscriber;
use App\Services\Newsletter\NewsletterEditorService;
use App\Services\Newsletter\NewsletterRenderService;
use Cake\TestSuite\TestCase;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Bausteineditor: Format-Erkennung, html_cache als Quelle, Platzhalter mit Ersatztext, HTML-Bereinigung.
 * Ohne Datenbank.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterEditorServiceTest extends TestCase
{

    public function testIsEditorFormat(): void
    {
        $this->assertTrue(NewsletterEditorService::isEditorFormat('{"format":"fcs-newsletter-blocks","version":1,"blocks":[]}'));
        $this->assertFalse(NewsletterEditorService::isEditorFormat('[{"type":"text","html":"x"}]'));
        $this->assertFalse(NewsletterEditorService::isEditorFormat(null));
        $this->assertFalse(NewsletterEditorService::isEditorFormat('kaputt'));
    }

    public function testRenderUsesHtmlCacheForEditorCampaigns(): void
    {
        $campaign = new NewsletterCampaign([
            'subject' => 'x',
            'blocks' => '{"format":"fcs-newsletter-blocks","version":1,"blocks":[]}',
            'html_cache' => '<html><body>vom Editor {{unsubscribe_url}}</body></html>',
        ]);
        $this->assertSame('<html><body>vom Editor {{unsubscribe_url}}</body></html>', (new NewsletterRenderService())->render($campaign));
    }

    public function testPersonalizeWithFallback(): void
    {
        $render = new NewsletterRenderService();
        $html = '<h1>Hallo {{first_name|liebe Mitglieder}}!</h1><p>{{last_name}}</p><a href="{{unsubscribe_url}}">ab</a> {{email}}';
        $with = $render->personalize($html, new NewsletterSubscriber(['first_name' => 'Anna', 'last_name' => 'Muster', 'email' => 'a@example.test']), 'https://x.test/ab');
        $this->assertSame('<h1>Hallo Anna!</h1><p>Muster</p><a href="https://x.test/ab">ab</a> a@example.test', $with);
        $without = $render->personalize($html, new NewsletterSubscriber(['first_name' => '', 'email' => 'b@example.test']), 'https://x.test/ab');
        $this->assertStringContainsString('Hallo liebe Mitglieder!', $without);
        $this->assertStringContainsString('<p></p>', $without);
        $escaped = $render->personalize('{{first_name}}', new NewsletterSubscriber(['first_name' => '<b>X</b>']), '#');
        $this->assertSame('&lt;b&gt;X&lt;/b&gt;', $escaped);
    }

    public function testSanitizeEditorHtmlKeepsOutlookCommentsAndRemovesScripts(): void
    {
        $service = new NewsletterEditorService();
        $html = '<!--[if mso]><table><tr><td><![endif]--><div onclick="x()">a</div><script>alert(1)</script><a href="javascript:alert(1)">b</a><img src="data:image/png;base64,AA">';
        $clean = $service->sanitizeEditorHtml($html);
        $this->assertStringContainsString('<!--[if mso]>', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('data:image/png', $clean);
    }

}
