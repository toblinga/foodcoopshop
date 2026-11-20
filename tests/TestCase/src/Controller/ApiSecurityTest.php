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
use App\Test\TestCase\Traits\LoginTrait;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Routing\Router;

/**
 * Security properties of the REST API: no CORS, every route checks its permission, invalid dates are refused,
 * tokens expire by default and token handling is logged.
 */
class ApiSecurityTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;
    use LoginTrait;

    /**
     * @param list<string> $permissions
     */
    private function tokenFor(array $permissions, ?DateTime $expires = null): string
    {
        $customerId = Configure::read('test.customerId');
        $this->getTableLocator()->get('CustomerPermissions')->setForCustomer($customerId, $permissions, null);
        return $this->getTableLocator()->get('ApiTokens')->generate($customerId, 'test', null, $expires);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function call(string $method, string $url, string $token, array|string $body = '{}'): void
    {
        $this->_request = [];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']]);
        match ($method) {
            'GET' => $this->get($url),
            'POST' => $this->post($url, is_array($body) ? (string) json_encode($body) : $body),
            'PATCH' => $this->patch($url, is_array($body) ? (string) json_encode($body) : $body),
            default => throw new \LogicException('method ' . $method),
        };
    }

    public function testNoCorsHeadersOnApiResponses(): void
    {
        $token = $this->tokenFor([P::API_PRODUCTS_READ]);
        $this->call('GET', '/api/v1/me', $token);
        $this->assertResponseOk();
        $this->assertFalse($this->_response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($this->_response->hasHeader('Access-Control-Allow-Methods'));
        // a browser preflight must not be approved either
        $this->_request = [];
        $this->configRequest(['headers' => ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET']]);
        $this->options('/api/v1/products');
        $this->assertFalse($this->_response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * Every API route (except the ones that are public on purpose) refuses a valid token without any permission.
     * A new endpoint that forgets requirePermission() makes this test fail.
     */
    public function testEveryApiRouteChecksItsPermission(): void
    {
        $token = $this->tokenFor([]);
        $this->call('GET', '/api/v1/me', $token); // the routes are loaded with the first request
        $publicOnPurpose = ['Me', 'Errors', 'Carddav']; // /me shows only the own permissions, CardDAV has its own auth
        $checked = 0;
        foreach (Router::routes() as $route) {
            $defaults = $route->defaults;
            if (!str_starts_with((string) $route->template, '/api/v1') || in_array($defaults['controller'] ?? '', $publicOnPurpose, true)) {
                continue;
            }
            $url = str_replace(['{id}', '{attributeId}'], ['1', '1'], (string) $route->template);
            $url = str_replace('/*', '', $url);
            foreach ((array) ($defaults['_method'] ?? $route->options['_method'] ?? ['GET']) as $method) {
                $this->call($method, $url, $token);
                $this->assertResponseCode(
                    403,
                    $method . ' ' . $url . ' (' . $defaults['controller'] . '::' . $defaults['action'] . ') must check its permission first'
                );
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(16, $checked, 'the routes of the API were found');
    }

    public function testInvalidDatesAreRefusedInsteadOfCrashing(): void
    {
        $token = $this->tokenFor([
            P::API_PRODUCTS_READ, P::API_PRODUCTS_WRITE, P::API_CUSTOMERS_READ, P::API_CUSTOMERS_WRITE,
            P::API_MANUFACTURERS_READ, P::API_MANUFACTURERS_WRITE, P::API_ORDERS_READ,
        ]);
        foreach (['/api/v1/products', '/api/v1/customers', '/api/v1/orders'] as $url) {
            $this->call('GET', $url . '?modified_since=not-a-date', $token);
            $this->assertResponseCode(422, $url);
        }
        $this->call('PATCH', '/api/v1/products/346', $token, ['name' => 'X', 'if_unmodified_since' => 'yesterday-ish']);
        $this->assertResponseCode(422);
        $this->call('PATCH', '/api/v1/customers/' . Configure::read('test.customerId'), $token, ['firstname' => 'X', 'if_unmodified_since' => '???']);
        $this->assertResponseCode(422);
        $this->call('PATCH', '/api/v1/manufacturers/4', $token, ['name' => 'X', 'if_unmodified_since' => '???']);
        $this->assertResponseCode(422);
        // valid dates still work
        $this->call('GET', '/api/v1/customers?modified_since=2000-01-01', $token);
        $this->assertResponseOk();
    }

    public function testTokenIsLimitedTo12MonthsByDefaultAndNoExpiryMustBeChosen(): void
    {
        $this->loginAsSuperadmin();
        $customerId = Configure::read('test.customerId');
        $tokens = $this->getTableLocator()->get('ApiTokens');

        // tokens are not reset between tests: unique names
        $suffix = bin2hex(random_bytes(4));
        $this->post('/admin/api-access/create-token', ['customer_id' => $customerId, 'name' => 'phone-' . $suffix, 'expires' => '']);
        $default = $tokens->find()->where(['name' => 'phone-' . $suffix])->firstOrFail();
        $this->assertNotNull($default->expires);
        $days = DateTime::now()->diffInDays($default->expires);
        $this->assertGreaterThanOrEqual(364, $days);
        $this->assertLessThanOrEqual(366, $days);

        $this->post('/admin/api-access/create-token', ['customer_id' => $customerId, 'name' => 'forever-' . $suffix, 'expires' => '2030-01-01', 'no_expiry' => '1']);
        $this->assertNull($tokens->find()->where(['name' => 'forever-' . $suffix])->firstOrFail()->expires);

        $this->post('/admin/api-access/create-token', ['customer_id' => $customerId, 'name' => 'dated-' . $suffix, 'expires' => '2027-03-01']);
        $this->assertSame('2027-03-01', $tokens->find()->where(['name' => 'dated-' . $suffix])->firstOrFail()->expires->format('Y-m-d'));

        $this->post('/admin/api-access/create-token', ['customer_id' => $customerId, 'name' => 'broken-' . $suffix, 'expires' => 'banana']);
        $this->assertSame(0, $tokens->find()->where(['name' => 'broken-' . $suffix])->count());
    }

    public function testCreatingAndRevokingTokensIsLoggedWithoutTheToken(): void
    {
        $this->loginAsSuperadmin();
        $customerId = Configure::read('test.customerId');
        $tokens = $this->getTableLocator()->get('ApiTokens');
        $name = 'Chat-' . bin2hex(random_bytes(4)); // tokens are not reset between tests: use a unique name
        $this->post('/admin/api-access/create-token', ['customer_id' => $customerId, 'name' => $name, 'expires' => '']);

        $logs = $this->getTableLocator()->get('ActionLogs');
        $created = $logs->find()->where(['type' => 'api_token_created'])->orderBy(['id' => 'DESC'])->firstOrFail();
        $this->assertStringContainsString($name, $created->text);
        $this->assertStringNotContainsString('fcs_', $created->text, 'the token itself is never logged');
        $this->assertStringContainsString((string) $customerId, $created->text);

        $tokenId = $tokens->find()->where(['name' => $name])->firstOrFail()->id;
        $this->post('/admin/api-access/revoke-token', ['token_id' => $tokenId]);
        $revoked = $logs->find()->where(['type' => 'api_token_revoked'])->orderBy(['id' => 'DESC'])->firstOrFail();
        $this->assertStringContainsString($name, $revoked->text);
        $this->assertFalse((bool) $tokens->get($tokenId)->active);
    }

    public function testMeShowsWhenTheTokenExpires(): void
    {
        $expires = DateTime::now()->addDays(40);
        $token = $this->tokenFor([P::API_PRODUCTS_READ], $expires);
        $this->call('GET', '/api/v1/me', $token);
        $data = json_decode((string) $this->_response->getBody(), true);
        $this->assertSame($expires->format('Y-m-d'), substr((string) $data['token_expires'], 0, 10));

        $forever = $this->tokenFor([P::API_PRODUCTS_READ], null);
        $this->call('GET', '/api/v1/me', $forever);
        $this->assertNull(json_decode((string) $this->_response->getBody(), true)['token_expires']);
    }
}
