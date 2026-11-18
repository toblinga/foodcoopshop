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
use App\Test\TestCase\AppCakeTestCase;
use App\Test\TestCase\Traits\AppIntegrationTestTrait;
use Cake\Core\Configure;

class ApiOrdersTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;

    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $customerId = Configure::read('test.customerId');
        $this->getTableLocator()->get('CustomerPermissions')->setForCustomer($customerId, [P::API_ORDERS_READ], null);
        $this->token = $this->getTableLocator()->get('ApiTokens')->generate($customerId, 'test', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function api(string $url): array
    {
        $this->_request = [];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $this->token]]);
        $this->get($url);
        return (array) json_decode((string) $this->_response->getBody(), true);
    }

    public function testNeedsOrdersPermission(): void
    {
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer(Configure::read('test.customerId'), [P::API_PRODUCTS_READ], null);
        $this->api('/api/v1/orders?product_id=346');
        $this->assertResponseCode(403);
    }

    public function testFilterIsRequired(): void
    {
        $result = $this->api('/api/v1/orders');
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('filters', $result['error']['details']);
        $this->api('/api/v1/orders?pickup_day_from=tomorrow');
        $this->assertResponseCode(422);
    }

    public function testListsOrderedProductsWithCustomerAndPickupDay(): void
    {
        $first = $this->getTableLocator()->get('OrderDetails')->find()->orderBy(['id_order_detail' => 'ASC'])->first();
        $this->assertNotNull($first, 'demo data has order details');
        $day = $first->pickup_day->format('Y-m-d');
        $result = $this->api('/api/v1/orders?pickup_day_from=' . $day . '&pickup_day_to=' . $day . '&product_id=' . $first->product_id);
        $this->assertResponseOk();
        $this->assertGreaterThan(0, $result['count']);
        $ids = array_column($result['orders'], 'id');
        $this->assertContains((int) $first->id_order_detail, $ids);
        $row = $result['orders'][array_search((int) $first->id_order_detail, $ids)];
        $this->assertSame($day, $row['pickup_day']);
        $this->assertSame((int) $first->product_id, $row['product_id']);
        $this->assertSame((int) $first->product_amount, $row['amount']);
        foreach (['total_price_tax_incl', 'total_price_tax_excl', 'tax_rate', 'deposit', 'quantity_in_units', 'unit'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertArrayHasKey('name', $row['customer']);
        $this->assertNotSame('', $row['customer']['name']);
        $other = $this->api('/api/v1/orders?pickup_day_from=2000-01-01&pickup_day_to=2000-01-02');
        $this->assertSame(0, $other['count']);
    }
}
