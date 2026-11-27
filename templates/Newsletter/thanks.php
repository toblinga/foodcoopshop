<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Gleiche Seite für neue, offene und bereits aktive Adressen (keine Auskunft nach außen).
 *
 * @var \App\View\AppView $this
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
use App\Services\Newsletter\NewsletterSubscriberService;
use Cake\Core\Configure;
$this->element('addScript', ['script' =>
    Configure::read('app.jsNamespace') . ".Helper.init();"
]);
?>
<div class="newsletter-page newsletter-message">
    <h1><?php echo h($title_for_layout); ?></h1>
    <p>Danke für dein Interesse! Wenn die Adresse noch nicht angemeldet ist, bekommst du in den nächsten Minuten eine E-Mail mit einem Bestätigungslink.</p>
    <p>Erst wenn du auf den Link klickst, ist die Anmeldung abgeschlossen. Der Link ist <?php echo NewsletterSubscriberService::CONFIRMATION_VALID_DAYS; ?> Tage gültig. Schau bitte auch im Spam-Ordner nach.</p>
    <p><a class="btn btn-outline-light" href="/">Zur Startseite</a></p>
</div>
