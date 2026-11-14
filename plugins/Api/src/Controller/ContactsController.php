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

use Api\Contacts\ContactCards;
use App\Model\Table\CustomerPermissionsTable as P;
use Cake\Http\Response;

/**
 * GET /api/v1/contacts (JSON) and /api/v1/contacts.vcf (vCard 3.0)
 *
 * Contact data of active members who consented in their profile (share_contact_data).
 * Needs the explicit permission contacts.read; every access is written to the action log.
 * The same data is available as address book via CardDAV (/api/v1/carddav/).
 */
class ContactsController extends ApiAppController
{
    public function index(): Response
    {
        if ($r = $this->requirePermission(P::CONTACTS_READ)) {
            return $r;
        }
        $customers = ContactCards::customers($this->getTableLocator());

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
                ->withStringBody(join('', array_map(fn($c) => ContactCards::vcard($c), $customers)));
        }
        return $this->json([
            'count' => count($customers),
            'contacts' => array_map(fn($c) => ContactCards::toArray($c), $customers),
        ]);
    }
}
