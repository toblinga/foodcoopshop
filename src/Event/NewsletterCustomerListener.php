<?php
declare(strict_types=1);

namespace App\Event;

use App\Model\Entity\Customer;
use App\Model\Table\CustomersTable;
use App\Services\Newsletter\NewsletterSubscriberService;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;
use Cake\Log\Log;
use Throwable;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Hält Kundenkonten (newsletter_enabled, E-Mail) und die Newsletter-Liste abgeglichen.
 * Wird global in Application::bootstrap() registriert, hört auf Model-Events der CustomersTable.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterCustomerListener implements EventListenerInterface
{

    public function implementedEvents(): array
    {
        return [
            'Model.afterSave' => 'afterSave',
            'Model.afterDelete' => 'afterDelete',
        ];
    }

    /**
     * @param EventInterface<\Cake\ORM\Table> $event
     */
    public function afterSave(EventInterface $event, EntityInterface $entity): void
    {
        if (!$event->getSubject() instanceof CustomersTable || !$entity instanceof Customer) {
            return;
        }
        if (!$entity->isNew() && !$entity->isDirty('email') && !$entity->isDirty('newsletter_enabled')) {
            return;
        }
        try {
            (new NewsletterSubscriberService())->syncFromCustomer($entity, $entity->isNew());
        } catch (Throwable $e) {
            // Speichern des Kundenkontos darf nie am Newsletter scheitern
            Log::error('Newsletter-Abgleich für Kunde ' . $entity->id_customer . ' fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * @param EventInterface<\Cake\ORM\Table> $event
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity): void
    {
        if (!$event->getSubject() instanceof CustomersTable || !$entity instanceof Customer) {
            return;
        }
        // Abonnent:in bleibt, nur die Verknüpfung wird gelöst
        (new NewsletterSubscriberService())->unlinkCustomer((int)$entity->id_customer);
    }

}
