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

use App\Model\Table\CustomerPermissionsTable as P;
use App\Test\Fixture\ProductsFixture;
use App\Test\TestCase\AppCakeTestCase;
use App\Test\TestCase\Traits\AppIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\I18n\Date;

/**
 * POST /api/v1/orders - instant order for a member directly for a chosen pickup day.
 */
class ApiOrdersWriteTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;

    private string $token;
    private int $apiUserId;
    private int $memberId;

    public function setUp(): void
    {
        parent::setUp();
        // the API user is the superadmin of the demo data, the order is placed for the demo member
        $this->apiUserId = (int) Configure::read('test.superadminId');
        $this->memberId = (int) Configure::read('test.customerId');
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer($this->apiUserId, [P::API_ORDERS_WRITE, P::API_ORDERS_READ], null);
        $this->token = $this->getTableLocator()->get('ApiTokens')->generate($this->apiUserId, 'test', null);
    }

    private function nextFriday(): string
    {
        $d = new Date();
        $d = $d->modify('next friday');
        return $d->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function placeOrder(array $body, string $query = ''): array
    {
        $this->_request = [];
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'],
        ]);
        $this->post('/api/v1/orders' . $query, (string) json_encode($body));
        return (array) json_decode((string) $this->_response->getBody(), true);
    }

    public function testNeedsOrdersWritePermission(): void
    {
        $this->getTableLocator()->get('CustomerPermissions')->setForCustomer($this->apiUserId, [P::API_ORDERS_READ], null);
        $this->placeOrder([]);
        $this->assertResponseCode(403);
    }

    public function testValidation(): void
    {
        $item = ['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 1];
        $this->placeOrder(['customer_id' => $this->memberId, 'items' => [$item]]);
        $this->assertResponseCode(422); // no pickup_day
        $this->placeOrder(['customer_id' => $this->memberId, 'pickup_day' => '2001-01-05', 'items' => [$item]]);
        $this->assertResponseCode(422); // in the past
        $this->placeOrder(['customer_id' => $this->memberId, 'pickup_day' => $this->nextFriday(), 'items' => []]);
        $this->assertResponseCode(422);
        $this->placeOrder(['customer_id' => $this->memberId, 'pickup_day' => $this->nextFriday(),
            'items' => [['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 0]]]);
        $this->assertResponseCode(422);
        $this->placeOrder(['customer_id' => 999999, 'pickup_day' => $this->nextFriday(), 'items' => [$item]]);
        $this->assertResponseCode(404);
    }

    public function testDryRunReturnsPricesAndSavesNothing(): void
    {
        $before = $this->getTableLocator()->get('OrderDetails')->find()->count();
        $result = $this->placeOrder([
            'customer_id' => $this->memberId,
            'pickup_day' => $this->nextFriday(),
            'items' => [['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 2]],
        ], '?dry_run=1');
        $this->assertResponseOk();
        $this->assertTrue($result['dry_run']);
        $this->assertSame($this->nextFriday(), $result['pickup_day']);
        $this->assertSame(2, $result['items'][0]['amount']);
        $this->assertGreaterThan(0, $result['total']);
        $this->assertSame($before, $this->getTableLocator()->get('OrderDetails')->find()->count());
        $left = $this->getTableLocator()->get('CartProducts')->find()
            ->matching('Carts', fn($q) => $q->where(['Carts.id_customer' => $this->memberId, 'Carts.cart_type' => 2]))->count();
        $this->assertSame(0, $left, 'the scratch cart is emptied again');
    }

    public function testPlacesInstantOrderDirectlyForTheChosenPickupDay(): void
    {
        $day = $this->nextFriday();
        $result = $this->placeOrder([
            'customer_id' => $this->memberId,
            'pickup_day' => $day,
            'items' => [['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 2]],
        ]);
        $this->assertResponseCode(201, (string) json_encode($result));
        $this->assertFalse($result['dry_run']);
        $this->assertNotEmpty($result['order_details']);
        foreach ($result['order_details'] as $row) {
            $this->assertSame($day, $row['pickup_day'], 'order is created for the chosen day, not for today');
            foreach (['product_name', 'amount', 'total_price_tax_incl', 'deposit', 'quantity_in_units', 'unit'] as $key) {
                $this->assertArrayHasKey($key, $row, 'rows carry what the shop stores: name, amount, price');
            }
        }
        $this->assertEqualsWithDelta($result['total_price_tax_incl'],
            array_sum(array_column($result['order_details'], 'total_price_tax_incl')), 0.011, 'rows add up to the sum');
        $orderDetails = $this->getTableLocator()->get('OrderDetails')->find()
            ->where(['OrderDetails.id_order_detail IN' => array_column($result['order_details'], 'id')])->all();
        foreach ($orderDetails as $od) {
            $this->assertSame($this->memberId, (int) $od->id_customer);
            $this->assertSame($day, $od->pickup_day->format('Y-m-d'));
        }
        $log = $this->getTableLocator()->get('ActionLogs')->find()->where(['type' => 'instant_order_added'])->first();
        $this->assertNotNull($log, 'same action log entry as the admin instant order');
        $this->assertSame($this->apiUserId, (int) $log->customer_id, 'logged for the API user who placed it');
        // no session mode leaks into the next request
        $again = $this->placeOrder(['customer_id' => $this->memberId, 'pickup_day' => $day, 'items' => [
            ['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 1]]], '?dry_run=1');
        $this->assertResponseOk();
        $this->assertSame(1, $again['items'][0]['amount']);
    }

    public function testUnavailableProductIsRefusedWithMessage(): void
    {
        $result = $this->placeOrder([
            'customer_id' => $this->memberId, 'pickup_day' => $this->nextFriday(),
            'items' => [['product_id' => 99999, 'amount' => 1]],
        ]);
        $this->assertResponseCode(422);
        $this->assertNotSame('', $result['error']['details']['items'][0]['message']);
        $this->assertSame(0, $this->getTableLocator()->get('OrderDetails')->find()
            ->where(['OrderDetails.product_id' => 99999])->count());
    }

    public function testExpectedTotalGuardRefusesWhenThePriceChanged(): void
    {
        $day = $this->nextFriday();
        $items = [['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 2]];
        $preview = $this->placeOrder(['customer_id' => $this->memberId, 'pickup_day' => $day, 'items' => $items], '?dry_run=1');
        $before = $this->getTableLocator()->get('OrderDetails')->find()->count();
        $result = $this->placeOrder(['customer_id' => $this->memberId, 'pickup_day' => $day, 'items' => $items,
            'expected_total' => $preview['total'] + 1]);
        $this->assertResponseCode(409);
        $this->assertSame($preview['total'], $result['error']['details']['total']);
        $this->assertSame($before, $this->getTableLocator()->get('OrderDetails')->find()->count());
        $this->placeOrder(['customer_id' => $this->memberId, 'pickup_day' => $day, 'items' => $items,
            'expected_total' => $preview['total']]);
        $this->assertResponseCode(201);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function moveOrder(array $body, string $query = ''): array
    {
        $this->_request = [];
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'],
        ]);
        $this->patch('/api/v1/orders' . $query, (string) json_encode($body));
        return (array) json_decode((string) $this->_response->getBody(), true);
    }

    private function placeFridayOrder(): string
    {
        $day = $this->nextFriday();
        $this->placeOrder(['customer_id' => $this->memberId, 'pickup_day' => $day,
            'items' => [['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 2]]]);
        $this->assertResponseCode(201);
        return $day;
    }

    private function dayAfter(string $day): string
    {
        return (new Date($day))->modify('+7 days')->format('Y-m-d');
    }

    public function testMoveNeedsPermissionAndValidInput(): void
    {
        $day = $this->placeFridayOrder();
        $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day]);
        $this->assertResponseCode(422);
        $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day, 'pickup_day' => '2001-01-05']);
        $this->assertResponseCode(422);
        $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day, 'pickup_day' => $day]);
        $this->assertResponseCode(422);
        $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $this->dayAfter($day), 'pickup_day' => $this->dayAfter($this->dayAfter($day))]);
        $this->assertResponseCode(404); // nothing ordered for that day
        $this->getTableLocator()->get('CustomerPermissions')->setForCustomer($this->apiUserId, [P::API_ORDERS_READ], null);
        $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day, 'pickup_day' => $this->dayAfter($day)]);
        $this->assertResponseCode(403);
    }

    public function testMovesTheOrderToTheFollowingFridayWithPreviewAndLog(): void
    {
        $day = $this->placeFridayOrder();
        $to = $this->dayAfter($day);
        $orderDetails = $this->getTableLocator()->get('OrderDetails');
        $preview = $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day, 'pickup_day' => $to], '?dry_run=1');
        $this->assertResponseOk();
        $this->assertTrue($preview['dry_run']);
        $this->assertNotEmpty($preview['order_details']);
        $ids = array_column($preview['order_details'], 'id');
        $this->assertSame($day, $orderDetails->get($ids[0])->pickup_day->format('Y-m-d'), 'dry run changes nothing');

        // a stale preview is refused
        $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day, 'pickup_day' => $to,
            'order_detail_ids' => [$ids[0] + 1000]]);
        $this->assertResponseCode(409);

        $result = $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day, 'pickup_day' => $to,
            'order_detail_ids' => $ids, 'reason' => 'Vom Chat verschoben']);
        $this->assertResponseOk();
        $this->assertSame(count($ids), $result['moved']);
        foreach ($result['order_details'] as $row) {
            $this->assertSame($to, $row['pickup_day']);
        }
        foreach ($ids as $id) {
            $this->assertSame($to, $orderDetails->get($id)->pickup_day->format('Y-m-d'));
        }
        $log = $this->getTableLocator()->get('ActionLogs')->find()->where(['type' => 'order_detail_pickup_day_changed'])->first();
        $this->assertNotNull($log, 'same action log as the admin');
        $this->assertSame($this->apiUserId, (int) $log->customer_id);
        $this->assertStringContainsString('Vom Chat verschoben', $log->text);
        // nothing left on the old day
        $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day, 'pickup_day' => $to]);
        $this->assertResponseCode(404);
    }

    public function testBilledOrdersAreNotMoved(): void
    {
        $day = $this->placeFridayOrder();
        $orderDetails = $this->getTableLocator()->get('OrderDetails');
        foreach ($orderDetails->find()->where(['pickup_day' => $day, 'id_customer' => $this->memberId])->all() as $od) {
            $od->order_state = \App\Model\Entity\OrderDetail::STATE_BILLED_CASH;
            $orderDetails->save($od);
        }
        $this->moveOrder(['customer_id' => $this->memberId, 'from_pickup_day' => $day, 'pickup_day' => $this->dayAfter($day)]);
        $this->assertResponseCode(404);
    }
}

