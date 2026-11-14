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

namespace Admin\Controller;

use App\Model\Entity\Customer;
use App\Model\Table\CustomerPermissionsTable;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * Superadmin page: API users, API tokens and explicit permissions per customer.
 */
class ApiAccessController extends AdminAppController
{
    public function index(): void
    {
        $customersTable = $this->getTableLocator()->get('Customers');
        $permissionsTable = $this->getTableLocator()->get('CustomerPermissions');
        $tokensTable = $this->getTableLocator()->get('ApiTokens');

        $withPermissions = $permissionsTable->find()->select(['customer_id'])->distinct();
        $customers = $customersTable->find()
            ->where(['OR' => [
                'Customers.is_api_user' => true,
                'Customers.id_customer IN' => $withPermissions,
            ]])
            ->orderBy(['Customers.is_api_user' => 'DESC', 'Customers.lastname' => 'ASC'])
            ->all();

        $rows = [];
        foreach ($customers as $customer) {
            $rows[] = [
                'customer' => $customer,
                'permissions' => $permissionsTable->getForCustomer($customer->id_customer),
                'tokens' => $tokensTable->find()->where(['customer_id' => $customer->id_customer])
                    ->orderBy(['created' => 'DESC'])->all()->toList(),
            ];
        }

        $this->set('rows', $rows);
        $this->set('allPermissions', CustomerPermissionsTable::getAll());
        $this->set('customersForDropdown', $customersTable->getForDropdown(false, false));
        $this->set('newToken', $this->getRequest()->getSession()->consume('Admin.ApiAccess.newToken'));
        $this->set('title_for_layout', __('API_and_permissions'));
    }

    public function createApiUser(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $name = trim(strip_tags((string) $this->getRequest()->getData('name')));
        if ($name === '') {
            $this->Flash->error(__('Please_enter_a_name.'));
            return $this->redirect(['action' => 'index']);
        }
        $customersTable = $this->getTableLocator()->get('Customers');
        $customer = $customersTable->newEntity([], ['validate' => false]);
        $customer = $customersTable->patchEntity($customer, [
            'firstname' => 'API',
            'lastname' => mb_substr($name, 0, 50),
            'email' => 'api-' . bin2hex(random_bytes(6)) . '@api.invalid',
            'id_default_group' => Customer::GROUP_SELF_SERVICE_CUSTOMER,
            'active' => APP_OFF, // API users can never log in to the shop
            'is_api_user' => true,
            'date_add' => DateTime::now(),
            'date_upd' => DateTime::now(),
        ], ['validate' => false, 'accessibleFields' => ['*' => true]]);
        $customersTable->saveOrFail($customer, ['checkRules' => false]);
        $this->Flash->success(__('The_API_user_{0}_has_been_created.', ['<b>' . h($name) . '</b>']));
        return $this->redirect(['action' => 'index']);
    }

    public function savePermissions(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $customerId = (int) $this->getRequest()->getData('customer_id');
        $this->getCustomerOrFail($customerId);
        $permissions = array_keys(array_filter((array) $this->getRequest()->getData('permissions')));
        $this->getTableLocator()->get('CustomerPermissions')
            ->setForCustomer($customerId, $permissions, $this->identity->getId());
        $this->getTableLocator()->get('ActionLogs')->customSave(
            'customer_permissions_changed', $this->identity->getId(), $customerId, 'customers',
            __('Permissions_of_customer_{0}_changed:_{1}', [$customerId, join(', ', $permissions) ?: '-']),
        );
        $this->Flash->success(__('The_permissions_have_been_saved.'));
        return $this->redirect(['action' => 'index']);
    }

    public function createToken(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $customerId = (int) $this->getRequest()->getData('customer_id');
        $this->getCustomerOrFail($customerId);
        $expires = (string) $this->getRequest()->getData('expires');
        $plain = $this->getTableLocator()->get('ApiTokens')->generate(
            $customerId,
            (string) $this->getRequest()->getData('name'),
            $this->identity->getId(),
            $expires !== '' ? new DateTime($expires . ' 23:59:59') : null,
        );
        // shown exactly once on the next page
        $this->getRequest()->getSession()->write('Admin.ApiAccess.newToken', $plain);
        return $this->redirect(['action' => 'index']);
    }

    public function revokeToken(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $this->getTableLocator()->get('ApiTokens')->revoke((int) $this->getRequest()->getData('token_id'));
        $this->Flash->success(__('The_token_has_been_revoked.'));
        return $this->redirect(['action' => 'index']);
    }

    private function getCustomerOrFail(int $customerId): Customer
    {
        $customer = $this->getTableLocator()->get('Customers')->find()
            ->where(['Customers.id_customer' => $customerId])->first();
        if ($customer === null) {
            throw new NotFoundException();
        }
        return $customer;
    }
}
