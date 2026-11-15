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

class ApiProductsTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;

    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $customerId = Configure::read('test.customerId');
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer($customerId, [P::API_PRODUCTS_READ, P::API_PRODUCTS_WRITE], null);
        $this->token = $this->getTableLocator()->get('ApiTokens')->generate($customerId, 'test', null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function api(string $method, string $url, array $body = []): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token];
        $request = ['headers' => $headers];
        if ($body) {
            $request = ['headers' => $headers + ['Content-Type' => 'application/json'], 'input' => json_encode($body)];
        }
        $this->_request = [];
        $this->configRequest($request);
        $this->{$method}($url);
        return (array) json_decode((string) $this->_response->getBody(), true);
    }

    /**
     * @param string[] $permissions
     */
    private function permissions(array $permissions): void
    {
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer(Configure::read('test.customerId'), $permissions, null);
    }

    public function testListAndViewNeedReadPermission(): void
    {
        $this->permissions([]);
        $this->api('get', '/api/v1/products');
        $this->assertResponseCode(403);

        $this->permissions([P::API_PRODUCTS_READ]);
        $data = $this->api('get', '/api/v1/products?active=1');
        $this->assertResponseOk();
        $this->assertSame(count($data['products']), $data['count']);

        $product = $this->api('get', '/api/v1/products/60'); // Milch (demo data)
        $this->assertResponseOk();
        $this->assertSame(60, $product['id']);
        $this->assertArrayHasKey('attributes', $product);
        $this->assertArrayHasKey('gross_price', $product);
    }

    public function testViewUnknownProductIs404(): void
    {
        $this->api('get', '/api/v1/products/999999');
        $this->assertResponseCode(404);
    }

    public function testWriteNeedsWritePermission(): void
    {
        $this->permissions([P::API_PRODUCTS_READ]);
        $this->api('patch', '/api/v1/products/346', ['gross_price' => 3.33]);
        $this->assertResponseCode(403);
    }

    public function testDryRunDoesNotSave(): void
    {
        $before = $this->api('get', '/api/v1/products/346');
        $result = $this->api('patch', '/api/v1/products/346?dry_run=1', ['gross_price' => 3.33]);
        $this->assertResponseOk();
        $this->assertTrue($result['dry_run']);
        $this->assertEquals(3.33, $result['changes']['gross_price']['new']);
        $this->assertSame($before['gross_price'], $this->api('get', '/api/v1/products/346')['gross_price']);
    }

    public function testChangePriceDepositNameAndStatus(): void
    {
        $result = $this->api('patch', '/api/v1/products/346', [
            'gross_price' => 3.33, 'deposit' => 0.5, 'name' => 'Neuer Name', 'active' => 0,
        ]);
        $this->assertResponseOk();
        $product = $result['product'];
        $this->assertEquals(3.33, $product['gross_price']);
        $this->assertEquals(0.5, $product['deposit']);
        $this->assertSame('Neuer Name', $product['name']);
        $this->assertSame(0, $product['active']);
        $log = $this->getTableLocator()->get('ActionLogs')->find()
            ->where(['type' => 'product_remotely_changed'])->orderBy(['id' => 'DESC'])->first();
        $this->assertStringContainsString('API (test) 346', $log->text);
    }

    public function testChangeAttributePrice(): void
    {
        $product = $this->api('get', '/api/v1/products/60');
        $attribute = $product['attributes'][0] ?? null;
        if ($attribute === null) {
            $this->markTestSkipped('demo product 60 has no attributes');
        }
        $result = $this->api('patch', '/api/v1/products/60/attributes/' . $attribute['attribute_id'], ['gross_price' => 1.23]);
        $this->assertResponseOk();
        $changed = array_values(array_filter($result['product']['attributes'], fn($a) => $a['attribute_id'] === $attribute['attribute_id']))[0];
        $this->assertEquals(1.23, $changed['gross_price']);
    }

    public function testUnknownFieldAndConflict(): void
    {
        $this->api('patch', '/api/v1/products/346', ['preis' => 1]);
        $this->assertResponseCode(422);
        $this->api('patch', '/api/v1/products/346', ['name' => 'x', 'if_unmodified_since' => '2000-01-01T00:00:00+00:00']);
        $this->assertResponseCode(409);
    }

    public function testChangeTaxRateKeepsGrossPrice(): void
    {
        $before = $this->api('get', '/api/v1/products/346');
        $newRate = $before['tax_rate'] == 10.0 ? 20.0 : 10.0;
        $taxes = $this->getTableLocator()->get('Taxes');
        if (!$taxes->find()->where(['rate' => $newRate, 'deleted' => APP_OFF])->count()) {
            $this->markTestSkipped('no tax with rate ' . $newRate);
        }
        $dry = $this->api('patch', '/api/v1/products/346?dry_run=1', ['tax_rate' => $newRate]);
        $this->assertTrue($dry['dry_run']);
        $this->assertEquals($before['tax_rate'], $this->api('get', '/api/v1/products/346')['tax_rate']);
        $result = $this->api('patch', '/api/v1/products/346', ['tax_rate' => $newRate]);
        $this->assertResponseOk();
        $this->assertEquals($newRate, $result['product']['tax_rate']);
        $this->assertEquals($before['gross_price'], $result['product']['gross_price']);
        $this->api('patch', '/api/v1/products/346', ['tax_rate' => 99.5]);
        $this->assertResponseCode(422);
    }

    public function testChangePricePerUnitConvertsGramsToKilogram(): void
    {
        $product = $this->api('get', '/api/v1/products/60');
        $attribute = $product['attributes'][0] ?? null;
        if ($attribute === null) {
            $this->markTestSkipped('demo product 60 has no attributes');
        }
        // 50 g variant, 0.09 EUR per g (= 90.00 EUR/kg), the usual setup for goods sold by weight
        $this->getTableLocator()->get('Units')->saveUnits(60, $attribute['attribute_id'], 1, 0.09, 'g', 1, 50, 0);
        $url = '/api/v1/products/60/attributes/' . $attribute['attribute_id'];
        $dry = $this->api('patch', $url . '?dry_run=1', ['price_per_unit' => ['price_incl_per_unit' => 90.0]]);
        $this->assertSame([], $dry['changes']); // same price, only stored in g
        $result = $this->api('patch', $url, ['price_per_unit' => ['price_incl_per_unit' => 90.23]]);
        $this->assertResponseOk();
        $changed = array_values(array_filter($result['product']['attributes'], fn($a) => $a['attribute_id'] === $attribute['attribute_id']))[0];
        $this->assertSame('kg', $changed['price_per_unit']['name']);
        $this->assertEquals(90.23, $changed['price_per_unit']['price_incl_per_unit']);
        $this->assertEquals(0.05, $changed['price_per_unit']['quantity_in_units']);
        $this->assertEquals(4.51, $changed['gross_price']); // 90.23 * 0.05 = 4.5115
        $again = $this->api('patch', $url . '?dry_run=1', ['price_per_unit' => ['price_incl_per_unit' => 90.23]]);
        $this->assertSame([], $again['changes']); // converges, no endless difference
        $this->api('patch', $url, ['price_per_unit' => ['price_incl_per_unit' => -1]]);
        $this->assertResponseCode(422);
    }
}
