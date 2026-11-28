<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Stellt sicher, dass die Spalte `attempts` in `queued_jobs` existiert.
 *
 * Das Queue-Plugin (dereuromark/cakephp-queue) erwartet diese Spalte, seine eigenen
 * Migrationen werden aber nur mit `bin/cake migrations migrate -p Queue` ausgeführt
 * (siehe composer.json post-update-cmd), nicht mit dem normalen Migrate der App.
 * Auf bestehenden Installationen fehlt die Spalte deshalb teilweise.
 *
 * Die Migration ist idempotent: Ist die Tabelle nicht vorhanden, wird übersprungen
 * (das Queue-Plugin legt sie dann selbst mit `attempts` an); ist die Spalte bereits
 * vorhanden, passiert nichts.
 */
class EnsureQueuedJobsAttempts extends BaseMigration
{
    public function up(): void
    {
        if (!$this->hasTable('queued_jobs')) {
            return;
        }

        $row = $this->fetchRow(
            "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'queued_jobs'
               AND COLUMN_NAME = 'attempts'"
        );

        if ($row && (int)$row['cnt'] > 0) {
            return;
        }

        $this->execute('ALTER TABLE queued_jobs ADD COLUMN attempts TINYINT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        // bewusst leer: Spalte wird nicht entfernt, um keine Queue-Daten zu verlieren
    }
}
