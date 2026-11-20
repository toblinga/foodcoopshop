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

namespace Api\Controller;

use App\Model\Entity\Customer;
use App\Model\Table\CustomerPermissionsTable as P;
use App\Services\SanitizeService;
use Cake\Http\Response;

/**
 * /api/v1/customers – read and change members (no passwords, no group, no consent on their behalf).
 * Same rules as the admin form (Admin\Traits\Customers\EditTrait).
 */
class CustomersController extends ApiAppController
{
    private const FIELDS = ['firstname', 'lastname', 'email', 'active', 'is_company', 'address', 'if_unmodified_since'];
    private const ADDRESS_FIELDS = ['address1', 'address2', 'postcode', 'city', 'phone', 'phone_mobile'];

    public function index(): Response
    {
        if ($r = $this->requirePermission(P::API_CUSTOMERS_READ)) {
            return $r;
        }
        $query = $this->query();
        if (($active = $this->getRequest()->getQuery('active')) !== null) {
            $query->where(['Customers.active' => (int) $active]);
        }
        if ($since = $this->getRequest()->getQuery('modified_since')) {
            $sinceDate = $this->parseDateTime($since);
            if ($sinceDate === null) {
                return $this->error(422, 'modified_since needs a date');
            }
            $query->where(['Customers.date_upd >=' => $sinceDate]);
        }
        $list = array_map(fn($c) => $this->toArray($c), $query->all()->toList());
        return $this->json(['count' => count($list), 'customers' => $list]);
    }

    public function view(int $id): Response
    {
        if ($r = $this->requirePermission(P::API_CUSTOMERS_READ)) {
            return $r;
        }
        $c = $this->query()->where(['Customers.id_customer' => $id])->first();
        return $c ? $this->json($this->toArray($c)) : $this->error(404, 'customer not found');
    }

    public function edit(int $id): Response
    {
        if ($r = $this->requirePermission(P::API_CUSTOMERS_WRITE)) {
            return $r;
        }
        $customer = $this->query()->where(['Customers.id_customer' => $id])->first();
        if ($customer === null) {
            return $this->error(404, 'customer not found');
        }
        $data = $this->body();
        $unknown = array_merge(
            array_diff(array_keys($data), self::FIELDS),
            array_map(fn($f) => 'address.' . $f, array_diff(array_keys((array) ($data['address'] ?? [])), self::ADDRESS_FIELDS)),
        );
        if ($unknown) {
            return $this->error(422, 'unknown or not changeable fields', ['fields' => array_values($unknown)]);
        }
        if (!empty($data['if_unmodified_since'])) {
            $unmodifiedSince = $this->parseDateTime($data['if_unmodified_since']);
            if ($unmodifiedSince === null) {
                return $this->error(422, 'if_unmodified_since needs a date');
            }
        }
        if (!empty($unmodifiedSince) && $customer->date_upd > $unmodifiedSince) {
            return $this->error(409, 'customer was modified in the meantime', ['modified' => $customer->date_upd->toIso8601String()]);
        }
        unset($data['if_unmodified_since']);

        $sanitize = new SanitizeService();
        $data = $sanitize->stripTagsAndPurifyRecursive($sanitize->trimRecursive($data));
        // like the admin form: name and e-mail are kept in sync with the address record
        $address = (array) ($data['address'] ?? []);
        foreach (['firstname', 'lastname', 'email'] as $f) {
            if (isset($data[$f])) {
                $address[$f] = $data[$f];
            }
        }
        $patch = $data;
        unset($patch['address']);
        if ($address) {
            $patch['address_customer'] = $address;
        }

        $before = $this->toArray($customer);
        $table = $this->getTableLocator()->get('Customers');
        $customer = $table->patchEntity($customer, $patch, [
            'validate' => 'edit',
            'associated' => ['AddressCustomers'],
            'accessibleFields' => ['active' => true, 'is_company' => true],
        ]);
        if ($customer->hasErrors()) {
            return $this->error(422, 'validation failed', ['errors' => $customer->getErrors()]);
        }
        if ($this->getRequest()->getQuery('dry_run')) {
            return $this->json(['dry_run' => true, 'before' => $before, 'after' => $this->toArray($customer)]);
        }
        $table->saveOrFail($customer, ['associated' => ['AddressCustomers']]);
        $this->getTableLocator()->get('ActionLogs')->customSave(
            'customer_profile_changed', $this->customer->id_customer, $customer->id_customer, 'customers',
            __('The_profile_of_{0}_was_changed.', ['<b>' . $customer->name . '</b>'])
                . ' (API ' . ($this->customer->get('api_token_name') ?? '-') . ')',
        );
        $fresh = $this->query()->where(['Customers.id_customer' => $customer->id_customer])->firstOrFail();
        return $this->json($this->toArray($fresh));
    }

    private function query(): \Cake\ORM\Query\SelectQuery
    {
        return $this->getTableLocator()->get('Customers')->find()
            ->contain(['AddressCustomers'])
            ->where(['Customers.is_api_user' => false])
            ->orderBy(['Customers.lastname' => 'ASC', 'Customers.firstname' => 'ASC']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Customer $c): array
    {
        $a = $c->address_customer;
        return [
            'id' => $c->id_customer,
            'firstname' => (string) $c->firstname,
            'lastname' => (string) $c->lastname,
            'email' => (string) $c->email,
            'active' => (int) $c->active,
            'group' => (int) $c->id_default_group,
            'is_company' => (bool) $c->is_company,
            'newsletter_enabled' => (bool) $c->newsletter_enabled,
            'share_contact_data' => (bool) $c->share_contact_data,
            'address' => $a ? array_combine(self::ADDRESS_FIELDS, array_map(fn($f) => (string) $a->{$f}, self::ADDRESS_FIELDS)) : null,
            'created' => $c->date_add?->toIso8601String(),
            'modified' => $c->date_upd?->toIso8601String(),
        ];
    }
}
