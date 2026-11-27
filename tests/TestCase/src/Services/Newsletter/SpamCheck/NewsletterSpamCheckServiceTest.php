<?php
declare(strict_types=1);

namespace App\Test\TestCase\src\Services\Newsletter\SpamCheck;

use App\Services\Newsletter\SpamCheck\NewsletterSpamCheckService;
use App\Services\Newsletter\SpamCheck\SpamAssassinClient;
use App\Services\Newsletter\SpamCheck\SpamCheckFinding;
use App\Services\Newsletter\SpamCheck\SpamCheckResult;
use PHPUnit\Framework\Attributes\DataProvider;
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
class NewsletterSpamCheckServiceTest extends TestCase
{

    private const SENDER = 'newsletter@news.example.org';
    private const GOOD_SUBJECT = 'Kürbiszeit und Käseverkostung am Freitag';
    private const GOOD_PREHEADER = 'Frisches Gemüse vom Biohof Leitner und die Verkostung am Freitag.';

    private const FOOTER = '<p>Beispiel-Foodcoop, Musterstraße 1, 12345 Musterstadt · <a href="https://shop.example.test/impressum">Impressum</a> · <a href="{{unsubscribe_url}}">Abmelden</a></p>';

    private static function configDir(): string
    {
        return CONFIG;
    }

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /**
     * @param array<string, mixed> $configOverride
     */
    private function service(
        array $configOverride = [],
        ?FakeDnsResolver $dns = null,
        ?FakeImageInfoProvider $images = null,
        ?FakeLinkProbe $probe = null,
        ?SpamAssassinClient $spamAssassin = null,
    ): NewsletterSpamCheckService {
        $loaded = NewsletterSpamCheckService::loadConfig(self::configDir());
        $config = array_replace_recursive($loaded['config'], $configOverride);
        return new NewsletterSpamCheckService(
            $config,
            $loaded['messages'],
            $dns ?? FakeDnsResolver::complete('news.example.org'),
            $images ?? new FakeImageInfoProvider(),
            $probe,
            $spamAssassin,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function goodOptions(): array
    {
        return [
            'fromEmail' => self::SENDER,
            'fromName' => 'Beispiel-Foodcoop',
            'replyTo' => 'info@example.org',
            'textPart' => str_repeat('Diese Woche gibt es frisches Gemüse vom Biohof Leitner. ', 20),
            'headers' => [
                'List-Unsubscribe' => '<https://shop.example.test/newsletter/abmelden/abc>, <mailto:abmelden@example.org>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ];
    }

    private function body(string $inner): string
    {
        return '<html><body><div data-block-id="t1">' . $inner . '</div><div data-block-id="footer">' . self::FOOTER . '</div></body></html>';
    }

    private function longText(): string
    {
        return '<p>' . str_repeat('Diese Woche gibt es frisches Gemüse vom Biohof Leitner aus dem Mühlviertel. ', 8) . '</p>';
    }

    // ------------------------------------------------------------------ Gesamtbeispiele

    public function testGoodNewsletterIsGreen(): void
    {
        $images = new FakeImageInfoProvider([
            'logo.png' => ['bytes' => 12000, 'width' => 400, 'height' => 120],
            'kuerbis.jpg' => ['bytes' => 95000, 'width' => 1104, 'height' => 600],
        ]);
        $result = $this->service([], null, $images)->check(self::fixture('good.html'), self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());

        $this->assertSame([], array_map(fn($f) => $f->ruleId . ': ' . $f->message, array_filter(
            $result->findings,
            fn(SpamCheckFinding $f) => $f->level !== SpamCheckFinding::LEVEL_INFO,
        )));
        $this->assertSame(SpamCheckResult::TRAFFIC_GREEN, $result->traffic);
        $this->assertSame(100, $result->score);
        $this->assertGreaterThan(500, $result->stats['textChars']);
    }

    public function testGoodNewsletterReadsPreheaderFromHtml(): void
    {
        $result = $this->service()->check(self::fixture('good.html'), self::GOOD_SUBJECT, null, self::goodOptions());
        $this->assertFalse($result->hasRule('PREHEADER_MISSING'));
        $this->assertFalse($result->hasRule('HIDDEN_TEXT'));
        $this->assertFalse($result->hasRule('TINY_FONT'));
    }

    public function testBadNewsletterIsRed(): void
    {
        $images = new FakeImageInfoProvider([
            'riesenbanner.jpg' => ['bytes' => 1500000, 'width' => 2400, 'height' => 3200],
            'button.png' => ['bytes' => 250000, 'width' => 300, 'height' => 80],
        ]);
        $result = $this->service([], new FakeDnsResolver(), $images)->check(
            self::fixture('bad.html'),
            'GRATIS!!! JETZT KAUFEN 🎉🎉🎉 100 % Gewinn',
            '',
            ['fromEmail' => 'aktion@gmail.com', 'replyTo' => 'kaputt', 'textPart' => null, 'headers' => []],
        );

        $expected = [
            'SUBJECT_CAPS', 'SUBJECT_PUNCTUATION', 'SUBJECT_SPAM_WORDS', 'SUBJECT_EMOJI', 'SUBJECT_CURRENCY',
            'PREHEADER_MISSING', 'IMAGE_ALT_MISSING', 'IMAGE_ALT_EMPTY_LINKED', 'IMAGE_FILESIZE',
            'IMAGE_DISPLAY_WIDTH', 'IMAGE_INTRINSIC_WIDTH', 'IMAGES_TOTAL_SIZE', 'IMAGE_ONLY',
            'BODY_SPAM_WORDS', 'BODY_CAPS', 'BODY_EXCLAMATION', 'DUMMY_TEXT',
            'LINK_SHORTENER', 'LINK_HTTP', 'LINK_IP', 'LINK_TEXT_MISMATCH', 'LINK_EMPTY',
            'UNSUBSCRIBE_LINK_MISSING', 'IMPRINT_MISSING', 'LIST_UNSUBSCRIBE_MISSING',
            'TEXT_PART_MISSING', 'SENDER_FREEMAIL', 'REPLY_TO_INVALID',
            'HTML_SCRIPT', 'HTML_FORM', 'HTML_EMBED', 'HIDDEN_TEXT', 'TINY_FONT', 'PLACEHOLDER_UNREPLACED',
        ];
        foreach ($expected as $ruleId) {
            $this->assertTrue($result->hasRule($ruleId), $ruleId . ' fehlt, gefunden: ' . implode(', ', $result->ruleIds()));
        }
        $this->assertSame(SpamCheckResult::TRAFFIC_RED, $result->traffic);
        $this->assertSame(0, $result->score);
        // Freemail-Absender: keine DNS-Abfrage noetig
        $this->assertFalse($result->hasRule('DNS_SPF'));
    }

    public function testResultToArrayIsJsonReady(): void
    {
        $result = $this->service()->check($this->body('<p>Hallo</p>'), '', null);
        $array = $result->toArray();
        $this->assertSame(['score', 'traffic', 'trafficLabel', 'hasErrors', 'counts', 'findings', 'stats', 'spamAssassin'], array_keys($array));
        $this->assertTrue($array['hasErrors']);
        $first = $array['findings'][0];
        $this->assertSame(['ruleId', 'level', 'category', 'message', 'tip', 'field', 'blockId', 'path', 'params', 'weight'], array_keys($first));
        $this->assertSame('error', $first['level']);
        $this->assertNotFalse(json_encode($array, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------------ Betreff

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function subjectProvider(): array
    {
        return [
            'fehlt' => ['', 'SUBJECT_MISSING'],
            'zu kurz' => ['Neu', 'SUBJECT_LENGTH'],
            'zu lang' => [str_repeat('Gemüse ', 15), 'SUBJECT_LENGTH'],
            'versalien' => ['FRISCHES GEMÜSE VOM BIOHOF DIESE WOCHE', 'SUBJECT_CAPS'],
            'ausrufezeichen' => ['Frisches Gemüse vom Biohof ist da!!!', 'SUBJECT_PUNCTUATION'],
            'fragezeichen' => ['Habt ihr schon bestellt??? Letzter Aufruf', 'SUBJECT_PUNCTUATION'],
            'spamwort de' => ['Kostenlos: Verkostung im Laden am Freitag', 'SUBJECT_SPAM_WORDS'],
            'spamwort en' => ['Free tasting in our shop this Friday', 'SUBJECT_SPAM_WORDS'],
            'geld zurueck' => ['Geld zurück bei Nichtgefallen, ehrlich', 'SUBJECT_SPAM_WORDS'],
            'emojis' => ['Kürbiszeit 🎃🎃 im Laden am Freitag', 'SUBJECT_EMOJI'],
            'platzhalter' => ['Hallo {{vorname}}, {{produkt}} ist da', 'SUBJECT_PLACEHOLDER'],
            'gut' => [self::GOOD_SUBJECT, null],
            'ein emoji ok' => ['Kürbiszeit 🎃 und Käseverkostung am Freitag', null],
            'bekannter platzhalter ok' => ['{{vorname}}, die Kürbisse sind endlich da', null],
            'platzhalter mit ersatztext ok' => ['{{first_name|Hallo}}, die Kürbisse sind endlich da', null],
        ];
    }

    #[DataProvider('subjectProvider')]
    public function testSubjectRules(string $subject, ?string $expectedRule): void
    {
        $result = $this->service()->check($this->body($this->longText()), $subject, self::GOOD_PREHEADER, self::goodOptions());
        $subjectRules = array_values(array_unique(array_map(
            fn(SpamCheckFinding $f) => $f->ruleId,
            array_filter($result->findings, fn(SpamCheckFinding $f) => $f->field === 'subject'),
        )));
        if ($expectedRule === null) {
            $this->assertSame([], $subjectRules);
        } else {
            $this->assertContains($expectedRule, $subjectRules);
        }
    }

    public function testSpamWordsDoNotMatchInsideWords(): void
    {
        // "Gewinnung" und "freely" sind keine Spam-Woerter
        $result = $this->service()->check($this->body($this->longText()), 'Ölgewinnung bei der Mühle, freely explained', self::GOOD_PREHEADER, self::goodOptions());
        $this->assertFalse($result->hasRule('SUBJECT_SPAM_WORDS'));
    }

    public function testVeryShortSubjectIsWarning(): void
    {
        $result = $this->service()->check($this->body($this->longText()), 'Hallo', self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_WARNING, $result->byRule('SUBJECT_LENGTH')[0]->level);
    }

    public function testPreheaderRules(): void
    {
        $service = $this->service();
        $missing = $service->check($this->body($this->longText()), self::GOOD_SUBJECT, null, self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_WARNING, $missing->byRule('PREHEADER_MISSING')[0]->level);
        $this->assertSame('preheader', $missing->byRule('PREHEADER_MISSING')[0]->field);

        $same = $service->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_SUBJECT, self::goodOptions());
        $this->assertTrue($same->hasRule('PREHEADER_SAME_AS_SUBJECT'));
    }

    // ------------------------------------------------------------------ Inhalt

    public function testImageOnlyMailIsError(): void
    {
        $html = $this->body('<a href="https://shop.example.test/"><img src="https://shop.example.test/img/flyer.jpg" width="600" height="900" alt="Flyer"></a>');
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $finding = $result->byRule('IMAGE_ONLY')[0];
        $this->assertSame(SpamCheckFinding::LEVEL_ERROR, $finding->level);
        $this->assertSame(SpamCheckResult::TRAFFIC_RED, $result->traffic);
    }

    public function testTextImageRatio(): void
    {
        $text = '<p>' . str_repeat('Kürbisse vom Biohof. ', 15) . '</p>';
        $images = str_repeat('<img src="https://shop.example.test/img/a.jpg" width="600" height="400" alt="Bild">', 3);
        $result = $this->service()->check($this->body($text . $images), self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertTrue($result->hasRule('TEXT_IMAGE_RATIO'));

        // genug Text: keine Meldung, auch mit Bildern
        $result = $this->service()->check($this->body($this->longText() . $images), self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertFalse($result->hasRule('TEXT_IMAGE_RATIO'));
    }

    public function testImageSizesAndAltText(): void
    {
        $images = new FakeImageInfoProvider([
            'gross.jpg' => ['bytes' => 300 * 1024, 'width' => 1000, 'height' => 600],
            'riesig.jpg' => ['bytes' => 2 * 1024 * 1024, 'width' => 4000, 'height' => 3000],
            'ok.jpg' => ['bytes' => 50 * 1024, 'width' => 1200, 'height' => 800],
        ]);
        $html = $this->body($this->longText()
            . '<img src="https://shop.example.test/img/gross.jpg" width="600" alt="Groß">'
            . '<img src="https://shop.example.test/img/riesig.jpg" width="800" alt="">'
            . '<img src="https://shop.example.test/img/ok.jpg" style="width:600px">'
            . '<img src="https://extern.example.org/x.jpg" width="100" height="100" alt="Extern">'
            . '<img src="https://shop.example.test/pixel.gif" width="1" height="1">');
        $result = $this->service([], null, $images)->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());

        $sizes = $result->byRule('IMAGE_FILESIZE');
        $this->assertCount(2, $sizes);
        $levels = array_map(fn($f) => $f->level, $sizes);
        sort($levels);
        $this->assertSame(['error', 'warning'], $levels);
        $this->assertSame('t1', $sizes[0]->blockId);
        $this->assertStringContainsString('img', (string)$sizes[0]->path);

        $this->assertCount(1, $result->byRule('IMAGE_DISPLAY_WIDTH'));
        $this->assertCount(1, $result->byRule('IMAGE_INTRINSIC_WIDTH'));
        $this->assertCount(1, $result->byRule('IMAGES_TOTAL_SIZE'));
        // nur ok.jpg hat gar kein alt; das Zaehlpixel wird ignoriert
        $this->assertCount(1, $result->byRule('IMAGE_ALT_MISSING'));
        $this->assertSame(SpamCheckFinding::LEVEL_INFO, $result->byRule('IMAGE_SIZE_UNKNOWN')[0]->level);
    }

    public function testDataUriImage(): void
    {
        $png = base64_encode((string)file_get_contents(__DIR__ . '/fixtures/pixel10.png'));
        $html = $this->body($this->longText() . '<img src="data:image/png;base64,' . $png . '" width="10" height="10" alt="Punkt">');
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertTrue($result->hasRule('IMAGE_DATA_URI'));
        $this->assertFalse($result->hasRule('IMAGE_SIZE_UNKNOWN'));
    }

    public function testGmailClipping(): void
    {
        $html = $this->body($this->longText() . '<!-- ' . str_repeat('x', 110 * 1024) . ' -->');
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_ERROR, $result->byRule('HTML_SIZE')[0]->level);

        $html = $this->body($this->longText() . '<!-- ' . str_repeat('x', 95 * 1024) . ' -->');
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_WARNING, $result->byRule('HTML_SIZE')[0]->level);
    }

    public function testBodySpamWordsAndEmptyBody(): void
    {
        $result = $this->service()->check($this->body('<p>Jetzt kaufen, gratis, kostenlos, Gewinnspiel, Geld zurück! ' . str_repeat('Gemüse vom Hof. ', 40) . '</p>'), self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_WARNING, $result->byRule('BODY_SPAM_WORDS')[0]->level);

        $result = $this->service()->check('', self::GOOD_SUBJECT, self::GOOD_PREHEADER);
        $this->assertTrue($result->hasRule('BODY_EMPTY'));
    }

    // ------------------------------------------------------------------ Versteckter Text, Schrift

    public function testHiddenTextLowContrastAndTinyFont(): void
    {
        $html = $this->body($this->longText()
            . '<div style="background-color:#2f5d34"><p style="color:#2f5d35">Unsichtbar auf Grün</p></div>'
            . '<p style="color:#bbbbbb">Hellgrau auf Weiß</p>'
            . '<p style="font-size:9px">Winzig</p>'
            . '<p style="font-size:2px">Fast weg</p>'
            . '<table bgcolor="#000000"><tr><td><font color="#000000">Schwarz auf Schwarz</font></td></tr></table>'
            . '<p style="color:#ffffff; background-color:#2f5d34">Weiß auf Grün ist ok</p>');
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());

        $hidden = array_map(fn($f) => $f->params['snippet'], $result->byRule('HIDDEN_TEXT'));
        $this->assertSame(['Unsichtbar auf Grün', 'Schwarz auf Schwarz'], $hidden);
        $this->assertSame(['Hellgrau auf Weiß'], array_map(fn($f) => $f->params['snippet'], $result->byRule('LOW_CONTRAST')));
        $tiny = $result->byRule('TINY_FONT');
        $this->assertCount(2, $tiny);
        $levels = array_map(fn($f) => $f->level, $tiny);
        sort($levels);
        $this->assertSame(['error', 'warning'], $levels);
    }

    public function testHiddenPreheaderAndResponsiveBlocksAreNotFlagged(): void
    {
        $html = '<html><body><div class="preheader" style="display:none;font-size:1px;color:#fff;max-height:0;overflow:hidden">Vorschau</div>'
            . '<div data-block-id="t1">' . $this->longText() . '<div style="display:none">Nur am Handy</div></div>'
            . '<div data-block-id="footer">' . self::FOOTER . '</div></body></html>';
        $result = $this->service()->check($html, self::GOOD_SUBJECT, null, self::goodOptions());
        $this->assertFalse($result->hasRule('HIDDEN_TEXT'));
        $this->assertFalse($result->hasRule('TINY_FONT'));
        $this->assertFalse($result->hasRule('PREHEADER_MISSING'));
    }

    public function testPreheaderHiddenByInvisibleFont(): void
    {
        $html = '<html><body><span data-preheader style="display:none;font-size:1px;color:#ffffff">Vorschau zum Newsletter</span><div data-block-id="t1">' . $this->longText() . '</div>' . self::FOOTER . '</body></html>';
        $result = $this->service()->check($html, self::GOOD_SUBJECT, null, self::goodOptions());
        $this->assertSame('color', $result->byRule('PREHEADER_INVISIBLE_FONT')[0]->params['method']);
        $this->assertFalse($result->hasRule('HIDDEN_TEXT'));
    }

    // ------------------------------------------------------------------ Links

    public function testLinkRules(): void
    {
        $html = $this->body($this->longText()
            . '<a href="https://bit.ly/x">Kurz</a>'
            . '<a href="https://203.0.113.9/shop">IP</a>'
            . '<a href="https://evil.example.com/">https://www.example.org</a>'
            . '<a href="https://shop.example.org/">www.example.org</a>'
            . '<a href="http://shop.example.test/alt">Alt</a>'
            . '<a href="">Leer</a>'
            . '<a href="/relativ">Relativ</a>'
            . '<a href="https://shop.example.test/{{produkt_url}}">Produkt</a>'
            . '<a href="mailto:info@example.org">info@example.org</a>'
            . '<a href="javascript:void(0)">JS</a>');
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());

        $this->assertCount(1, $result->byRule('LINK_SHORTENER'));
        $this->assertCount(1, $result->byRule('LINK_IP'));
        $mismatch = $result->byRule('LINK_TEXT_MISMATCH');
        $this->assertCount(1, $mismatch);
        $this->assertSame('evil.example.com', $mismatch[0]->params['hrefHost']);
        $this->assertCount(1, $result->byRule('LINK_HTTP'));
        $this->assertCount(2, $result->byRule('LINK_EMPTY'));
        $this->assertCount(1, $result->byRule('LINK_PLACEHOLDER'));
        $this->assertTrue($result->hasRule('HTML_SCRIPT'));
    }

    public function testLinkCount(): void
    {
        $links = '';
        for ($i = 0; $i < 30; $i++) {
            $links .= '<a href="https://shop.example.test/p/' . $i . '">Produkt ' . $i . '</a> ';
        }
        $result = $this->service()->check($this->body($this->longText() . $links), self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_HINT, $result->byRule('LINK_COUNT')[0]->level);
    }

    public function testBrokenLinksOnlyWhenEnabled(): void
    {
        $probe = new FakeLinkProbe(['https://shop.example.test/weg' => 404]);
        $html = $this->body($this->longText() . '<a href="https://shop.example.test/weg">Weg</a><a href="https://shop.example.test/da">Da</a>');

        $result = $this->service([], null, null, $probe)->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertFalse($result->hasRule('LINK_BROKEN'));
        $this->assertSame([], $probe->calls);

        $result = $this->service(['rules' => ['LINK_BROKEN' => ['enabled' => true]]], null, null, $probe)->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertCount(1, $result->byRule('LINK_BROKEN'));
        $this->assertSame('404', $result->byRule('LINK_BROKEN')[0]->params['status']);
        $this->assertCount(3, $probe->calls); // weg, da, impressum
    }

    public function testBlocklistOnlyWhenEnabled(): void
    {
        $dns = FakeDnsResolver::complete('news.example.org');
        $html = $this->body($this->longText() . '<a href="https://spam-domain.example/">x</a>');
        $result = $this->service([], $dns)->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertFalse($result->hasRule('LINK_BLOCKLIST'));
        $this->assertSame([], array_filter($dns->queries, fn($q) => str_starts_with($q, 'A ')));

        $dns = new FakeDnsResolver([], [], ['spam-domain.example.dbl.test']);
        $result = $this->service(['rules' => ['LINK_BLOCKLIST' => ['enabled' => true, 'zones' => ['dbl.test']]]], $dns)
            ->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions() + ['checkDns' => true]);
        $this->assertCount(1, $result->byRule('LINK_BLOCKLIST'));
    }

    // ------------------------------------------------------------------ Platzhalter

    public function testPlaceholders(): void
    {
        $html = $this->body($this->longText() . '<p>Hallo {{ Vorname }}, dein Code: {{gutschein}} [[datum]] *|FNAME|* %%Firma%%</p>');
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $found = array_map(fn($f) => $f->params['placeholder'], $result->byRule('PLACEHOLDER_UNREPLACED'));
        $this->assertSame(['{{gutschein}}', '[[datum]]', '*|FNAME|*', '%%Firma%%'], $found);
        $this->assertSame('t1', $result->byRule('PLACEHOLDER_UNREPLACED')[0]->blockId);
    }

    // ------------------------------------------------------------------ Pflichtangaben

    public function testMandatoryFooter(): void
    {
        $html = '<html><body><div data-block-id="t1">' . $this->longText() . '</div></body></html>';
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_ERROR, $result->byRule('UNSUBSCRIBE_LINK_MISSING')[0]->level);
        $this->assertSame(SpamCheckFinding::LEVEL_ERROR, $result->byRule('IMPRINT_MISSING')[0]->level);

        // Adresse statt Impressum-Link genuegt, Abmeldelink ueber URL erkannt
        $html = $this->body($this->longText()) . '<p>Beispiel-Foodcoop, Musterstraße 1, 12345 Musterstadt</p><a href="https://shop.example.test/newsletter/unsubscribe/abc">hier</a>';
        $html = str_replace(self::FOOTER, '', $html);
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertFalse($result->hasRule('UNSUBSCRIBE_LINK_MISSING'));
        $this->assertFalse($result->hasRule('IMPRINT_MISSING'));
    }

    public function testListUnsubscribeHeaders(): void
    {
        $service = $this->service();
        $html = $this->body($this->longText());

        $result = $service->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame([], array_values(array_filter($result->ruleIds(), fn($id) => str_starts_with($id, 'LIST_'))));

        $options = self::goodOptions();
        $options['headers'] = ['List-Unsubscribe' => '<mailto:abmelden@example.org>'];
        $result = $service->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, $options);
        $this->assertTrue($result->hasRule('LIST_UNSUBSCRIBE_NO_HTTPS'));
        $this->assertTrue($result->hasRule('LIST_UNSUBSCRIBE_POST_MISSING'));

        $options['headers'] = [];
        $result = $service->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, $options);
        $this->assertSame(SpamCheckFinding::LEVEL_ERROR, $result->byRule('LIST_UNSUBSCRIBE_MISSING')[0]->level);

