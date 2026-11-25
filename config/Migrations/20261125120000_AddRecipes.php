<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddRecipes extends BaseMigration
{
    public function up(): void
    {
        $this->table('fcs_recipes', ['id' => false, 'primary_key' => ['id_recipe']])
            ->addColumn('id_recipe', 'integer', [
                'identity' => true,
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('title', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('slug', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('short_description', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('content', 'text', [
                'default' => null,
                'limit' => 16777215,
                'null' => true,
            ])
            ->addColumn('image', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('servings', 'string', [
                'default' => null,
                'limit' => 50,
                'null' => true,
            ])
            ->addColumn('prep_time', 'string', [
                'default' => null,
                'limit' => 50,
                'null' => true,
            ])
            ->addColumn('cook_time', 'string', [
                'default' => null,
                'limit' => 50,
                'null' => true,
            ])
            ->addColumn('active', 'tinyinteger', [
                'default' => '1',
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('source_wp_id', 'integer', [
                'default' => null,
                'limit' => null,
                'null' => true,
                'signed' => false,
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'null' => false,
            ])
            ->addIndex(['source_wp_id'], [
                'name' => 'idx_recipes_source_wp_id',
                'unique' => true,
            ])
            ->addIndex(['slug'], [
                'name' => 'idx_recipes_slug',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('fcs_recipes')->drop()->save();
    }
}
