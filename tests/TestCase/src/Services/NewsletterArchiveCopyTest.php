<?php
declare(strict_types=1);

use App\Services\Newsletter\NewsletterEditorService;
use Cake\TestSuite\TestCase;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Kopieren importierter Mails: Vorschautext fällt weg, Inhalt bleibt als Originalinhalt-Baustein bearbeitbar.
 * Ohne Datenbank.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterArchiveCopyTest extends TestCase
{

    private const ARCHIVE_HTML = '<html><head><title>x</title></head><body style="background:#e6e6e6;">'
        . '<div class="preheader" style="display:none;">Vorschau</div>'
        . '<table width="580"><tr><td><p>Hallo <strong>Welt</strong></p><img src="/files/images/newsletter/archiv/a.jpg" alt=""></td></tr></table>'
        . '<script>alert(1)</script>'
        . '</body></html>';

    public function testIsArchiveBlocks(): void
    {
        $this->assertTrue(NewsletterEditorService::isArchiveBlocks('{"format":"fcs-newsletter-blocks","version":1,"blocks":[],"archiv":{"quelle":"rapidmail"}}'));
        $this->assertFalse(NewsletterEditorService::isArchiveBlocks('{"format":"fcs-newsletter-blocks","version":1,"blocks":[]}'));
        $this->assertFalse(NewsletterEditorService::isArchiveBlocks(null));
    }

    public function testExtractBodyHtmlKeepsContentAndDropsPreheaderAndScripts(): void
    {
        $body = (new NewsletterEditorService())->extractBodyHtml(self::ARCHIVE_HTML);

        $this->assertStringContainsString('<strong>Welt</strong>', $body);
        $this->assertStringContainsString('src="/files/images/newsletter/archiv/a.jpg"', $body);
        $this->assertStringNotContainsString('Vorschau', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<body', $body);
    }

    public function testArchiveCopyDocumentHasHeaderRawBlockAndFooter(): void
    {
        $doc = (new NewsletterEditorService())->getArchiveCopyDocument(self::ARCHIVE_HTML);
        $types = array_column($doc['blocks'], 'type');

        $this->assertSame(['header', 'raw', 'footer'], $types);
        $this->assertStringContainsString('<strong>Welt</strong>', $doc['blocks'][1]['data']['html']);
        $this->assertTrue($doc['blocks'][2]['locked']);
    }

}
