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
 * @property string $subject
 * @property string|null $preheader
 * @property string|null $blocks JSON, siehe NewsletterRenderService
 * @property string|null $html_cache
 * @property string $status
 * @property string|null $sender_name
 * @property string|null $sender_email
 * @property int $count_total
 * @property int $count_sent
 * @property int $count_failed
 * @property \Cake\I18n\DateTime|null $started_at
 * @property \Cake\I18n\DateTime|null $sent_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class NewsletterCampaign extends AppEntity
{

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function getBlocksArray(): array
    {
        $decoded = json_decode((string)$this->blocks, true);
        if (!is_array($decoded)) {
            return [];
        }
        $blocks = [];
        foreach ($decoded as $block) {
            if (is_array($block)) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

}
