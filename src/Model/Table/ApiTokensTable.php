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

use App\Model\Entity\Customer;
use Cake\I18n\DateTime;

/**
 * Bearer tokens for the REST API. Only a SHA-256 hash is stored; the plain token is shown once.
 *
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\ApiToken>
 */
class ApiTokensTable extends AppTable
{
    public const TOKEN_PREFIX = 'fcs_';

    public function initialize(array $config): void
    {
        $this->setTable('api_tokens');
        parent::initialize($config);
        $this->belongsTo('Customers', [
            'foreignKey' => 'customer_id',
        ]);
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Creates a token and returns the plain value (only available now).
     */
    public function generate(int $customerId, string $name, ?int $createdBy, ?DateTime $expires = null): string
    {
        $plain = self::TOKEN_PREFIX . bin2hex(random_bytes(24));
        $this->saveOrFail($this->newEntity([
            'customer_id' => $customerId,
            'name' => mb_substr(trim($name) ?: 'API', 0, 100),
            'token_hash' => self::hash($plain),
            'token_hint' => substr($plain, -4),
            'active' => true,
            'expires' => $expires,
            'created' => DateTime::now(),
            'created_by' => $createdBy,
        ]));
        return $plain;
    }

    public function revoke(int $tokenId): bool
    {
        return (bool) $this->updateAll(['active' => false], ['id' => $tokenId]);
    }

    /**
     * Customer (with address, like the auth finder) for a valid token, or null.
     * Valid = active, not expired. API users are inactive customers on purpose (no shop login),
     * normal users must be active.
     */
    public function findCustomerByPlainToken(string $plainToken): ?Customer
    {
        if (!str_starts_with($plainToken, self::TOKEN_PREFIX)) {
            return null;
        }
        $token = $this->find()
            ->where([
                'token_hash' => self::hash($plainToken),
                'active' => true,
                'OR' => ['expires IS' => null, 'expires >' => DateTime::now()],
            ])
            ->first();
        if ($token === null) {
            return null;
        }
        /** @var \App\Model\Entity\Customer|null $customer */
        $customer = $this->Customers->find()
            ->where(['Customers.id_customer' => $token->customer_id])
            ->contain(['AddressCustomers'])
            ->first();
        if ($customer === null || (!$customer->is_api_user && !$customer->active)) {
            return null;
        }
        $this->updateAll(['last_used' => DateTime::now()], ['id' => $token->id]);
        $customer->set('api_token_name', $token->name);
        $customer->set('api_token_expires', $token->expires?->toIso8601String());
        return $customer;
    }
}
