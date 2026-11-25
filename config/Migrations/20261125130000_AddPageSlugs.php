<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPageSlugs extends BaseMigration
{
    public function up(): void
    {
        $this->table('fcs_pages')
            ->addColumn('slug', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
                'after' => 'title',
            ])
            ->addIndex(['slug'], ['name' => 'idx_pages_slug'])
            ->update();
    }

    public function down(): void
    {
        $this->table('fcs_pages')
            ->removeIndexByName('idx_pages_slug')
            ->removeColumn('slug')
            ->update();
    }
}
