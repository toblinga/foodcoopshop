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
use App\Services\CartService;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\I18n\Date;
use Cake\Routing\Router;

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
    private const MAX_ITEMS = 50;
    private const MAX_DAYS_AHEAD = 120;

    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash'); // CartService reports into the flash messages
    }

    /**
     * POST /api/v1/orders – instant order for a member ("Sofort-Bestellung"), directly for a chosen pickup day.
     *
     * Body: {"customer_id": 12, "pickup_day": "2026-11-27", "items": [{"product_id": 346, "attribute_id": 0,
     * "amount": 2, "quantity_in_units": 0.8}]}. `?dry_run=1` only checks and returns the prices (nothing is saved).
     *
     * It uses the very same cart code as the admin instant order (availability, prices, deposit, tax, stock,
     * confirmation e-mail, action log "instant order added"), only the pickup day is chosen by the caller instead of
     * being "today" - so no second step "change pickup day" is needed afterwards.
     */
    public function add(): Response
    {
        if ($r = $this->requirePermission(P::API_ORDERS_WRITE)) {
            return $r;
        }
        if (Configure::read('appDb.FCS_CUSTOMER_CAN_SELECT_PICKUP_DAY')) {
            return $this->error(409, 'not supported while customers select the pickup day themselves');
        }
        $body = $this->body();
        $dryRun = !empty($this->getRequest()->getQuery('dry_run'));

        $pickupDay = $this->parseDay((string) ($body['pickup_day'] ?? ''));
        if ($pickupDay === null) {
            return $this->error(422, 'pickup_day needs a date (YYYY-MM-DD)');
        }
        $today = new Date();
        if ($pickupDay->format('Y-m-d') < $today->format('Y-m-d')) {
            return $this->error(422, 'pickup_day must not be in the past');
        }
        if ($pickupDay->format('Y-m-d') > $today->modify('+' . self::MAX_DAYS_AHEAD . ' days')->format('Y-m-d')) {
            return $this->error(422, 'pickup_day is too far in the future');
        }

        $items = [];
        $rawItems = $body['items'] ?? null;
        if (!is_array($rawItems) || count($rawItems) < 1 || count($rawItems) > self::MAX_ITEMS) {
            return $this->error(422, 'items needs 1 to ' . self::MAX_ITEMS . ' entries');
        }
        foreach (array_values($rawItems) as $i => $raw) {
            $amount = is_array($raw) ? ($raw['amount'] ?? null) : null;
            $productId = is_array($raw) ? (int) ($raw['product_id'] ?? 0) : 0;
            if ($productId < 1 || !is_numeric($amount) || (int) $amount != $amount || (int) $amount < 1
                || (int) $amount > Configure::read('app.maxProductAmountForCart')) {
                return $this->error(422, 'items[' . $i . ']: product_id and a whole amount >= 1 are required');
            }
            $qiu = $raw['quantity_in_units'] ?? null;
            if ($qiu !== null && (!is_numeric($qiu) || (float) $qiu <= 0)) {
                return $this->error(422, 'items[' . $i . ']: quantity_in_units must be a positive number');
            }
            $items[] = [
                'product_id' => $productId,
                'attribute_id' => (int) ($raw['attribute_id'] ?? 0),
                'amount' => (int) $amount,
                'quantity_in_units' => $qiu === null ? -1.0 : (float) $qiu,
            ];
        }

        /** @var \App\Model\Entity\Customer|null $orderCustomer */
        $orderCustomer = $this->getTableLocator()->get('Customers')->find('all',
            conditions: ['Customers.id_customer' => (int) ($body['customer_id'] ?? 0), 'Customers.active' => 1],
            contain: ['AddressCustomers'],
        )->first();
        if ($orderCustomer === null || !empty($orderCustomer->is_api_user)) {
            return $this->error(404, 'customer not found or not active');
        }

        $request = $this->getRequest();
        $session = $request->getSession();
        $session->write('OriginalIdentity', $this->customer);
        $session->write('OrderIdentity', $orderCustomer);
        Configure::write('app.apiInstantOrderPickupDay', $pickupDay->format('Y-m-d'));
        $identity = $orderCustomer; // the Customer entity is the app's identity object
        // the admin ticks the two checkboxes of the checkout form on behalf of the member - so does the API
        $orderRequest = $request->withAttribute('identity', $identity)->withParsedBody([
            'cancellation_terms_accepted' => 1,
            'general_terms_and_conditions_accepted' => 1,
        ]);
        $this->setRequest($orderRequest);
        Router::setRequest($orderRequest);
        $identity->setCart($identity->getCart()); // like the app does for every logged in customer

        $cartService = new CartService($this);
        $cartFilled = false;
        try {
            $cartService->doEmptyCart();
            $cartFilled = true;
            $errors = [];
            $cartProductsTable = $this->getTableLocator()->get('CartProducts');
            foreach ($items as $i => $item) {
                $result = $cartProductsTable->add($item['product_id'], $item['attribute_id'], $item['amount'], $item['quantity_in_units']);
                if (is_array($result)) {
                    $errors[] = ['item' => $i, 'product_id' => $item['product_id'], 'message' => $this->plain((string) $result['msg'])];
                }
            }
            if ($errors) {
                return $this->error(422, 'order not possible', ['items' => $errors]);
            }
            $identity->setCart($identity->getCart());
            $cart = $identity->getCart();
            $cartProductIds = array_map(fn($p) => (int) $p['cartProductId'], $cart['CartProducts']);
            $summary = $this->summary($cart, $orderCustomer, $pickupDay);

            // optional guard: the caller confirmed this total (e.g. after a preview) - refuse if prices changed meanwhile
            if (!$dryRun && isset($body['expected_total']) && is_numeric($body['expected_total'])
                && abs((float) $body['expected_total'] - $summary['total']) > 0.005) {
                return $this->error(409, 'the total changed since the preview', [
                    'expected_total' => (float) $body['expected_total'], 'total' => $summary['total'],
                ]);
            }

            if ($dryRun) {
                return $this->json(['dry_run' => true] + $summary);
            }

            $finished = $cartService->finish();
            $vars = $this->viewBuilder()->getVars();
            if (!empty($vars['cartErrors']) || !empty($vars['formErrors'])) {
                $messages = [];
                array_walk_recursive($vars['cartErrors'], function ($m) use (&$messages) {
                    $messages[] = $this->plain((string) $m);
                });
                return $this->error(422, 'order not possible', ['items' => array_map(fn($m) => ['message' => $m], $messages)]);
            }
            $cartFilled = false; // the cart is saved as an order now

            $orderDetails = $this->getTableLocator()->get('OrderDetails')->find()
                ->contain(['OrderDetailUnits'])
                ->where(['OrderDetails.id_cart_product IN' => $cartProductIds])
                ->orderBy(['OrderDetails.id_order_detail' => 'ASC'])->all();
            $rows = [];
            foreach ($orderDetails as $od) {
                $unit = $od->order_detail_unit ?? null;
                $rows[] = [
                    'id' => (int) $od->id_order_detail,
                    'product_id' => (int) $od->product_id,
                    'attribute_id' => $od->product_attribute_id ? (int) $od->product_attribute_id : null,
                    // exactly as stored (and shown in the shop's order list): name incl. unity, amount, gross price
                    'product_name' => (string) $od->product_name,
                    'amount' => (int) $od->product_amount,
                    'quantity_in_units' => $unit && $unit->product_quantity_in_units !== null ? (float) $unit->product_quantity_in_units : null,
                    'unit' => $unit ? (string) $unit->unit_name : null,
                    'pickup_day' => $od->pickup_day?->format('Y-m-d'),
                    'total_price_tax_incl' => (float) $od->total_price_tax_incl,
                    'deposit' => (float) $od->deposit,
                ];
            }
            return $this->json(['dry_run' => false, 'cart_id' => (int) $finished['Cart']->id_cart, 'order_details' => $rows] + $summary, 201);
        } finally {
            if ($cartFilled) {
                try {
                    $cartService->doEmptyCart();
                } catch (\Throwable) {
                    // best effort, the cart is only a scratch cart of the API
                }
            }
            Configure::delete('app.apiInstantOrderPickupDay');
            $session->delete('OrderIdentity');
            $session->delete('OriginalIdentity');
            $this->setRequest($request);
            Router::setRequest($request);
        }
    }

    /**
     * @param array<string, mixed> $cart
     * @return array<string, mixed>
     */
    private function summary(array $cart, mixed $customer, Date $pickupDay): array
    {
        $items = [];
        foreach ($cart['CartProducts'] as $p) {
            $items[] = [
                'product_name' => (string) ($p['productName'] ?? ''),
                'unity' => (string) ($p['unity'] ?? ''),
                'amount' => (int) $p['amount'],
                'quantity_in_units' => isset($p['quantityInUnits']) ? (float) $p['quantityInUnits'] : null,
                'unit' => isset($p['unitName']) ? (string) $p['unitName'] : null,
                'price' => round((float) $p['price'], 2),
                'deposit' => round((float) $p['deposit'], 2),
            ];
        }
        $sum = round((float) $cart['CartProductSum'], 2);
        $deposit = round((float) $cart['CartDepositSum'], 2);
        return [
            'customer' => ['id' => (int) $customer->id_customer, 'name' => trim((string) $customer->name)],
            'pickup_day' => $pickupDay->format('Y-m-d'),
            'items' => $items,
            'total_price_tax_incl' => $sum,
            'total_deposit' => $deposit,
            'total' => round($sum + $deposit, 2),
        ];
    }

    private function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

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
