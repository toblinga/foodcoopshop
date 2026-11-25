<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddBlogTags extends BaseMigration
{
    public function up(): void
    {
        $this->table('fcs_blog_tags', ['id' => false, 'primary_key' => ['id_blog_tag']])
            ->addColumn('id_blog_tag', 'integer', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('slug', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('active', 'integer', ['default' => 1, 'null' => false])
            ->addIndex(['name'], ['name' => 'idx_bt_name'])
            ->create();

        $this->table('fcs_blog_post_tags', ['id' => false, 'primary_key' => ['id_blog_post', 'id_blog_tag']])
            ->addColumn('id_blog_post', 'integer', ['null' => false])
            ->addColumn('id_blog_tag', 'integer', ['null' => false])
            ->addIndex(['id_blog_tag'], ['name' => 'idx_bpt_tag'])
            ->create();
    }

    public function down(): void
    {
        $this->table('fcs_blog_post_tags')->drop()->save();
        $this->table('fcs_blog_tags')->drop()->save();
    }
}
