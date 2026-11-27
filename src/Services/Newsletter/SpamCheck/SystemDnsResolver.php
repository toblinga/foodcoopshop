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
 * DNS ueber den Resolver des Systems (dns_get_record). Es werden nur Namen
 * abgefragt (Absenderdomain), keine Inhalte der Mail.
 */
final class SystemDnsResolver implements DnsResolverInterface
{

    /** @var array<string, mixed> */
    private array $cache = [];

    public function txt(string $name): ?array
    {
        $key = 'txt:' . $name;
        if (!array_key_exists($key, $this->cache)) {
            $records = @dns_get_record($name, DNS_TXT);
            if ($records === false) {
                $this->cache[$key] = null;
            } else {
                $txt = [];
                foreach ($records as $record) {
                    if (isset($record['entries']) && is_array($record['entries'])) {
                        $txt[] = implode('', $record['entries']);
                    } elseif (isset($record['txt'])) {
                        $txt[] = (string)$record['txt'];
                    }
                }
                $this->cache[$key] = $txt;
            }
        }
        return $this->cache[$key];
    }

    public function cname(string $name): ?string
    {
        $key = 'cname:' . $name;
        if (!array_key_exists($key, $this->cache)) {
            $records = @dns_get_record($name, DNS_CNAME);
            $this->cache[$key] = is_array($records) && isset($records[0]['target']) ? (string)$records[0]['target'] : null;
        }
        return $this->cache[$key];
    }

    public function hasA(string $name): bool
    {
        $records = @dns_get_record($name, DNS_A);
        return is_array($records) && count($records) > 0;
    }

}
