<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Setting FCS_BILL_ORDERS_WITHOUT_INVOICES: the cronjob "send invoices to customers" does not create invoices,
 * PDFs or e-mails but only marks past orders as billed (billing happens in another system, e.g. a cash register).
 */
class AddConfigurationBillOrdersWithoutInvoices extends BaseMigration
{
    public function change(): void
    {
        $this->execute("INSERT INTO fcs_configuration (name, active, value, type, position) VALUES ('FCS_BILL_ORDERS_WITHOUT_INVOICES',1,'0','boolean',584);");
    }
}
