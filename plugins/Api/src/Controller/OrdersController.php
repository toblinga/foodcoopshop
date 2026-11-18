<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://www.foodcoopshop.com
 */

namespace Api\Controller;

use App\Model\Table\CustomerPermissionsTable as P;
use Cake\Http\Response;
use Cake\I18n\Date;

/**
 * GET /api/v1/orders – ordered products (order details), read only.
 *
 * One row per ordered product (or variant) and customer, with pickup day and state. Meant for stock keeping
 * (what is ordered but not picked up yet) and for questions like "who ordered product X".
 * Contains member names, therefore its own permission (api.orders.read). Prices, tax and deposit are per row
 * (enough for delivery notes per customer).
 *
 * To not hand out the whole history by accident, at least one filter is required:
 * pickup_day_from, pickup_day_to, product_id, customer_id or modified_since.
 */
class OrdersController extends ApiAppController
{
    private const MAX_ROWS = 10000;

    public function index(): Response
    {
        if ($r = $this->requirePermission(P::API_ORDERS_READ)) {
            return $r;
        }
        $q = $this->getRequest()->getQueryParams();
        if (!array_intersect(['pickup_day_from', 'pickup_day_to', 'product_id', 'customer_id', 'modified_since'], array_keys($q))) {
            return $this->error(422, 'at least one filter is required', [
                'filters' => ['pickup_day_from', 'pickup_day_to', 'product_id', 'customer_id', 'modified_since'],
            ]);
        }

        $query = $this->getTableLocator()->get('OrderDetails')->find()
            ->contain(['Customers', 'OrderDetailUnits'])
            ->orderBy(['OrderDetails.pickup_day' => 'ASC', 'OrderDetails.id_order_detail' => 'ASC'])
            ->limit(self::MAX_ROWS);

        foreach (['pickup_day_from' => '>=', 'pickup_day_to' => '<='] as $param => $operator) {
            if (!empty($q[$param])) {
                $day = $this->parseDay((string) $q[$param]);
                if ($day === null) {
                    return $this->error(422, $param . ' needs a date (YYYY-MM-DD)');
                }
                $query->where(['OrderDetails.pickup_day ' . $operator => $day]);
            }
        }
        if (isset($q['product_id'])) {
            $query->where(['OrderDetails.product_id' => (int) $q['product_id']]);
        }
        if (isset($q['attribute_id'])) {
            $query->where(['OrderDetails.product_attribute_id' => (int) $q['attribute_id']]);
        }
        if (isset($q['customer_id'])) {
            $query->where(['OrderDetails.id_customer' => (int) $q['customer_id']]);
        }
        if (isset($q['state'])) {
            $states = array_values(array_filter(array_map('intval', explode(',', (string) $q['state']))));
            if ($states) {
                $query->where(['OrderDetails.order_state IN' => $states]);
            }
        }
        if (!empty($q['modified_since'])) {
            try {
                $query->where(['OrderDetails.modified >=' => new \Cake\I18n\DateTime((string) $q['modified_since'])]);
            } catch (\Exception) {
                return $this->error(422, 'modified_since needs a date');
            }
        }

        $rows = [];
        foreach ($query->all() as $od) {
            $unit = $od->order_detail_unit ?? null;
            $rows[] = [
                'id' => (int) $od->id_order_detail,
                'product_id' => (int) $od->product_id,
                'attribute_id' => $od->product_attribute_id ? (int) $od->product_attribute_id : null,
                'product_name' => (string) $od->product_name,
                'amount' => (int) $od->product_amount,
                // goods counted by weight: ordered quantity in the unit of the product
                'quantity_in_units' => $unit && $unit->product_quantity_in_units !== null ? (float) $unit->product_quantity_in_units : null,
                'unit' => $unit ? (string) $unit->unit_name : null,
                'pickup_day' => $od->pickup_day?->format('Y-m-d'),
                'state' => (int) $od->order_state,
                'customer' => ['id' => (int) $od->id_customer, 'name' => $od->customer ? trim((string) $od->customer->name) : ''],
                'total_price_tax_incl' => (float) $od->total_price_tax_incl,
                'total_price_tax_excl' => (float) $od->total_price_tax_excl,
                'tax_rate' => (float) $od->tax_rate,
                'deposit' => (float) $od->deposit,
                'created' => $od->created?->toIso8601String(),
            ];
        }
        return $this->json(['count' => count($rows), 'truncated' => count($rows) >= self::MAX_ROWS, 'orders' => $rows]);
    }

    private function parseDay(string $value): ?Date
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return new Date($value);
        } catch (\Exception) {
            return null;
        }
    }
}
