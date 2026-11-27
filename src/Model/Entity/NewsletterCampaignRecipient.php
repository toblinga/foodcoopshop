<?php
declare(strict_types=1);

namespace App\Model\Entity;

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
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $subscriber_id
 * @property string $email
 * @property string $status
 * @property string|null $error
 * @property \Cake\I18n\DateTime|null $sent_at
 * @property \App\Model\Entity\NewsletterSubscriber|null $newsletter_subscriber
 */
class NewsletterCampaignRecipient extends AppEntity
{

    public const STATUS_QUEUED = 'queued';
    // atomar gesperrt, Mail wird gerade verschickt (verhindert Doppelversand)
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    // Abonnent:in war beim Versand nicht mehr aktiv
    public const STATUS_SKIPPED = 'skipped';

}
