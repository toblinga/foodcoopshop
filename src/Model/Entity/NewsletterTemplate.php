<?php
declare(strict_types=1);

namespace App\Model\Entity;

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
 * @property int $id
 * @property string $name
 * @property string $type block|template
 * @property string|null $blocks JSON (gleiches Format wie NewsletterCampaign::$blocks)
 */
class NewsletterTemplate extends AppEntity
{

    public const TYPE_BLOCK = 'block';
    public const TYPE_TEMPLATE = 'template';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

}
