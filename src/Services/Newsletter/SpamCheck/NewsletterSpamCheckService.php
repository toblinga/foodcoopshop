<?php
declare(strict_types=1);

namespace App\Services\Newsletter\SpamCheck;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Throwable;

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
 * Spam- und Qualitaetscheck fuer Newsletter. Arbeitet nur lokal auf dem HTML,
 * dem Betreff und dem Preheader. Optional: DNS-Abfrage der Absenderdomain,
 * HEAD-Anfragen an Linkziele und ein lokales SpamAssassin (spamd).
 *
 * Regeln, Schwellwerte und Wortlisten: config/newsletter_spamcheck.php
 * Texte (de/en): config/newsletter_spamcheck_messages.php
 *
 * Optionen von check():
 *  - language        'de' (Standard) | 'en'
 *  - textPart        Textteil der Mail; Schluessel fehlt = nicht pruefen, null/'' = fehlt
 *  - fromEmail       Absenderadresse; Schluessel fehlt = nicht pruefen
 *  - fromName        Absendername (nur fuer SpamAssassin)
 *  - replyTo         Antwortadresse
 *  - headers         geplante Versand-Header ['List-Unsubscribe' => '...']; fehlt = Info
 *  - isTestInstance  true: DNS-Meldungen nur als Info
 *  - checkDns        false: keine DNS-Abfragen (Standard true)
 *  - checkLinks      true/false: HEAD-Pruefung der Links (Standard laut Konfiguration)
 *  - spamAssassin    true/false: SpamAssassin (Standard laut Konfiguration)
 */
class NewsletterSpamCheckService
{

    public const MAX_FINDINGS_PER_RULE = 10;

    private const CATEGORY_PREFIXES = [
        'SUBJECT_' => 'subject',
        'PREHEADER_' => 'subject',
        'BODY_' => 'content',
        'IMAGE' => 'content',
        'TEXT_IMAGE' => 'content',
        'DUMMY_' => 'content',
        'HTML_SIZE' => 'content',
        'LINK_' => 'links',
        'UNSUBSCRIBE_' => 'legal',
        'IMPRINT_' => 'legal',
        'LIST_UNSUBSCRIBE' => 'legal',
        'HEADERS_' => 'legal',
        'SPAMASSASSIN' => 'spamassassin',
    ];

    private const NAMED_COLORS = [
        'white' => [255, 255, 255], 'black' => [0, 0, 0], 'red' => [255, 0, 0], 'green' => [0, 128, 0],
        'blue' => [0, 0, 255], 'yellow' => [255, 255, 0], 'gray' => [128, 128, 128], 'grey' => [128, 128, 128],
        'silver' => [192, 192, 192], 'lightgray' => [211, 211, 211], 'lightgrey' => [211, 211, 211],
        'whitesmoke' => [245, 245, 245], 'snow' => [255, 250, 250], 'ivory' => [255, 255, 240],
        'beige' => [245, 245, 220], 'orange' => [255, 165, 0], 'navy' => [0, 0, 128], 'maroon' => [128, 0, 0],
        'purple' => [128, 0, 128], 'teal' => [0, 128, 128], 'olive' => [128, 128, 0], 'lime' => [0, 255, 0],
        'aqua' => [0, 255, 255], 'cyan' => [0, 255, 255], 'fuchsia' => [255, 0, 255], 'magenta' => [255, 0, 255],
        'darkgreen' => [0, 100, 0], 'darkgray' => [169, 169, 169], 'darkgrey' => [169, 169, 169],
        'gainsboro' => [220, 220, 220], 'linen' => [250, 240, 230], 'floralwhite' => [255, 250, 240],
    ];

    private const FONT_SIZE_ATTR = [1 => 10.0, 2 => 13.0, 3 => 16.0, 4 => 18.0, 5 => 24.0, 6 => 32.0, 7 => 48.0];

    private const HEADING_SIZES = ['h1' => 32.0, 'h2' => 24.0, 'h3' => 18.72, 'h4' => 16.0, 'h5' => 13.28, 'h6' => 10.72];

    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, array<string, array{msg: string, tip: string}>> */
    private array $messages;

    private string $language = 'de';

    /** @var list<SpamCheckFinding> */
    private array $findings = [];

    /** @var array<string, int> */
    private array $suppressed = [];

    /** @var array<string, true> */
    private array $reportedKeys = [];

    /**
     * @param array<string, mixed>|null $config null = config/newsletter_spamcheck.php
     * @param array<string, mixed>|null $messages null = config/newsletter_spamcheck_messages.php
     */
    public function __construct(
        ?array $config = null,
        ?array $messages = null,
        private readonly ?DnsResolverInterface $dns = new SystemDnsResolver(),
        private readonly ?ImageInfoProviderInterface $images = null,
        private readonly ?LinkProbeInterface $linkProbe = null,
        private readonly ?SpamAssassinClient $spamAssassinClient = null,
    ) {
        if ($config === null || $messages === null) {
            $loaded = self::loadConfig();
            $config ??= $loaded['config'];
            $messages ??= $loaded['messages'];
        }
        $this->config = $config;
        /** @var array<string, array<string, array{msg: string, tip: string}>> $messages */
        $this->messages = $messages;
    }

    /**
     * Laedt Regeln und Texte. Eine Datei newsletter_spamcheck.custom.php im selben
     * Ordner wird rekursiv darueber gelegt (Schluessel 'messages' fuer Texte).
     *
     * @return array{config: array<string, mixed>, messages: array<string, mixed>}
     */
    public static function loadConfig(?string $dir = null): array
    {
        $dir ??= self::defaultConfigDir();
        $config = require $dir . DIRECTORY_SEPARATOR . 'newsletter_spamcheck.php';
        $messages = require $dir . DIRECTORY_SEPARATOR . 'newsletter_spamcheck_messages.php';
        $customFile = $dir . DIRECTORY_SEPARATOR . 'newsletter_spamcheck.custom.php';
        if (is_file($customFile)) {
            $custom = require $customFile;
            if (is_array($custom)) {
                if (isset($custom['messages']) && is_array($custom['messages'])) {
                    $messages = array_replace_recursive($messages, $custom['messages']);
                    unset($custom['messages']);
                }
                // Listen (Wortlisten, Domains) werden ersetzt, nicht gemischt
                foreach ($custom as $key => $value) {
                    if (is_array($value) && isset($config[$key]) && is_array($config[$key]) && !array_is_list($value)) {
                        $config[$key] = array_replace_recursive($config[$key], $value);
                    } else {
                        $config[$key] = $value;
                    }
                }
            }
        }
        return ['config' => $config, 'messages' => $messages];
    }

    private static function defaultConfigDir(): string
    {
        if (defined('CONFIG')) {
            return rtrim((string)constant('CONFIG'), DIRECTORY_SEPARATOR);
        }
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'config';
    }

