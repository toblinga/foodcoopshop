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

use Api\Contacts\ContactCards;
use App\Model\Table\CustomerPermissionsTable as P;
use App\Test\TestCase\AppCakeTestCase;
use App\Test\TestCase\Traits\AppIntegrationTestTrait;
use Cake\Core\Configure;

class ApiCarddavTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;

    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $this->permissions([P::CONTACTS_READ]);
        $this->token = $this->getTableLocator()->get('ApiTokens')->generate(Configure::read('test.adminId'), 'carddav test', null);
        $customers = $this->getTableLocator()->get('Customers');
        $customers->updateAll(['share_contact_data' => false], []);
        $customers->updateAll(['share_contact_data' => true], ['id_customer' => Configure::read('test.customerId')]);
        $this->getTableLocator()->get('AddressCustomers')->updateAll(
            ['phone_mobile' => '0664 123 45 67', 'phone' => '+43 7289 12345'],
            ['id_customer' => Configure::read('test.customerId')],
        );
    }

    /**
     * @param list<string> $permissions
     */
    private function permissions(array $permissions): void
    {
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer(Configure::read('test.adminId'), $permissions, null);
    }

    /**
     * @param array<string, string> $headers
     */
    private function dav(string $method, string $url, string $body = '', ?array $headers = null): string
    {
        $headers ??= ['Authorization' => 'Basic ' . base64_encode('anyone:' . $this->token)];
        $this->_request = [];
        $this->configRequest(['headers' => $headers + ['Content-Type' => 'application/xml'], 'input' => $body]);
        $this->_sendRequest($url, $method);
        return (string) $this->_response->getBody();
    }

    public function testNeedsLoginAndAnswersWithBasicChallenge(): void
    {
        $this->dav('PROPFIND', '/api/v1/carddav/', '', ['Authorization' => 'Basic ' . base64_encode('anyone:fcs_wrong')]);
        $this->assertResponseCode(401);
        $this->assertStringContainsString('Basic', $this->_response->getHeaderLine('WWW-Authenticate'));
    }

    public function testNeedsPermission(): void
    {
        $this->permissions([P::API_PRODUCTS_READ]);
        $this->dav('PROPFIND', '/api/v1/carddav/');
        $this->assertResponseCode(403);
    }

    public function testBearerTokenIsAccepted(): void
    {
        $this->dav('PROPFIND', '/api/v1/carddav/', '', ['Authorization' => 'Bearer ' . $this->token, 'Depth' => '0']);
        $this->assertResponseCode(207);
    }

    public function testDiscoveryAndAddressBook(): void
    {
        $body = $this->dav('PROPFIND', '/api/v1/carddav/', '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>', [
            'Authorization' => 'Basic ' . base64_encode('anyone:' . $this->token),
            'Depth' => '0',
        ]);
        $this->assertResponseCode(207);
        $this->assertStringContainsString('/api/v1/carddav/principal/', $body);

        $body = $this->dav('PROPFIND', '/api/v1/carddav/contacts/', '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getetag/></d:prop></d:propfind>', [
            'Authorization' => 'Basic ' . base64_encode('anyone:' . $this->token),
            'Depth' => '1',
        ]);
        $this->assertResponseCode(207);
        $this->assertStringContainsString('<c:addressbook/>', $body);
        $this->assertStringContainsString(ContactCards::uid(Configure::read('test.customerId')) . '.vcf', $body);
        // exactly the address book itself and the one member with consent
        $this->assertSame(2, substr_count($body, '<d:response>'));
    }

    public function testReportDeliversVcardWithInternationalPhoneNumbersAndIsLogged(): void
    {
        $body = $this->dav('REPORT', '/api/v1/carddav/contacts/', '<c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/><c:address-data/></d:prop></c:addressbook-query>');
        $this->assertResponseCode(207);
        $this->assertStringContainsString('TEL;TYPE=CELL:+436641234567', $body);
        $this->assertStringContainsString('TEL;TYPE=VOICE:+43728912345', $body);
        $this->assertTrue($this->getTableLocator()->get('ActionLogs')->exists(['type' => 'customer_contacts_exported']));
    }

    public function testMultigetAnswersUnknownContactsWith404(): void
    {
        $id = Configure::read('test.customerId');
        $body = $this->dav('REPORT', '/api/v1/carddav/contacts/', '<c:addressbook-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop><d:getetag/></d:prop>'
            . '<d:href>/api/v1/carddav/contacts/fcs-customer-' . $id . '.vcf</d:href>'
            . '<d:href>/api/v1/carddav/contacts/fcs-customer-99999.vcf</d:href></c:addressbook-multiget>');
        $this->assertResponseCode(207);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $body);
        $this->assertStringContainsString('HTTP/1.1 404 Not Found', $body);
    }

    public function testSingleContactAndReadOnly(): void
    {
        $url = '/api/v1/carddav/contacts/' . ContactCards::uid(Configure::read('test.customerId')) . '.vcf';
        $body = $this->dav('GET', $url);
        $this->assertResponseOk();
        $this->assertStringContainsString('BEGIN:VCARD', $body);
        $this->assertNotSame('', $this->_response->getHeaderLine('ETag'));

        $this->dav('PUT', $url, 'BEGIN:VCARD');
        $this->assertResponseCode(403);
        $this->dav('DELETE', $url);
        $this->assertResponseCode(403);

        $this->dav('GET', '/api/v1/carddav/contacts/' . ContactCards::uid(99999) . '.vcf');
        $this->assertResponseCode(404);
    }

    public function testSyncCollectionIsNotSupported(): void
    {
        $this->dav('REPORT', '/api/v1/carddav/contacts/', '<d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:prop><d:getetag/></d:prop></d:sync-collection>');
        $this->assertResponseCode(403);
    }

    public function testWellKnownRedirectsToAddressBook(): void
    {
        $this->_request = [];
        $this->get('/.well-known/carddav');
        $this->assertResponseCode(301);
        $this->assertSame('/api/v1/carddav/', $this->_response->getHeaderLine('Location'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function phoneProvider(): array
    {
        return [
            'austrian mobile' => ['0664 1234567', '+436641234567'],
            'with separators' => ['0664/123-45 67', '+436641234567'],
            'already international' => ['+43 664 1234567', '+436641234567'],
            'double zero' => ['0043 664 1234567', '+436641234567'],
            'german number' => ['+49 170 1234567', '+491701234567'],
            'unknown format stays' => ['1234', '1234'],
            'empty' => ['', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phoneProvider')]
    public function testInternationalPhone(string $input, string $expected): void
    {
        $this->assertSame($expected, ContactCards::internationalPhone($input));
    }
}
