<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Migrations\BaseMigration;

/**
 * Contacts API / CardDAV address book as admin settings:
 * FCS_CONTACTS_MODE (consent / opt_out / all) and FCS_CONTACTS_ORDERED_WITHIN_MONTHS (0 = all active members).
 * The start value of the mode is the former config value app.contactsMode (so nothing changes), default consent.
 */
class AddConfigurationContacts extends BaseMigration
{
    public function change(): void
    {
        $mode = Configure::read('app.contactsMode');
        if (!in_array($mode, ['consent', 'opt_out', 'all'], true)) {
            $mode = 'consent';
        }
        $this->execute("INSERT INTO fcs_configuration (name, active, value, type, position) VALUES ('FCS_CONTACTS_MODE',1,'" . $mode . "','dropdown',3640);");
        $this->execute("INSERT INTO fcs_configuration (name, active, value, type, position) VALUES ('FCS_CONTACTS_ORDERED_WITHIN_MONTHS',1,'0','number',3650);");
    }
}