    /**
     * @param array<string, mixed> $options siehe Klassenkommentar
     */
    public function check(string $html, string $subject, ?string $preheader = null, array $options = []): SpamCheckResult
    {
        $this->findings = [];
        $this->suppressed = [];
        $this->reportedKeys = [];
        $this->language = in_array($options['language'] ?? 'de', ['de', 'en'], true) ? (string)($options['language'] ?? 'de') : 'de';

        $doc = HTMLDocument::createFromString($html !== '' ? $html : '<p></p>', LIBXML_NOERROR, 'UTF-8');
        $body = $doc->body;

        $segments = [];
        if ($body !== null) {
            $this->collectSegments($body, [
                'hidden' => false,
                'hiddenReason' => '',
                'color' => [0, 0, 0],
                'bg' => [255, 255, 255],
                'fontPx' => 16.0,
                'preheader' => false,
            ], $segments);
        }

        // Preheader aus dem HTML, falls nicht uebergeben
        $preheaderFromHtml = trim(implode(' ', array_map(fn($s) => $s['text'], array_filter($segments, fn($s) => $s['preheader']))));
        $preheaderText = trim((string)$preheader);
        if ($preheaderText === '' && $preheaderFromHtml !== '') {
            $preheaderText = $this->normalizeSpace($preheaderFromHtml);
        }
        // versteckter Text, der dem Preheader entspricht, gilt als Preheader
        if ($preheaderText !== '') {
            foreach ($segments as $i => $segment) {
                if ($segment['hidden'] && !$segment['preheader'] && str_contains($preheaderText, $this->normalizeSpace($segment['text']))) {
                    $segments[$i]['preheader'] = true;
                }
            }
        }

        $visibleSegments = array_values(array_filter($segments, fn($s) => !$s['hidden'] && !$s['preheader']));
        $visibleText = $this->normalizeSpace(implode(' ', array_map(fn($s) => $s['text'], $visibleSegments)));
        $visibleChars = mb_strlen($visibleText);

        $this->checkSubject($subject);
        $this->checkPreheader($preheaderText, $subject);

        $imageStats = $body !== null ? $this->checkImages($doc, $visibleChars) : ['count' => 0, 'area' => 0, 'bytes' => 0];
        $this->checkBodyText($visibleText, $visibleSegments, $imageStats);
        $this->checkStyles($visibleSegments);
        $this->checkPreheaderHiding(array_values(array_filter($segments, fn($s) => $s['preheader'])));
        $this->checkHtmlSize($html);
        $links = $body !== null ? $this->checkLinks($doc) : [];
        $this->checkPlaceholders($visibleSegments, $doc);
        $this->checkMandatory($doc, $links, $visibleText, $options);
        if ($body !== null) {
            $this->checkActiveContent($doc);
        }
        $this->checkTextPart($options, $visibleChars);
        $this->checkSender($options);
        $this->checkLinkTargets($links, $options);
        $spamAssassin = $this->checkSpamAssassin($html, $subject, $options);

        return $this->buildResult([
            'textChars' => $visibleChars,
            'images' => $imageStats['count'],
            'imageBytes' => $imageStats['bytes'],
            'textShare' => $imageStats['textShare'] ?? null,
            'links' => count(array_filter($links, fn($l) => $l['web'])),
            'htmlBytes' => strlen($html),
        ], $spamAssassin);
    }

    // ------------------------------------------------------------------ Betreff / Preheader

    private function checkSubject(string $subject): void
    {
        $subject = $this->normalizeSpace($subject);
        if ($subject === '') {
            $this->add('SUBJECT_MISSING', [], null, 'subject');
            return;
        }
        $rule = $this->rule('SUBJECT_LENGTH');
        $length = mb_strlen($subject);
        if ($length < $rule['min'] || $length > $rule['max']) {
            $level = ($length < $rule['hardMin'] || $length > $rule['hardMax']) ? SpamCheckFinding::LEVEL_WARNING : null;
            $this->add('SUBJECT_LENGTH', ['length' => $length, 'min' => $rule['min'], 'max' => $rule['max']], null, 'subject', $level);
        }

        $rule = $this->rule('SUBJECT_CAPS');
        $letters = preg_match_all('/\p{L}/u', $subject);
        $upper = preg_match_all('/\p{Lu}/u', $subject);
        $capsWords = preg_match_all('/(?<![\p{L}\p{N}])\p{Lu}{4,}(?![\p{L}\p{N}])/u', $subject);
        if ($letters >= $rule['minLetters'] && $upper / max(1, $letters) > $rule['maxUpperShare']) {
            $this->add('SUBJECT_CAPS', ['detail' => round(100 * $upper / $letters) . ' %'], null, 'subject');
        } elseif ($capsWords > $rule['maxCapsWords']) {
            $this->add('SUBJECT_CAPS', ['detail' => $capsWords . ' Wörter'], null, 'subject');
        }

        $rule = $this->rule('SUBJECT_PUNCTUATION');
        if (preg_match($rule['pattern'], $subject, $m)) {
            $this->add('SUBJECT_PUNCTUATION', ['detail' => $m[0]], null, 'subject');
        } elseif (substr_count($subject, '!') > $rule['maxExclamation']) {
            $this->add('SUBJECT_PUNCTUATION', ['detail' => substr_count($subject, '!') . '× !'], null, 'subject', SpamCheckFinding::LEVEL_HINT);
        }

        foreach ($this->matchSpamWords($subject) as $word => $weight) {
            $this->add('SUBJECT_SPAM_WORDS', ['word' => $word], null, 'subject', null, (float)$weight, 'word:' . $word);
        }

        $rule = $this->rule('SUBJECT_EMOJI');
        $emojis = preg_match_all('/\p{Extended_Pictographic}/u', $subject);
        if ($emojis > $rule['max']) {
            $this->add('SUBJECT_EMOJI', ['count' => $emojis], null, 'subject');
        }

        foreach ($this->unknownPlaceholders($subject) as $placeholder) {
            $this->add('SUBJECT_PLACEHOLDER', ['placeholder' => $placeholder], null, 'subject');
        }

        $rule = $this->rule('SUBJECT_CURRENCY');
        if (preg_match($rule['pattern'], $subject)) {
            $this->add('SUBJECT_CURRENCY', [], null, 'subject');
        }
    }

    private function checkPreheader(string $preheader, string $subject): void
    {
        if ($preheader === '') {
            $this->add('PREHEADER_MISSING', [], null, 'preheader');
            return;
        }
        $rule = $this->rule('PREHEADER_LENGTH');
        $length = mb_strlen($preheader);
        if ($length < $rule['min'] || $length > $rule['max']) {
            $this->add('PREHEADER_LENGTH', ['length' => $length, 'min' => $rule['min'], 'max' => $rule['max']], null, 'preheader');
        }
        if (mb_strtolower($this->normalizeSpace($preheader)) === mb_strtolower($this->normalizeSpace($subject))) {
            $this->add('PREHEADER_SAME_AS_SUBJECT', [], null, 'preheader');
        }
        foreach ($this->unknownPlaceholders($preheader) as $placeholder) {
            $this->add('PLACEHOLDER_UNREPLACED', ['placeholder' => $placeholder, 'known' => $this->knownList()], null, 'preheader');
        }
    }

