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

class ApiManufacturersTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;

    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $this->permissions([P::API_MANUFACTURERS_READ, P::API_MANUFACTURERS_WRITE]);
        $this->token = $this->getTableLocator()->get('ApiTokens')->generate(Configure::read('test.customerId'), 'test', null);
    }

    /**
     * @param string[] $permissions
     */
    private function permissions(array $permissions): void
    {
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer(Configure::read('test.customerId'), $permissions, null);
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

    public function testReadNeedsPermission(): void
    {
        $this->permissions([]);
        $this->api('get', '/api/v1/manufacturers');
        $this->assertResponseCode(403);
    }

    public function testListAndView(): void
    {
        $list = $this->api('get', '/api/v1/manufacturers');
        $this->assertResponseOk();
        $this->assertGreaterThan(0, $list['count']);
        $m = $this->api('get', '/api/v1/manufacturers/' . $list['manufacturers'][0]['id']);
        $this->assertResponseOk();
        $this->assertArrayHasKey('address', $m);
        $this->api('get', '/api/v1/manufacturers/999999');
        $this->assertResponseCode(404);
    }

    public function testCreateManufacturerWithAddress(): void
    {
        $m = $this->api('post', '/api/v1/manufacturers', [
            'name' => 'Obsthof Test',
            'iban' => 'AT48 3200 0000 1234 5864',
            'homepage' => 'obsthof.example.com',
            'address' => ['firstname' => 'Anna', 'lastname' => 'Obst', 'email' => 'obsthof-test@example.com',
                'address1' => 'Weg 1', 'postcode' => '4020', 'city' => 'Linz'],
        ]);
        $this->assertResponseCode(201);
        $this->assertSame('Obsthof Test', $m['name']);
        $this->assertSame('AT483200000012345864', $m['iban']);
        $this->assertSame('https://obsthof.example.com', $m['homepage']);
        $this->assertSame('Linz', $m['address']['city']);
        $this->assertTrue($this->getTableLocator()->get('Customers')->exists(['email' => 'obsthof-test@example.com']));
    }

    public function testEditDryRunValidationAndConflict(): void
    {
        $id = $this->api('get', '/api/v1/manufacturers')['manufacturers'][0]['id'];
        $dry = $this->api('patch', '/api/v1/manufacturers/' . $id . '?dry_run=1', ['name' => 'Neuer Hofname']);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame('Neuer Hofname', $dry['after']['name']);
        $this->assertNotSame('Neuer Hofname', $this->api('get', '/api/v1/manufacturers/' . $id)['name']);

        $this->api('patch', '/api/v1/manufacturers/' . $id, ['name' => 'ab']);
        $this->assertResponseCode(422);
        $this->api('patch', '/api/v1/manufacturers/' . $id, ['passwort' => 'x']);
        $this->assertResponseCode(422);
        $this->api('patch', '/api/v1/manufacturers/' . $id, ['name' => 'Hof', 'if_unmodified_since' => '2000-01-01T00:00:00+00:00']);
        $this->assertResponseCode(409);

        $m = $this->api('patch', '/api/v1/manufacturers/' . $id, ['name' => 'Neuer Hofname', 'address' => ['city' => 'Wels']]);
        $this->assertResponseOk();
        $this->assertSame('Neuer Hofname', $m['name']);
        $this->assertSame('Wels', $m['address']['city']);
    }
}
