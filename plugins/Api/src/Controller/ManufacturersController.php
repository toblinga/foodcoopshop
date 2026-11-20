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

use App\Controller\Component\StringComponent;
use App\Model\Entity\Manufacturer;
use App\Model\Table\CustomerPermissionsTable as P;
use App\Services\SanitizeService;
use Cake\Core\Configure;
use Cake\Http\Response;

/**
 * /api/v1/manufacturers – read, create and change manufacturers incl. address.
 * Same rules as the admin form (Admin\Traits\Manufacturers\AddEditTrait).
 */
class ManufacturersController extends ApiAppController
{
    private const FIELDS = ['name', 'description', 'short_description', 'active', 'is_private', 'uid_number',
        'iban', 'bic', 'bank_name', 'bank_account_holder', 'homepage', 'address', 'if_unmodified_since'];
    private const ADDRESS_FIELDS = ['firstname', 'lastname', 'address1', 'address2', 'postcode', 'city',
        'phone', 'phone_mobile', 'email'];

    public function index(): Response
    {
        if ($r = $this->requirePermission(P::API_MANUFACTURERS_READ)) {
            return $r;
        }
        $query = $this->query();
        if (($active = $this->getRequest()->getQuery('active')) !== null) {
            $query->where(['Manufacturers.active' => (int) $active]);
        }
        $list = array_map(fn($m) => $this->toArray($m), $query->all()->toList());
        return $this->json(['count' => count($list), 'manufacturers' => $list]);
    }

    public function view(int $id): Response
    {
        if ($r = $this->requirePermission(P::API_MANUFACTURERS_READ)) {
            return $r;
        }
        $m = $this->query()->where(['Manufacturers.id_manufacturer' => $id])->first();
        return $m ? $this->json($this->toArray($m)) : $this->error(404, 'manufacturer not found');
    }

    public function add(): Response
    {
        if ($r = $this->requirePermission(P::API_MANUFACTURERS_WRITE)) {
            return $r;
        }
        $table = $this->getTableLocator()->get('Manufacturers');
        $manufacturer = $table->newEntity([
            'active' => APP_ON,
            'is_private' => Configure::read('appDb.FCS_SEND_INVOICES_TO_CUSTOMERS') ? APP_OFF : APP_ON,
            'anonymize_customers' => APP_ON,
        ], ['validate' => false]);
        return $this->save($manufacturer, false);
    }

    public function edit(int $id): Response
    {
        if ($r = $this->requirePermission(P::API_MANUFACTURERS_WRITE)) {
            return $r;
        }
        $manufacturer = $this->query()->where(['Manufacturers.id_manufacturer' => $id])->first();
        if ($manufacturer === null) {
            return $this->error(404, 'manufacturer not found');
        }
        return $this->save($manufacturer, true);
    }

    private function save(Manufacturer $manufacturer, bool $isEdit): Response
    {
        $data = $this->body();
        $unknown = array_merge(
            array_diff(array_keys($data), self::FIELDS),
            array_map(fn($f) => 'address.' . $f, array_diff(array_keys((array) ($data['address'] ?? [])), self::ADDRESS_FIELDS)),
        );
        if ($unknown) {
            return $this->error(422, 'unknown fields', ['fields' => array_values($unknown)]);
        }
        if ($isEdit && !empty($data['if_unmodified_since'])) {
            $unmodifiedSince = $this->parseDateTime($data['if_unmodified_since']);
            if ($unmodifiedSince === null) {
                return $this->error(422, 'if_unmodified_since needs a date');
            }
        }
        if ($isEdit && !empty($unmodifiedSince) && $manufacturer->modified > $unmodifiedSince) {
            return $this->error(409, 'manufacturer was modified in the meantime', ['modified' => $manufacturer->modified->toIso8601String()]);
        }
        unset($data['if_unmodified_since']);

        $sanitize = new SanitizeService();
        $data = $sanitize->stripTagsAndPurifyRecursive($sanitize->trimRecursive($data), ['description', 'short_description']);
        foreach (['iban', 'bic'] as $f) {
            if (isset($data[$f])) {
                $data[$f] = str_replace(' ', '', (string) $data[$f]);
            }
        }
        if (!empty($data['homepage'])) {
            $data['homepage'] = StringComponent::addProtocolToUrl((string) $data['homepage']);
        }
        $patch = $data;
        if (isset($data['address'])) {
            $patch['address_manufacturer'] = $data['address'];
            unset($patch['address']);
        }

        $before = $isEdit ? $this->toArray($manufacturer) : null;
        $table = $this->getTableLocator()->get('Manufacturers');
        $oldEmail = $manufacturer->address_manufacturer->email ?? null;
        $manufacturer = $table->patchEntity($manufacturer, $patch, ['associated' => ['AddressManufacturers']]);
        if ($manufacturer->hasErrors()) {
            return $this->error(422, 'validation failed', ['errors' => $manufacturer->getErrors()]);
        }
        if ($this->getRequest()->getQuery('dry_run')) {
            return $this->json(['dry_run' => true, 'before' => $before, 'after' => $this->toArray($manufacturer)]);
        }
        $manufacturer = $table->saveOrFail($manufacturer);

        // like the admin form: the manufacturer login (customer record) follows the address
        if (isset($data['address'])) {
            $customersTable = $this->getTableLocator()->get('Customers');
            $customer = $isEdit && $oldEmail ? $table->getCustomerRecord($oldEmail) : null;
            $customerData = [
                'email' => $manufacturer->address_manufacturer->email,
                'firstname' => $manufacturer->address_manufacturer->firstname,
                'lastname' => $manufacturer->address_manufacturer->lastname,
                'active' => APP_ON,
            ];
            $customersTable->save($customer ? $customersTable->patchEntity($customer, $customerData) : $customersTable->newEntity($customerData));
        }

        $message = __('The_manufacturer_{0}_has_been_{1}.', ['<b>' . $manufacturer->name . '</b>', $isEdit ? __('changed') : __('created')])
            . ' (API ' . ($this->customer->get('api_token_name') ?? '-') . ')';
        $this->getTableLocator()->get('ActionLogs')->customSave(
            $isEdit ? 'manufacturer_changed' : 'manufacturer_added',
            $this->customer->id_customer, $manufacturer->id_manufacturer, 'manufacturers', $message,
        );
        $fresh = $this->query()->where(['Manufacturers.id_manufacturer' => $manufacturer->id_manufacturer])->firstOrFail();
        return $this->json($this->toArray($fresh), $isEdit ? 200 : 201);
    }

    private function query(): \Cake\ORM\Query\SelectQuery
    {
        return $this->getTableLocator()->get('Manufacturers')->find()
            ->contain(['AddressManufacturers'])
            ->orderBy(['Manufacturers.name' => 'ASC']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Manufacturer $m): array
    {
        $a = $m->address_manufacturer;
        return [
            'id' => $m->id_manufacturer,
            'name' => $m->name,
            'short_description' => (string) $m->short_description,
            'description' => (string) $m->description,
            'active' => (int) $m->active,
            'is_private' => (int) $m->is_private,
            'uid_number' => (string) $m->uid_number,
            'iban' => (string) $m->iban,
            'bic' => (string) $m->bic,
            'bank_name' => (string) $m->bank_name,
            'bank_account_holder' => (string) $m->bank_account_holder,
            'homepage' => (string) $m->homepage,
            'address' => $a ? array_combine(self::ADDRESS_FIELDS, array_map(fn($f) => (string) $a->{$f}, self::ADDRESS_FIELDS)) : null,
            'modified' => $m->modified?->toIso8601String(),
        ];
    }
}
