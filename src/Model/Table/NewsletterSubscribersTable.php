<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\NewsletterSubscriber;
use ArrayObject;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 *
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\NewsletterSubscriber>
 */
class NewsletterSubscribersTable extends AppTable
{

    public function initialize(array $config): void
    {
        $this->setTable('newsletter_subscribers');
        parent::initialize($config);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('Customers', [
            'foreignKey' => 'id_customer',
        ]);
    }

    /**
     * @param EventInterface<\App\Model\Table\NewsletterSubscribersTable> $event
     * @param ArrayObject<string, mixed> $data
     * @param ArrayObject<string, mixed> $options
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        if (isset($data['email'])) {
            $data['email'] = NewsletterSubscriber::normalizeEmail((string)$data['email']);
        }
        foreach (['first_name', 'last_name'] as $field) {
            if (isset($data[$field])) {
                $value = $this->sanitizePlainStringValue($data[$field]);
                $data[$field] = $value === '' ? null : mb_substr($value, 0, 100);
            }
        }
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('email', __('Please_enter_your_email_address.'));
        $validator->email('email', false, __('The_email_address_is_not_valid.'));
        $validator->maxLength('email', 255);
        $validator->inList('status', [
            NewsletterSubscriber::STATUS_PENDING,
            NewsletterSubscriber::STATUS_ACTIVE,
            NewsletterSubscriber::STATUS_UNSUBSCRIBED,
            NewsletterSubscriber::STATUS_BOUNCED,
        ]);
        $validator->inList('source', [
            NewsletterSubscriber::SOURCE_HOME,
            NewsletterSubscriber::SOURCE_CUSTOMER_ACCOUNT,
            NewsletterSubscriber::SOURCE_IMPORT,
            NewsletterSubscriber::SOURCE_ADMIN,
        ]);
        $validator->allowEmptyString('first_name');
        $validator->allowEmptyString('last_name');
        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['email']));
        $rules->add($rules->isUnique(['token']));
        return $rules;
    }

    public function findByEmail(string $email): ?NewsletterSubscriber
    {
        /** @var NewsletterSubscriber|null $subscriber */
        $subscriber = $this->find()->where([
            $this->aliasField('email') => NewsletterSubscriber::normalizeEmail($email),
        ])->first();
        return $subscriber;
    }

    public function findByToken(string $token): ?NewsletterSubscriber
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        /** @var NewsletterSubscriber|null $subscriber */
        $subscriber = $this->find()->where([
            $this->aliasField('token') => $token,
        ])->first();
        return $subscriber;
    }

}
