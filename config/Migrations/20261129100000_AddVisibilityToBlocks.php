<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddVisibilityToBlocks extends BaseMigration
{
    public function up(): void
    {
        // both = eingeloggte und nicht eingeloggte Nutzer, logged_in / logged_out = nur die jeweilige Gruppe
        $this->table('fcs_blocks')
            ->addColumn('visibility', 'string', [
                'default' => 'both',
                'limit' => 20,
                'null' => false,
                'after' => 'active',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('fcs_blocks')
            ->removeColumn('visibility')
            ->update();
    }
}
