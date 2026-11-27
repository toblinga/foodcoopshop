<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Gleiche Seite für gültige und unbekannte Tokens (keine Auskunft nach außen).
 *
 * @var \App\View\AppView $this
 * @var bool $done
 * @var string $actionUrl
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
<?php if (!$done) { ?>
<div class="newsletter-page newsletter-message">
    <h1>Vom Newsletter abmelden?</h1>
    <p>Mit einem Klick auf den Button bekommst du ab sofort keinen Newsletter mehr von uns.</p>
    <form method="post" action="<?php echo h($actionUrl); ?>">
        <input type="hidden" name="_csrfToken" value="<?php echo h((string)$this->getRequest()->getAttribute('csrfToken')); ?>">
        <p><button type="submit" class="btn btn-success">Jetzt abmelden</button></p>
    </form>
    <p><a class="btn btn-outline-light" href="/">Zur Startseite</a></p>
</div>
<?php } else { ?>
<div class="newsletter-page newsletter-message">
    <h1>Du bist abgemeldet</h1>
    <p>Du bekommst ab jetzt keinen Newsletter mehr von uns. Schade, dass du gehst!</p>
    <p>Falls das ein Versehen war, kannst du dich jederzeit wieder anmelden.</p>
    <p>
        <a class="btn btn-success" href="/newsletter">Wieder anmelden</a>
        <a class="btn btn-outline-light" href="/">Zur Startseite</a>
    </p>
</div>
<?php } ?>
