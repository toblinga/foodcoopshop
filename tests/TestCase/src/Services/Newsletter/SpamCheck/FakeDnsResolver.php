<?php
declare(strict_types=1);

namespace App\Test\TestCase\src\Services\Newsletter\SpamCheck;

use App\Services\Newsletter\SpamCheck\DnsResolverInterface;

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
final class FakeDnsResolver implements DnsResolverInterface
{

    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string, list<string>|null> $txt
     * @param array<string, string> $cname
     * @param list<string> $a
     */
    public function __construct(private array $txt = [], private array $cname = [], private array $a = [])
    {
    }

    public static function complete(string $domain): self
    {
        return new self([
            $domain => ['v=spf1 include:spf.brevo.com ~all'],
            '_dmarc.' . $domain => ['v=DMARC1; p=none; rua=mailto:dmarc@' . $domain],
        ], ['brevo1._domainkey.' . $domain => 'b1.example.dkim.brevo.com']);
    }

    public function txt(string $name): ?array
    {
        $this->queries[] = 'TXT ' . $name;
        return array_key_exists($name, $this->txt) ? $this->txt[$name] : [];
    }

    public function cname(string $name): ?string
    {
        $this->queries[] = 'CNAME ' . $name;
        return $this->cname[$name] ?? null;
    }

    public function hasA(string $name): bool
    {
        $this->queries[] = 'A ' . $name;
        return in_array($name, $this->a, true);
    }

}
