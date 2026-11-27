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
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $status
 * @property int|null $id_customer
 * @property string $token
 * @property string $source
 * @property \Cake\I18n\DateTime|null $consent_at
 * @property string|null $consent_ip
 * @property string|null $consent_text
 * @property \Cake\I18n\DateTime|null $confirmation_sent_at
 * @property \Cake\I18n\DateTime|null $confirmed_at
 * @property \Cake\I18n\DateTime|null $unsubscribed_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class NewsletterSubscriber extends AppEntity
{

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_BOUNCED = 'bounced';

    public const SOURCE_HOME = 'startseite';
    public const SOURCE_CUSTOMER_ACCOUNT = 'kundenkonto';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_ADMIN = 'admin';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = [
        'token',
    ];

    public static function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string)$email));
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

}
