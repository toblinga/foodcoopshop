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
use App\Test\TestCase\Traits\LoginTrait;
use Cake\Core\Configure;

/**
 * Changes through the API are readable in the admin action log and link to a page that exists.
 */
class ApiActionLogTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;
    use LoginTrait;

    /**
     * @param array<string, mixed> $body
     */
    private function patchProduct(int $id, array $body): void
    {
        $customerId = Configure::read('test.customerId');
        $this->getTableLocator()->get('CustomerPermissions')->setForCustomer($customerId, [P::API_PRODUCTS_READ, P::API_PRODUCTS_WRITE], null);
        $token = $this->getTableLocator()->get('ApiTokens')->generate($customerId, 'ls-zentrale', null);
        $this->_request = [];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']]);
        $this->patch('/api/v1/products/' . $id, (string) json_encode($body));
        $this->assertResponseOk();
    }

    public function testLogShowsProductNameManufacturerAndReadableValues(): void
    {
        $id = ProductsFixture::ID_ARTICHOKE;
        $before = $this->getTableLocator()->get('Products')->get($id);
        $this->patchProduct($id, ['active' => 0, 'gross_price' => 7.5, 'always_available' => 1]);

        $log = $this->getTableLocator()->get('ActionLogs')->find()->where(['type' => 'product_changed_via_api'])->firstOrFail();
        $this->assertSame($id, (int) $log->object_id);
        $text = (string) $log->text;
        $this->assertStringContainsString('API (ls-zentrale):', $text);
        $this->assertStringContainsString('<b>' . $before->name . '</b>', $text, 'product name instead of a bare id');
        $this->assertStringContainsString('ID ' . $id, $text);
        $this->assertStringContainsString('Status: online → offline', $text);
        $this->assertStringContainsString('Preis (brutto): ', $text);
        $this->assertStringContainsString('7,50', $text);
        $this->assertStringNotContainsString('json', strtolower($text));
        $this->assertStringNotContainsString('gross_price', $text, 'readable field names');
    }

    public function testLinkInTheLogLeadsToTheAdminProductListEvenForOfflineProducts(): void
    {
        $id = ProductsFixture::ID_ARTICHOKE;
        $this->patchProduct($id, ['active' => 0]);

        $this->loginAsSuperadmin();
        $this->get('/admin/action-logs');
        $this->assertResponseOk();
        $manufacturerId = $this->getTableLocator()->get('Products')->get($id)->id_manufacturer;
        $this->assertResponseRegExp('~/admin/products\?manufacturerId=' . $manufacturerId . '(&amp;|&)productId=' . $id . '~');
        $this->assertResponseContains('Produkt in der Produktverwaltung anzeigen');

        // the target page works for the offline product
        $this->get('/admin/products?manufacturerId=' . $manufacturerId . '&productId=' . $id);
        $this->assertResponseOk();
        $name = $this->getTableLocator()->get('Products')->get($id)->name;
        $this->assertResponseContains($name);
    }
}
