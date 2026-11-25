<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Component\StringComponent;
use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;

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
class RecipesController extends FrontendController
{

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated([
            'index',
            'detail',
        ]);
    }

    public function index(): void
    {
        $recipesTable = TableRegistry::getTableLocator()->get('Recipes');
        $recipes = $recipesTable->findActiveRecipes();
        $this->set('recipes', $recipes);
        $this->set('title_for_layout', 'Rezepte');
    }

    public function detail(): ?Response
    {
        $recipeId = (int) $this->getRequest()->getParam('idAndSlug');

        $recipesTable = TableRegistry::getTableLocator()->get('Recipes');
        $recipe = $recipesTable->find('all', conditions: [
            'Recipes.active' => APP_ON,
            'Recipes.id_recipe' => $recipeId,
        ])->first();

        if (empty($recipe)) {
            throw new RecordNotFoundException('recipe not found');
        }

        // Nur bei falschem Slug einmal auf die kanonische URL umleiten.
        // Die kanonische URL nutzt dieselbe slugify()-Funktion, daher entsteht keine Schleife.
        $correctSlug = StringComponent::slugify($recipe->title);
        $givenSlug = StringComponent::removeIdFromSlug((string) $this->getRequest()->getParam('idAndSlug'));
        if ($correctSlug != $givenSlug) {
            return $this->redirect('/rezepte/' . $recipeId . '-' . $correctSlug);
        }

        $this->set('recipe', $recipe);
        $this->set('title_for_layout', $recipe->title);
        return null;
    }
}
