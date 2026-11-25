<?php
declare(strict_types=1);

$this->element('addScript', ['script' =>
    \Cake\Core\Configure::read('app.jsNamespace').".Helper.init();"
]);

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
use App\Controller\Component\StringComponent;
?>

<h1><?php echo h($title_for_layout); ?></h1>

<div class="recipes-grid">
<?php foreach ($recipes as $recipe) {
    $recipeUrl = '/rezepte/' . $recipe->id_recipe . '-' . StringComponent::slugify($recipe->title);
    $hasImage = !empty($recipe->image) && file_exists(WWW_ROOT . 'files' . DS . 'lieblingsspeis' . DS . $recipe->image);
    ?>
    <article class="recipe-card">
        <?php if ($hasImage) { ?>
            <a href="<?php echo h($recipeUrl); ?>">
                <img src="<?php echo h('/files/lieblingsspeis/' . rawurlencode($recipe->image)); ?>" alt="<?php echo h($recipe->title); ?>" loading="lazy" />
            </a>
        <?php } ?>
        <div class="recipe-card-body">
            <h3>
                <?php echo $this->Html->link(h($recipe->title), $recipeUrl); ?>
            </h3>
            <?php if (!empty($recipe->short_description)) { ?>
                <p><?php echo h($recipe->short_description); ?></p>
            <?php } ?>
        </div>
    </article>
<?php } ?>
</div>
