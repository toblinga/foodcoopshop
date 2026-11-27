<?php
declare(strict_types=1);

namespace App\Services\Newsletter;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use RuntimeException;
use Throwable;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Importiert versendete Newsletter aus .eml-Dateien (z. B. Export aus dem Postfach, rapidmail-Mails).
 *
 * - Jede Mail wird als versendete Kampagne angelegt (Status "sent", sent_at = Datum der Mail).
 * - Das Mail-HTML wird unverändert übernommen; eingebettete Bilder (cid:) werden nach
 *   files/images/newsletter/archiv/ gespeichert und im HTML auf die Datei-URL umgeschrieben.
 * - Ohne Editor-Bausteine: die Kampagne wird über html_cache angezeigt (NewsletterRenderService).
 * - Idempotent: eine Mail mit gleichem Betreff und Versanddatum wird übersprungen.
 * - Reiner PHP-Code (kein Python, keine Zusatzpakete), läuft also auch auf dem Produktivserver.
 *
 * Aufruf: bin/cake newsletter import_archive --dir <ordner> [--dry-run]
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class RapidmailArchiveImportService
{

    public const SOURCE = 'rapidmail';

    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * @return array{files: int, imported: int, skipped: int, images: int, errors: list<string>, campaigns: list<array{id: ?int, subject: string, sent_at: string, images: int, status: string}>}
     */
    public function importDirectory(string $dir, bool $dryRun = false): array
    {
        if (!is_dir($dir)) {
            throw new RuntimeException('Ordner nicht gefunden: ' . $dir);
        }
        $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.eml') ?: [];
        sort($files, SORT_NATURAL);

        $result = ['files' => count($files), 'imported' => 0, 'skipped' => 0, 'images' => 0, 'errors' => [], 'campaigns' => []];
        foreach ($files as $file) {
            try {
                $mail = $this->parseFile($file);
            } catch (Throwable $e) {
                $result['errors'][] = basename($file) . ': ' . $e->getMessage();
                continue;
            }

            $existing = TableRegistry::getTableLocator()->get('NewsletterCampaigns')->find()
                ->where(['subject' => $mail['subject'], 'sent_at' => $mail['sentAt'], 'status' => 'sent'])
                ->first();
            $entry = [
                'id' => $existing ? (int)$existing->id : null,
                'subject' => $mail['subject'],
                'sent_at' => $mail['sentAt']->format('Y-m-d H:i'),
                'images' => 0,
                'status' => '',
            ];

            if ($existing) {
                $entry['status'] = 'vorhanden';
                $result['skipped']++;
                $result['campaigns'][] = $entry;
                continue;
            }

            $html = $this->embedImages($mail['html'], $mail['parts'], $dryRun, $entry['images']);
            $result['images'] += $entry['images'];

            if (!$dryRun) {
                $campaign = $this->saveCampaign($mail, $html, basename($file));
                $entry['id'] = (int)$campaign->id;
            }
            $entry['status'] = $dryRun ? 'würde importiert' : 'importiert';
            $result['imported']++;
            $result['campaigns'][] = $entry;
        }

        return $result;
    }

    /**
     * @return array{subject: string, sentAt: DateTime, fromName: string, fromEmail: string, preheader: string, html: string, parts: array<string, array{type: string, data: string}>}
     */
    public function parseFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Datei leer oder nicht lesbar.');
        }
        $message = $this->parseMime($raw);
        $headers = $message['headers'];

        $html = null;
        $parts = [];
        $this->collectParts($message, $html, $parts);
        if ($html === null || $html === '') {
            throw new RuntimeException('Kein HTML-Teil gefunden.');
        }

        $dateHeader = trim($headers['date'] ?? '');
        if ($dateHeader === '') {
            throw new RuntimeException('Kein Datum im Header.');
        }
        $sentAt = new DateTime($dateHeader);

        $subject = mb_substr($this->decodeHeader($headers['subject'] ?? ''), 0, 255);
        if ($subject === '') {
            $subject = 'Newsletter ' . $sentAt->format('d.m.Y');
        }

        $from = $this->decodeHeader($headers['from'] ?? '');
        $fromEmail = '';
        $fromName = '';
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $from, $m)) {
            $fromName = trim($m[1]);
            $fromEmail = trim($m[2]);
        } elseif (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) {
            $fromEmail = trim($from);
        }

        return [
            'subject' => $subject,
            'sentAt' => $sentAt,
            'fromName' => mb_substr($fromName, 0, 255),
            'fromEmail' => mb_substr($fromEmail, 0, 255),
            'preheader' => $this->extractPreheader($html),
            'html' => $html,
            'parts' => $parts,
        ];
    }

    /**
     * Einfacher MIME-Parser: Header (mit Fortsetzungszeilen), Content-Type, Boundary, Transfer-Encoding, Charset.
     *
     * @return array{headers: array<string, string>, type: string, charset: string, body: string, parts: list<array<string, mixed>>}
     */
    public function parseMime(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $split = strpos($raw, "\n\n");
        $headerBlock = $split === false ? $raw : substr($raw, 0, $split);
        $body = $split === false ? '' : substr($raw, $split + 2);

        $headers = [];
        $unfolded = preg_replace("/\n[ \t]+/", ' ', $headerBlock) ?? $headerBlock;
        foreach (explode("\n", $unfolded) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            if (!isset($headers[$name])) {
                $headers[$name] = trim(substr($line, $pos + 1));
            }
        }

        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? 'text/plain')[0]));
        $charset = '';
        if (preg_match('/charset="?([^";\s]+)"?/i', $headers['content-type'] ?? '', $m)) {
            $charset = strtolower($m[1]);
        }

        $node = ['headers' => $headers, 'type' => $contentType, 'charset' => $charset, 'body' => '', 'parts' => []];

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = '';
            if (preg_match('/boundary="?([^";\s]+)"?/i', $headers['content-type'] ?? '', $m)) {
                $boundary = $m[1];
            }
            if ($boundary === '') {
                throw new RuntimeException('Multipart ohne Boundary.');
            }
            $chunks = explode("\n--" . $boundary, "\n" . $body);
            array_shift($chunks); // Präambel
            foreach ($chunks as $chunk) {
                if (str_starts_with($chunk, '--')) {
                    break; // Abschluss-Marker
                }
                $node['parts'][] = $this->parseMime(ltrim($chunk, "\n"));
            }

            return $node;
        }

        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        $decoded = match ($encoding) {
            'base64' => (string)base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii') {
            $decoded = mb_convert_encoding($decoded, 'UTF-8', $charset);
        }
        $node['body'] = $decoded;

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array{type: string, data: string}> $parts
     */
    private function collectParts(array $node, ?string &$html, array &$parts): void
    {
        if ($node['parts'] !== []) {
            foreach ($node['parts'] as $child) {
                $this->collectParts($child, $html, $parts);
            }

            return;
        }
        $isAttachment = str_contains(strtolower($node['headers']['content-disposition'] ?? ''), 'attachment');
        if ($node['type'] === 'text/html' && !$isAttachment && $html === null) {
            $html = $node['body'];

            return;
        }
        $cid = trim($node['headers']['content-id'] ?? '', '<> ');
        if ($cid !== '') {
            $parts[$cid] = ['type' => $node['type'], 'data' => $node['body']];
        }
    }

    private function decodeHeader(string $value): string
    {
        $decoded = mb_decode_mimeheader($value);

        return trim((string)preg_replace('/\s+/', ' ', $decoded));
    }

    private function extractPreheader(string $html): string
    {
        if (!preg_match('/<div class="preheader"[^>]*>(.*?)<\/div>/s', $html, $m)) {
            return '';
        }
        $text = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim((string)preg_replace('/\s+/', ' ', $text)), 0, 255);
    }

    /**
     * Ersetzt cid:-Verweise durch Bild-URLs. Zählt die übernommenen Bilder.
     *
     * @param array<string, array{type: string, data: string}> $parts
     */
    private function embedImages(string $html, array $parts, bool $dryRun, int &$count): string
    {
        if (!preg_match_all('/cid:([^"\'\s>)]+)/', $html, $matches)) {
            return $html;
        }
        foreach (array_unique($matches[1]) as $cid) {
            if (!isset($parts[$cid])) {
                continue;
            }
            $url = $this->storeImage($parts[$cid]['data'], $parts[$cid]['type'], $dryRun);
            if ($url === null) {
                continue;
            }
            $html = str_replace('cid:' . $cid, $url, $html);
            $count++;
        }

        return $html;
    }

    private function storeImage(string $data, string $type, bool $dryRun): ?string
    {
        $extension = self::IMAGE_EXTENSIONS[strtolower($type)] ?? null;
        if ($extension === null || $data === '') {
            return null;
        }
        $name = substr(sha1($data), 0, 20) . '.' . $extension;
        $dir = WWW_ROOT . 'files' . DS . 'images' . DS . NewsletterEditorService::UPLOAD_DIR . DS . 'archiv';
        $file = $dir . DS . $name;
        if (!$dryRun && !file_exists($file)) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Bildordner kann nicht angelegt werden: ' . $dir);
            }
            if (file_put_contents($file, $data) === false) {
                throw new RuntimeException('Bild kann nicht gespeichert werden: ' . $file);
            }
        }

        // Relativer Pfad: Archiv-Mails werden nie erneut verschickt, und so laden die Bilder
        // in der Vorschau auf jedem Host (Localhost, Tailscale, Produktion).
        return '/files/images/' . NewsletterEditorService::UPLOAD_DIR . '/archiv/' . $name;
    }

    /**
     * @param array<string, mixed> $mail
     */
    private function saveCampaign(array $mail, string $html, string $fileName): \App\Model\Entity\NewsletterCampaign
    {
        $table = TableRegistry::getTableLocator()->get('NewsletterCampaigns');
        $blocks = (string)json_encode([
            'format' => NewsletterEditorService::FORMAT,
            'version' => 1,
            'settings' => [],
            'blocks' => [],
            'archiv' => ['quelle' => self::SOURCE, 'datei' => $fileName],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $entity = $table->newEntity([
            'subject' => $mail['subject'],
            'preheader' => $mail['preheader'],
            'blocks' => $blocks,
            'html_cache' => $html,
            'status' => 'sent',
            'sender_name' => $mail['fromName'] ?: null,
            'sender_email' => $mail['fromEmail'] ?: null,
            'count_total' => 0,
            'count_sent' => 0,
            'count_failed' => 0,
            'sent_at' => $mail['sentAt'],
            'started_at' => $mail['sentAt'],
        ]);
        $table->saveOrFail($entity);

        // Anlage- und Änderungsdatum auf das Versanddatum setzen
        $table->updateAll(['created' => $mail['sentAt'], 'modified' => $mail['sentAt']], ['id' => $entity->id]);

        return $entity;
    }

}
