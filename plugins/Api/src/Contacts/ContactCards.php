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

namespace Api\Contacts;

use App\Model\Entity\Customer;
use App\Services\ContactsSettingsService;
use Cake\I18n\Date;
use Cake\ORM\Locator\LocatorInterface;

/**
 * Shared by the JSON/vCard export (ContactsController) and the CardDAV server (CarddavController):
 * which members are visible as contacts and how they are turned into vCards.
 */
class ContactCards
{
    public const DEFAULT_COUNTRY_CODE = '43';

    /**
     * Active members without API users; depending on the settings FCS_CONTACTS_MODE and FCS_CONTACTS_ORDERED_WITHIN_MONTHS.
     *
     * @return list<\App\Model\Entity\Customer>
     */
    public static function customers(LocatorInterface $locator): array
    {
        $conditions = [
            'Customers.active' => APP_ON,
            'Customers.is_api_user' => false,
        ];
        $mode = ContactsSettingsService::getMode();
        if ($mode === ContactsSettingsService::MODE_OPT_OUT) {
            $conditions['Customers.contacts_opt_out'] = false;
        } elseif ($mode !== ContactsSettingsService::MODE_ALL) {
            $conditions['Customers.share_contact_data'] = true; // consent (default)
        }
        $months = ContactsSettingsService::getOrderedWithinMonths();
        if ($months > 0) {
            // only members that ordered (or have a future order) in the last X months
            $conditions['Customers.id_customer IN'] = $locator->get('OrderDetails')->find()
                ->select(['id_customer'])
                ->where(['OrderDetails.pickup_day >=' => Date::now()->subMonths($months)->format('Y-m-d')]);
        }
        return array_values($locator->get('Customers')->find()
            ->contain(['AddressCustomers'])
            ->where($conditions)
            ->orderBy(['Customers.lastname' => 'ASC', 'Customers.firstname' => 'ASC', 'Customers.id_customer' => 'ASC'])
            ->all()->toList());
    }

    public static function uid(int $customerId): string
    {
        return 'fcs-customer-' . $customerId;
    }

    /**
     * Customer id from a resource name like "fcs-customer-17.vcf" (null if it is none).
     */
    public static function customerIdFromName(string $name): ?int
    {
        return preg_match('/^fcs-customer-(\d+)\.vcf$/', $name, $m) ? (int) $m[1] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Customer $c): array
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

    /**
     * International format (+436641234567), so that caller ID and messengers (Signal, WhatsApp)
     * recognise the number. Numbers that cannot be interpreted are returned unchanged.
     */
    public static function internationalPhone(string $phone, string $countryCode = self::DEFAULT_COUNTRY_CODE): string
    {
        $phone = trim($phone);
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if ($digits === '') {
            return $phone;
        }
        if (str_starts_with($phone, '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            return '+' . $countryCode . substr($digits, 1);
        }
        return $phone;
    }

    private static function escape(string $value): string
    {
        return str_replace(["\\", "\r\n", "\n", ',', ';'], ["\\\\", '\\n', '\\n', '\\,', '\\;'], $value);
    }

    public static function vcard(Customer $c): string
    {
        $d = self::toArray($c);
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:' . self::uid($d['id']),
            'N:' . self::escape($d['lastname']) . ';' . self::escape($d['firstname']) . ';;;',
            'FN:' . self::escape(trim($d['firstname'] . ' ' . $d['lastname'])),
        ];
        if ($d['email'] !== '') {
            $lines[] = 'EMAIL;TYPE=INTERNET:' . self::escape($d['email']);
        }
        if ($d['phone_mobile'] !== '') {
            $lines[] = 'TEL;TYPE=CELL:' . self::escape(self::internationalPhone($d['phone_mobile']));
        }
        if ($d['phone'] !== '') {
            $lines[] = 'TEL;TYPE=VOICE:' . self::escape(self::internationalPhone($d['phone']));
        }
        if ($d['address1'] !== '' || $d['city'] !== '') {
            $lines[] = 'ADR;TYPE=HOME:;;' . self::escape($d['address1']) . ';' . self::escape($d['city']) . ';;' . self::escape($d['postcode']) . ';';
        }
        if ($d['modified']) {
            $lines[] = 'REV:' . $d['modified'];
        }
        $lines[] = 'END:VCARD';
        return join("\r\n", $lines) . "\r\n";
    }

    public static function etag(string $vcard): string
    {
        return '"' . md5($vcard) . '"';
    }
}
