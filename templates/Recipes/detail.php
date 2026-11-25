<?php
declare(strict_types=1);

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
use Cake\Core\Configure;

$this->element('addScript', ['script' =>
    Configure::read('app.jsNamespace').".Helper.init();"
]);
$hasImage = !empty($recipe->image) && file_exists(WWW_ROOT . 'files' . DS . 'lieblingsspeis' . DS . $recipe->image);
?>

<?php if ($hasImage) { ?>
    <div class="recipe-detail-hero">
        <img src="<?php echo h('/files/lieblingsspeis/' . rawurlencode($recipe->image)); ?>" alt="<?php echo h($recipe->title); ?>" />
    </div>
<?php } ?>

<h1><?php echo h($title_for_layout); ?></h1>

<?php if (!empty($recipe->short_description)) { ?>
    <p><b><?php echo h($recipe->short_description); ?></b></p>
<?php } ?>

<?php
$details = [];
if (!empty($recipe->servings)) {
    $details[] = '<b>Portionen:</b> ' . h($recipe->servings);
}
if (!empty($recipe->prep_time)) {
    $details[] = '<b>Vorbereitungszeit:</b> ' . h($recipe->prep_time);
}
if (!empty($recipe->cook_time)) {
    $details[] = '<b>Kochzeit:</b> ' . h($recipe->cook_time);
}
if (!empty($details)) {
    echo '<p>' . implode(' &middot; ', $details) . '</p>';
}
?>

<div class="content">
    <?php echo $recipe->content; ?>
</div>

<p><i><br /><?php echo __('Modified_on') . ' ' . $recipe->modified->i18nFormat(Configure::read('app.timeHelper')->getI18Format('DateNTimeShort')); ?></i></p>
<div class="sc"></div>
