<?php
declare(strict_types=1);

namespace App\Services\Newsletter;

use Cake\Core\Configure;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Webansicht ("Diese Mail im Browser ansehen"): öffentliche Adresse einer versendeten Kampagne.
 * Das Token ist an die Kampagnen-ID und das Security-Salt gebunden, damit niemand fremde
 * Kampagnen über zählende IDs aufrufen kann. Ausgeliefert wird nur, wenn die Kampagne versendet wurde.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterWebviewService
{

    public static function token(int $campaignId): string
    {
        return substr(hash_hmac('sha256', 'webansicht:' . $campaignId, (string)Configure::read('Security.salt')), 0, 32);
    }

    public static function url(int $campaignId): string
    {
        return rtrim((string)Configure::read('App.fullBaseUrl'), '/') . '/newsletter/online/' . $campaignId . '/' . self::token($campaignId);
    }

    public static function isValid(int $campaignId, string $token): bool
    {
        return $campaignId > 0 && preg_match('/^[a-f0-9]{32}$/', $token) === 1 && hash_equals(self::token($campaignId), $token);
    }

}
