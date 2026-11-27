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
 * Liest Bilder aus dem eigenen Dateisystem (URL-Praefix -> Ordner).
 * Externe Bilder werden nur geladen, wenn ihr Host ausdruecklich erlaubt ist.
 */
final class LocalImageInfoProvider implements ImageInfoProviderInterface
{

    /** @var array<string, array{bytes: int, width: int|null, height: int|null}|null> */
    private array $cache = [];

    /**
     * @param array<string, string> $urlMap z. B. ['https://shop.example.at/' => '/var/www/html/webroot/', '/' => '/var/www/html/webroot/']
     * @param list<string> $remoteHosts Hosts, von denen Bilder geladen werden duerfen (Standard: keine)
     */
    public function __construct(
        private readonly array $urlMap = [],
        private readonly array $remoteHosts = [],
        private readonly int $timeout = 3,
        private readonly int $maxRemoteBytes = 5 * 1024 * 1024,
    ) {
    }

    public function info(string $src): ?array
    {
        if (array_key_exists($src, $this->cache)) {
            return $this->cache[$src];
        }
        $info = $this->local($src);
        if ($info === null) {
            $info = $this->remote($src);
        }
        $this->cache[$src] = $info;
        return $info;
    }

    /**
     * @return array{bytes: int, width: int|null, height: int|null}|null
     */
    private function local(string $src): ?array
    {
        foreach ($this->urlMap as $prefix => $dir) {
            if (!str_starts_with($src, $prefix)) {
                continue;
            }
            $relative = substr($src, strlen($prefix));
            $relative = (string)preg_replace('/[?#].*$/', '', $relative);
            $base = realpath($dir);
            if ($base === false) {
                continue;
            }
            $file = realpath($base . DIRECTORY_SEPARATOR . rawurldecode(ltrim($relative, '/')));
            if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
                continue;
            }
            $size = @getimagesize($file);
            return [
                'bytes' => (int)filesize($file),
                'width' => $size !== false ? (int)$size[0] : null,
                'height' => $size !== false ? (int)$size[1] : null,
            ];
        }
        return null;
    }

    /**
     * @return array{bytes: int, width: int|null, height: int|null}|null
     */
    private function remote(string $src): ?array
    {
        $host = strtolower((string)parse_url($src, PHP_URL_HOST));
        $scheme = strtolower((string)parse_url($src, PHP_URL_SCHEME));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true) || !in_array($host, $this->remoteHosts, true)) {
            return null;
        }
        $context = stream_context_create(['http' => ['timeout' => $this->timeout, 'ignore_errors' => false]]);
        $data = @file_get_contents($src, false, $context, 0, $this->maxRemoteBytes + 1);
        if ($data === false || $data === '') {
            return null;
        }
        $size = @getimagesizefromstring($data);
        return [
            'bytes' => strlen($data),
            'width' => $size !== false ? (int)$size[0] : null,
            'height' => $size !== false ? (int)$size[1] : null,
        ];
    }

}
