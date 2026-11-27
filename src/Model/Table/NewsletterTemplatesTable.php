<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\NewsletterTemplate;
use Cake\Validation\Validator;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Wiederverwendbare Bausteine (type=block) und Vorlagen (type=template) für den Editor (Phase 2).
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 *
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\NewsletterTemplate>
 */
class NewsletterTemplatesTable extends AppTable
{

    public function initialize(array $config): void
    {
        $this->setTable('newsletter_templates');
        parent::initialize($config);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('name', __('Please_enter_a_name.'));
        $validator->inList('type', [NewsletterTemplate::TYPE_BLOCK, NewsletterTemplate::TYPE_TEMPLATE]);
        return $validator;
    }

}
