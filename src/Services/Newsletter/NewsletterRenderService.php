<?php
declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Model\Entity\NewsletterCampaign;
use App\Model\Entity\NewsletterSubscriber;
use Cake\Core\Configure;
use Cake\View\ViewBuilder;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Rendert eine Kampagne (Bausteine als JSON) in ein mobilfreundliches HTML-Mailtemplate.
 * Ergebnis enthält Platzhalter, die je Empfänger:in ersetzt werden (personalize()).
 *
 * Bausteine (NewsletterCampaign::$blocks, NewsletterTemplate::$blocks), Liste von Objekten:
 *   {"type":"heading","text":"…","level":1|2}
 *   {"type":"text","html":"<p>…</p>"}            erlaubte Tags: ALLOWED_TAGS
 *   {"type":"image","src":"https://…","alt":"…","href":"https://…"}
 *   {"type":"button","label":"…","href":"https://…"}
 *   {"type":"divider"}
 *   {"type":"spacer","height":24}
 * Platzhalter in Texten: {{first_name}}, {{email}}, {{unsubscribe_url}}
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterRenderService
{

    public const BLOCK_TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer'];

    public const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><a><ul><ol><li><h2><h3><span><img><blockquote>';

    public const PLACEHOLDER_FIRST_NAME = '{{first_name}}';
    public const PLACEHOLDER_EMAIL = '{{email}}';
    public const PLACEHOLDER_UNSUBSCRIBE_URL = '{{unsubscribe_url}}';
    public const PLACEHOLDER_WEBVIEW_URL = '{{webansicht_url}}';

    /**
     * HTML mit Platzhaltern (wird als html_cache gespeichert).
     */
    public function render(NewsletterCampaign $campaign): string
    {
        // Kampagnen aus dem Bausteineditor: der Browser rendert, html_cache ist die Quelle (NewsletterEditorService)
        if (NewsletterEditorService::isEditorFormat($campaign->blocks) && (string)$campaign->html_cache !== '') {
            return (string)$campaign->html_cache;
        }
        $builder = new ViewBuilder();
        $builder->setClassName('View')
            ->setTemplatePath('email' . DS . 'html')
            ->setLayoutPath('email' . DS . 'html')
            ->setTemplate('newsletter_campaign')
            ->setLayout('newsletter')
            ->setVars([
                'campaign' => $campaign,
                'blocks' => $this->prepareBlocks($campaign->getBlocksArray()),
                'preheader' => (string)$campaign->preheader,
                'appName' => (string)Configure::read('appDb.FCS_APP_NAME'),
                'appAddress' => (string)Configure::read('appDb.FCS_APP_ADDRESS'),
                'baseUrl' => (string)Configure::read('App.fullBaseUrl'),
                'logoUrl' => Configure::read('App.fullBaseUrl') . '/files/images/' . Configure::read('app.logoFileName'),
                'themeColor' => (string)(Configure::read('app.customThemeMainColor') ?: '#719f41'),
                'unsubscribeUrlPlaceholder' => self::PLACEHOLDER_UNSUBSCRIBE_URL,
            ]);
        return $builder->build()->render();
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public function prepareBlocks(array $blocks): array
    {
        $prepared = [];
        foreach ($blocks as $block) {
            $type = (string)($block['type'] ?? '');
            if (!in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }
            switch ($type) {
                case 'heading':
                    $prepared[] = [
                        'type' => 'heading',
                        'text' => trim(strip_tags((string)($block['text'] ?? ''))),
                        'level' => (int)($block['level'] ?? 1) === 2 ? 2 : 1,
                    ];
                    break;
                case 'text':
                    $prepared[] = [
                        'type' => 'text',
                        'html' => $this->sanitizeHtml((string)($block['html'] ?? '')),
                    ];
                    break;
                case 'image':
                    $src = $this->sanitizeUrl((string)($block['src'] ?? ''));
                    if ($src === '') {
                        break;
                    }
                    $prepared[] = [
                        'type' => 'image',
                        'src' => $src,
                        'alt' => trim(strip_tags((string)($block['alt'] ?? ''))),
                        'href' => $this->sanitizeUrl((string)($block['href'] ?? '')),
                    ];
                    break;
                case 'button':
                    $href = $this->sanitizeUrl((string)($block['href'] ?? ''));
                    $label = trim(strip_tags((string)($block['label'] ?? '')));
                    if ($href === '' || $label === '') {
                        break;
                    }
                    $prepared[] = ['type' => 'button', 'label' => $label, 'href' => $href];
                    break;
                case 'spacer':
                    $prepared[] = ['type' => 'spacer', 'height' => max(4, min(120, (int)($block['height'] ?? 24)))];
                    break;
                default:
                    $prepared[] = ['type' => $type];
            }
        }
        return $prepared;
    }

    public function sanitizeHtml(string $html): string
    {
        $html = strip_tags($html, self::ALLOWED_TAGS);
        // Event-Handler und javascript:-Links entfernen
        $html = (string)preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string)preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $html);
        return trim($html);
    }

    public function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/')) {
            $url = Configure::read('App.fullBaseUrl') . $url;
        }
        if (!preg_match('#^(https?://|mailto:)#i', $url)) {
            return '';
        }
        return $url;
    }

    /**
     * Platzhalter für eine Empfänger:in ersetzen.
     */
    public function personalize(string $html, ?NewsletterSubscriber $subscriber, string $unsubscribeUrl, string $webviewUrl = ''): string
    {
        // Editor-Form: {{first_name|Ersatztext}} bzw. {{last_name}} / {{last_name|…}}
        $values = [
            'first_name' => $subscriber !== null ? trim((string)$subscriber->first_name) : '',
            'last_name' => $subscriber !== null ? trim((string)$subscriber->last_name) : '',
        ];
        $html = (string)preg_replace_callback(
            '/\{\{(first_name|last_name)(?:\|([^}]*))?\}\}/',
            fn(array $m) => h($values[$m[1]] !== '' ? $values[$m[1]] : html_entity_decode($m[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            $html,
        );
        return str_replace(
            [self::PLACEHOLDER_FIRST_NAME, self::PLACEHOLDER_EMAIL, self::PLACEHOLDER_UNSUBSCRIBE_URL, self::PLACEHOLDER_WEBVIEW_URL],
            [
                h($subscriber !== null ? (string)$subscriber->first_name : ''),
                h($subscriber !== null ? (string)$subscriber->email : ''),
                h($unsubscribeUrl),
                h($webviewUrl !== '' ? $webviewUrl : '#'),
            ],
            $html,
        );
    }

    /**
     * Textteil (multipart/alternative) aus dem HTML.
     */
    public function toText(string $html, string $unsubscribeUrl): string
    {
        $text = (string)preg_replace('#<(style|head)[^>]*>.*?</\1>#is', '', $html);
        $text = (string)preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $text);
        $text = (string)preg_replace('#<(br|/p|/h[1-6]|/li|/tr|/div)[^>]*>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace("/[ \t]+/", ' ', $text);
        $text = (string)preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);
        $lines = array_map('trim', explode("\n", $text));
        $text = trim(implode("\n", $lines));
        if (!str_contains($text, $unsubscribeUrl)) {
            $text .= "\n\nAbmelden: " . $unsubscribeUrl;
        }
        return $text;
    }

}
