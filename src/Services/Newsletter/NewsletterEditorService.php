<?php
declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Model\Entity\NewsletterCampaign;
use App\Model\Entity\NewsletterTemplate;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Bausteineditor (Phase 2): Editor-Format speichern/laden, Vorlagen und Bausteine, Bilder.
 *
 * Editor-Format in `blocks`:
 *   {"format":"fcs-newsletter-blocks","version":1,"settings":{…},"blocks":[{"id","type","data","style"},…]}
 * Der Browser rendert das Mail-HTML (newsletter-editor/js/nl-render.js) und schickt es mit;
 * es wird geprueft und als html_cache gespeichert. NewsletterRenderService::render() gibt fuer
 * Kampagnen im Editor-Format html_cache zurueck, statt neu zu rendern.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterEditorService
{

    public const FORMAT = 'fcs-newsletter-blocks';
    public const MAX_HTML_BYTES = 1048576;
    public const MAX_BLOCKS_BYTES = 2097152;
    public const UPLOAD_DIR = 'newsletter';
    public const MAX_UPLOAD_BYTES = 5242880;
    public const MAX_IMAGE_WIDTH = 1200;

    public static function isEditorFormat(?string $blocksJson): bool
    {
        if ($blocksJson === null || $blocksJson === '') {
            return false;
        }
        $decoded = json_decode($blocksJson, true);
        return is_array($decoded) && ($decoded['format'] ?? null) === self::FORMAT;
    }

    /**
     * Startdokument: Kopf mit Logo, Begruessung, Text, Pflicht-Fusszeile.
     *
     * @return array<string, mixed>
     */
    public function getDefaultDocument(): array
    {
        $base = (string)Configure::read('App.fullBaseUrl');
        $logo = $base . '/files/images/' . Configure::read('app.logoFileName');
        return [
            'format' => self::FORMAT,
            'version' => 1,
            'settings' => $this->getSettings(),
            'blocks' => [
                ['id' => 'header', 'type' => 'header', 'style' => ['bg' => 'white'], 'data' => ['logo' => $logo, 'alt' => (string)Configure::read('appDb.FCS_APP_NAME'), 'logoWidth' => 160, 'tagline' => '', 'url' => $base]],
                // Neutraler Startentwurf: Begrüßung, Text, Trenner, Gruß, Fußzeile (Layout-Vorlagen: bin/cake newsletter import_library)
                ['id' => 'b' . bin2hex(random_bytes(5)), 'type' => 'text', 'style' => ['bg' => 'white'], 'data' => ['html' => '<p>Hallo {{vorname|liebe Mitglieder}}!</p><p>Dein Text …</p>', 'align' => 'left']],
                ['id' => 'b' . bin2hex(random_bytes(5)), 'type' => 'divider', 'style' => ['bg' => 'white'], 'data' => ['variant' => 'line']],
                ['id' => 'b' . bin2hex(random_bytes(5)), 'type' => 'text', 'style' => ['bg' => 'white'], 'data' => ['html' => '<p>Viele Grüße<br>Dein Team</p>', 'align' => 'left']],
                ['id' => 'footer', 'type' => 'footer', 'locked' => true, 'style' => ['bg' => 'white'], 'data' => [
                    'social' => [['network' => 'website', 'url' => $base]],
                    'contactHtml' => '<p>Fragen? Schreib uns an <a href="mailto:' . h((string)Configure::read('appDb.FCS_APP_EMAIL')) . '">' . h((string)Configure::read('appDb.FCS_APP_EMAIL')) . '</a></p>',
                ]],
            ],
        ];
    }

    /**
     * Angaben fuer den Pflicht-Fussbereich (Impressum, Adresse) aus der FCS-Konfiguration.
     *
     * @return array<string, string>
     */
    public function getSettings(): array
    {
        $base = (string)Configure::read('App.fullBaseUrl');
        $address = trim(strip_tags(str_replace(['<br />', '<br>', '<br/>', "\n"], ', ', (string)Configure::read('appDb.FCS_APP_ADDRESS'))), ', ');
        return [
            'orgName' => (string)Configure::read('appDb.FCS_APP_NAME'),
            'orgAddress' => (string)preg_replace('/\s*,\s*(,\s*)+/', ', ', $address),
            'orgEmail' => (string)Configure::read('appDb.FCS_APP_EMAIL'),
            'imprintUrl' => $base . '/' . __('route_imprint'),
            'privacyUrl' => $base . '/' . __('route_privacy_policy'),
            'shopUrl' => $base,
        ];
    }

    /**
     * Importierte Mails (RapidmailArchiveImportService) haben nur fertiges HTML, keine Bausteine.
     */
    public static function isArchiveBlocks(?string $blocksJson): bool
    {
        return self::isEditorFormat($blocksJson) && isset(json_decode((string)$blocksJson, true)['archiv']);
    }

    /**
     * Entwurf aus einer importierten Mail: Kopf und Fußzeile wie üblich, der Inhalt der Mail als
     * ein Baustein "Originalinhalt (HTML)" in der Mitte, damit er sich im Editor bearbeiten lässt.
     *
     * @return array<string, mixed>
     */
    public function getArchiveCopyDocument(string $html): array
    {
        $doc = $this->getDefaultDocument();
        $header = $doc['blocks'][0];
        $footer = $doc['blocks'][count($doc['blocks']) - 1];
        $doc['blocks'] = [
            $header,
            ['id' => 'b' . bin2hex(random_bytes(5)), 'type' => 'raw', 'style' => ['bg' => 'white'], 'data' => ['html' => $this->extractBodyHtml($html)]],
            $footer,
        ];

        return $doc;
    }

    /**
     * Inhalt von <body> ohne Vorschautext (div.preheader) und Skripte.
     */
    public function extractBodyHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query("//script | //*[contains(concat(' ', normalize-space(@class), ' '), ' preheader ')]") as $node) {
            $node->parentNode?->removeChild($node);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $this->sanitizeEditorHtml($html);
        }
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $this->sanitizeEditorHtml($out);
    }

    public function createDraft(?int $templateId = null): NewsletterCampaign
    {
        $doc = $this->getDefaultDocument();
        if ($templateId) {
            $template = TableRegistry::getTableLocator()->get('NewsletterTemplates')->find()->where(['id' => $templateId, 'type' => 'template'])->first();
            if ($template && self::isEditorFormat($template->blocks)) {
                $doc = json_decode((string)$template->blocks, true);
                $doc['settings'] = $this->getSettings();
            }
        }
        return (new NewsletterCampaignService())->create([
            'subject' => 'Neuer Newsletter',
            'preheader' => '',
            'blocks' => $doc,
        ]);
    }

    /**
     * Zustand fuer NLEditor (doc + meta).
     *
     * @return array<string, mixed>
     */
    public function getEditorState(NewsletterCampaign $campaign): array
    {
        $doc = self::isEditorFormat($campaign->blocks) ? json_decode((string)$campaign->blocks, true) : $this->convertLegacy($campaign->getBlocksArray());
        // Pflichtangaben immer aktuell aus der Konfiguration
        $doc['settings'] = array_merge((array)($doc['settings'] ?? []), $this->getSettings());
        return [
            'doc' => $doc,
            'meta' => [
                'name' => '',
                'subject' => (string)$campaign->subject,
                'preheader' => (string)$campaign->preheader,
                'senderName' => (string)$campaign->sender_name,
                'senderEmail' => (string)$campaign->sender_email,
                'scheduledAt' => $campaign->scheduled_at ? $campaign->scheduled_at->format('Y-m-d\TH:i') : '',
            ],
        ];
    }

    /**
     * Phase-1-Bausteine (flach) ins Editor-Format uebernehmen.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    public function convertLegacy(array $blocks): array
    {
        $doc = $this->getDefaultDocument();
        if ($blocks === []) {
            return $doc;
        }
        $converted = [];
        foreach ($blocks as $b) {
            $id = 'b' . bin2hex(random_bytes(5));
            $type = (string)($b['type'] ?? '');
            $data = match ($type) {
                'heading' => ['text' => (string)($b['text'] ?? ''), 'level' => (int)($b['level'] ?? 1), 'align' => 'left'],
                'text' => ['html' => (string)($b['html'] ?? ''), 'align' => 'left'],
                'image' => ['src' => (string)($b['src'] ?? ''), 'alt' => (string)($b['alt'] ?? ''), 'url' => (string)($b['href'] ?? ''), 'size' => 'wide', 'caption' => ''],
                'button' => ['label' => (string)($b['label'] ?? ''), 'url' => (string)($b['href'] ?? ''), 'align' => 'center', 'variant' => 'filled'],
                'divider' => ['variant' => 'line'],
                'spacer' => ['height' => (int)($b['height'] ?? 24)],
                default => null,
            };
            if ($data !== null) {
                // Phase-1-Platzhalter in Editor-Schreibweise
                array_walk_recursive($data, function (&$v) {
                    if (is_string($v)) {
                        $v = str_replace(['{{first_name}}', '{{unsubscribe_url}}'], ['{{vorname}}', '{{abmeldelink}}'], $v);
                    }
                });
                $converted[] = ['id' => $id, 'type' => $type, 'style' => ['bg' => 'white'], 'data' => $data];
            }
        }
        $first = $doc['blocks'][0];
        $footer = $doc['blocks'][count($doc['blocks']) - 1];
        $doc['blocks'] = array_merge([$first], $converted, [$footer]);
        return $doc;
    }

    /**
     * @param array<string, mixed> $data blocks (Array oder JSON), html, subject, preheader, sender_name, sender_email
     */
    public function saveFromEditor(NewsletterCampaign $campaign, array $data): NewsletterCampaign
    {
        $blocks = $data['blocks'] ?? null;
        if (is_string($blocks)) {
            $blocks = json_decode($blocks, true);
        }
        if (!is_array($blocks) || ($blocks['format'] ?? null) !== self::FORMAT || !is_array($blocks['blocks'] ?? null)) {
            throw new RuntimeException('Ungültiges Bausteinformat.');
        }
        $blocksJson = (string)json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($blocksJson) > self::MAX_BLOCKS_BYTES) {
            throw new RuntimeException('Die Kampagne ist zu groß (eingebettete Bilder?).');
        }
        $html = $this->sanitizeEditorHtml((string)($data['html'] ?? ''));
        if ($html === '' || !str_contains($html, '{{unsubscribe_url}}')) {
            throw new RuntimeException('Das Mail-HTML fehlt oder hat keinen Abmeldelink.');
        }
        if (strlen($html) > self::MAX_HTML_BYTES) {
            throw new RuntimeException('Das Mail-HTML ist zu groß.');
        }
        $senderEmail = trim((string)($data['sender_email'] ?? ''));
        if ($senderEmail !== '' && !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Die Absender-Adresse ist ungültig.');
        }
        $table = TableRegistry::getTableLocator()->get('NewsletterCampaigns');
        $campaign = $table->patchEntity($campaign, [
            'subject' => mb_substr(trim((string)($data['subject'] ?? '')), 0, 255) ?: 'Ohne Betreff',
            'preheader' => mb_substr(trim((string)($data['preheader'] ?? '')), 0, 255),
            'sender_name' => trim((string)($data['sender_name'] ?? '')) ?: null,
            'sender_email' => $senderEmail ?: null,
            'blocks' => $blocksJson,
            'html_cache' => $html,
        ]);
        if (!$table->save($campaign)) {
            throw new RuntimeException('Speichern fehlgeschlagen: ' . json_encode($campaign->getErrors()));
        }
        return $campaign;
    }

    /**
     * Vom Browser geliefertes Mail-HTML entschaerfen. Bedingte Outlook-Kommentare bleiben erhalten.
     * Bedienende sind Admins; das ist Schutz gegen Fehler und eingefuegte Fremdinhalte, kein Sandbox-Ersatz.
     */
    public function sanitizeEditorHtml(string $html): string
    {
        $html = (string)preg_replace('#<(script|iframe|object|embed|form|input|textarea|select|button|link|meta\s+http-equiv="refresh")\b[^>]*>(.*?</\1>)?#is', '', $html);
        $html = (string)preg_replace('#</?(script|iframe|object|embed|form)\b[^>]*>#i', '', $html);
        $html = (string)preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string)preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|vbscript|data:(?!image\/))[^"\']*\2/i', '$1="#"', $html);
        return trim($html);
    }

    // ---------------------------------------------------------------- Vorlagen

    /**
     * @return list<array<string, mixed>>
     */
    public function listTemplates(string $type): array
    {
        $rows = TableRegistry::getTableLocator()->get('NewsletterTemplates')->find()
            ->where(['type' => $type])->orderBy(['name' => 'ASC'])->all();
        $items = [];
        foreach ($rows as $t) {
            $blocks = json_decode((string)$t->blocks, true);
            if (!is_array($blocks)) {
                continue;
            }
            // Baustein: im Editor als Liste mit einem Block
            if ($type === 'block' && ($blocks['format'] ?? null) === self::FORMAT) {
                $blocks = $blocks['blocks'];
            }
            $items[] = ['id' => $t->id, 'type' => $t->type, 'name' => $t->name, 'blocks' => $blocks, 'created' => $t->created?->format('c')];
        }
        return $items;
    }

    /**
     * @param mixed $blocks Vorlage: Dokument; Baustein: Liste mit einem Block
     * @return array<string, mixed>
     */
    public function saveTemplate(string $type, string $name, mixed $blocks): array
    {
        if (!in_array($type, ['block', 'template'], true)) {
            throw new RuntimeException('Ungültiger Typ.');
        }
        $name = mb_substr(trim(strip_tags($name)), 0, 255);
        if ($name === '') {
            throw new RuntimeException('Bitte einen Namen eingeben.');
        }
        if (is_string($blocks)) {
            $blocks = json_decode($blocks, true);
        }
        if (!is_array($blocks)) {
            throw new RuntimeException('Ungültige Bausteine.');
        }
        if ($type === 'block') {
            $list = array_values(array_filter($blocks, 'is_array'));
            if (count($list) !== 1 || ($list[0]['type'] ?? '') === 'footer') {
                throw new RuntimeException('Ein gespeicherter Baustein enthält genau einen Block (keine Fußzeile).');
            }
            $doc = ['format' => self::FORMAT, 'version' => 1, 'settings' => [], 'blocks' => $list];
        } else {
            if (($blocks['format'] ?? null) !== self::FORMAT) {
                throw new RuntimeException('Ungültiges Vorlagenformat.');
            }
            $doc = $blocks;
        }
        $json = (string)json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_BLOCKS_BYTES) {
            throw new RuntimeException('Die Vorlage ist zu groß.');
        }
        $table = TableRegistry::getTableLocator()->get('NewsletterTemplates');
        /** @var NewsletterTemplate $entity */
        $entity = $table->newEntity(['type' => $type, 'name' => $name, 'blocks' => $json]);
        if (!$table->save($entity)) {
            throw new RuntimeException('Speichern fehlgeschlagen.');
        }
        return ['id' => $entity->id, 'type' => $type, 'name' => $name, 'blocks' => $type === 'block' ? $doc['blocks'] : $doc];
    }

    public function renameTemplate(int $id, string $name): void
    {
        $table = TableRegistry::getTableLocator()->get('NewsletterTemplates');
        $t = $table->get($id);
        $name = mb_substr(trim(strip_tags($name)), 0, 255);
        if ($name === '') {
            throw new RuntimeException('Bitte einen Namen eingeben.');
        }
        $t->name = $name;
        $table->saveOrFail($t);
    }

    public function deleteTemplate(int $id): void
    {
        $table = TableRegistry::getTableLocator()->get('NewsletterTemplates');
        $table->delete($table->get($id));
    }

    // ------------------------------------------------------------------ Bilder

    protected function getUploadDir(): string
    {
        return WWW_ROOT . 'files' . DS . 'images' . DS . self::UPLOAD_DIR;
    }

    /**
     * @return list<array{src: string, name: string}>
     */
    public function listMedia(): array
    {
        $base = (string)Configure::read('App.fullBaseUrl');
        $items = [];
        $dir = $this->getUploadDir();
        if (is_dir($dir)) {
            $files = glob($dir . DS . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($files, 0, 120) as $file) {
                $items[] = ['src' => $base . '/files/images/' . self::UPLOAD_DIR . '/' . basename($file), 'name' => basename($file)];
            }
        }
        $items[] = ['src' => $base . '/files/images/' . Configure::read('app.logoFileName'), 'name' => 'Logo'];
        return $items;
    }

    /**
     * Bild speichern (verkleinert auf hoechstens 1200 px Breite), absolute URL zurueck.
     *
     * @return array{src: string, name: string}
     */
    public function storeUpload(UploadedFileInterface $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload fehlgeschlagen.');
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Das Bild ist größer als 5 MB.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'nl');
        $file->moveTo($tmp);
        $info = @getimagesize($tmp);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if ($info === false || !isset($types[$info[2]])) {
            @unlink($tmp);
            throw new RuntimeException('Nur JPG, PNG, GIF oder WebP.');
        }
        $ext = $types[$info[2]];
        $dir = $this->getUploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            @unlink($tmp);
            throw new RuntimeException('Bildordner files/images/' . self::UPLOAD_DIR . ' kann nicht angelegt werden (Schreibrechte).');
        }
        if (!is_writable($dir)) {
            @unlink($tmp);
            throw new RuntimeException('Bildordner files/images/' . self::UPLOAD_DIR . ' ist nicht beschreibbar.');
        }
        $orig = pathinfo((string)$file->getClientFilename(), PATHINFO_FILENAME);
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $orig) ?: 'bild')), '-') ?: 'bild';
        $name = date('Ymd-His') . '-' . substr($slug, 0, 40) . '.' . $ext;
        $target = $dir . DS . $name;

        $width = (int)$info[0];
        if ($width > self::MAX_IMAGE_WIDTH && $ext !== 'gif' && function_exists('imagecreatefromstring')) {
            $src = imagecreatefromstring((string)file_get_contents($tmp));
            if ($src !== false) {
                $height = (int)round($info[1] * self::MAX_IMAGE_WIDTH / $width);
                $dst = imagecreatetruecolor(self::MAX_IMAGE_WIDTH, $height);
                if ($ext === 'png' || $ext === 'webp') {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, self::MAX_IMAGE_WIDTH, $height, $width, (int)$info[1]);
                match ($ext) {
                    'png' => imagepng($dst, $target, 8),
                    'webp' => imagewebp($dst, $target, 82),
                    default => imagejpeg($dst, $target, 82),
                };
                @unlink($tmp);
            }
        }
        if (!file_exists($target) && !@rename($tmp, $target) && !@copy($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Bild konnte nicht gespeichert werden.');
        }
        @unlink($tmp);
        @chmod($target, 0664);
        return ['src' => Configure::read('App.fullBaseUrl') . '/files/images/' . self::UPLOAD_DIR . '/' . $name, 'name' => $name];
    }

}
