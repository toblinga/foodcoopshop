<?php
declare(strict_types=1);

namespace App\Services;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\Mailer\TransportFactory;
use Cake\ORM\TableRegistry;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Zentrale Wahl des Mailwegs für ALLE Mails, die über die Queue gehen (AppEmailTask)
 * und für den Newsletter-Versand (NewsletterBatchTask).
 *
 * Einstellung FCS_MAIL_TRANSPORT:
 *  - own_smtp => EmailTransport "default" (eigener SMTP)
 *  - relay    => EmailTransport "relay" (externer Relay, in credentials.php / custom_config.php)
 * Ist "relay" gewählt, aber nicht konfiguriert, wird "default" genommen (mit Log-Eintrag).
 *
 * Drosselung: FCS_MAIL_MAX_PER_MINUTE begrenzt alle Mails (gleitendes 60-Sekunden-Fenster), 0 = unbegrenzt.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class MailTransportService
{

    public const MODE_OWN_SMTP = 'own_smtp';
    public const MODE_RELAY = 'relay';

    public const TRANSPORT_DEFAULT = 'default';
    public const TRANSPORT_RELAY = 'relay';

    /**
     * Liest eine Einstellung frisch aus der Datenbank. Queue-Worker laufen bis zu 6 Minuten und hätten sonst
     * noch den Wert vom Start (Configure appDb.*). Fallback: Configure.
     */
    public static function readSetting(string $name): mixed
    {
        try {
            $row = TableRegistry::getTableLocator()->get('Configurations')->find()
                ->select(['value'])
                ->where(['name' => $name, 'active' => APP_ON])
                ->disableHydration()
                ->first();
            if (is_array($row)) {
                return $row['value'];
            }
        } catch (\Throwable $e) {
            // z. B. Tabelle noch nicht migriert
        }
        return Configure::read('appDb.' . $name);
    }

    public static function getMode(): string
    {
        $mode = (string)self::readSetting('FCS_MAIL_TRANSPORT');
        return $mode === self::MODE_RELAY ? self::MODE_RELAY : self::MODE_OWN_SMTP;
    }

    /**
     * Name der EmailTransport-Konfiguration, über die gerade verschickt wird.
     */
    public static function getTransportName(): string
    {
        if (self::getMode() === self::MODE_RELAY) {
            if (TransportFactory::getConfig(self::TRANSPORT_RELAY) !== null) {
                return self::TRANSPORT_RELAY;
            }
            Log::warning('FCS_MAIL_TRANSPORT = relay, aber EmailTransport "relay" ist nicht konfiguriert. Es wird "default" verwendet.');
        }
        return self::TRANSPORT_DEFAULT;
    }

    public static function getMaxPerMinute(): int
    {
        return max(0, (int)self::readSetting('FCS_MAIL_MAX_PER_MINUTE'));
    }

    /**
     * Zählt alle Mails der letzten 60 Sekunden: erledigte AppEmail-Jobs plus verschickte Newsletter-Mails.
     */
    public static function countSentInLastMinute(): int
    {
        $since = DateTime::now()->subSeconds(60);
        $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
        $count = $queuedJobsTable->find()->where([
            'job_task' => 'AppEmail',
            'completed >=' => $since,
        ])->count();
        $recipientsTable = TableRegistry::getTableLocator()->get('NewsletterCampaignRecipients');
        $count += $recipientsTable->find()->where([
            'NewsletterCampaignRecipients.sent_at >=' => $since,
        ])->count();
        return $count;
    }

    /**
     * Wie viele Mails dürfen jetzt noch raus? null = unbegrenzt.
     */
    public static function getRemainingBudget(): ?int
    {
        $max = self::getMaxPerMinute();
        if ($max === 0) {
            return null;
        }
        return max(0, $max - self::countSentInLastMinute());
    }

    /**
     * Wartet (blockierend, im Queue-Worker) bis wieder eine Mail raus darf.
     * Gibt false zurück, wenn nach $maxWaitSeconds immer noch kein Platz frei ist (die Mail geht dann trotzdem).
     */
    public static function waitForSendSlot(int $maxWaitSeconds = 120, int $sleepSeconds = 5): bool
    {
        $waited = 0;
        while (true) {
            $budget = self::getRemainingBudget();
            if ($budget === null || $budget > 0) {
                return true;
            }
            if ($waited >= $maxWaitSeconds) {
                return false;
            }
            sleep($sleepSeconds);
            $waited += $sleepSeconds;
        }
    }

}
