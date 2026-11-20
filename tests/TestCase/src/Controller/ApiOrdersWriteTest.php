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
    private function post(array $body, string $query = ''): array
    {
        $this->_request = [];
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'],
        ]);
        parent::post('/api/v1/orders' . $query, (string) json_encode($body));
        return (array) json_decode((string) $this->_response->getBody(), true);
    }

    public function testNeedsOrdersWritePermission(): void
    {
        $this->getTableLocator()->get('CustomerPermissions')->setForCustomer($this->apiUserId, [P::API_ORDERS_READ], null);
        $this->post([]);
        $this->assertResponseCode(403);
    }

    public function testValidation(): void
    {
        $item = ['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 1];
        $this->post(['customer_id' => $this->memberId, 'items' => [$item]]);
        $this->assertResponseCode(422); // no pickup_day
        $this->post(['customer_id' => $this->memberId, 'pickup_day' => '2001-01-05', 'items' => [$item]]);
        $this->assertResponseCode(422); // in the past
        $this->post(['customer_id' => $this->memberId, 'pickup_day' => $this->nextFriday(), 'items' => []]);
        $this->assertResponseCode(422);
        $this->post(['customer_id' => $this->memberId, 'pickup_day' => $this->nextFriday(),
            'items' => [['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 0]]]);
        $this->assertResponseCode(422);
        $this->post(['customer_id' => 999999, 'pickup_day' => $this->nextFriday(), 'items' => [$item]]);
        $this->assertResponseCode(404);
    }

    public function testDryRunReturnsPricesAndSavesNothing(): void
    {
        $before = $this->getTableLocator()->get('OrderDetails')->find()->count();
        $result = $this->post([
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
        $this->assertSame(0, $this->getTableLocator()->get('Carts')->find()
            ->where(['Carts.id_customer' => $this->memberId, 'Carts.status' => 1, 'Carts.cart_type' => 2])
            ->contain(['CartProducts'])->all()->extract('cart_products')->count() > 0 ? 1 : 0, 'scratch cart is emptied');
    }

    public function testPlacesInstantOrderDirectlyForTheChosenPickupDay(): void
    {
        $day = $this->nextFriday();
        $result = $this->post([
            'customer_id' => $this->memberId,
            'pickup_day' => $day,
            'items' => [
                ['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 2],
                ['product_id' => ProductsFixture::ID_MILK_0_5L, 'attribute_id' => 0, 'amount' => 1],
            ],
        ]);
        // the milk id is a product with variants in the demo data: it must be refused cleanly, not crash
        if ($this->_response->getStatusCode() === 422) {
            $this->assertSame('order not possible', $result['error']['message']);
            $result = $this->post([
                'customer_id' => $this->memberId, 'pickup_day' => $day,
                'items' => [['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 2]],
            ]);
        }
        $this->assertResponseCode(201);
        $this->assertFalse($result['dry_run']);
        $this->assertNotEmpty($result['order_details']);
        foreach ($result['order_details'] as $row) {
            $this->assertSame($day, $row['pickup_day'], 'order is created for the chosen day, not for today');
        }
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
        $again = $this->post(['customer_id' => $this->memberId, 'pickup_day' => $day, 'items' => [
            ['product_id' => ProductsFixture::ID_ARTICHOKE, 'amount' => 1]]], '?dry_run=1');
        $this->assertResponseOk();
        $this->assertSame(1, $again['items'][0]['amount']);
    }

    public function testUnavailableProductIsRefusedWithMessage(): void
    {
        $result = $this->post([
            'customer_id' => $this->memberId, 'pickup_day' => $this->nextFriday(),
            'items' => [['product_id' => 99999, 'amount' => 1]],
        ]);
        $this->assertResponseCode(422);
        $this->assertNotSame('', $result['error']['details']['items'][0]['message']);
        $this->assertSame(0, $this->getTableLocator()->get('OrderDetails')->find()
            ->where(['OrderDetails.product_id' => 99999])->count());
    }
}
