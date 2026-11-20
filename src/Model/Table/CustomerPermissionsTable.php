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

namespace App\Model\Table;

use Cake\I18n\DateTime;

/**
 * Explicit permissions per customer, granted in addition to the customer group.
 * Nobody gets them implicitly - not even superadmins - so e.g. access to member contact
 * data has to be granted deliberately.
 *
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\CustomerPermission>
 */
class CustomerPermissionsTable extends AppTable
{
    public const API_PRODUCTS_READ = 'api.products.read';
    public const API_PRODUCTS_WRITE = 'api.products.write';
    public const API_ORDERS_READ = 'api.orders.read';
    public const API_ORDERS_WRITE = 'api.orders.write';
    public const API_MANUFACTURERS_READ = 'api.manufacturers.read';
    public const API_MANUFACTURERS_WRITE = 'api.manufacturers.write';
    public const API_CUSTOMERS_READ = 'api.customers.read';
    public const API_CUSTOMERS_WRITE = 'api.customers.write';
    public const CONTACTS_READ = 'contacts.read';

    public function initialize(array $config): void
    {
        $this->setTable('customer_permissions');
        parent::initialize($config);
        $this->belongsTo('Customers', [
            'foreignKey' => 'customer_id',
        ]);
    }

    /**
     * @return array<string, string> permission => label
     */
    public static function getAll(): array
    {
        return [
            self::API_PRODUCTS_READ => __('Permission_api_products_read'),
            self::API_PRODUCTS_WRITE => __('Permission_api_products_write'),
            self::API_ORDERS_READ => __('Permission_api_orders_read'),
            self::API_ORDERS_WRITE => __('Permission_api_orders_write'),
            self::API_MANUFACTURERS_READ => __('Permission_api_manufacturers_read'),
            self::API_MANUFACTURERS_WRITE => __('Permission_api_manufacturers_write'),
            self::API_CUSTOMERS_READ => __('Permission_api_customers_read'),
            self::API_CUSTOMERS_WRITE => __('Permission_api_customers_write'),
            self::CONTACTS_READ => __('Permission_contacts_read'),
        ];
    }

    public function has(int $customerId, string $permission): bool
    {
        return $this->exists(['customer_id' => $customerId, 'permission' => $permission]);
    }

    /**
     * @return string[]
     */
    public function getForCustomer(int $customerId): array
    {
        return $this->find('list', valueField: 'permission')
            ->where(['customer_id' => $customerId])
            ->orderBy(['permission' => 'ASC'])
            ->all()->toList();
    }

    /**
     * Replaces the permissions of a customer. Unknown permissions are rejected.
     * @param string[] $permissions
     */
    public function setForCustomer(int $customerId, array $permissions, ?int $changedBy): void
    {
        $unknown = array_diff($permissions, array_keys(self::getAll()));
        if ($unknown) {
            throw new \InvalidArgumentException('unknown permission: ' . join(', ', $unknown));
        }
        $this->getConnection()->transactional(function () use ($customerId, $permissions, $changedBy) {
            $this->deleteAll(['customer_id' => $customerId]);
            foreach (array_unique($permissions) as $permission) {
                $this->saveOrFail($this->newEntity([
                    'customer_id' => $customerId,
                    'permission' => $permission,
                    'created' => DateTime::now(),
                    'created_by' => $changedBy,
                ]));
            }
        });
    }
}
