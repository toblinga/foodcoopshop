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
use App\Services\ContactsSettingsService;
use App\Test\Fixture\ProductsFixture;
use App\Test\TestCase\AppCakeTestCase;
use App\Test\TestCase\Traits\AppIntegrationTestTrait;
use App\Test\TestCase\Traits\LoginTrait;
use Cake\Core\Configure;
use Cake\I18n\Date;

/**
 * Address book (contacts API / CardDAV) as admin settings: mode and "ordered in the last X months".
 */
class ApiContactsSettingsTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;
    use LoginTrait;

    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $adminId = Configure::read('test.adminId');
        $this->getTableLocator()->get('CustomerPermissions')->setForCustomer($adminId, [P::CONTACTS_READ], null);
        $this->token = $this->getTableLocator()->get('ApiTokens')->generate($adminId, 'contacts', null);
    }

    /**
     * @return list<int> ids of the members in the address book
     */
    private function contactIds(): array
    {
        $this->_request = [];
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . $this->token]]);
        $this->get('/api/v1/contacts');
        $this->assertResponseOk();
        $data = (array) json_decode((string) $this->_response->getBody(), true);
        return array_map(fn($c) => (int) $c['id'], $data['contacts']);
    }

    private function orderAndSetPickupDay(string $pickupDay): void
    {
        $this->loginAsCustomer();
        $this->addProductToCart(ProductsFixture::ID_ARTICHOKE, 1);
        $this->finishCart();
        $this->getTableLocator()->get('OrderDetails')->updateAll(
            ['pickup_day' => $pickupDay],
            ['id_customer' => Configure::read('test.customerId')],
        );
    }

    public function testStandardIsConsentAndTheSettingsExist(): void
    {
        $this->assertSame('consent', ContactsSettingsService::getMode());
        $this->assertSame(0, ContactsSettingsService::getOrderedWithinMonths());
        $names = $this->getTableLocator()->get('Configurations')->find('list', keyField: 'name', valueField: 'type')
            ->where(['name IN' => ['FCS_CONTACTS_MODE', 'FCS_CONTACTS_ORDERED_WITHIN_MONTHS']])->toArray();
        $this->assertSame(['FCS_CONTACTS_MODE' => 'dropdown', 'FCS_CONTACTS_ORDERED_WITHIN_MONTHS' => 'number'], $names);
        // consent without any consent: nobody
        $this->getTableLocator()->get('Customers')->updateAll(['share_contact_data' => false], []);
        $this->assertSame([], $this->contactIds());
    }

    public function testModeAllShowsEveryActiveMemberButNeverApiUsers(): void
    {
        $this->changeConfiguration('FCS_CONTACTS_MODE', 'all');
        $customers = $this->getTableLocator()->get('Customers');
        $expected = $customers->find()->where(['active' => APP_ON, 'is_api_user' => false])->count();
        $ids = $this->contactIds();
        $this->assertCount($expected, $ids);
        $customers->updateAll(['is_api_user' => true], ['id_customer' => Configure::read('test.customerId')]);
        $this->assertNotContains(Configure::read('test.customerId'), $this->contactIds());
    }

    public function testOnlyMembersWhoOrderedInTheLastMonths(): void
    {
        $this->changeConfiguration('FCS_CONTACTS_MODE', 'all');
        $memberId = Configure::read('test.customerId');
        $everyone = $this->contactIds();
        $this->assertContains($memberId, $everyone);

        $this->orderAndSetPickupDay(Date::now()->subMonths(2)->format('Y-m-d'));
        $this->changeConfiguration('FCS_CONTACTS_ORDERED_WITHIN_MONTHS', 6);
        $recent = $this->contactIds();
        $this->assertContains($memberId, $recent, 'ordered 2 months ago, limit is 6 months');
        $this->assertLessThan(count($everyone), count($recent), 'members without a recent order are left out');

        $this->getTableLocator()->get('OrderDetails')->updateAll(
            ['pickup_day' => Date::now()->subMonths(14)->format('Y-m-d')], ['id_customer' => $memberId]);
        $this->assertNotContains($memberId, $this->contactIds(), 'last order 14 months ago');

        $this->changeConfiguration('FCS_CONTACTS_ORDERED_WITHIN_MONTHS', 24);
        $this->assertContains($memberId, $this->contactIds());

        // a future order counts as well
        $this->getTableLocator()->get('OrderDetails')->updateAll(
            ['pickup_day' => Date::now()->addDays(5)->format('Y-m-d')], ['id_customer' => $memberId]);
        $this->changeConfiguration('FCS_CONTACTS_ORDERED_WITHIN_MONTHS', 1);
        $this->assertContains($memberId, $this->contactIds());

        $this->changeConfiguration('FCS_CONTACTS_ORDERED_WITHIN_MONTHS', 0); // 0 = everyone again
        $this->assertCount(count($everyone), $this->contactIds());
    }

    public function testMonthsAreCombinedWithConsent(): void
    {
        $memberId = Configure::read('test.customerId');
        $this->orderAndSetPickupDay(Date::now()->subMonths(1)->format('Y-m-d'));
        $this->changeConfiguration('FCS_CONTACTS_ORDERED_WITHIN_MONTHS', 6);
        $this->getTableLocator()->get('Customers')->updateAll(['share_contact_data' => false], []);
        $this->assertSame([], $this->contactIds(), 'consent mode: ordered recently but no consent');
        $this->getTableLocator()->get('Customers')->updateAll(['share_contact_data' => true], ['id_customer' => $memberId]);
        $this->assertSame([$memberId], $this->contactIds());
    }

    public function testFallbackToConfigValueAndUnknownValuesMeanConsent(): void
    {
        Configure::write('appDb.FCS_CONTACTS_MODE', 'garbage');
        Configure::write('app.contactsMode', 'all');
        $this->assertSame('all', ContactsSettingsService::getMode());
        Configure::write('app.contactsMode', 'nonsense');
        $this->assertSame('consent', ContactsSettingsService::getMode());
        Configure::write('appDb.FCS_CONTACTS_ORDERED_WITHIN_MONTHS', '-5');
        $this->assertSame(0, ContactsSettingsService::getOrderedWithinMonths());
    }

    public function testAdminCanSeeAndChangeTheSettings(): void
    {
        $this->loginAsSuperadmin();
        $this->get('/admin/configurations');
        $this->assertResponseOk();
        $this->assertResponseContains('Adressbuch (Kontakte-API / CardDAV)');
        $this->assertResponseContains('nur Mitglieder, die in den letzten');
        $this->get('/admin/configurations/edit/FCS_CONTACTS_MODE');
        $this->assertResponseOk();
        $this->assertResponseContains('Alle aktiven Mitglieder, außer wer widersprochen hat');
        $this->assertResponseContains('Nur Mitglieder, die zugestimmt haben');
    }
}
