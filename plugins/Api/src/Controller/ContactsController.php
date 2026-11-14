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
use Cake\Http\Response;

/**
 * GET /api/v1/contacts (JSON) and /api/v1/contacts.vcf (vCard 3.0)
 *
 * Contact data of active members who consented in their profile (share_contact_data).
 * Needs the explicit permission contacts.read; every access is written to the action log.
 */
class ContactsController extends ApiAppController
{
    public function index(): Response
    {
        if ($r = $this->requirePermission(P::CONTACTS_READ)) {
            return $r;
        }
        $customers = $this->getTableLocator()->get('Customers')->find()
            ->contain(['AddressCustomers'])
            ->where([
                'Customers.active' => APP_ON,
                'Customers.is_api_user' => false,
                'Customers.share_contact_data' => true,
            ])
            ->orderBy(['Customers.lastname' => 'ASC', 'Customers.firstname' => 'ASC'])
            ->all()->toList();

        $vcf = $this->getRequest()->getParam('_ext') === 'vcf';
        $this->getTableLocator()->get('ActionLogs')->customSave(
            'customer_contacts_exported', $this->customer->id_customer, 0, 'customers',
            __('{0}_contacts_exported_via_API_({1}).', [count($customers), $vcf ? 'vCard' : 'JSON'])
                . ' (' . ($this->customer->get('api_token_name') ?? '-') . ')',
        );

        if ($vcf) {
            return $this->getResponse()
                ->withType('text/vcard')
                ->withCharset('UTF-8')
                ->withStringBody(join('', array_map(fn($c) => $this->vcard($c), $customers)));
        }
        return $this->json([
            'count' => count($customers),
            'contacts' => array_map(fn($c) => $this->toArray($c), $customers),
        ]);
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
            'phone' => (string) ($a->phone ?? ''),
            'phone_mobile' => (string) ($a->phone_mobile ?? ''),
            'address1' => (string) ($a->address1 ?? ''),
            'postcode' => (string) ($a->postcode ?? ''),
            'city' => (string) ($a->city ?? ''),
            'modified' => $c->date_upd?->toIso8601String(),
        ];
    }

    private function vcard(Customer $c): string
    {
        $e = fn(string $v) => str_replace(["\\", "\n", ',', ';'], ["\\\\", '\\n', '\\,', '\\;'], $v);
        $d = $this->toArray($c);
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:fcs-customer-' . $d['id'],
            'N:' . $e($d['lastname']) . ';' . $e($d['firstname']) . ';;;',
            'FN:' . $e(trim($d['firstname'] . ' ' . $d['lastname'])),
        ];
        if ($d['email'] !== '') {
            $lines[] = 'EMAIL;TYPE=INTERNET:' . $e($d['email']);
        }
        if ($d['phone_mobile'] !== '') {
            $lines[] = 'TEL;TYPE=CELL:' . $e($d['phone_mobile']);
        }
        if ($d['phone'] !== '') {
            $lines[] = 'TEL;TYPE=VOICE:' . $e($d['phone']);
        }
        if ($d['address1'] !== '' || $d['city'] !== '') {
            $lines[] = 'ADR;TYPE=HOME:;;' . $e($d['address1']) . ';' . $e($d['city']) . ';;' . $e($d['postcode']) . ';';
        }
        if ($d['modified']) {
            $lines[] = 'REV:' . $d['modified'];
        }
        $lines[] = 'END:VCARD';
        return join("\r\n", $lines) . "\r\n";
    }
}
