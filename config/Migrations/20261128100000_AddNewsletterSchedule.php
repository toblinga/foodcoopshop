<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Geplanter Versand: Entwurf bekommt einen Termin (scheduled_at, Ortszeit der Installation).
 * bin/cake newsletter send_due startet fällige Kampagnen (Cron, z. B. alle 5 Minuten).
 */
class AddNewsletterSchedule extends BaseMigration
{
    public function up(): void
    {
        $this->table('fcs_newsletter_campaigns')
            ->addColumn('scheduled_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'sent_at'])
            ->addIndex(['status', 'scheduled_at'], ['name' => 'idx_newsletter_campaigns_schedule'])
            ->update();
    }

    public function down(): void
    {
        $this->table('fcs_newsletter_campaigns')
            ->removeIndexByName('idx_newsletter_campaigns_schedule')
            ->removeColumn('scheduled_at')
            ->update();
    }
}
