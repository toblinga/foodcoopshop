<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Consent of a member to share contact data with members that have the permission contacts.read.
 */
class AddShareContactDataToCustomers extends BaseMigration
{
    public function change(): void
    {
        $this->table('fcs_customer')
            ->addColumn('share_contact_data', 'boolean', ['null' => false, 'default' => false, 'after' => 'newsletter_enabled'])
            ->addColumn('share_contact_data_changed', 'datetime', ['null' => true, 'default' => null, 'after' => 'share_contact_data'])
            ->update();
    }
}
