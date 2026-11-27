<?php
declare(strict_types=1);

namespace App\Services\Newsletter\SpamCheck;

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
 * Prueft Linkziele per HEAD (Fallback GET mit Range 0-0, falls HEAD abgelehnt wird).
 * Es wird nur die URL aufgerufen, kein Inhalt der Mail verschickt.
 */
final class HttpLinkProbe implements LinkProbeInterface
{

    public function __construct(private readonly string $userAgent = 'FoodCoopShop-Newsletter-Linkcheck/1.0')
    {
    }

    public function status(string $url, int $timeout): int
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 0;
        }
        $status = $this->request($url, $timeout, true);
        if (in_array($status, [0, 403, 405, 501], true)) {
            $status = $this->request($url, $timeout, false);
        }
        return $status;
    }

    private function request(string $url, int $timeout, bool $head): int
    {
        if (!function_exists('curl_init')) {
            $context = stream_context_create(['http' => [
                'method' => $head ? 'HEAD' : 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'user_agent' => $this->userAgent,
                'header' => $head ? '' : "Range: bytes=0-0\r\n",
            ]]);
            $headers = @get_headers($url, false, $context);
            if ($headers === false) {
                return 0;
            }
            $status = 0;
            foreach ($headers as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int)$m[1];
                }
            }
            return $status;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => $head,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RANGE => $head ? null : '0-0',
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return $status;
    }

}
