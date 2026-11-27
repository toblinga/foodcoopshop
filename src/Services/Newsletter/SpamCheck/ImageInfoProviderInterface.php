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
interface ImageInfoProviderInterface
{

    /**
     * Dateigroesse und Abmessungen eines Bildes oder null, wenn unbekannt.
     *
     * @return array{bytes: int, width: int|null, height: int|null}|null
     */
    public function info(string $src): ?array;

}
