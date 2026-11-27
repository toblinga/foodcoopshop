<?php
declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Mailer\AppMailer;
use App\Model\Entity\Customer;
use App\Model\Entity\NewsletterSubscriber;
use App\Model\Table\NewsletterSubscribersTable;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validation;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Anmeldung (Double-Opt-in), Bestätigung, Abmeldung, Abgleich mit Kundenkonten, Import.
 * Öffentliche Rückmeldungen verraten nie, ob eine Adresse schon in der Liste steht.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterSubscriberService
{

    public const CONFIRMATION_VALID_DAYS = 7;
    public const RESEND_CONFIRMATION_AFTER_MINUTES = 60;

    public const RESULT_CONFIRMATION_SENT = 'confirmation_sent';
    public const RESULT_CONFIRMATION_THROTTLED = 'confirmation_throttled';
    public const RESULT_ALREADY_ACTIVE = 'already_active';
    public const RESULT_INVALID = 'invalid';

    public const CONFIRM_CONFIRMED = 'confirmed';
    public const CONFIRM_ALREADY_ACTIVE = 'already_active';
    public const CONFIRM_EXPIRED = 'expired';
    public const CONFIRM_INVALID = 'invalid';
    public const CONFIRM_PENDING = 'pending';

    protected NewsletterSubscribersTable $subscribersTable;

    public function __construct()
    {
        /** @var NewsletterSubscribersTable $table */
        $table = TableRegistry::getTableLocator()->get('NewsletterSubscribers');
        $this->subscribersTable = $table;
    }

    public static function isEnabled(): bool
    {
        return (bool)Configure::read('appDb.FCS_NEWSLETTER_ENABLED');
    }

    public static function getConfirmUrl(NewsletterSubscriber $subscriber): string
    {
        return Configure::read('App.fullBaseUrl') . '/newsletter/bestaetigen/' . $subscriber->token;
    }

    public static function getUnsubscribeUrl(NewsletterSubscriber $subscriber): string
    {
        return Configure::read('App.fullBaseUrl') . '/newsletter/abmelden/' . $subscriber->token;
    }

    public static function getConsentText(): string
    {
        $text = trim(strip_tags((string)Configure::read('appDb.FCS_NEWSLETTER_CONSENT_TEXT')));
        if ($text === '') {
            $text = 'Ja, ich möchte den Newsletter per E-Mail erhalten. Ich kann mich jederzeit über den Link in jeder Ausgabe wieder abmelden.';
        }
        return $text;
    }

    /**
     * Anmeldung mit Double-Opt-in. Legt höchstens eine Zeile je Adresse an.
     * Rückgabe ist nur für Tests und Protokolle gedacht, nicht für die öffentliche Anzeige.
     */
    public function subscribe(
        string $email,
        ?string $firstName,
        string $source,
        ?string $ip,
        ?string $consentText,
        ?int $customerId = null,
        ?string $lastName = null,
    ): string {
        $email = NewsletterSubscriber::normalizeEmail($email);
        if ($email === '' || !Validation::email($email) || mb_strlen($email) > 255) {
            return self::RESULT_INVALID;
        }

        $now = DateTime::now();
        $subscriber = $this->subscribersTable->findByEmail($email);

        if ($subscriber !== null && $subscriber->status === NewsletterSubscriber::STATUS_ACTIVE) {
            if ($customerId !== null && $subscriber->id_customer === null) {
                $subscriber->id_customer = $customerId;
                $this->subscribersTable->save($subscriber);
            }
            return self::RESULT_ALREADY_ACTIVE;
        }

        if ($subscriber !== null && $subscriber->status === NewsletterSubscriber::STATUS_PENDING) {
            if ($subscriber->confirmation_sent_at !== null
                && $subscriber->confirmation_sent_at->greaterThan($now->subMinutes(self::RESEND_CONFIRMATION_AFTER_MINUTES))) {
                return self::RESULT_CONFIRMATION_THROTTLED;
            }
            if ($this->isConfirmationExpired($subscriber)) {
                $subscriber->token = NewsletterSubscriber::generateToken();
            }
        }

        if ($subscriber === null) {
            $subscriber = $this->subscribersTable->newEntity([
                'email' => $email,
                'token' => NewsletterSubscriber::generateToken(),
            ]);
        } elseif ($subscriber->status !== NewsletterSubscriber::STATUS_PENDING) {
            // unsubscribed / bounced: neue Einwilligung, neuer Token
            $subscriber->token = NewsletterSubscriber::generateToken();
            $subscriber->confirmed_at = null;
        }

        $patch = [
            'status' => NewsletterSubscriber::STATUS_PENDING,
            'source' => $source,
            'consent_at' => $now,
            'consent_ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'consent_text' => $consentText,
        ];
        if ($firstName !== null && trim($firstName) !== '') {
            $patch['first_name'] = $firstName;
        }
        if ($lastName !== null && trim($lastName) !== '') {
            $patch['last_name'] = $lastName;
        }
        if ($customerId !== null && $subscriber->id_customer === null) {
            $patch['id_customer'] = $customerId;
        }
        $subscriber = $this->subscribersTable->patchEntity($subscriber, $patch);

        if (!$this->subscribersTable->save($subscriber)) {
            // z. B. gleichzeitige Anmeldung derselben Adresse (unique email): nichts verraten
            Log::warning('Newsletter-Anmeldung nicht gespeichert: ' . json_encode($subscriber->getErrors()));
            return self::RESULT_CONFIRMATION_THROTTLED;
        }

        $this->sendConfirmationMail($subscriber);
        $subscriber->confirmation_sent_at = $now;
        $this->subscribersTable->save($subscriber);

        return self::RESULT_CONFIRMATION_SENT;
    }

    public function isConfirmationExpired(NewsletterSubscriber $subscriber): bool
    {
        $sentAt = $subscriber->confirmation_sent_at ?? $subscriber->created;
        return $sentAt->lessThan(DateTime::now()->subDays(self::CONFIRMATION_VALID_DAYS));
    }

    public function sendConfirmationMail(NewsletterSubscriber $subscriber): void
    {
        $email = new AppMailer(false);
        $email->viewBuilder()->setTemplate('newsletter_confirmation');
        NewsletterSender::applyToMailer($email);
        $email->setTo($subscriber->email)
            ->setSubject('Bitte bestätige deine Newsletter-Anmeldung bei ' . Configure::read('appDb.FCS_APP_NAME'))
            ->setViewVars([
                'subscriber' => $subscriber,
                'confirmUrl' => self::getConfirmUrl($subscriber),
                'validDays' => self::CONFIRMATION_VALID_DAYS,
            ]);
        $email->addToQueue();
    }

    /**
     * Prüft einen Bestätigungslink, ohne etwas zu ändern. Die Bestätigungsseite (GET) ruft nur diese Methode auf,
     * damit Link-Scanner von Mailanbietern die Anmeldung nicht auslösen.
     */
    public function checkConfirmable(string $token): string
    {
        $subscriber = $this->subscribersTable->findByToken($token);
        if ($subscriber === null) {
            return self::CONFIRM_INVALID;
        }
        if ($subscriber->status === NewsletterSubscriber::STATUS_ACTIVE) {
            return self::CONFIRM_ALREADY_ACTIVE;
        }
        if ($subscriber->status !== NewsletterSubscriber::STATUS_PENDING) {
            return self::CONFIRM_INVALID;
        }
        if ($this->isConfirmationExpired($subscriber)) {
            return self::CONFIRM_EXPIRED;
        }
        return self::CONFIRM_PENDING;
    }

    /**
     * Bestätigt die Anmeldung. Wird nur per POST-Button aufgerufen (siehe NewsletterController::confirm()).
     */
    public function confirm(string $token): string
    {
        $state = $this->checkConfirmable($token);
        if ($state !== self::CONFIRM_PENDING) {
            return $state;
        }
        $subscriber = $this->subscribersTable->findByToken($token);
        if ($subscriber === null) {
            return self::CONFIRM_INVALID;
        }

        $subscriber->status = NewsletterSubscriber::STATUS_ACTIVE;
        $subscriber->confirmed_at = DateTime::now();
        $subscriber->unsubscribed_at = null;

        if ($subscriber->id_customer === null) {
            $customer = $this->findCustomerByEmail($subscriber->email);
            if ($customer !== null && !$this->isCustomerLinked((int)$customer->id_customer)) {
                $subscriber->id_customer = (int)$customer->id_customer;
            }
        }
        $this->subscribersTable->save($subscriber);

        if ($subscriber->id_customer !== null) {
            $this->setCustomerNewsletterFlag($subscriber->id_customer, true);
        }

        return self::CONFIRM_CONFIRMED;
    }

    /**
     * Abmeldung per Token (Link im Newsletter, One-Click-POST). false = Token unbekannt.
     */
    public function unsubscribeByToken(string $token): bool
    {
        $subscriber = $this->subscribersTable->findByToken($token);
        if ($subscriber === null) {
            return false;
        }
        $this->unsubscribe($subscriber);
        return true;
    }

    public function unsubscribe(NewsletterSubscriber $subscriber): void
    {
        if ($subscriber->status !== NewsletterSubscriber::STATUS_UNSUBSCRIBED) {
            $subscriber->status = NewsletterSubscriber::STATUS_UNSUBSCRIBED;
            $subscriber->unsubscribed_at = DateTime::now();
            $this->subscribersTable->save($subscriber);
        }
        if ($subscriber->id_customer !== null) {
            $this->setCustomerNewsletterFlag($subscriber->id_customer, false);
        }
    }

    /**
     * Abgleich nach dem Speichern eines Kundenkontos (siehe NewsletterCustomerListener).
     *
     * - gleiche Adresse schon in der Liste (getrimmt, klein): verknüpfen; ist sie aktiv, newsletter_enabled = 1 (ohne Mail)
     * - newsletter_enabled angehakt und kein aktiver Eintrag: pending + Double-Opt-in-Mail
     * - newsletter_enabled abgehakt (bestehendes Konto): Abmeldung
     * - E-Mail geändert: Adresse beim verknüpften Eintrag mitändern
     */
    public function syncFromCustomer(Customer $customer, bool $isNew): void
    {
        $customerId = (int)$customer->id_customer;
        if ($customerId === 0) {
            return;
        }
        $email = NewsletterSubscriber::normalizeEmail((string)$customer->email);
        $emailValid = $email !== '' && Validation::email($email);

        /** @var NewsletterSubscriber|null $linked */
        $linked = $this->subscribersTable->find()->where(['NewsletterSubscribers.id_customer' => $customerId])->first();

        if ($linked !== null && $emailValid && $linked->email !== $email) {
            $other = $this->subscribersTable->findByEmail($email);
            if ($other !== null && $other->id !== $linked->id) {
                // neue Adresse steht schon in der Liste: diese verknüpfen, alte Verknüpfung lösen
                $linked->id_customer = null;
                $this->subscribersTable->save($linked);
                if ($other->id_customer === null) {
                    $other->id_customer = $customerId;
                    $this->subscribersTable->save($other);
                }
                $linked = $other->id_customer === $customerId ? $other : null;
            } else {
                $linked->email = $email;
                $this->subscribersTable->save($linked);
            }
        }

        $justLinked = false;
        if ($linked === null && $emailValid) {
            $byEmail = $this->subscribersTable->findByEmail($email);
            if ($byEmail !== null && $byEmail->id_customer === null) {
                $byEmail->id_customer = $customerId;
                $this->subscribersTable->save($byEmail);
                $linked = $byEmail;
                $justLinked = true;
            }
        }

        $wantsNewsletter = (bool)$customer->newsletter_enabled;
        $flagChanged = !$isNew && $customer->isDirty('newsletter_enabled');
        $isActive = $linked !== null && $linked->status === NewsletterSubscriber::STATUS_ACTIVE;

        if ($flagChanged && !$wantsNewsletter) {
            if ($linked !== null && $linked->status !== NewsletterSubscriber::STATUS_UNSUBSCRIBED) {
                $this->unsubscribe($linked);
            }
            return;
        }

        if ($wantsNewsletter && ($isNew || $flagChanged)) {
            if (!$isActive && $emailValid && self::isEnabled()) {
                $this->subscribe(
                    $email,
                    (string)$customer->firstname,
                    NewsletterSubscriber::SOURCE_CUSTOMER_ACCOUNT,
                    null,
                    'Kundenkonto: „Newsletter erhalten“ angehakt. ' . self::getConsentText(),
                    $customerId,
                    (string)$customer->lastname,
                );
            }
            return;
        }

        if (($justLinked || $isNew) && $isActive && !$wantsNewsletter) {
            // erst Newsletter, dann Kundenkonto: Haken setzen, keine neue Bestätigung
            $this->setCustomerNewsletterFlag($customerId, true);
        }
    }

    public function unlinkCustomer(int $customerId): void
    {
        $this->subscribersTable->updateAll(['id_customer' => null], ['id_customer' => $customerId]);
    }

    /**
     * Übernimmt alle Kund:innen mit newsletter_enabled = 1 als aktive Abonnent:innen (idempotent).
     *
     * @return array{created: int, linked: int, skipped: int}
     */
    public function importCustomersWithNewsletterEnabled(): array
    {
        $result = ['created' => 0, 'linked' => 0, 'skipped' => 0];
        $customersTable = TableRegistry::getTableLocator()->get('Customers');
        $customers = $customersTable->find()
            ->select(['id_customer', 'firstname', 'lastname', 'email'])
            ->where(['newsletter_enabled' => 1]);
        foreach ($customers as $customer) {
            $email = NewsletterSubscriber::normalizeEmail((string)$customer->email);
            if ($email === '' || !Validation::email($email)) {
                $result['skipped']++;
                continue;
            }
            $subscriber = $this->subscribersTable->findByEmail($email);
            if ($subscriber !== null) {
                if ($subscriber->id_customer === null && !$this->isCustomerLinked((int)$customer->id_customer)) {
                    $subscriber->id_customer = (int)$customer->id_customer;
                    $this->subscribersTable->save($subscriber);
                    $result['linked']++;
                } else {
                    $result['skipped']++;
                }
                continue;
            }
            $now = DateTime::now();
            $entity = $this->subscribersTable->newEntity([
                'email' => $email,
                'first_name' => $customer->firstname,
                'last_name' => $customer->lastname,
                'status' => NewsletterSubscriber::STATUS_ACTIVE,
                'id_customer' => (int)$customer->id_customer,
                'token' => NewsletterSubscriber::generateToken(),
                'source' => NewsletterSubscriber::SOURCE_CUSTOMER_ACCOUNT,
                'consent_at' => $now,
                'consent_text' => 'Übernahme aus dem Kundenkonto (newsletter_enabled = 1)',
                'confirmed_at' => $now,
            ]);
            if ($this->subscribersTable->save($entity)) {
                $result['created']++;
            } else {
                $result['skipped']++;
            }
        }
        return $result;
    }

    /**
     * Import (z. B. rapidmail-CSV in Phase 2): legt aktive Abonnent:innen ohne Bestätigungsmail an.
     * Bestehende Adressen werden nicht verändert (keine Doppelten, keine Reaktivierung Abgemeldeter).
     *
     * @param array{email: string, first_name?: string|null, last_name?: string|null, consent_at?: DateTime|null, consent_text?: string|null, consent_ip?: string|null, source?: string} $row
     */
    public function importSubscriber(array $row): string
    {
        $email = NewsletterSubscriber::normalizeEmail($row['email']);
        if ($email === '' || !Validation::email($email)) {
            return self::RESULT_INVALID;
        }
        if ($this->subscribersTable->findByEmail($email) !== null) {
            return 'exists';
        }
        $now = DateTime::now();
        $customer = $this->findCustomerByEmail($email);
        $entity = $this->subscribersTable->newEntity([
            'email' => $email,
            'first_name' => $row['first_name'] ?? null,
            'last_name' => $row['last_name'] ?? null,
            'status' => NewsletterSubscriber::STATUS_ACTIVE,
            'id_customer' => $customer !== null && !$this->isCustomerLinked((int)$customer->id_customer) ? (int)$customer->id_customer : null,
            'token' => NewsletterSubscriber::generateToken(),
            'source' => $row['source'] ?? NewsletterSubscriber::SOURCE_IMPORT,
            'consent_at' => $row['consent_at'] ?? $now,
            'consent_ip' => $row['consent_ip'] ?? null,
            'consent_text' => $row['consent_text'] ?? 'Import',
            'confirmed_at' => $row['consent_at'] ?? $now,
        ]);
        if (!$this->subscribersTable->save($entity)) {
            return self::RESULT_INVALID;
        }
        if ($entity->id_customer !== null) {
            $this->setCustomerNewsletterFlag($entity->id_customer, true);
        }
        return 'created';
    }

    /**
     * Löscht unbestätigte Anmeldungen, deren Bestätigungslink abgelaufen ist.
     */
    public function purgeExpiredPending(): int
    {
        $limit = DateTime::now()->subDays(self::CONFIRMATION_VALID_DAYS);
        $expired = $this->subscribersTable->find()->where([
            'NewsletterSubscribers.status' => NewsletterSubscriber::STATUS_PENDING,
            'OR' => [
                ['NewsletterSubscribers.confirmation_sent_at <' => $limit],
                ['NewsletterSubscribers.confirmation_sent_at IS' => null, 'NewsletterSubscribers.created <' => $limit],
            ],
        ])->all();
        $count = 0;
        foreach ($expired as $subscriber) {
            if ($subscriber->id_customer !== null) {
                // Haken im Kundenkonto passt sonst nicht mehr zur Liste
                $this->setCustomerNewsletterFlag($subscriber->id_customer, false);
            }
            $this->subscribersTable->delete($subscriber);
            $count++;
        }
        return $count;
    }

    protected function setCustomerNewsletterFlag(int $customerId, bool $enabled): void
    {
        // updateAll löst keine Model-Events aus => keine Schleife mit dem Listener
        TableRegistry::getTableLocator()->get('Customers')->updateAll(
            ['newsletter_enabled' => $enabled ? 1 : 0],
            ['id_customer' => $customerId],
        );
    }

    protected function isCustomerLinked(int $customerId): bool
    {
        return $this->subscribersTable->exists(['NewsletterSubscribers.id_customer' => $customerId]);
    }

    protected function findCustomerByEmail(string $email): ?EntityInterface
    {
        $customersTable = TableRegistry::getTableLocator()->get('Customers');
        return $customersTable->find()
            ->select(['Customers.id_customer', 'Customers.email'])
            ->where(['LOWER(TRIM(Customers.email))' => $email])
            ->first();
    }

}
