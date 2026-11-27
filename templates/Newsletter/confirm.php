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
 * @var string $result
 * @var string $actionUrl
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
<div class="newsletter-page newsletter-message newsletter-confirm-<?php echo h($result); ?>">
    <?php if ($result === NewsletterSubscriberService::CONFIRM_PENDING) { ?>
        <h1>Bitte bestätige deine Anmeldung</h1>
        <p>Mit einem Klick auf den Button bestätigst du, dass du unseren Newsletter bekommen möchtest.</p>
        <form method="post" action="<?php echo h($actionUrl); ?>">
            <input type="hidden" name="_csrfToken" value="<?php echo h((string)$this->getRequest()->getAttribute('csrfToken')); ?>">
            <p><button type="submit" class="btn btn-success">Anmeldung bestätigen</button></p>
        </form>
    <?php } elseif (in_array($result, [NewsletterSubscriberService::CONFIRM_CONFIRMED, NewsletterSubscriberService::CONFIRM_ALREADY_ACTIVE], true)) { ?>
        <h1>Danke, deine Anmeldung ist bestätigt!</h1>
        <p>Du bekommst ab jetzt unseren Newsletter. Abmelden kannst du dich jederzeit über den Link am Ende jeder Ausgabe.</p>
    <?php } elseif ($result === NewsletterSubscriberService::CONFIRM_EXPIRED) { ?>
        <h1>Der Bestätigungslink ist abgelaufen</h1>
        <p>Bitte melde dich einfach noch einmal an, dann schicken wir dir einen neuen Link.</p>
        <p><a class="btn btn-success" href="/newsletter">Neu anmelden</a></p>
    <?php } else { ?>
        <h1>Dieser Link ist nicht gültig</h1>
        <p>Vielleicht wurde er schon verwendet oder unvollständig kopiert. Du kannst dich jederzeit neu anmelden.</p>
        <p><a class="btn btn-success" href="/newsletter">Zur Anmeldung</a></p>
    <?php } ?>
    <p><a class="btn btn-outline-light" href="/">Zur Startseite</a></p>
</div>
