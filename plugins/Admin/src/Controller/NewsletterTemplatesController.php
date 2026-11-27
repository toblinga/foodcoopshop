<?php
declare(strict_types=1);

namespace Admin\Controller;

use App\Services\Newsletter\NewsletterEditorService;
use Cake\Http\Response;
use Throwable;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Gespeicherte Bausteine (type=block) und Vorlagen (type=template) des Newsletter-Editors.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterTemplatesController extends AdminAppController
{

    protected function service(): NewsletterEditorService
    {
        return new NewsletterEditorService();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return $this->response->withType('application/json')->withStatus($status)
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestData(): array
    {
        $request = $this->getRequest();
        if (str_contains((string)$request->getHeaderLine('Content-Type'), 'application/json')) {
            $data = json_decode((string)$request->getBody(), true);
            return is_array($data) ? $data : [];
        }
        return (array)$request->getData();
    }

    public function index(): void
    {
        $this->set('templates', $this->service()->listTemplates('template'));
        $this->set('blocks', $this->service()->listTemplates('block'));
        $this->set('title_for_layout', 'Newsletter: Vorlagen und Bausteine');
    }

    public function items(): Response
    {
        $type = (string)$this->getRequest()->getQuery('type');
        if (!in_array($type, ['block', 'template'], true)) {
            return $this->json(['status' => 0, 'msg' => 'type fehlt'], 400);
        }
        return $this->json(['status' => 1, 'items' => $this->service()->listTemplates($type)]);
    }

    public function save(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $data = $this->requestData();
        try {
            $item = $this->service()->saveTemplate((string)($data['type'] ?? ''), (string)($data['name'] ?? ''), $data['blocks'] ?? null);
        } catch (Throwable $e) {
            return $this->json(['status' => 0, 'msg' => $e->getMessage()], 422);
        }
        return $this->json(['status' => 1, 'item' => $item]);
    }

    public function rename(int $id): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        try {
            $this->service()->renameTemplate($id, (string)$this->getRequest()->getData('name'));
            $this->Flash->success('Umbenannt.');
        } catch (Throwable $e) {
            $this->Flash->error($e->getMessage());
        }
        return $this->redirect('/admin/newsletter-templates');
    }

    public function delete(int $id): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        $this->service()->deleteTemplate($id);
        if ($this->getRequest()->is('ajax') || str_contains((string)$this->getRequest()->getHeaderLine('Accept'), 'application/json')) {
            return $this->json(['status' => 1]);
        }
        $this->Flash->success('Gelöscht.');
        return $this->redirect('/admin/newsletter-templates');
    }

}
