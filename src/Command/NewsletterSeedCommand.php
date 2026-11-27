<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\NewsletterSubscriber;
use App\Model\Table\NewsletterSubscribersTable;
use App\Services\Newsletter\NewsletterSubscriberService;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Mailer\TransportFactory;
use Cake\ORM\TableRegistry;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Testdaten: bin/cake newsletter_seed [anzahl] (Standard 300), bin/cake newsletter_seed --reset
 * Nur Adressen unter example.test / example.org / example.com. Läuft nur, wenn debug an ist oder
 * alle Mail-Transporte auf Mailpit / Debug / localhost zeigen.
 * Seed-Zeilen sind am Einwilligungstext "[SEED]" erkennbar.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterSeedCommand extends AppCommand
{

    public const MARKER = '[SEED]';
    public const DOMAINS = ['example.test', 'example.org', 'example.com'];
    public const LINKED_CUSTOMERS = 20;

    private const FIRST_NAMES = ['Jürgen', 'Jörg', 'Günther', 'Bärbel', 'Käthe', 'Sören', 'Zoë', 'Anna', 'Lukas', 'Sophie', 'Maximilian', 'Lea', 'Elias', 'Hannah', 'Felix', 'Marie', 'Jonas', 'Lena', 'Paul', 'Emma', 'Tobias', 'Ute', 'Björn', 'Gerhard', 'Theresa', 'Ömer', 'Ayşe', 'Renée', 'José', 'Mia'];
    private const LAST_NAMES = ['Größ', 'Müller', 'Schäfer', 'Hölzl', 'Weiß', 'Bäck', 'Köhler', 'Gruber', 'Huber', 'Wagner', 'Pichler', 'Steiner', 'Moser', 'Mayr', 'Hofer', 'Leitner', 'Brunner', 'Fuchs', 'Eder', 'Fischer', 'Schwarz', 'Lang', 'Öztürk', 'Dvořák', 'Strauß', 'Kühn', 'Böhm', 'Lindner', 'Wimmer', 'Aigner'];

    public static function defaultName(): string
    {
        return 'newsletter_seed';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Erzeugt zufällige Newsletter-Abonnent:innen für Tests (nur Mailpit/Debug). --reset entfernt sie wieder.');
        $parser->addArgument('anzahl', ['required' => false, 'help' => 'Anzahl (Standard 300)']);
        $parser->addOption('reset', ['boolean' => true, 'help' => 'Seed-Daten entfernen']);
        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $unsafe = self::getUnsafeTransports();
        if (!Configure::read('debug') && !empty($unsafe)) {
            $io->err('Abbruch: Mailweg zeigt nicht auf Mailpit/Debug (' . implode(', ', $unsafe) . '). Seed nur in Testinstanzen.');
            return static::CODE_ERROR;
        }

        /** @var NewsletterSubscribersTable $table */
        $table = TableRegistry::getTableLocator()->get('NewsletterSubscribers');

        if ($args->getOption('reset')) {
            $ids = $table->find()->select(['id'])->where(['consent_text LIKE' => self::MARKER . '%'])->all()->extract('id')->toList();
            if (!empty($ids)) {
                TableRegistry::getTableLocator()->get('NewsletterCampaignRecipients')->deleteAll(['subscriber_id IN' => $ids]);
                $table->deleteAll(['id IN' => $ids]);
            }
            $io->out('Seed-Abonnent:innen entfernt: ' . count($ids));
            return static::CODE_SUCCESS;
        }

        $count = (int)($args->getArgument('anzahl') ?? 300);
        if ($count <= 0) {
            $count = 300;
        }

        $customerIds = $this->getCustomerIdsToLink();
        $stats = ['created' => 0, 'active' => 0, 'pending' => 0, 'pending_expired' => 0, 'unsubscribed' => 0, 'bounced' => 0, 'linked' => 0, 'duplicates_rejected' => 0, 'duplicates_merged' => 0];
        $sources = [NewsletterSubscriber::SOURCE_HOME, NewsletterSubscriber::SOURCE_CUSTOMER_ACCOUNT, NewsletterSubscriber::SOURCE_IMPORT, NewsletterSubscriber::SOURCE_ADMIN];
        $createdEmails = [];
        $runId = substr(bin2hex(random_bytes(3)), 0, 6);

        for ($i = 0; $i < $count; $i++) {
            $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $local = $this->toAscii($first) . '.' . $this->toAscii($last) . '.' . $runId . $i;
            $email = $this->mixCase($local) . '@' . $this->mixCase(self::DOMAINS[array_rand(self::DOMAINS)]);

            $roll = mt_rand(1, 100);
            $status = match (true) {
                $roll <= 80 => NewsletterSubscriber::STATUS_ACTIVE,
                $roll <= 90 => NewsletterSubscriber::STATUS_PENDING,
                $roll <= 97 => NewsletterSubscriber::STATUS_UNSUBSCRIBED,
                default => NewsletterSubscriber::STATUS_BOUNCED,
            };
            $consentAt = DateTime::now()->subMinutes(mt_rand(60, 3 * 365 * 24 * 60));
            $data = [
                'email' => $email, // beforeMarshal: trim + klein
                'first_name' => mt_rand(1, 10) <= 8 ? $first : null,
                'last_name' => mt_rand(1, 10) <= 6 ? $last : null,
                'status' => $status,
                'token' => NewsletterSubscriber::generateToken(),
                'source' => $sources[array_rand($sources)],
                'consent_at' => $consentAt,
                'consent_ip' => '192.0.2.' . mt_rand(1, 254), // TEST-NET-1
                'consent_text' => self::MARKER . ' ' . NewsletterSubscriberService::getConsentText(),
            ];
            if ($status === NewsletterSubscriber::STATUS_PENDING) {
                $expired = mt_rand(1, 3) === 1;
                $sentAt = $expired
                    ? DateTime::now()->subDays(mt_rand(NewsletterSubscriberService::CONFIRMATION_VALID_DAYS + 1, 60))
                    : DateTime::now()->subHours(mt_rand(2, 24 * (NewsletterSubscriberService::CONFIRMATION_VALID_DAYS - 1)));
                $data['consent_at'] = $sentAt;
                $data['confirmation_sent_at'] = $sentAt;
                $stats[$expired ? 'pending_expired' : 'pending']++;
            } else {
                $data['confirmed_at'] = $consentAt->addMinutes(mt_rand(1, 600));
                $data['confirmation_sent_at'] = $consentAt;
                $stats[$status]++;
            }
            if ($status === NewsletterSubscriber::STATUS_UNSUBSCRIBED) {
                $data['unsubscribed_at'] = DateTime::now()->subDays(mt_rand(1, 300));
            }
            if ($status === NewsletterSubscriber::STATUS_ACTIVE && !empty($customerIds)) {
                $data['id_customer'] = array_shift($customerIds);
                $stats['linked']++;
            }

            $entity = $table->newEntity($data);
            if ($table->save($entity)) {
                $stats['created']++;
                $createdEmails[] = $email;
            }
        }

        // Dubletten: gleiche Adresse in anderer Schreibweise (Groß/Klein, Leerzeichen) muss zurückgewiesen werden
        $service = new NewsletterSubscriberService();
        $duplicates = array_slice($createdEmails, 0, max(5, (int)round($count * 0.05)));
        foreach ($duplicates as $n => $email) {
            $variant = $n % 2 === 0 ? '  ' . strtoupper($email) . ' ' : ucwords(strtolower($email), '.@');
            $entity = $table->newEntity([
                'email' => $variant,
                'status' => NewsletterSubscriber::STATUS_ACTIVE,
                'token' => NewsletterSubscriber::generateToken(),
                'source' => NewsletterSubscriber::SOURCE_IMPORT,
                'consent_text' => self::MARKER . ' Dublette',
            ]);
            if (!$table->save($entity)) {
                $stats['duplicates_rejected']++;
            }
        }
        // und über den Import-Weg des Service: keine zweite Zeile, bestehender Eintrag bleibt
        foreach (array_slice($createdEmails, 0, 3) as $email) {
            if ($service->importSubscriber(['email' => ' ' . strtoupper($email), 'consent_text' => self::MARKER . ' Dublette']) === 'exists') {
                $stats['duplicates_merged']++;
            }
        }

        foreach ($stats as $key => $value) {
            $io->out(str_pad($key, 22) . $value);
        }
        $io->out('Gesamt in der Liste: ' . $table->find()->count());
        return static::CODE_SUCCESS;
    }

    /**
     * @return list<string> Transporte, die nicht auf Mailpit / Debug / localhost zeigen
     */
    public static function getUnsafeTransports(): array
    {
        $unsafe = [];
        foreach (TransportFactory::configured() as $name) {
            $config = (array)TransportFactory::getConfig($name);
            $className = (string)($config['className'] ?? '');
            $host = (string)($config['host'] ?? '');
            $isSafe = str_contains($className, 'Debug')
                || str_contains($className, 'TestEmailTransport')
                || preg_match('/mailpit|^localhost$|^127\.0\.0\.1$/i', $host);
            if (!$isSafe) {
                $unsafe[] = $name . ' (' . ($host !== '' ? $host : $className) . ')';
            }
        }
        return $unsafe;
    }

    /**
     * @return list<int>
     */
    private function getCustomerIdsToLink(): array
    {
        $linked = TableRegistry::getTableLocator()->get('NewsletterSubscribers')->find()
            ->select(['id_customer'])->where(['id_customer IS NOT' => null])->all()->extract('id_customer')->toList();
        $query = TableRegistry::getTableLocator()->get('Customers')->find()
            ->select(['Customers.id_customer'])
            ->orderBy(['Customers.id_customer' => 'DESC'])
            ->limit(self::LINKED_CUSTOMERS);
        if (!empty($linked)) {
            $query->where(['Customers.id_customer NOT IN' => $linked]);
        }
        return array_map('intval', $query->all()->extract('id_customer')->toList());
    }

    private function toAscii(string $value): string
    {
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', 'ë' => 'e', 'é' => 'e', 'ş' => 's', 'ř' => 'r']);
        return (string)preg_replace('/[^A-Za-z]/', '', $value);
    }

    private function mixCase(string $value): string
    {
        return match (mt_rand(1, 3)) {
            1 => strtolower($value),
            2 => ucfirst($value),
            default => strtoupper($value),
        };
    }

}
