<?php
declare(strict_types=1);

namespace App\Test\TestCase\src\Services\Newsletter\SpamCheck;

use App\Services\Newsletter\SpamCheck\ImageInfoProviderInterface;

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
final class FakeImageInfoProvider implements ImageInfoProviderInterface
{

    /**
     * @param array<string, array{bytes: int, width: int|null, height: int|null}> $images Dateiname => Info
     */
    public function __construct(private array $images = [])
    {
    }

    public function info(string $src): ?array
    {
        return $this->images[basename((string)parse_url($src, PHP_URL_PATH))] ?? null;
    }

}
