<?php
declare(strict_types=1);

namespace App\Model\Table;

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
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\NewsletterCampaignRecipient>
 */
class NewsletterCampaignRecipientsTable extends AppTable
{

    public function initialize(array $config): void
    {
        $this->setTable('newsletter_campaign_recipients');
        parent::initialize($config);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('NewsletterCampaigns', [
            'foreignKey' => 'campaign_id',
        ]);
        $this->belongsTo('NewsletterSubscribers', [
            'foreignKey' => 'subscriber_id',
        ]);
    }

}
