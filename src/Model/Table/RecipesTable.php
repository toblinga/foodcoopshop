<?php
declare(strict_types=1);
namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
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
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\Recipe>
 */
class RecipesTable extends AppTable
{

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setPrimaryKey('id_recipe');
        $this->addBehavior('Timestamp');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('title', __('Please_enter_a_title.'));
        $validator->allowEmptyString('content');
        $validator->allowEmptyString('short_description');
        $validator->maxLength('short_description', 255, __('Please_enter_max_{0}_characters.', [255]));
        return $validator;
    }

    /**
     * Liefert nur aktive Rezepte, neueste zuerst.
     *
     * @return SelectQuery<\App\Model\Entity\Recipe>
     */
    public function findActiveRecipes(): SelectQuery
    {
        return $this->find('all',
            conditions: [
                $this->aliasField('active') => APP_ON,
            ],
            order: [
                $this->aliasField('created') => 'DESC',
                $this->aliasField('id_recipe') => 'DESC',
            ],
        );
    }
}
