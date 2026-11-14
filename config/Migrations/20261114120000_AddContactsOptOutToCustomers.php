<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Opt-out of the contacts API / CardDAV address book (only used with app.contactsMode = opt_out).
 */
class AddContactsOptOutToCustomers extends BaseMigration
{
    public function change(): void
    {
        $this->table('fcs_customer')
            ->addColumn('contacts_opt_out', 'boolean', ['null' => false, 'default' => false, 'after' => 'share_contact_data_changed'])
            ->update();
    }
}
