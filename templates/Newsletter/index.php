<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @var \App\View\AppView $this
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
use Cake\Core\Configure;

$this->element('addScript', ['script' =>
    Configure::read('app.jsNamespace') . ".Helper.init();"
]);
?>
<div class="newsletter-page">
    <h1><?php echo h($title_for_layout); ?></h1>
    <p class="newsletter-page-lead">Melde dich an und erfahre als Erste:r von neuen Produkten, saisonalen Rezepten und unseren Terminen. Du kannst dich jederzeit mit einem Klick wieder abmelden.</p>
    <?php echo $this->element('Newsletter/signupForm', [
        'variant' => 'page',
        'errors' => $errors ?? [],
        'formData' => $formData ?? [],
        'consentText' => $consentText ?? null,
    ]); ?>
</div>
