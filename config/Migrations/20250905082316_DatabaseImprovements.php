<?php
declare(strict_types=1);

use App\Migrations\Utf8mb4CollationTrait;
use Migrations\BaseMigration;

class DatabaseImprovements extends BaseMigration
{
    use Utf8mb4CollationTrait;

    public function change(): void
    {
        $collation = $this->getUtf8mb4Collation();
        $tables = [
            'fcs_barcodes',
            'fcs_feedbacks',
            'fcs_purchase_prices',
            'fcs_invoice_taxes',
            'fcs_storage_locations',
            'fcs_order_detail_purchase_prices'
        ];
        foreach ($tables as $table) {
            $sql = "ALTER TABLE $table CONVERT TO CHARACTER SET utf8mb4 COLLATE $collation;";
            $this->execute($sql);
        }

        $sql = "ALTER TABLE fcs_pickup_days ADD UNIQUE(customer_id, pickup_day);";
        $this->execute($sql);
    }
}
