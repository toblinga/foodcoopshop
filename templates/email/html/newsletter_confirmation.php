<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Double-Opt-in-Mail.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\NewsletterSubscriber $subscriber
 * @var string $confirmUrl
 * @var int $validDays
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
use Cake\Core\Configure;

$themeColor = (string)(Configure::read('app.customThemeMainColor') ?: '#719f41');
?>
<?php echo $this->element('email/tableHead'); ?>
    <tbody>
        <tr>
            <td style="font-size:15px;line-height:1.5;">
                <p><?php echo $subscriber->first_name ? 'Hallo ' . h($subscriber->first_name) . ',' : 'Hallo,'; ?></p>
                <p>du hast dich für den Newsletter von <?php echo h((string)Configure::read('appDb.FCS_APP_NAME')); ?> angemeldet. Bitte bestätige deine Anmeldung mit einem Klick:</p>
                <p style="padding:10px 0;">
                    <a href="<?php echo h($confirmUrl); ?>" style="display:inline-block;background:<?php echo h($themeColor); ?>;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:24px;font-weight:bold;">Anmeldung bestätigen</a>
                </p>
                <p style="font-size:13px;color:#555;">Falls der Knopf nicht funktioniert, kopiere diesen Link in deinen Browser:<br />
                    <a href="<?php echo h($confirmUrl); ?>"><?php echo h($confirmUrl); ?></a></p>
                <p style="font-size:13px;color:#555;">Der Link ist <?php echo (int)$validDays; ?> Tage gültig. Wenn du dich nicht angemeldet hast, ignoriere diese E-Mail einfach. Ohne Bestätigung bekommst du keinen Newsletter.</p>
            </td>
        </tr>
    </tbody>
<?php echo $this->element('email/tableFoot'); ?>
