<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddBlogPostMappings extends BaseMigration
{
    public function up(): void
    {
        $this->table('fcs_blog_post_manufacturers', ['id' => false, 'primary_key' => ['id_blog_post', 'id_manufacturer']])
            ->addColumn('id_blog_post', 'integer', ['null' => false])
            ->addColumn('id_manufacturer', 'integer', ['null' => false])
            ->addIndex(['id_manufacturer'], ['name' => 'idx_bpm_manufacturer'])
            ->create();

        $this->table('fcs_blog_post_products', ['id' => false, 'primary_key' => ['id_blog_post', 'id_product']])
            ->addColumn('id_blog_post', 'integer', ['null' => false])
            ->addColumn('id_product', 'integer', ['null' => false])
            ->addIndex(['id_product'], ['name' => 'idx_bpp_product'])
            ->create();

        // bestehende Lieferanten-Zuordnung uebernehmen
        $this->execute(
            'INSERT IGNORE INTO fcs_blog_post_manufacturers (id_blog_post, id_manufacturer)
             SELECT id_blog_post, id_manufacturer FROM fcs_blog_posts WHERE id_manufacturer > 0'
        );
    }

    public function down(): void
    {
        $this->table('fcs_blog_post_products')->drop()->save();
        $this->table('fcs_blog_post_manufacturers')->drop()->save();
    }
}
