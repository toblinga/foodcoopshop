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
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Base controller of the REST API: JSON only, bearer token (see Application::getAuthenticationService),
 * every resource checks an explicit permission (CustomerPermissionsTable).
 */
class ApiAppController extends Controller
{
    protected ?Customer $customer = null;

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->autoRender = false;
        $identity = $this->getRequest()->getAttribute('identity');
        $this->customer = $identity ? $identity->getOriginalData() : null;
        if ($this->customer === null) {
            $event->setResult($this->error(401, 'missing or invalid bearer token'));
            $event->stopPropagation();
        }
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return $this->getResponse()
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function error(int $status, string $message, array $details = []): Response
    {
        return $this->json(['error' => ['status' => $status, 'message' => $message] + ($details ? ['details' => $details] : [])], $status);
    }

    protected function can(string $permission): bool
    {
        return $this->customer !== null
            && $this->getTableLocator()->get('CustomerPermissions')->has($this->customer->id_customer, $permission);
    }

    /**
     * Returns an error response when the permission is missing, otherwise null.
     */
    protected function requirePermission(string $permission): ?Response
    {
        return $this->can($permission) ? null : $this->error(403, 'missing permission: ' . $permission);
    }
}
