<?php
declare(strict_types=1);

namespace App\Services\Newsletter\SpamCheck;

use RuntimeException;

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
 * Minimaler Client fuer spamd (Protokoll SPAMC/1.5, Befehl REPORT), wie spamc.
 * Gedacht fuer einen lokalen Container (z. B. axllent/spamassassin, DNS-Tests aus).
 * Die Mail geht nur an diesen lokalen Dienst.
 */
class SpamAssassinClient
{

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 783,
        private readonly int $timeout = 10,
    ) {
    }

    /**
     * @return array{score: float, threshold: float, isSpam: bool, rules: list<array{name: string, score: float, description: string}>}
     */
    public function check(string $rawMessage): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new RuntimeException('spamd ' . $this->host . ':' . $this->port . ': ' . ($errstr !== '' ? $errstr : 'connection failed'));
        }
        stream_set_timeout($socket, $this->timeout);
        $rawMessage = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $rawMessage);
        $request = "REPORT SPAMC/1.5\r\n"
            . 'Content-length: ' . strlen($rawMessage) . "\r\n"
            . "\r\n"
            . $rawMessage;
        fwrite($socket, $request);
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $response = stream_get_contents($socket);
        fclose($socket);
        if ($response === false || $response === '') {
            throw new RuntimeException('spamd: empty response');
        }
        return self::parseResponse($response);
    }

    /**
     * @return array{score: float, threshold: float, isSpam: bool, rules: list<array{name: string, score: float, description: string}>}
     */
    public static function parseResponse(string $response): array
    {
        $parts = preg_split("/\r?\n\r?\n/", $response, 2);
        $head = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        if (!preg_match('#^SPAMD/[\d.]+\s+0\s+#', $head)) {
            throw new RuntimeException('spamd: ' . strtok($head, "\r\n"));
        }
        if (!preg_match('#Spam:\s*(True|False|Yes|No)\s*;\s*(-?[\d.]+)\s*/\s*(-?[\d.]+)#i', $head, $m)) {
            throw new RuntimeException('spamd: no score in response');
        }
        $rules = [];
        $current = null;
        foreach (preg_split("/\r?\n/", $body) ?: [] as $line) {
            if (preg_match('#^\s*(-?\d+(?:\.\d+)?)\s+([A-Z0-9_]{2,})\s+(.*)$#', $line, $r)) {
                if ($current !== null) {
                    $rules[] = $current;
                }
                $current = ['name' => $r[2], 'score' => (float)$r[1], 'description' => trim($r[3])];
            } elseif ($current !== null && preg_match('#^\s{10,}(\S.*)$#', $line, $c)) {
                $current['description'] .= ' ' . trim($c[1]);
            }
        }
        if ($current !== null) {
            $rules[] = $current;
        }
        return [
            'score' => (float)$m[2],
            'threshold' => (float)$m[3],
            'isSpam' => in_array(strtolower($m[1]), ['true', 'yes'], true),
            'rules' => $rules,
        ];
    }

    /**
     * Baut eine einfache MIME-Nachricht (multipart/alternative) fuer die Pruefung.
     *
     * @param array<string, string> $headers zusaetzliche Header, z. B. List-Unsubscribe
     */
    public static function buildMessage(
        string $html,
        ?string $text,
        string $subject,
        string $fromEmail,
        string $fromName = '',
        array $headers = [],
    ): string {
        $boundary = 'fcs-' . bin2hex(random_bytes(8));
        $domain = substr((string)strrchr($fromEmail, '@'), 1) ?: 'localhost';
        $from = $fromName !== '' ? mb_encode_mimeheader($fromName, 'UTF-8', 'Q') . ' <' . $fromEmail . '>' : $fromEmail;
        $lines = [
            'From: ' . $from,
            'To: newsletter-check@example.invalid',
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'Q'),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . date('YmdHis') . '.' . bin2hex(random_bytes(6)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
        ];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $parts = [];
        if ($text !== null && $text !== '') {
            $parts[] = "Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: quoted-printable\n\n" . quoted_printable_encode($text);
        }
        $parts[] = "Content-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: quoted-printable\n\n" . quoted_printable_encode($html);
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $message = implode("\n", $lines) . "\n\n";
        foreach ($parts as $part) {
            $message .= '--' . $boundary . "\n" . $part . "\n";
        }
        $message .= '--' . $boundary . "--\n";
        return $message;
    }

}