    // ------------------------------------------------------------------ Inhalt

    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $segments
     */
    private function collectSegments(Node $node, array $state, array &$segments): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $text = $child->textContent ?? '';
                if (trim(str_replace("\u{00A0}", ' ', $text)) === '' || preg_match('/^[\s\x{200B}-\x{200D}\x{FEFF}\x{00A0}\x{034F}]*$/u', $text)) {
                    continue;
                }
                $parent = $child->parentElement;
                $segments[] = $state + ['text' => $text, 'element' => $parent];
                continue;
            }
            if (!$child instanceof Element) {
                continue;
            }
            $tag = strtolower($child->localName);
            if (in_array($tag, ['script', 'style', 'head', 'title', 'template', 'noscript', 'svg', 'math'], true)) {
                continue;
            }
            $childState = $this->elementState($child, $state);
            $this->collectSegments($child, $childState, $segments);
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function elementState(Element $el, array $state): array
    {
        $tag = strtolower($el->localName);
        $style = $this->parseStyle((string)$el->getAttribute('style'));

        if (!$state['preheader']) {
            $attr = (string)$this->config['preheaderAttribute'];
            if ($attr !== '' && $el->hasAttribute($attr)) {
                $state['preheader'] = true;
            }
            foreach ((array)$this->config['preheaderClasses'] as $class) {
                if ($el->classList->contains((string)$class)) {
                    $state['preheader'] = true;
                }
            }
        }

        if (!$state['hidden']) {
            $reason = '';
            if (($style['display'] ?? '') === 'none') {
                $reason = 'display:none';
            } elseif (in_array($style['visibility'] ?? '', ['hidden', 'collapse'], true)) {
                $reason = 'visibility:hidden';
            } elseif (isset($style['opacity']) && (float)$style['opacity'] === 0.0 && is_numeric($style['opacity'])) {
                $reason = 'opacity:0';
            } elseif ($el->hasAttribute('hidden')) {
                $reason = 'hidden';
            } elseif (isset($style['max-height']) && (float)$style['max-height'] === 0.0 && ($style['overflow'] ?? '') === 'hidden') {
                $reason = 'max-height:0';
            }
            if ($reason !== '') {
                $state['hidden'] = true;
                $state['hiddenReason'] = $reason;
            }
        }

        // Hintergrund
        $bg = null;
        if (isset($style['background-color'])) {
            $bg = $this->parseColor($style['background-color']);
        }
        if ($bg === null && isset($style['background'])) {
            foreach (preg_split('/\s+(?![^(]*\))/', $style['background']) ?: [] as $token) {
                $bg = $this->parseColor($token);
                if ($bg !== null) {
                    break;
                }
            }
        }
        if ($bg === null && $el->hasAttribute('bgcolor')) {
            $bg = $this->parseColor((string)$el->getAttribute('bgcolor'));
        }
        if ($bg !== null) {
            $state['bg'] = $bg;
        }

        // Schriftfarbe
        $color = null;
        if (isset($style['color'])) {
            $color = $this->parseColor($style['color']);
        } elseif ($tag === 'font' && $el->hasAttribute('color')) {
            $color = $this->parseColor((string)$el->getAttribute('color'));
        } elseif ($tag === 'a') {
            $color = [0, 0, 238];
        }
        if ($color !== null) {
            $state['color'] = $color;
        }

        // Schriftgroesse
        $parentPx = (float)$state['fontPx'];
        if (isset($style['font-size'])) {
            $px = $this->parseFontSize($style['font-size'], $parentPx);
            if ($px !== null) {
                $state['fontPx'] = $px;
            }
        } elseif ($tag === 'font' && $el->hasAttribute('size')) {
            $size = (int)$el->getAttribute('size');
            $state['fontPx'] = self::FONT_SIZE_ATTR[max(1, min(7, $size))];
        } elseif (isset(self::HEADING_SIZES[$tag])) {
            $state['fontPx'] = self::HEADING_SIZES[$tag];
        }

        return $state;
    }

    /**
     * @return array{count: int, area: int, bytes: int, textShare?: float|null}
     */
    private function checkImages(HTMLDocument $doc, int $visibleChars): array
    {
        $count = 0;
        $area = 0;
        $totalBytes = 0;
        $fileRule = $this->rule('IMAGE_FILESIZE');
        $widthRule = $this->rule('IMAGE_DISPLAY_WIDTH');
        $intrinsicRule = $this->rule('IMAGE_INTRINSIC_WIDTH');
        $ratioRule = $this->rule('TEXT_IMAGE_RATIO');
        $seenSrc = [];

        foreach ($doc->querySelectorAll('img') as $img) {
            $src = trim((string)$img->getAttribute('src'));
            $style = $this->parseStyle((string)$img->getAttribute('style'));
            $displayWidth = $this->pxValue($style['width'] ?? (string)$img->getAttribute('width'));
            $displayHeight = $this->pxValue($style['height'] ?? (string)$img->getAttribute('height'));
            // Zaehlpixel ignorieren
            if ($displayWidth !== null && $displayHeight !== null && $displayWidth <= 2 && $displayHeight <= 2) {
                continue;
            }
            if ($this->isHiddenElement($img)) {
                continue;
            }
            $count++;
            $shortSrc = $this->shortSrc($src);

            if (!$img->hasAttribute('alt')) {
                $this->add('IMAGE_ALT_MISSING', ['src' => $shortSrc], $img);
            } elseif (trim((string)$img->getAttribute('alt')) === '') {
                if ($img->closest('a[href]') !== null) {
                    $this->add('IMAGE_ALT_EMPTY_LINKED', ['src' => $shortSrc], $img);
                } elseif ($displayWidth === null || $displayWidth >= (int)($this->rule('IMAGE_ALT_EMPTY')['minWidth'] ?? 100)) {
                    // alt="" ist fuer Zierbilder richtig, bei Inhaltsbildern fehlt die Beschreibung
                    $this->add('IMAGE_ALT_EMPTY', ['src' => $shortSrc], $img);
                }
            }

            $info = null;
            if (str_starts_with(strtolower($src), 'data:')) {
                $data = $this->decodeDataUri($src);
                if ($data !== null) {
                    $dims = @getimagesizefromstring($data);
                    $info = ['bytes' => strlen($data), 'width' => $dims !== false ? (int)$dims[0] : null, 'height' => $dims !== false ? (int)$dims[1] : null];
                    $this->add('IMAGE_DATA_URI', ['size' => $this->formatBytes(strlen($data))], $img);
                }
            } elseif ($src !== '' && $this->images !== null) {
                $info = $this->images->info($src);
            }

            if ($info === null) {
                if ($src !== '' && !isset($seenSrc[$src])) {
                    $this->add('IMAGE_SIZE_UNKNOWN', ['src' => $shortSrc], $img);
                }
            } else {
                if (!isset($seenSrc[$src])) {
                    $totalBytes += $info['bytes'];
                    if ($info['bytes'] > $fileRule['errorBytes']) {
                        $this->add('IMAGE_FILESIZE', ['size' => $this->formatBytes($info['bytes']), 'src' => $shortSrc], $img, 'body', SpamCheckFinding::LEVEL_ERROR);
                    } elseif ($info['bytes'] > $fileRule['warnBytes']) {
                        $this->add('IMAGE_FILESIZE', ['size' => $this->formatBytes($info['bytes']), 'src' => $shortSrc], $img);
                    }
                    if ($info['width'] !== null && $info['width'] > $intrinsicRule['max']) {
                        $this->add('IMAGE_INTRINSIC_WIDTH', ['width' => $info['width'], 'max' => $intrinsicRule['max'], 'src' => $shortSrc], $img);
                    }
                }
            }
            $seenSrc[$src] = true;

            if ($displayWidth !== null && $displayWidth > $widthRule['max']) {
                $this->add('IMAGE_DISPLAY_WIDTH', ['width' => (int)$displayWidth, 'max' => $widthRule['max'], 'src' => $shortSrc], $img);
            }

            // Flaeche fuer das Verhaeltnis Text zu Bild (Anzeige max. 600 px breit)
            $w = $displayWidth ?? ($info['width'] ?? null);
            $h = $displayHeight;
            if ($w !== null && $h === null && $info !== null && $info['width'] && $info['height']) {
                $h = $w * $info['height'] / $info['width'];
            }
            if ($w !== null && $h !== null) {
                $scale = $w > 600 ? 600 / $w : 1;
                $area += (int)($w * $scale * $h * $scale);
            } else {
                $area += (int)$ratioRule['defaultImageArea'];
            }
        }

        $totalRule = $this->rule('IMAGES_TOTAL_SIZE');
        if ($totalBytes > $totalRule['warnBytes']) {
            $this->add('IMAGES_TOTAL_SIZE', ['size' => $this->formatBytes($totalBytes)]);
        }

        $textShare = null;
        if ($count > 0) {
            $textArea = $visibleChars * (int)$ratioRule['pxPerChar'];
            $textShare = $textArea / max(1, $textArea + $area);
        }
        return ['count' => $count, 'area' => $area, 'bytes' => $totalBytes, 'textShare' => $textShare === null ? null : round($textShare, 2)];
    }

    /**
     * @param list<array<string, mixed>> $segments
     * @param array<string, mixed> $imageStats
     */
    private function checkBodyText(string $text, array $segments, array $imageStats): void
    {
        $chars = mb_strlen($text);
        $images = (int)$imageStats['count'];
        if ($chars === 0 && $images === 0) {
            $this->add('BODY_EMPTY');
            return;
        }
        $imageOnly = $this->rule('IMAGE_ONLY');
        $ratio = $this->rule('TEXT_IMAGE_RATIO');
        if ($images > 0 && $chars < $imageOnly['minText']) {
            $this->add('IMAGE_ONLY', ['chars' => $chars, 'images' => $images]);
        } elseif ($images > 0 && $chars < $ratio['minTextChars'] && ($imageStats['textShare'] ?? 1) < $ratio['minTextShare']) {
            $this->add('TEXT_IMAGE_RATIO', ['share' => (int)round(100 * (float)$imageStats['textShare']), 'chars' => $chars]);
        }

        $words = $this->matchSpamWords($text);
        if ($words !== []) {
            $rule = $this->rule('BODY_SPAM_WORDS');
            $level = count($words) >= $rule['maxBeforeWarning'] ? SpamCheckFinding::LEVEL_WARNING : null;
            $weight = min(20.0, array_sum($words) / 2);
            $this->add('BODY_SPAM_WORDS', ['words' => implode(', ', array_keys($words))], null, 'body', $level, $weight);
        }

        $capsRule = $this->rule('BODY_CAPS');
        $exclRule = $this->rule('BODY_EXCLAMATION');
        $dummyRule = $this->rule('DUMMY_TEXT');
        $run = max(2, (int)$capsRule['minRun']);
        $capsPattern = '/(?<![\p{L}\p{N}])(?:\p{Lu}{2,}[\s,.;:!?\-–]+){' . ($run - 1) . ',}\p{Lu}{2,}(?![\p{L}\p{N}])/u';
        // je Element pruefen, damit die Meldung auf den Baustein zeigt
        foreach ($this->groupByElement($segments) as $group) {
            $elementText = $this->normalizeSpace($group['text']);
            if (preg_match($capsPattern, $elementText, $m)) {
                $this->add('BODY_CAPS', ['snippet' => $this->snippet($m[0])], $group['element']);
            }
            if (preg_match($exclRule['pattern'], $elementText, $m, PREG_OFFSET_CAPTURE)) {
                $this->add('BODY_EXCLAMATION', ['snippet' => $this->snippetAround($elementText, (int)$m[0][1])], $group['element']);
            }
            if (preg_match($dummyRule['pattern'] . 'i', $elementText, $m)) {
                $this->add('DUMMY_TEXT', ['snippet' => $m[0]], $group['element']);
            }
        }
    }

    /**
     * Versteckter Text, schwacher Kontrast und sehr kleine Schrift (nur Inline-Styles).
     *
     * @param list<array<string, mixed>> $segments
     */
    private function checkStyles(array $segments): void
    {
        $hidden = $this->rule('HIDDEN_TEXT');
        $low = $this->rule('LOW_CONTRAST');
        $tiny = $this->rule('TINY_FONT');
        foreach ($this->groupByElement($segments) as $group) {
            $first = $group['first'];
            $snippet = $this->snippet($this->normalizeSpace($group['text']));
            $contrast = $this->contrast($first['color'], $first['bg']);
            $px = (float)$first['fontPx'];
            if ($contrast < $hidden['minContrast']) {
                $this->add('HIDDEN_TEXT', ['contrast' => $this->formatNumber($contrast), 'snippet' => $snippet], $group['element']);
                continue;
            }
            if ($px <= $tiny['hiddenPx']) {
                $this->add('TINY_FONT', ['size' => $this->formatNumber($px) . ' px', 'snippet' => $snippet], $group['element'], 'body', SpamCheckFinding::LEVEL_ERROR);
                continue;
            }
            if ($contrast < $low['minContrast']) {
                $this->add('LOW_CONTRAST', ['contrast' => $this->formatNumber($contrast), 'snippet' => $snippet], $group['element']);
            }
            if ($px < $tiny['minPx']) {
                $this->add('TINY_FONT', ['size' => $this->formatNumber($px) . ' px', 'snippet' => $snippet], $group['element']);
            }
        }
    }

    /**
     * Preheader, der ueber gleiche Farbe oder Mini-Schrift versteckt wird. Im lokalen Test mit
     * SpamAssassin 4 loeste das teils FONT_INVIS_MSGID (2,0 Punkte) aus; display:none genuegt.
     *
     * @param list<array<string, mixed>> $segments
     */
    private function checkPreheaderHiding(array $segments): void
    {
        $hidden = $this->rule('HIDDEN_TEXT');
        $tiny = $this->rule('TINY_FONT');
        foreach ($this->groupByElement($segments) as $group) {
            $first = $group['first'];
            $method = null;
            if ($this->contrast($first['color'], $first['bg']) < $hidden['minContrast']) {
                $method = 'color';
            } elseif ((float)$first['fontPx'] <= $tiny['hiddenPx']) {
                $method = 'font-size';
            }
            if ($method !== null) {
                $this->add('PREHEADER_INVISIBLE_FONT', ['method' => $method], $group['element'], 'preheader');
                return;
            }
        }
    }

    private function checkHtmlSize(string $html): void
    {
        $rule = $this->rule('HTML_SIZE');
        $bytes = strlen($html);
        if ($bytes > $rule['errorBytes']) {
            $this->add('HTML_SIZE', ['size' => $this->formatBytes($bytes)], null, 'body', SpamCheckFinding::LEVEL_ERROR);
        } elseif ($bytes > $rule['warnBytes']) {
            $this->add('HTML_SIZE', ['size' => $this->formatBytes($bytes)]);
        }
    }

    // ------------------------------------------------------------------ Links

    /**
     * @return list<array{href: string, text: string, host: string, web: bool, placeholder: bool, element: Element}>
     */
    private function checkLinks(HTMLDocument $doc): array
    {
        $links = [];
        $shorteners = array_map('strtolower', (array)$this->config['shortenerDomains']);
        foreach ($doc->querySelectorAll('a') as $a) {
            if (!$a->hasAttribute('href')) {
                continue;
            }
            $href = trim((string)$a->getAttribute('href'));
            $text = $this->normalizeSpace((string)$a->textContent);
            if ($text === '') {
                $img = $a->querySelector('img[alt]');
                $text = $img !== null ? trim((string)$img->getAttribute('alt')) : '';
            }
            $lower = strtolower($href);
            $link = ['href' => $href, 'text' => $text, 'host' => '', 'web' => false, 'placeholder' => false, 'element' => $a];

            if (str_starts_with($lower, 'javascript:')) {
                $this->add('HTML_SCRIPT', ['detail' => 'javascript:-Link'], $a);
                $links[] = $link;
                continue;
            }
            if (preg_match('/^(mailto|tel|sms):/i', $href)) {
                $links[] = $link;
                continue;
            }
            if (preg_match((string)$this->config['placeholderPattern'], $href)) {
                $link['placeholder'] = true;
                if ($this->unknownPlaceholders($href) !== []) {
                    $this->add('LINK_PLACEHOLDER', ['href' => $this->shortUrl($href)], $a);
                }
                $links[] = $link;
                continue;
            }
            if ($href === '' || $href === '#' || !preg_match('#^https?://#i', $href)) {
                $this->add('LINK_EMPTY', ['text' => $text !== '' ? $this->snippet($text, 40) : $href], $a);
                $links[] = $link;
                continue;
            }

            $host = strtolower((string)parse_url($href, PHP_URL_HOST));
            $host = trim($host, '[]');
            $link['host'] = $host;
            $link['web'] = true;

            if (str_starts_with($lower, 'http://')) {
                $this->add('LINK_HTTP', ['href' => $this->shortUrl($href)], $a, 'body', null, null, 'http:' . $href);
            }
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $this->add('LINK_IP', ['href' => $this->shortUrl($href)], $a);
            }
            foreach ($shorteners as $shortener) {
                if ($host === $shortener || str_ends_with($host, '.' . $shortener)) {
                    $this->add('LINK_SHORTENER', ['host' => $host], $a, 'body', null, null, 'short:' . $href);
                    break;
                }
            }
            $textHost = $this->hostFromText($text);
            if ($textHost !== null && $host !== '' && $this->registrableDomain($textHost) !== $this->registrableDomain($host)) {
                $this->add('LINK_TEXT_MISMATCH', ['textHost' => $textHost, 'hrefHost' => $host], $a);
            }
            $links[] = $link;
        }

        $countRule = $this->rule('LINK_COUNT');
        $web = count(array_filter($links, fn($l) => $l['web']));
        if ($web > $countRule['maxWarning']) {
            $this->add('LINK_COUNT', ['count' => $web], null, 'body', SpamCheckFinding::LEVEL_WARNING);
        } elseif ($web > $countRule['max']) {
            $this->add('LINK_COUNT', ['count' => $web]);
        }
        return $links;
    }

    /**
     * Optional: HEAD-Pruefung und DNS-Blockliste.
     *
     * @param list<array<string, mixed>> $links
     * @param array<string, mixed> $options
     */
    private function checkLinkTargets(array $links, array $options): void
    {
        $broken = $this->rule('LINK_BROKEN');
        $enabled = array_key_exists('checkLinks', $options) ? (bool)$options['checkLinks'] : (bool)($broken['enabled'] ?? false);
        if ($enabled && $this->linkProbe !== null) {
            $checked = [];
            foreach ($links as $link) {
                if (!$link['web'] || isset($checked[$link['href']]) || filter_var($link['host'], FILTER_VALIDATE_IP) !== false || $link['host'] === 'localhost') {
                    continue;
                }
                if (count($checked) >= (int)$broken['maxLinks']) {
                    break;
                }
                $checked[$link['href']] = true;
                $status = $this->linkProbe->status($link['href'], (int)$broken['timeout']);
                if ($status === 0 || $status >= 400) {
                    $this->add('LINK_BROKEN', ['status' => $status === 0 ? 'keine Antwort' : (string)$status, 'href' => $this->shortUrl($link['href'])], $link['element'], 'body', null, null, 'broken:' . $link['href']);
                }
            }
        }

        $blocklist = $this->rule('LINK_BLOCKLIST');
        if (!empty($blocklist['enabled']) && $this->dns !== null && ($options['checkDns'] ?? true) !== false) {
            $domains = [];
            foreach ($links as $link) {
                if ($link['web'] && filter_var($link['host'], FILTER_VALIDATE_IP) === false) {
                    $domains[$this->registrableDomain($link['host'])] = $link['element'];
                }
            }
            foreach ($domains as $domain => $element) {
                foreach ((array)$blocklist['zones'] as $zone) {
                    if ($this->dns->hasA($domain . '.' . $zone)) {
                        $this->add('LINK_BLOCKLIST', ['host' => $domain, 'zone' => (string)$zone], $element);
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------ Platzhalter

    /**
     * @param list<array<string, mixed>> $segments
     */
    private function checkPlaceholders(array $segments, HTMLDocument $doc): void
    {
        foreach ($this->groupByElement($segments) as $group) {
            foreach ($this->unknownPlaceholders($group['text']) as $placeholder) {
                $this->add('PLACEHOLDER_UNREPLACED', ['placeholder' => $placeholder, 'known' => $this->knownList()], $group['element'], 'body', null, null, 'ph:' . $placeholder);
            }
        }
        foreach ($doc->querySelectorAll('img[alt], img[src]') as $img) {
            $value = (string)$img->getAttribute('alt') . ' ' . (string)$img->getAttribute('src');
            foreach ($this->unknownPlaceholders($value) as $placeholder) {
                $this->add('PLACEHOLDER_UNREPLACED', ['placeholder' => $placeholder, 'known' => $this->knownList()], $img, 'body', null, null, 'ph:' . $placeholder);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function unknownPlaceholders(string $text): array
    {
        if (!preg_match_all((string)$this->config['placeholderPattern'], $text, $m)) {
            return [];
        }
        $known = array_map(fn($p) => $this->normalizePlaceholder((string)$p), (array)$this->config['knownPlaceholders']);
        $unknown = [];
        foreach ($m[0] as $placeholder) {
            if (!in_array($this->normalizePlaceholder($placeholder), $known, true)) {
                $unknown[$placeholder] = true;
            }
        }
        return array_keys($unknown);
    }

    private function normalizePlaceholder(string $placeholder): string
    {
        $placeholder = rawurldecode($placeholder);
        // {{first_name|liebe Mitglieder}} -> {{first_name}}
        $placeholder = (string)preg_replace('/^\{\{\s*([^|{}]+?)\s*\|[^{}]*\}\}$/u', '{{$1}}', $placeholder);
        return mb_strtolower((string)preg_replace('/\s+/u', '', $placeholder));
    }

    private function knownList(): string
    {
        return implode(', ', array_slice((array)$this->config['knownPlaceholders'], 0, 6));
    }

    // ------------------------------------------------------------------ Pflichtangaben

    /**
     * @param list<array<string, mixed>> $links
     * @param array<string, mixed> $options
     */
    private function checkMandatory(HTMLDocument $doc, array $links, string $visibleText, array $options): void
    {
        $unsubscribe = false;
        $imprint = false;
        $unsubscribePatterns = array_map('mb_strtolower', (array)$this->config['unsubscribePatterns']);
        $imprintPatterns = array_map('mb_strtolower', (array)$this->config['imprintPatterns']);
        foreach ($links as $link) {
            $haystack = mb_strtolower(rawurldecode($link['href']) . ' ' . $link['text']);
            foreach ($unsubscribePatterns as $pattern) {
                if (str_contains($haystack, $pattern)) {
                    $unsubscribe = true;
                }
            }
            foreach ($imprintPatterns as $pattern) {
                if (str_contains($haystack, $pattern)) {
                    $imprint = true;
                }
            }
        }
        $lowerText = mb_strtolower($visibleText);
        if (!$imprint) {
            foreach ($imprintPatterns as $pattern) {
                if (preg_match('/(?<![\p{L}])' . preg_quote($pattern, '/') . '(?![\p{L}])/u', $lowerText)) {
                    $imprint = true;
                }
            }
        }
        if (!$imprint && preg_match((string)$this->config['addressPattern'], $visibleText)) {
            $imprint = true;
        }
        if (!$unsubscribe) {
            $this->add('UNSUBSCRIBE_LINK_MISSING');
        }
        if (!$imprint) {
            $this->add('IMPRINT_MISSING');
        }

        if (!isset($options['headers']) || !is_array($options['headers'])) {
            $this->add('HEADERS_UNKNOWN', [], null, 'headers');
            return;
        }
        $headers = array_change_key_case($options['headers'], CASE_LOWER);
        $listUnsubscribe = trim((string)($headers['list-unsubscribe'] ?? ''));
        if ($listUnsubscribe === '') {
            $this->add('LIST_UNSUBSCRIBE_MISSING', [], null, 'headers');
            return;
        }
        if (!preg_match('/<https:\/\/[^>]+>/i', $listUnsubscribe)) {
            $this->add('LIST_UNSUBSCRIBE_NO_HTTPS', [], null, 'headers');
        }
        $post = strtolower(trim((string)($headers['list-unsubscribe-post'] ?? '')));
        if ($post !== 'list-unsubscribe=one-click') {
            $this->add('LIST_UNSUBSCRIBE_POST_MISSING', [], null, 'headers');
        }
    }

    // ------------------------------------------------------------------ Technik

    private function checkActiveContent(HTMLDocument $doc): void
    {
        foreach ($doc->querySelectorAll('script') as $script) {
            $this->add('HTML_SCRIPT', ['detail' => '<script>'], $script, 'body', null, null, 'script');
        }
        foreach ($doc->querySelectorAll('*') as $el) {
            foreach ($el->getAttributeNames() as $name) {
                if (str_starts_with(strtolower($name), 'on')) {
                    $this->add('HTML_SCRIPT', ['detail' => $name], $el, 'body', null, null, 'on:' . $name);
                }
            }
        }
        foreach ($doc->querySelectorAll('form, input, select, textarea') as $el) {
            $this->add('HTML_FORM', ['detail' => '<' . strtolower($el->localName) . '>'], $el, 'body', null, null, 'form:' . $el->localName);
        }
        foreach ($doc->querySelectorAll('iframe, object, embed, applet, video, audio') as $el) {
            $this->add('HTML_EMBED', ['detail' => '<' . strtolower($el->localName) . '>'], $el, 'body', null, null, 'embed:' . $el->localName);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function checkTextPart(array $options, int $visibleChars): void
    {
        if (!array_key_exists('textPart', $options)) {
            return;
        }
        $text = $this->normalizeSpace((string)$options['textPart']);
        if ($text === '') {
            $this->add('TEXT_PART_MISSING', [], null, 'headers');
            return;
        }
        $rule = $this->rule('TEXT_PART_SHORT');
        if ($visibleChars > 0 && mb_strlen($text) < $visibleChars * $rule['minShare']) {
            $this->add('TEXT_PART_SHORT', [], null, 'headers');
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function checkSender(array $options): void
    {
        if (!array_key_exists('fromEmail', $options)) {
            return;
        }
        $from = strtolower(trim((string)$options['fromEmail']));
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $this->add('SENDER_INVALID', ['email' => $from], null, 'sender');
            return;
        }
        $fromDomain = substr($from, strrpos($from, '@') + 1);
        $freemail = array_map('strtolower', (array)$this->config['freemailDomains']);
        if (in_array($fromDomain, $freemail, true)) {
            $this->add('SENDER_FREEMAIL', ['domain' => $fromDomain], null, 'sender');
        }
        if (preg_match('/^(no-?reply|do-?not-?reply|donotreply|noreply)[^@]*@/', $from)) {
            $this->add('SENDER_NOREPLY', [], null, 'sender');
        }

        $replyTo = strtolower(trim((string)($options['replyTo'] ?? '')));
        if ($replyTo !== '') {
            if (filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
                $this->add('REPLY_TO_INVALID', ['email' => $replyTo], null, 'sender');
            } else {
                $replyDomain = substr($replyTo, strrpos($replyTo, '@') + 1);
                if (in_array($replyDomain, $freemail, true) && !in_array($fromDomain, $freemail, true)) {
                    $this->add('REPLY_TO_FREEMAIL', ['domain' => $replyDomain], null, 'sender');
                } elseif ($this->registrableDomain($replyDomain) !== $this->registrableDomain($fromDomain)) {
                    $this->add('REPLY_TO_OTHER_DOMAIN', ['domain' => $replyDomain, 'fromDomain' => $fromDomain], null, 'sender');
                }
            }
        }

        if ($this->dns === null || ($options['checkDns'] ?? true) === false || in_array($fromDomain, $freemail, true)) {
            return;
        }
        $isTest = !empty($options['isTestInstance'])
            || preg_match('/(^|\.)(test|local|localhost|invalid|example|lan|internal)$/', $fromDomain);
        $level = $isTest ? SpamCheckFinding::LEVEL_INFO : null;

        if ($this->rule('DNS_SPF')['enabled'] ?? true) {
            $txt = $this->dns->txt($fromDomain);
            if ($txt !== null) {
                $spf = array_values(array_filter($txt, fn($r) => stripos(trim($r), 'v=spf1') === 0));
                if (count($spf) === 0) {
                    $this->add('DNS_SPF', ['domain' => $fromDomain], null, 'dns', $level);
                } elseif (count($spf) > 1) {
                    $this->add('DNS_SPF_MULTIPLE', ['domain' => $fromDomain, 'count' => count($spf)], null, 'dns', $level);
                }
            }
        }
        if ($this->rule('DNS_DMARC')['enabled'] ?? true) {
            $found = null;
            foreach (array_unique([$fromDomain, $this->registrableDomain($fromDomain)]) as $domain) {
                $txt = $this->dns->txt('_dmarc.' . $domain);
                if ($txt === null) {
                    continue;
                }
                $found = $found ?? false;
                foreach ($txt as $record) {
                    if (stripos(trim($record), 'v=DMARC1') === 0) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if ($found === false) {
                $this->add('DNS_DMARC', ['domain' => $fromDomain], null, 'dns', $level);
            }
        }
        $dkim = $this->rule('DNS_DKIM');
        if ($dkim['enabled'] ?? true) {
            $found = false;
            foreach ((array)$dkim['selectors'] as $selector) {
                $name = $selector . '._domainkey.' . $fromDomain;
                $txt = $this->dns->txt($name);
                if ($txt !== null) {
                    foreach ($txt as $record) {
                        if (str_contains($record, 'p=')) {
                            $found = true;
                            break 2;
                        }
                    }
                }
                if ($this->dns->cname($name) !== null) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->add('DNS_DKIM', ['domain' => $fromDomain, 'selectors' => implode(', ', (array)$dkim['selectors'])], null, 'dns', $level);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function checkSpamAssassin(string $html, string $subject, array $options): ?array
    {
        $rule = $this->rule('SPAMASSASSIN');
        $enabled = array_key_exists('spamAssassin', $options) ? (bool)$options['spamAssassin'] : (bool)($rule['enabled'] ?? false);
        if (!$enabled) {
            return null;
        }
        $client = $this->spamAssassinClient ?? new SpamAssassinClient((string)$rule['host'], (int)$rule['port'], (int)$rule['timeout']);
        $headers = isset($options['headers']) && is_array($options['headers']) ? array_map('strval', $options['headers']) : [];
        $from = (string)($options['fromEmail'] ?? 'newsletter@example.invalid');
        $message = SpamAssassinClient::buildMessage($html, isset($options['textPart']) ? (string)$options['textPart'] : null, $subject, $from, (string)($options['fromName'] ?? ''), $headers);
        try {
            $result = $client->check($message);
        } catch (Throwable $e) {
            $this->add('SPAMASSASSIN_UNAVAILABLE', ['detail' => $e->getMessage()], null, 'spamassassin', SpamCheckFinding::LEVEL_INFO);
            return ['available' => false, 'error' => $e->getMessage()];
        }
        $threshold = (float)($rule['threshold'] ?? $result['threshold']);
        $level = SpamCheckFinding::LEVEL_INFO;
        if ($result['score'] >= $threshold) {
            $level = SpamCheckFinding::LEVEL_ERROR;
        } elseif ($result['score'] >= (float)$rule['warnAt']) {
            $level = SpamCheckFinding::LEVEL_WARNING;
        }
        $names = array_map(fn($r) => $r['name'] . ' (' . $r['score'] . ')', array_filter($result['rules'], fn($r) => abs($r['score']) >= 0.01));
        $this->add('SPAMASSASSIN', [
            'score' => $this->formatNumber($result['score']),
            'threshold' => $this->formatNumber($threshold),
            'rules' => $names !== [] ? implode(', ', $names) : '-',
        ], null, 'spamassassin', $level);
        return ['available' => true] + $result;
    }

    // ------------------------------------------------------------------ Ergebnis

    /**
     * @param array<string, mixed> $stats
     * @param array<string, mixed>|null $spamAssassin
     */
    private function buildResult(array $stats, ?array $spamAssassin): SpamCheckResult
    {
        // Abzug je Regel: erster Treffer voll, weitere halb, hoechstens 3x Gewicht
        $byRule = [];
        foreach ($this->findings as $finding) {
            $byRule[$finding->ruleId][] = $finding;
        }
        $deduction = 0.0;
        foreach ($byRule as $findings) {
            $sum = 0.0;
            $max = 0.0;
            foreach ($findings as $i => $finding) {
                $sum += $i === 0 ? $finding->weight : $finding->weight / 2;
                $max = max($max, $finding->weight);
            }
            $deduction += min($sum, 3 * $max);
        }
        $score = (int)max(0, min(100, round(100 - $deduction)));

        $order = array_flip(SpamCheckFinding::LEVELS);
        $findings = $this->findings;
        usort($findings, fn(SpamCheckFinding $a, SpamCheckFinding $b) => $order[$a->level] <=> $order[$b->level]);

        $hasError = false;
        $hasWarning = false;
        foreach ($findings as $finding) {
            $hasError = $hasError || $finding->level === SpamCheckFinding::LEVEL_ERROR;
            $hasWarning = $hasWarning || $finding->level === SpamCheckFinding::LEVEL_WARNING;
        }
        $traffic = $this->config['traffic'];
        if ($hasError || $score < $traffic['red']) {
            $color = SpamCheckResult::TRAFFIC_RED;
        } elseif ($hasWarning || $score < $traffic['green']) {
            $color = SpamCheckResult::TRAFFIC_YELLOW;
        } else {
            $color = SpamCheckResult::TRAFFIC_GREEN;
        }
        $labels = [
            'de' => ['green' => 'Gut', 'yellow' => 'Bitte prüfen', 'red' => 'Kritisch'],
            'en' => ['green' => 'Good', 'yellow' => 'Please review', 'red' => 'Critical'],
        ];
        $stats['suppressed'] = $this->suppressed;
        return new SpamCheckResult($findings, $score, $color, $labels[$this->language][$color], $stats, $spamAssassin);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function add(
        string $ruleId,
        array $params = [],
        ?Element $element = null,
        string $field = 'body',
        ?string $levelOverride = null,
        ?float $weight = null,
        ?string $dedupeKey = null,
    ): void {
        $rule = $this->config['rules'][$ruleId] ?? ($ruleId === 'SPAMASSASSIN_UNAVAILABLE' ? ['level' => 'info'] : null);
        // bei SPAMASSASSIN steuert 'enabled' nur, ob die Pruefung laeuft (Option kann sie einschalten)
        if ($rule === null || (($rule['enabled'] ?? true) === false && !str_starts_with($ruleId, 'SPAMASSASSIN'))) {
            return;
        }
        $level = $levelOverride ?? (string)($rule['level'] ?? SpamCheckFinding::LEVEL_WARNING);
        if (!in_array($level, SpamCheckFinding::LEVELS, true)) {
            $level = SpamCheckFinding::LEVEL_WARNING;
        }
        // gleiche Meldung fuer dasselbe Element nur einmal
        $path = $element !== null ? $this->elementPath($element) : null;
        $key = $ruleId . '|' . ($dedupeKey ?? '') . '|' . ($path ?? $field);
        if (isset($this->reportedKeys[$key])) {
            return;
        }
        $this->reportedKeys[$key] = true;

        $count = 0;
        foreach ($this->findings as $finding) {
            if ($finding->ruleId === $ruleId) {
                $count++;
            }
        }
        if ($count >= self::MAX_FINDINGS_PER_RULE) {
            $this->suppressed[$ruleId] = ($this->suppressed[$ruleId] ?? 0) + 1;
            return;
        }

        if ($level === SpamCheckFinding::LEVEL_INFO) {
            $weight = 0.0;
        } elseif ($weight === null) {
            $weight = isset($rule['weight']) && $levelOverride === null ? (float)$rule['weight'] : (float)($this->config['levelWeights'][$level] ?? 0);
        }

        $texts = $this->messages[$this->language][$ruleId] ?? $this->messages['de'][$ruleId] ?? ['msg' => $ruleId, 'tip' => ''];
        $blockId = null;
        if ($element !== null) {
            $attr = (string)$this->config['blockAttribute'];
            $node = $element;
            while ($node !== null) {
                if ($node->hasAttribute($attr)) {
                    $blockId = (string)$node->getAttribute($attr);
                    break;
                }
                $node = $node->parentElement;
            }
        }
        $this->findings[] = new SpamCheckFinding(
            $ruleId,
            $level,
            $this->category($ruleId),
            $this->interpolate((string)$texts['msg'], $params),
            $this->interpolate((string)$texts['tip'], $params),
            $field,
            $blockId,
            $path,
            $params,
            $weight,
        );
    }

    private function category(string $ruleId): string
    {
        foreach (self::CATEGORY_PREFIXES as $prefix => $category) {
            if (str_starts_with($ruleId, $prefix)) {
                return $category;
            }
        }
        return 'technical';
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function interpolate(string $text, array $params): string
    {
        $replace = [];
        foreach ($params as $key => $value) {
            $replace['{' . $key . '}'] = (string)$value;
        }
        return strtr($text, $replace);
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(string $ruleId): array
    {
        return (array)($this->config['rules'][$ruleId] ?? []);
    }

    // ------------------------------------------------------------------ Helfer

    /**
     * @return array<string, int> gefundenes Wort => Gewicht
     */
    private function matchSpamWords(string $text): array
    {
        $found = [];
        foreach ((array)$this->config['spamWords'] as $list) {
            foreach ((array)$list as $word => $weight) {
                $word = (string)$word;
                // Gewicht 0 oder null schaltet ein Wort ab (z. B. in der custom-Datei)
                if ((int)$weight <= 0) {
                    continue;
                }
                if (str_starts_with($word, '/')) {
                    $pattern = $word;
                } else {
                    $pattern = '/(?<![\p{L}\p{N}])' . preg_replace('/\\\\ /', '\s+', preg_quote($word, '/')) . '(?![\p{L}\p{N}])/iu';
                }
                if (@preg_match($pattern, $text, $m)) {
                    $label = str_starts_with($word, '/') ? $m[0] : $word;
                    $found[mb_strtolower($label)] = (int)$weight;
                }
            }
        }
        // "100 %" und "100%" nur einmal zaehlen, laengere Phrasen schlucken kuerzere
        $keys = array_keys($found);
        foreach ($keys as $a) {
            foreach ($keys as $b) {
                if ($a !== $b && isset($found[$a], $found[$b]) && str_contains((string)$b, (string)$a)) {
                    unset($found[$a]);
                }
            }
        }
        if (isset($found['100 %'], $found['100%'])) {
            unset($found['100%']);
        }
        return $found;
    }

    /**
     * @param list<array<string, mixed>> $segments
     * @return list<array{element: Element|null, text: string, first: array<string, mixed>}>
     */
    private function groupByElement(array $segments): array
    {
        $groups = [];
        foreach ($segments as $segment) {
            $element = $segment['element'];
            $id = $element !== null ? spl_object_id($element) : 0;
            if (!isset($groups[$id])) {
                $groups[$id] = ['element' => $element, 'text' => '', 'first' => $segment];
            }
            $groups[$id]['text'] .= ' ' . $segment['text'];
        }
        return array_values($groups);
    }

    private function isHiddenElement(Element $el): bool
    {
        $node = $el;
        while ($node !== null) {
            $style = $this->parseStyle((string)$node->getAttribute('style'));
            if (($style['display'] ?? '') === 'none' || ($style['visibility'] ?? '') === 'hidden') {
                return true;
            }
            $node = $node->parentElement;
        }
        return false;
    }

    /**
     * @return array<string, string>
     */
    private function parseStyle(string $style): array
    {
        $result = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = strtolower(trim($parts[0]));
            $value = strtolower(trim(str_ireplace('!important', '', $parts[1])));
            if ($name !== '' && $value !== '') {
                $result[$name] = $value;
            }
        }
        return $result;
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function parseColor(string $value): ?array
    {
        $value = strtolower(trim($value));
        if ($value === '' || in_array($value, ['transparent', 'inherit', 'initial', 'currentcolor', 'none'], true)) {
            return null;
        }
        if (isset(self::NAMED_COLORS[$value])) {
            return self::NAMED_COLORS[$value];
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value, $m) || preg_match('/^([0-9a-f]{6})$/', $value, $m)) {
            $hex = $m[1];
            if (strlen($hex) === 3 || strlen($hex) === 4) {
                if (strlen($hex) === 4 && $hex[3] === '0') {
                    return null;
                }
                return [hexdec($hex[0] . $hex[0]), hexdec($hex[1] . $hex[1]), hexdec($hex[2] . $hex[2])];
            }
            if (strlen($hex) === 6 || strlen($hex) === 8) {
                if (strlen($hex) === 8 && substr($hex, 6, 2) === '00') {
                    return null;
                }
                return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
            }
            return null;
        }
        if (preg_match('/^rgba?\(\s*(\d+)%?[\s,]+(\d+)%?[\s,]+(\d+)%?(?:[\s,\/]+([\d.]+%?))?\s*\)$/', $value, $m)) {
            if (isset($m[4]) && (float)$m[4] === 0.0) {
                return null;
            }
            return [min(255, (int)$m[1]), min(255, (int)$m[2]), min(255, (int)$m[3])];
        }
        return null;
    }

    private function parseFontSize(string $value, float $parentPx): ?float
    {
        $value = trim($value);
        $keywords = ['xx-small' => 9.0, 'x-small' => 10.0, 'small' => 13.0, 'medium' => 16.0, 'large' => 18.0, 'x-large' => 24.0, 'xx-large' => 32.0];
        if (isset($keywords[$value])) {
            return $keywords[$value];
        }
        if (!preg_match('/^(-?[\d.]+)\s*(px|pt|em|rem|%)?$/', $value, $m)) {
            return null;
        }
        $number = (float)$m[1];
        return match ($m[2] ?? '') {
            'pt' => $number * 4 / 3,
            'em' => $number * $parentPx,
            'rem' => $number * 16,
            '%' => $number * $parentPx / 100,
            default => $number,
        };
    }

    private function pxValue(string $value): ?float
    {
        $value = trim(strtolower($value));
        if ($value === '' || $value === 'auto') {
            return null;
        }
        if (preg_match('/^([\d.]+)\s*%$/', $value, $m)) {
            return 600 * (float)$m[1] / 100;
        }
        if (preg_match('/^([\d.]+)\s*(px)?$/', $value, $m)) {
            return (float)$m[1];
        }
        return null;
    }

    /**
     * WCAG-Kontrastverhaeltnis 1..21.
     *
     * @param array<int, int> $a
     * @param array<int, int> $b
     */
    private function contrast(array $a, array $b): float
    {
        $l1 = $this->luminance($a);
        $l2 = $this->luminance($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /**
     * @param array<int, int> $rgb
     */
    private function luminance(array $rgb): float
    {
        $channels = [];
        foreach ($rgb as $c) {
            $c = $c / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function hostFromText(string $text): ?string
    {
        $text = trim($text, " \t\n\r\0\x0B.,;:!?()[]<>\"'");
        if (!preg_match('~^(?:https?://)?((?:[a-z0-9äöüß-]+\.)+[a-z]{2,24})(?::\d+)?(?:[/?#]\S*)?$~iu', $text, $m)) {
            return null;
        }
        // "z.B" oder Dateinamen ohne Punkt-Endung ausschliessen
        $host = strtolower($m[1]);
        if (preg_match('/\.(jpg|jpeg|png|gif|pdf|docx?|xlsx?|html?|php)$/', $host)) {
            return null;
        }
        return $host;
    }

    private function registrableDomain(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        $labels = explode('.', $host);
        $count = count($labels);
        if ($count <= 2) {
            return $host;
        }
        $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
        if (in_array($lastTwo, (array)$this->config['secondLevelSuffixes'], true)) {
            return $labels[$count - 3] . '.' . $lastTwo;
        }
        return $lastTwo;
    }

    private function decodeDataUri(string $src): ?string
    {
        if (!preg_match('#^data:[^,]*?(;base64)?,(.*)$#is', $src, $m)) {
            return null;
        }
        $data = $m[1] !== '' ? base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true) : rawurldecode($m[2]);
        return $data === false ? null : $data;
    }

    private function elementPath(Element $element): string
    {
        $parts = [];
        $node = $element;
        while ($node !== null && strtolower($node->localName) !== 'html') {
            $tag = strtolower($node->localName);
            $part = $tag;
            $id = (string)$node->getAttribute('id');
            if ($id !== '') {
                $part .= '#' . $id;
            } else {
                $parent = $node->parentElement;
                if ($parent !== null) {
                    $index = 0;
                    $same = 0;
                    foreach ($parent->children as $sibling) {
                        if (strtolower($sibling->localName) === $tag) {
                            $same++;
                            if ($sibling === $node) {
                                $index = $same;
                            }
                        }
                    }
                    if ($same > 1) {
                        $part .= ':nth-of-type(' . $index . ')';
                    }
                }
            }
            array_unshift($parts, $part);
            if ($id !== '') {
                break;
            }
            $node = $node->parentElement;
        }
        return implode(' > ', $parts);
    }

    private function normalizeSpace(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{034F}"], [' ', '', '', '', '', ''], $text);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function snippet(string $text, int $length = 60): string
    {
        $text = $this->normalizeSpace($text);
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    private function snippetAround(string $text, int $byteOffset): string
    {
        $charOffset = mb_strlen(substr($text, 0, $byteOffset));
        $start = max(0, $charOffset - 30);
        return ($start > 0 ? '…' : '') . $this->snippet(mb_substr($text, $start, 60), 60);
    }

    private function shortSrc(string $src): string
    {
        if (str_starts_with(strtolower($src), 'data:')) {
            return 'data:…';
        }
        $path = (string)parse_url($src, PHP_URL_PATH);
        $name = basename($path);
        return $name !== '' ? $name : $this->shortUrl($src);
    }

    private function shortUrl(string $url): string
    {
        return mb_strlen($url) > 80 ? mb_substr($url, 0, 79) . '…' : $url;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return $this->formatNumber($bytes / 1024 / 1024) . ' MB';
        }
        return $this->formatNumber($bytes / 1024, 0) . ' KB';
    }

    private function formatNumber(float $number, int $decimals = 1): string
    {
        return $this->language === 'de'
            ? number_format($number, $decimals, ',', '.')
            : number_format($number, $decimals, '.', ',');
    }

}
