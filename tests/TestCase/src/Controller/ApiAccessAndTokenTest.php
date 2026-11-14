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

use App\Model\Table\CustomerPermissionsTable;
use App\Test\TestCase\AppCakeTestCase;
use App\Test\TestCase\Traits\AppIntegrationTestTrait;
use App\Test\TestCase\Traits\LoginTrait;
use Cake\Core\Configure;
use Cake\I18n\DateTime;

class ApiAccessAndTokenTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;
    use LoginTrait;

    private function bearer(string $token): void
    {
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']]);
    }

    public function testMeWithoutTokenIs401(): void
    {
        $this->get('/api/v1/me');
        $this->assertResponseCode(401);
        $this->assertResponseContains('missing or invalid bearer token');
    }

    public function testMeWithTokenShowsPermissions(): void
    {
        $customerId = Configure::read('test.customerId');
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer($customerId, [CustomerPermissionsTable::CONTACTS_READ], null);
        $token = $this->getTableLocator()->get('ApiTokens')->generate($customerId, 'phone', null);
        $this->bearer($token);
        $this->get('/api/v1/me');
        $this->assertResponseOk();
        $data = json_decode((string) $this->_response->getBody(), true);
        $this->assertSame($customerId, $data['id']);
        $this->assertSame('phone', $data['token']);
        $this->assertSame([CustomerPermissionsTable::CONTACTS_READ], $data['permissions']);
    }

    public function testRevokedExpiredAndUnknownTokensAre401(): void
    {
        $tokens = $this->getTableLocator()->get('ApiTokens');
        $customerId = Configure::read('test.customerId');

        $revoked = $tokens->generate($customerId, 'revoked', null);
        $tokens->revoke($tokens->find()->where(['name' => 'revoked'])->firstOrFail()->id);
        $expired = $tokens->generate($customerId, 'expired', null, DateTime::now()->subDays(1));

        foreach ([$revoked, $expired, 'fcs_' . str_repeat('0', 48), 'no-prefix'] as $token) {
            $this->bearer($token);
            $this->get('/api/v1/me');
            $this->assertResponseCode(401);
        }
    }

    public function testInactiveNormalCustomerTokenIs401ButApiUserWorks(): void
    {
        $customersTable = $this->getTableLocator()->get('Customers');
        $customerId = Configure::read('test.customerId');
        $customersTable->updateAll(['active' => APP_OFF], ['id_customer' => $customerId]);
        $token = $this->getTableLocator()->get('ApiTokens')->generate($customerId, 'x', null);
        $this->bearer($token);
        $this->get('/api/v1/me');
        $this->assertResponseCode(401);

        // API users are inactive on purpose (no shop login) but may use the API
        $customersTable->updateAll(['is_api_user' => true], ['id_customer' => $customerId]);
        $this->bearer($token);
        $this->get('/api/v1/me');
        $this->assertResponseOk();
    }

    public function testUnknownEndpointIs404Json(): void
    {
        $token = $this->getTableLocator()->get('ApiTokens')->generate(Configure::read('test.customerId'), 'x', null);
        $this->bearer($token);
        $this->get('/api/v1/does-not-exist');
        $this->assertResponseCode(404);
        $this->assertContentType('application/json');
    }

    public function testUnknownPermissionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer(Configure::read('test.customerId'), ['superpowers'], null);
    }

    public function testApiAccessPageOnlyForSuperadmin(): void
    {
        $this->loginAsAdmin();
        $this->get('/admin/api-access');
        $this->assertRedirectContains('/anmelden');

        $this->loginAsSuperadmin();
        $this->get('/admin/api-access');
        $this->assertResponseOk();
    }

    public function testSuperadminCreatesApiUserTokenAndPermissions(): void
    {
        $this->loginAsSuperadmin();
        $this->post('/admin/api-access/create-api-user', ['name' => 'LS-Zentrale']);
        $apiUser = $this->getTableLocator()->get('Customers')->find()
            ->where(['is_api_user' => true, 'lastname' => 'LS-Zentrale'])->firstOrFail();
        $this->assertEquals(APP_OFF, $apiUser->active);

        $this->post('/admin/api-access/save-permissions', [
            'customer_id' => $apiUser->id_customer,
            'permissions' => [CustomerPermissionsTable::API_PRODUCTS_READ],
        ]);
        $this->assertSame([CustomerPermissionsTable::API_PRODUCTS_READ],
            $this->getTableLocator()->get('CustomerPermissions')->getForCustomer($apiUser->id_customer));

        $this->post('/admin/api-access/create-token', ['customer_id' => $apiUser->id_customer, 'name' => 'LSZ', 'expires' => '']);
        $stored = $this->getTableLocator()->get('ApiTokens')->find()->where(['customer_id' => $apiUser->id_customer])->firstOrFail();
        $this->assertSame('LSZ', $stored->name);
        $this->assertSame(64, strlen($stored->token_hash)); // only the SHA-256 hash is stored
        $this->assertRedirectContains('/admin/api-access');

        // the plain token waits in the session and is shown once on the page
        $this->session(['Admin.ApiAccess.newToken' => 'fcs_shownonce']);
        $this->get('/admin/api-access');
        $this->assertResponseContains('fcs_shownonce');
    }
}
