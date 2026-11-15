<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * "Price per unit" (e.g. 0.09 EUR per gram) was stored with two decimals only.
 *
 * That is too coarse for goods that are priced per gram or per ml: with the unit "g" the price can only be
 * changed in steps of 0.01 EUR per gram, which is 10 EUR per kg. A price of 90.23 EUR/kg (= 0.09023 EUR/g)
 * cannot be stored and is silently rounded to 90.00 EUR/kg. Whenever the price is derived from another system
 * (e.g. a cash register or an accounting tool) the rounded value never matches, which shows up as an
 * endless price difference.
 *
 * Four decimals are enough for 0.0001 EUR per gram (= 0.10 EUR/kg). The columns that hold the purchase price
 * and the copy in the order details are widened as well, so that calculations on old and new orders agree.
 * Existing values are not changed (decimal(10,2) -> decimal(10,4) is lossless).
 */
class UnitPricesWithMoreDecimals extends BaseMigration
{
    private const COLUMNS = [
        'fcs_units' => ['price_incl_per_unit', 'purchase_price_incl_per_unit'],
        'fcs_order_detail_units' => ['price_incl_per_unit', 'purchase_price_incl_per_unit'],
    ];

    public function up(): void
    {
        $this->alterColumns('decimal', ['precision' => 10, 'scale' => 4, 'signed' => false, 'null' => true]);
    }

    public function down(): void
    {
        // rounds values that were saved with more than two decimals
        $this->alterColumns('decimal', ['precision' => 10, 'scale' => 2, 'signed' => false, 'null' => true]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function alterColumns(string $type, array $options): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $t = $this->table($table);
            foreach ($columns as $column) {
                $t->changeColumn($column, $type, $options);
            }
            $t->update();
        }
    }
}
