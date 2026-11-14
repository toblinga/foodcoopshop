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

class ApiCustomersContactsTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;

    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $this->permissions([P::API_CUSTOMERS_READ, P::API_CUSTOMERS_WRITE, P::CONTACTS_READ]);
        $this->token = $this->getTableLocator()->get('ApiTokens')->generate(Configure::read('test.adminId'), 'test', null);
    }

    /**
     * @param string[] $permissions
     */
    private function permissions(array $permissions): void
    {
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer(Configure::read('test.adminId'), $permissions, null);
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

    public function testCustomersNeedPermission(): void
    {
        $this->permissions([P::CONTACTS_READ]);
        $this->api('get', '/api/v1/customers');
        $this->assertResponseCode(403);
    }

    public function testListAndViewWithoutSecrets(): void
    {
        $list = $this->api('get', '/api/v1/customers');
        $this->assertResponseOk();
        $this->assertGreaterThan(0, $list['count']);
        $customer = $this->api('get', '/api/v1/customers/' . Configure::read('test.customerId'));
        $this->assertResponseOk();
        $this->assertArrayNotHasKey('passwd', $customer);
        $this->assertArrayHasKey('address', $customer);
    }

    public function testEditKeepsAddressInSyncAndRejectsProtectedFields(): void
    {
        $id = Configure::read('test.customerId');
        $customer = $this->api('patch', '/api/v1/customers/' . $id, ['firstname' => 'Maria', 'address' => ['city' => 'Steyr']]);
        $this->assertResponseOk();
        $this->assertSame('Maria', $customer['firstname']);
        $this->assertSame('Steyr', $customer['address']['city']);
        $address = $this->getTableLocator()->get('AddressCustomers')->find()->where(['id_customer' => $id])->firstOrFail();
        $this->assertSame('Maria', $address->firstname);

        foreach (['passwd', 'id_default_group', 'share_contact_data'] as $field) {
            $this->api('patch', '/api/v1/customers/' . $id, [$field => 'x']);
            $this->assertResponseCode(422);
        }
        $this->api('patch', '/api/v1/customers/' . $id, ['email' => 'no-email']);
        $this->assertResponseCode(422);
    }

    public function testEditNeedsWritePermissionAndDryRun(): void
    {
        $id = Configure::read('test.customerId');
        $dry = $this->api('patch', '/api/v1/customers/' . $id . '?dry_run=1', ['lastname' => 'Neu']);
        $this->assertTrue($dry['dry_run']);
        $this->assertNotSame('Neu', $this->api('get', '/api/v1/customers/' . $id)['lastname']);

        $this->permissions([P::API_CUSTOMERS_READ]);
        $this->api('patch', '/api/v1/customers/' . $id, ['lastname' => 'Neu']);
        $this->assertResponseCode(403);
    }

    public function testContactsOnlyWithConsentAndPermission(): void
    {
        Configure::write('app.contactsRequireConsent', true);
        $customersTable = $this->getTableLocator()->get('Customers');
        $customersTable->updateAll(['share_contact_data' => false], []);
        $customersTable->updateAll(['share_contact_data' => true], ['id_customer' => Configure::read('test.customerId')]);

        $data = $this->api('get', '/api/v1/contacts');
        $this->assertResponseOk();
        $this->assertSame(1, $data['count']);
        $this->assertSame(Configure::read('test.customerId'), $data['contacts'][0]['id']);

        $this->_request = [];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $this->token]]);
        $this->get('/api/v1/contacts.vcf');
        $this->assertResponseOk();
        $this->assertContentType('text/vcard');
        $this->assertResponseContains('BEGIN:VCARD');
        $this->assertResponseContains('UID:fcs-customer-' . Configure::read('test.customerId'));

        $this->assertTrue($this->getTableLocator()->get('ActionLogs')->exists(['type' => 'customer_contacts_exported']));

        $this->permissions([P::API_CUSTOMERS_READ]);
        $this->api('get', '/api/v1/contacts');
        $this->assertResponseCode(403);
    }
}
