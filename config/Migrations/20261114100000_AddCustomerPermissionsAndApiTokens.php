<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Explicit permissions per customer (independent of the customer group), API users and API tokens.
 */
class AddCustomerPermissionsAndApiTokens extends BaseMigration
{
    public function change(): void
    {
        $this->table('fcs_customer_permissions')
            ->addColumn('customer_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('permission', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['customer_id', 'permission'], ['unique' => true])
            ->create();

        $this->table('fcs_api_tokens')
            ->addColumn('customer_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('token_hint', 'string', ['limit' => 8, 'null' => false, 'default' => ''])
            ->addColumn('active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('expires', 'datetime', ['null' => true])
            ->addColumn('last_used', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['customer_id'])
            ->create();

        $this->table('fcs_customer')
            ->addColumn('is_api_user', 'boolean', ['null' => false, 'default' => false, 'after' => 'active'])
            ->update();
    }
}
