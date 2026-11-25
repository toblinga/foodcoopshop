<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddBlogCategories extends BaseMigration
{
    public function up(): void
    {
        $this->table('fcs_blog_categories', ['id' => false, 'primary_key' => ['id_blog_category']])
            ->addColumn('id_blog_category', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('parent_id', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('icon', 'string', ['limit' => 60, 'default' => null, 'null' => true])
            ->addColumn('position', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('active', 'integer', ['default' => 1, 'null' => false])
            ->addIndex(['parent_id'], ['name' => 'idx_bc_parent'])
            ->create();

        $this->table('fcs_blog_post_categories', ['id' => false, 'primary_key' => ['id_blog_post', 'id_blog_category']])
            ->addColumn('id_blog_post', 'integer', ['null' => false])
            ->addColumn('id_blog_category', 'integer', ['null' => false])
            ->addIndex(['id_blog_category'], ['name' => 'idx_bpc_category'])
            ->create();

        // Kategorien wie auf lieblingsspeis.at (WordPress-IDs), Reihenfolge nach Anzahl Beitraege
        $this->execute("INSERT INTO fcs_blog_categories (id_blog_category, parent_id, name, icon, position, active) VALUES
            (28, 0, 'Rezept', 'fa-utensils', 1, 1),
            (1, 0, 'Allgemein', 'fa-newspaper', 2, 1),
            (44, 0, 'Fachbeiträge', 'fa-book-open', 3, 1),
            (4, 0, 'Produzentinnen', 'fa-seedling', 4, 1),
            (2, 0, 'Produkt', 'fa-box', 5, 1),
            (242, 0, 'LieblingsSpeisereise', 'fa-bicycle', 6, 1),
            (42, 0, 'Über uns', 'fa-circle-info', 7, 1)");
    }

    public function down(): void
    {
        $this->table('fcs_blog_post_categories')->drop()->save();
        $this->table('fcs_blog_categories')->drop()->save();
    }
}
