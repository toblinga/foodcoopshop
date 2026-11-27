<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Newsletter-Anmeldeformular. Wird auf der Startseite (variant=teaser) und unter /newsletter (variant=page) genutzt.
 *
 * @var \App\View\AppView $this
 * @var string|null $variant teaser|page
 * @var array<string, string>|null $errors
 * @var array<string, mixed>|null $formData
 * @var string|null $consentText
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */

use App\Controller\NewsletterController;
use App\Services\Newsletter\NewsletterSubscriberService;
use Cake\Core\Configure;

$variant = ($variant ?? 'teaser') === 'page' ? 'page' : 'teaser';
$errors = $errors ?? [];
$formData = $formData ?? [];
$consentText = $consentText ?? NewsletterSubscriberService::getConsentText();
$idPrefix = 'newsletter-' . $variant;
$headingFont = (string) Configure::read('appDb.FCS_FONT_BLOCK_HEADING');
$headingClass = $headingFont !== '' ? 'font-' . $headingFont : '';
?>
<section class="newsletter-signup newsletter-signup-<?php echo $variant; ?>" id="<?php echo $idPrefix; ?>" aria-labelledby="<?php echo $idPrefix; ?>-heading">
    <div class="newsletter-signup-inner">
        <div class="newsletter-signup-intro">
            <?php if ($variant === 'teaser') { ?>
                <h2 id="<?php echo $idPrefix; ?>-heading" class="<?php echo h($headingClass); ?>">Newsletter</h2>
            <?php } else { ?>
                <h2 id="<?php echo $idPrefix; ?>-heading" class="visually-hidden">Anmeldung</h2>
            <?php } ?>
            <p>Neuigkeiten aus der <?php echo h((string)Configure::read('appDb.FCS_APP_NAME')); ?>: neue Produkte, Rezepte und Termine direkt in dein Postfach.</p>
        </div>
        <?php
        echo $this->Form->create(null, [
            'url' => '/newsletter#newsletter-page',
            'class' => 'newsletter-signup-form',
            'novalidate' => true,
            'id' => $idPrefix . '-form',
        ]);
        echo $this->Form->hidden('Newsletter.form', ['value' => $variant]);
        ?>
        <div class="newsletter-fields">
            <div class="newsletter-field newsletter-field-email<?php echo isset($errors['email']) ? ' has-error' : ''; ?>">
                <label for="<?php echo $idPrefix; ?>-email">E-Mail-Adresse</label>
                <?php echo $this->Form->email('Newsletter.email', [
                    'id' => $idPrefix . '-email',
                    'required' => true,
                    'autocomplete' => 'email',
                    'inputmode' => 'email',
                    // kein Beispiel mit @: der Ausgabefilter der FCS würde es als Adresse verschleiern
                    'placeholder' => 'deine E-Mail-Adresse',
                    'value' => (string)($formData['email'] ?? ''),
                    'aria-invalid' => isset($errors['email']) ? 'true' : 'false',
                ]); ?>
                <?php if (isset($errors['email'])) { ?>
                    <div class="newsletter-error" role="alert"><?php echo h($errors['email']); ?></div>
                <?php } ?>
            </div>
            <div class="newsletter-field newsletter-field-first-name<?php echo isset($errors['first_name']) ? ' has-error' : ''; ?>">
                <label for="<?php echo $idPrefix; ?>-first-name">Vorname <span class="optional">(optional)</span></label>
                <?php echo $this->Form->text('Newsletter.first_name', [
                    'id' => $idPrefix . '-first-name',
                    'autocomplete' => 'given-name',
                    'maxlength' => 100,
                    'value' => (string)($formData['first_name'] ?? ''),
                ]); ?>
                <?php if (isset($errors['first_name'])) { ?>
                    <div class="newsletter-error" role="alert"><?php echo h($errors['first_name']); ?></div>
                <?php } ?>
            </div>
        </div>
        <div class="newsletter-hp" aria-hidden="true">
            <label for="<?php echo $idPrefix; ?>-website">Bitte leer lassen</label>
            <?php echo $this->Form->text('Newsletter.' . NewsletterController::HONEYPOT_FIELD, [
                'id' => $idPrefix . '-website',
                'tabindex' => '-1',
                'autocomplete' => 'off',
                'value' => '',
            ]); ?>
        </div>
        <div class="newsletter-consent<?php echo isset($errors['consent']) ? ' has-error' : ''; ?>">
            <?php
            $consentCheckbox = $this->Form->checkbox('Newsletter.consent', [
                'id' => $idPrefix . '-consent',
                'checked' => !empty($formData['consent']),
                'required' => true,
            ]);
            echo is_array($consentCheckbox) ? implode('', $consentCheckbox) : $consentCheckbox;
            ?>
            <label for="<?php echo $idPrefix; ?>-consent">
                <?php echo h($consentText); ?>
                Mehr dazu in der <a href="<?php echo h($this->Slug->getPrivacyPolicy()); ?>" target="_blank">Datenschutzerklärung</a>.
            </label>
            <?php if (isset($errors['consent'])) { ?>
                <div class="newsletter-error" role="alert"><?php echo h($errors['consent']); ?></div>
            <?php } ?>
        </div>
        <div class="newsletter-actions">
            <button type="submit" class="btn btn-success">Anmelden</button>
            <span class="newsletter-hint">Du bekommst gleich eine E-Mail mit einem Bestätigungslink.</span>
        </div>
        <?php echo $this->Form->end(); ?>
    </div>
</section>
