<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Einstellungen für Newsletter und Mailweg:
 * - FCS_MAIL_TRANSPORT: own_smtp (EmailTransport "default") | relay (EmailTransport "relay"), gilt für ALLE Mails
 * - FCS_MAIL_MAX_PER_MINUTE: Obergrenze aller Mails pro Minute (Queue), 0 = unbegrenzt
 * - FCS_NEWSLETTER_SENDS_PER_MINUTE: Newsletter-Mails pro Minute
 * - FCS_NEWSLETTER_SENDER_NAME / _EMAIL: eigene Absenderadresse (leer = Standard-Absender)
 * - FCS_NEWSLETTER_CONSENT_TEXT: Einwilligungstext im Anmeldeformular (wird beim Abonnenten gespeichert)
 */
class AddConfigurationNewsletter extends BaseMigration
{
    public function up(): void
    {
        $consentText = 'Ja, ich möchte den Newsletter per E-Mail erhalten. Ich kann mich jederzeit über den Link in jeder Ausgabe wieder abmelden.';
        $rows = [
            ['FCS_MAIL_TRANSPORT', 'own_smtp', 'dropdown', 3405],
            ['FCS_MAIL_MAX_PER_MINUTE', '60', 'number', 3406],
            ['FCS_NEWSLETTER_SENDS_PER_MINUTE', '20', 'number', 3410],
            ['FCS_NEWSLETTER_SENDER_NAME', '', 'text', 3420],
            ['FCS_NEWSLETTER_SENDER_EMAIL', '', 'text', 3430],
            ['FCS_NEWSLETTER_CONSENT_TEXT', $consentText, 'textarea', 3440],
        ];
        foreach ($rows as $row) {
            $this->execute(
                'INSERT INTO fcs_configuration (name, active, value, type, position) VALUES (?, 1, ?, ?, ?)',
                [$row[0], $row[1], $row[2], $row[3]],
            );
        }
    }

    public function down(): void
    {
        $this->execute("DELETE FROM fcs_configuration WHERE name IN ('FCS_MAIL_TRANSPORT','FCS_MAIL_MAX_PER_MINUTE','FCS_NEWSLETTER_SENDS_PER_MINUTE','FCS_NEWSLETTER_SENDER_NAME','FCS_NEWSLETTER_SENDER_EMAIL','FCS_NEWSLETTER_CONSENT_TEXT')");
    }
}
