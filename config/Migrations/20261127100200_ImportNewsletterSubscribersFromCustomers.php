<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Übernimmt Kund:innen mit newsletter_enabled = 1 als aktive Abonnent:innen (Quelle kundenkonto).
 * Dasselbe macht `bin/cake newsletter sync_customers` (idempotent).
 */
class ImportNewsletterSubscribersFromCustomers extends BaseMigration
{
    public function up(): void
    {
        $customers = $this->fetchAll("SELECT id_customer, firstname, lastname, email FROM fcs_customer WHERE newsletter_enabled = 1 AND email IS NOT NULL AND email <> ''");
        $now = date('Y-m-d H:i:s');
        foreach ($customers as $customer) {
            $email = mb_strtolower(trim((string)$customer['email']));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $this->execute(
                'INSERT IGNORE INTO fcs_newsletter_subscribers (email, first_name, last_name, status, id_customer, token, source, consent_at, consent_text, confirmed_at, created, modified) '
                . "VALUES (?, ?, ?, 'active', ?, ?, 'kundenkonto', ?, ?, ?, ?, ?)",
                [
                    $email,
                    mb_substr((string)$customer['firstname'], 0, 100),
                    mb_substr((string)$customer['lastname'], 0, 100),
                    (int)$customer['id_customer'],
                    bin2hex(random_bytes(32)),
                    $now,
                    'Übernahme aus dem Kundenkonto (newsletter_enabled = 1)',
                    $now,
                    $now,
                    $now,
                ],
            );
        }
    }

    public function down(): void
    {
    }
}
