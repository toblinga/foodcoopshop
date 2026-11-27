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
 */
interface DnsResolverInterface
{

    /**
     * TXT-Eintraege eines Namens. Leeres Array = keine Eintraege, null = Abfrage fehlgeschlagen.
     *
     * @return list<string>|null
     */
    public function txt(string $name): ?array;

    /**
     * Ziel eines CNAME oder null.
     */
    public function cname(string $name): ?string;

    /**
     * true, wenn der Name (A-Record) aufgeloest werden kann. Fuer DNS-Blocklisten.
     */
    public function hasA(string $name): bool;

}
