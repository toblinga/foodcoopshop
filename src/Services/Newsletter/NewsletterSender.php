<?php
declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Model\Entity\NewsletterCampaign;
use Cake\Core\Configure;
use Cake\Mailer\Mailer;
use Cake\Validation\Validation;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Absender für Newsletter-Mails (Kampagne > Einstellung FCS_NEWSLETTER_SENDER_* > Standard-Absender "Email.default.from").
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterSender
{

    /**
     * @return array<string, string> [email => name]
     */
    public static function getFrom(?NewsletterCampaign $campaign = null): array
    {
        $email = '';
        $name = '';
        if ($campaign !== null) {
            $email = trim((string)$campaign->sender_email);
            $name = trim((string)$campaign->sender_name);
        }
        if ($email === '') {
            $email = trim((string)Configure::read('appDb.FCS_NEWSLETTER_SENDER_EMAIL'));
        }
        if ($name === '') {
            $name = trim((string)Configure::read('appDb.FCS_NEWSLETTER_SENDER_NAME'));
        }

        $defaultFrom = Mailer::getConfig('default')['from'] ?? [];
        if (is_string($defaultFrom)) {
            $defaultFrom = [$defaultFrom => $defaultFrom];
        }
        if ($email === '' || !Validation::email($email)) {
            $email = (string)array_key_first($defaultFrom);
            if ($name === '') {
                $name = (string)($defaultFrom[$email] ?? '');
            }
        }
        if ($email === '') {
            // kein Standard-Absender konfiguriert: Adresse der Foodcoop
            $email = (string)Configure::read('appDb.FCS_APP_EMAIL');
        }
        if ($name === '') {
            $name = (string)Configure::read('appDb.FCS_APP_NAME');
        }
        return [$email => $name];
    }

    public static function applyToMailer(Mailer $mailer, ?NewsletterCampaign $campaign = null): void
    {
        $from = self::getFrom($campaign);
        $email = (string)array_key_first($from);
        if ($email !== '') {
            $mailer->setFrom($email, $from[$email]);
        }
    }

}
