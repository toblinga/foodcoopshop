<?php
declare(strict_types=1);

namespace App\Test\TestCase\src\Services\Newsletter\SpamCheck;

use App\Services\Newsletter\SpamCheck\LinkProbeInterface;

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
final class FakeLinkProbe implements LinkProbeInterface
{

    /** @var list<string> */
    public array $calls = [];

    /**
     * @param array<string, int> $status URL => Status (Standard 200)
     */
    public function __construct(private array $status = [])
    {
    }

    public function status(string $url, int $timeout): int
    {
        $this->calls[] = $url;
        return $this->status[$url] ?? 200;
    }

}
