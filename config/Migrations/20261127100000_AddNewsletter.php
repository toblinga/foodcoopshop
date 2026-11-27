<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Newsletter (Phase 1): Abonnent:innen, Kampagnen, Versand-Empfänger, Vorlagen/Bausteine.
 * Die Liste ist bewusst von fcs_customer getrennt, Verknüpfung nur über id_customer.
 */
class AddNewsletter extends BaseMigration
{
    public function up(): void
    {
        $this->table('fcs_newsletter_subscribers', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            // immer getrimmt und kleingeschrieben gespeichert, dadurch eindeutig je Adresse
            ->addColumn('email', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('first_name', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('last_name', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            // pending | active | unsubscribed | bounced
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pending'])
            ->addColumn('id_customer', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('token', 'string', ['limit' => 64, 'null' => false])
            // startseite | kundenkonto | import | admin
            ->addColumn('source', 'string', ['limit' => 20, 'null' => false, 'default' => 'startseite'])
            ->addColumn('consent_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('consent_ip', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('consent_text', 'text', ['null' => true, 'default' => null])
            ->addColumn('confirmation_sent_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('confirmed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('unsubscribed_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['email'], ['name' => 'uq_newsletter_subscribers_email', 'unique' => true])
            ->addIndex(['token'], ['name' => 'uq_newsletter_subscribers_token', 'unique' => true])
            ->addIndex(['id_customer'], ['name' => 'idx_newsletter_subscribers_customer'])
            ->addIndex(['status'], ['name' => 'idx_newsletter_subscribers_status'])
            ->create();

        $this->table('fcs_newsletter_campaigns', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('preheader', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('blocks', 'text', ['limit' => 16777215, 'null' => true, 'default' => null])
            ->addColumn('html_cache', 'text', ['limit' => 16777215, 'null' => true, 'default' => null])
            // draft | sending | sent
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'draft'])
            ->addColumn('sender_name', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('sender_email', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('count_total', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('count_sent', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('count_failed', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('started_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('sent_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['status'], ['name' => 'idx_newsletter_campaigns_status'])
            ->create();

        $this->table('fcs_newsletter_campaign_recipients', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('campaign_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('subscriber_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 255, 'null' => false])
            // queued | sending | sent | failed | skipped
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'queued'])
            ->addColumn('error', 'text', ['null' => true, 'default' => null])
            ->addColumn('sent_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['campaign_id', 'subscriber_id'], ['name' => 'uq_newsletter_recipients_campaign_subscriber', 'unique' => true])
            ->addIndex(['campaign_id', 'status'], ['name' => 'idx_newsletter_recipients_campaign_status'])
            ->addIndex(['sent_at'], ['name' => 'idx_newsletter_recipients_sent_at'])
            ->create();

        $this->table('fcs_newsletter_templates', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            // block = wiederverwendbarer Baustein, template = ganze Vorlage
            ->addColumn('type', 'string', ['limit' => 20, 'null' => false, 'default' => 'template'])
            ->addColumn('blocks', 'text', ['limit' => 16777215, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['type'], ['name' => 'idx_newsletter_templates_type'])
            ->create();
    }

    public function down(): void
    {
        $this->table('fcs_newsletter_campaign_recipients')->drop()->save();
        $this->table('fcs_newsletter_campaigns')->drop()->save();
        $this->table('fcs_newsletter_subscribers')->drop()->save();
        $this->table('fcs_newsletter_templates')->drop()->save();
    }
}