        unset($options['headers']);
        $result = $service->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, $options);
        $this->assertSame(SpamCheckFinding::LEVEL_INFO, $result->byRule('HEADERS_UNKNOWN')[0]->level);
    }

    // ------------------------------------------------------------------ Technik

    public function testTextPart(): void
    {
        $options = self::goodOptions();
        $options['textPart'] = 'Kurz.';
        $result = $this->service()->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_PREHEADER, $options);
        $this->assertTrue($result->hasRule('TEXT_PART_SHORT'));

        $options['textPart'] = '';
        $result = $this->service()->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_PREHEADER, $options);
        $this->assertTrue($result->hasRule('TEXT_PART_MISSING'));
    }

    public function testSenderAndReplyTo(): void
    {
        $options = self::goodOptions();
        $options['fromEmail'] = 'noreply@news.example.org';
        $options['replyTo'] = 'beispiel@gmx.at';
        $result = $this->service()->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_PREHEADER, $options);
        $this->assertTrue($result->hasRule('SENDER_NOREPLY'));
        $this->assertTrue($result->hasRule('REPLY_TO_FREEMAIL'));

        $options['fromEmail'] = 'kein-at-zeichen';
        $result = $this->service()->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_PREHEADER, $options);
        $this->assertSame(SpamCheckFinding::LEVEL_ERROR, $result->byRule('SENDER_INVALID')[0]->level);

        $options['fromEmail'] = self::SENDER;
        $options['replyTo'] = 'office@anderer-verein.at';
        $result = $this->service()->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_PREHEADER, $options);
        $this->assertTrue($result->hasRule('REPLY_TO_OTHER_DOMAIN'));
    }

    public function testDnsRecords(): void
    {
        $html = $this->body($this->longText());
        $dns = new FakeDnsResolver([
            'news.example.org' => ['v=spf1 include:a ~all', 'v=spf1 include:b ~all'],
        ]);
        $result = $this->service([], $dns)->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertTrue($result->hasRule('DNS_SPF_MULTIPLE'));
        $this->assertSame(SpamCheckFinding::LEVEL_WARNING, $result->byRule('DNS_DMARC')[0]->level);
        $this->assertSame(SpamCheckFinding::LEVEL_WARNING, $result->byRule('DNS_DKIM')[0]->level);
        $this->assertContains('TXT _dmarc.example.org', $dns->queries, 'Fallback auf die Hauptdomain');

        // DMARC der Hauptdomain gilt auch fuer die Subdomain
        $dns = new FakeDnsResolver([
            'news.example.org' => ['v=spf1 include:spf.brevo.com ~all'],
            '_dmarc.example.org' => ['v=DMARC1; p=none'],
            'mail._domainkey.news.example.org' => ['v=DKIM1; k=rsa; p=MIIB'],
        ]);
        $result = $this->service([], $dns)->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame([], array_values(array_filter($result->ruleIds(), fn($id) => str_starts_with($id, 'DNS_'))));

        // Testinstanz: nur Info
        $result = $this->service([], new FakeDnsResolver())->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, ['isTestInstance' => true] + self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_INFO, $result->byRule('DNS_SPF')[0]->level);
        $this->assertSame(0.0, $result->byRule('DNS_SPF')[0]->weight);

        // abschaltbar
        $dns = new FakeDnsResolver();
        $result = $this->service([], $dns)->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, ['checkDns' => false] + self::goodOptions());
        $this->assertSame([], $dns->queries);
    }

    public function testActiveContent(): void
    {
        $html = $this->body($this->longText() . '<p onclick="x()">Klick</p><select><option>1</option></select><object data="x.swf"></object>');
        $result = $this->service()->check($html, self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame('onclick', $result->byRule('HTML_SCRIPT')[0]->params['detail']);
        $this->assertTrue($result->hasRule('HTML_FORM'));
        $this->assertTrue($result->hasRule('HTML_EMBED'));
    }

    // ------------------------------------------------------------------ Konfiguration, Sprache, Punkte

    public function testRulesCanBeDisabledAndRelevelled(): void
    {
        $html = $this->body($this->longText());
        $result = $this->service(['rules' => ['PREHEADER_MISSING' => ['enabled' => false]]])->check($html, self::GOOD_SUBJECT, null, self::goodOptions());
        $this->assertFalse($result->hasRule('PREHEADER_MISSING'));

        $result = $this->service(['rules' => ['PREHEADER_MISSING' => ['level' => 'error']]])->check($html, self::GOOD_SUBJECT, null, self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_ERROR, $result->byRule('PREHEADER_MISSING')[0]->level);
    }

    public function testCustomConfigFile(): void
    {
        $dir = sys_get_temp_dir() . '/spamcheck-' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy(self::configDir() . '/newsletter_spamcheck.php', $dir . '/newsletter_spamcheck.php');
        copy(self::configDir() . '/newsletter_spamcheck_messages.php', $dir . '/newsletter_spamcheck_messages.php');
        file_put_contents($dir . '/newsletter_spamcheck.custom.php', '<?php return ['
            . "'spamWords' => ['de' => ['kürbis' => 3, 'gratis' => 0]],"
            . "'rules' => ['SUBJECT_LENGTH' => ['min' => 5]],"
            . "'messages' => ['de' => ['SUBJECT_SPAM_WORDS' => ['msg' => 'Eigenes Wort: {word}']]],"
            . '];');
        $loaded = NewsletterSpamCheckService::loadConfig($dir);
        $this->assertSame(3, $loaded['config']['spamWords']['de']['kürbis']);
        $this->assertSame(4, $loaded['config']['spamWords']['de']['kostenlos']);
        $this->assertSame(60, $loaded['config']['rules']['SUBJECT_LENGTH']['max']);
        $service = new NewsletterSpamCheckService($loaded['config'], $loaded['messages'], new FakeDnsResolver());
        $result = $service->check($this->body($this->longText()), 'Kürbis frisch und gratis', self::GOOD_PREHEADER, ['checkDns' => false]);
        $this->assertSame(['Eigenes Wort: kürbis'], array_map(fn($f) => $f->message, $result->byRule('SUBJECT_SPAM_WORDS')));
        $this->assertFalse($result->hasRule('SUBJECT_LENGTH'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testEnglishMessages(): void
    {
        $result = $this->service()->check($this->body($this->longText()), '', null, ['language' => 'en']);
        $this->assertSame('The subject is missing.', $result->byRule('SUBJECT_MISSING')[0]->message);
        $this->assertSame('Critical', $result->trafficLabel);
    }

    public function testAllRulesHaveMessagesInBothLanguages(): void
    {
        $loaded = NewsletterSpamCheckService::loadConfig(self::configDir());
        foreach (array_keys($loaded['config']['rules']) as $ruleId) {
            foreach (['de', 'en'] as $lang) {
                $this->assertArrayHasKey($ruleId, $loaded['messages'][$lang], $lang . ': ' . $ruleId);
                $this->assertNotSame('', $loaded['messages'][$lang][$ruleId]['tip']);
            }
            $this->assertContains($loaded['config']['rules'][$ruleId]['level'], SpamCheckFinding::LEVELS, $ruleId);
        }
    }

    public function testScoreCapsRepeatedFindings(): void
    {
        $imgs = str_repeat('<img src="https://shop.example.test/img/a.jpg" width="100" height="100">', 15);
        $result = $this->service()->check($this->body($this->longText() . $imgs), self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        // 15 Bilder ohne alt: hoechstens 10 Meldungen, Abzug hoechstens 3 x 6 Punkte
        $this->assertCount(10, $result->byRule('IMAGE_ALT_MISSING'));
        $this->assertSame(5, $result->stats['suppressed']['IMAGE_ALT_MISSING']);
        $this->assertSame(82, $result->score);
        $this->assertSame(SpamCheckResult::TRAFFIC_YELLOW, $result->traffic);
    }

    // ------------------------------------------------------------------ SpamAssassin

    public function testSpamAssassinIsOffByDefault(): void
    {
        $client = new class extends SpamAssassinClient {
            public int $calls = 0;

            public function check(string $rawMessage): array
            {
                $this->calls++;
                return ['score' => 0.0, 'threshold' => 5.0, 'isSpam' => false, 'rules' => []];
            }
        };
        $result = $this->service([], null, null, null, $client)->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $this->assertSame(0, $client->calls);
        $this->assertNull($result->spamAssassin);
    }

    public function testSpamAssassinResultIsMapped(): void
    {
        $client = new class extends SpamAssassinClient {
            public string $message = '';

            public function check(string $rawMessage): array
            {
                $this->message = $rawMessage;
                return ['score' => 6.2, 'threshold' => 5.0, 'isSpam' => true, 'rules' => [
                    ['name' => 'HTML_IMAGE_ONLY_08', 'score' => 1.8, 'description' => 'HTML: images with 400-800 bytes of words'],
                    ['name' => 'MIME_HTML_ONLY', 'score' => 0.1, 'description' => 'BODY: Message only has text/html MIME parts'],
                ]];
            }
        };
        $result = $this->service(['rules' => ['SPAMASSASSIN' => ['enabled' => true]]], null, null, null, $client)
            ->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_PREHEADER, self::goodOptions());
        $finding = $result->byRule('SPAMASSASSIN')[0];
        $this->assertSame(SpamCheckFinding::LEVEL_ERROR, $finding->level);
        $this->assertStringContainsString('HTML_IMAGE_ONLY_08 (1.8)', $finding->message);
        $this->assertTrue($result->spamAssassin['available']);
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $client->message);
        $this->assertStringContainsString('multipart/alternative', $client->message);
    }

    public function testSpamAssassinUnavailableIsInfo(): void
    {
        $client = new SpamAssassinClient('127.0.0.1', 1, 1);
        $result = $this->service([], null, null, null, $client)->check($this->body($this->longText()), self::GOOD_SUBJECT, self::GOOD_PREHEADER, ['spamAssassin' => true] + self::goodOptions());
        $this->assertSame(SpamCheckFinding::LEVEL_INFO, $result->byRule('SPAMASSASSIN_UNAVAILABLE')[0]->level);
        $this->assertFalse($result->spamAssassin['available']);
    }

    public function testParseSpamdReport(): void
    {
        $response = "SPAMD/1.1 0 EX_OK\r\nContent-length: 400\r\nSpam: False ; 1.3 / 5.0\r\n\r\n"
            . "Spam detection software, running on the system \"x\", has NOT identified\n"
            . " Content analysis details:   (1.3 points, 5.0 required)\n\n"
            . " pts rule name              description\n"
            . "---- ---------------------- --------------------------------------------------\n"
            . " 0.1 MIME_HTML_ONLY         BODY: Message only has text/html MIME parts\n"
            . " 1.2 HTML_IMAGE_RATIO_02    BODY: HTML has a low ratio of text to image\n"
            . "                            area\n"
            . "-0.0 NO_RELAYS              Informational: message was not relayed via SMTP\n";
        $parsed = SpamAssassinClient::parseResponse($response);
        $this->assertSame(1.3, $parsed['score']);
        $this->assertSame(5.0, $parsed['threshold']);
        $this->assertFalse($parsed['isSpam']);
        $this->assertSame(['MIME_HTML_ONLY', 'HTML_IMAGE_RATIO_02', 'NO_RELAYS'], array_column($parsed['rules'], 'name'));
        $this->assertSame('BODY: HTML has a low ratio of text to image area', $parsed['rules'][1]['description']);
    }

}
