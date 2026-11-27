<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\NewsletterCampaign;
use Cake\Validation\Validator;

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
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\NewsletterCampaign>
 */
class NewsletterCampaignsTable extends AppTable
{

    public function initialize(array $config): void
    {
        $this->setTable('newsletter_campaigns');
        parent::initialize($config);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->hasMany('NewsletterCampaignRecipients', [
            'foreignKey' => 'campaign_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('subject', __('Please_enter_a_title.'));
        $validator->maxLength('subject', 255);
        $validator->allowEmptyString('preheader');
        $validator->maxLength('preheader', 255);
        $validator->allowEmptyString('sender_email');
        $validator->email('sender_email', false);
        $validator->allowEmptyString('sender_name');
        $validator->inList('status', [
            NewsletterCampaign::STATUS_DRAFT,
            NewsletterCampaign::STATUS_SENDING,
            NewsletterCampaign::STATUS_SENT,
        ]);
        $validator->add('blocks', 'validJson', [
            'rule' => function ($value) {
                return $value === null || $value === '' || is_array(json_decode((string)$value, true));
            },
            'message' => 'blocks muss gültiges JSON (Liste von Bausteinen) sein.',
        ]);
        return $validator;
    }

}
