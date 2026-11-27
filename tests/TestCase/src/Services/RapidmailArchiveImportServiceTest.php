<?php
declare(strict_types=1);

use App\Services\Newsletter\RapidmailArchiveImportService;
use Cake\TestSuite\TestCase;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Import versendeter Newsletter aus .eml: MIME-Parsing, Betreff, Datum, Vorschautext, cid:-Bilder.
 * Ohne Datenbank.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class RapidmailArchiveImportServiceTest extends TestCase
{

    private string $tmpFile = '';

    protected function tearDown(): void
    {
        if ($this->tmpFile !== '' && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    private function sampleEml(): string
    {
        return "From: Beispiel Newsletter <newsletter@example.com>\n"
            . "To: kunde@example.com\n"
            . "Subject: =?UTF-8?Q?Bio=20Marillen=20=F0=9F=91=8C?=\n"
            . "Date: Mon, 08 Jul 2024 20:00:55 +0200\n"
            . "MIME-Version: 1.0\n"
            . "Content-Type: multipart/related; boundary=\"XYZ\"\n"
            . "\n"
            . "--XYZ\n"
            . "Content-Type: text/html; charset=UTF-8\n"
            . "Content-Transfer-Encoding: quoted-printable\n"
            . "\n"
            . "<div class=3D\"preheader\" style=3D\"display:none\">Vorschau&#252;text</div>"
            . "<img src=3D\"cid:bild1\" alt=3D\"\"><p>Hallo =C3=BCberall</p>\n"
            . "--XYZ\n"
            . "Content-Type: image/png\n"
            . "Content-Transfer-Encoding: base64\n"
            . "Content-ID: <bild1>\n"
            . "Content-Disposition: inline\n"
            . "\n"
            . "iVBORw0KGgo=\n"
            . "--XYZ--\n";
    }

    private function writeSample(): string
    {
        $this->tmpFile = sys_get_temp_dir() . DS . 'rm-test-' . uniqid() . '.eml';
        file_put_contents($this->tmpFile, $this->sampleEml());

        return $this->tmpFile;
    }

    public function testParseFileReadsSubjectDateSenderAndPreheader(): void
    {
        $mail = (new RapidmailArchiveImportService())->parseFile($this->writeSample());

        $this->assertSame('Bio Marillen 👌', $mail['subject']);
        $this->assertSame('2024-07-08 20:00:55', $mail['sentAt']->setTimezone(new \DateTimeZone('Europe/Vienna'))->format('Y-m-d H:i:s'));
        $this->assertSame('Beispiel Newsletter', $mail['fromName']);
        $this->assertSame('newsletter@example.com', $mail['fromEmail']);
        $this->assertSame('Vorschauütext', $mail['preheader']);
    }

    public function testParseFileDecodesQuotedPrintableHtmlAndKeepsInlineImage(): void
    {
        $mail = (new RapidmailArchiveImportService())->parseFile($this->writeSample());

        $this->assertStringContainsString('<p>Hallo überall</p>', $mail['html']);
        $this->assertStringContainsString('src="cid:bild1"', $mail['html']);
        $this->assertArrayHasKey('bild1', $mail['parts']);
        $this->assertSame('image/png', $mail['parts']['bild1']['type']);
        $this->assertSame("\x89PNG\r\n\x1a\n", $mail['parts']['bild1']['data']);
    }

    public function testParseFileRejectsMailWithoutHtml(): void
    {
        $this->tmpFile = sys_get_temp_dir() . DS . 'rm-test-' . uniqid() . '.eml';
        file_put_contents($this->tmpFile, "Subject: nur Text\nDate: Mon, 08 Jul 2024 20:00:55 +0200\nContent-Type: text/plain\n\nHallo\n");

        $this->expectException(\RuntimeException::class);
        (new RapidmailArchiveImportService())->parseFile($this->tmpFile);
    }

}
