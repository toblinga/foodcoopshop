<?php
declare(strict_types=1);

namespace Admin\Controller;

use App\Model\Entity\NewsletterCampaign;
use App\Services\Newsletter\SpamCheck\CampaignSpamCheckService;
use Cake\I18n\DateTime;

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
 * POST /admin/newsletter/campaigns/{id}/check
 * Optionaler Body (noch nicht gespeicherter Stand aus dem Editor):
 *   subject, preheader, blocks (Array oder JSON), html, sender_name, sender_email,
 *   checkLinks (0/1, HEAD-Pruefung der Links), spamAssassin (0/1)
 * Antwort: JSON {status, ok, messages, campaignId, checkedAt, result}.
 * Es wird nichts gespeichert.
 */
class NewsletterSpamCheckController extends AdminAppController
{

    public function check(int $campaignId): void
    {
        $this->request->allowMethod(['post']);
        $this->request = $this->request->withParam('_ext', 'json');
        $this->viewBuilder()->setClassName('Json');

        $campaignsTable = $this->getTableLocator()->get('NewsletterCampaigns');
        /** @var NewsletterCampaign|null $campaign */
        $campaign = $campaignsTable->find()->where(['id' => $campaignId])->first();
        if ($campaign === null) {
            $this->response = $this->response->withStatus(404);
            $this->set(['status' => 0, 'msg' => 'campaign not found']);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        $data = $this->getRequest()->getData();
        foreach (['subject', 'preheader', 'sender_name', 'sender_email'] as $field) {
            if (isset($data[$field]) && is_scalar($data[$field])) {
                $campaign->set($field, trim((string)$data[$field]));
            }
        }
        if (isset($data['blocks'])) {
            $blocks = is_string($data['blocks']) ? json_decode($data['blocks'], true) : $data['blocks'];
            if (is_array($blocks)) {
                // Editor-Dokument {"format":…,"blocks":[…]} bleibt, wie es ist; sonst flache Liste
                $blocks = isset($blocks['blocks']) ? $blocks : array_values($blocks);
                $campaign->set('blocks', (string)json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        $html = isset($data['html']) && is_string($data['html']) ? $data['html'] : null;

        $options = [];
        foreach (['checkLinks', 'spamAssassin'] as $flag) {
            if (isset($data[$flag]) && $data[$flag] !== '') {
                $options[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $result = (new CampaignSpamCheckService())->check($campaign, $options, $html);

        // 'ok' und 'messages' entsprechen der Kurzform, die der Editor-Prototyp erwartet
        $messages = array_map(fn($f) => [
            'level' => $f->level,
            'code' => $f->ruleId,
            'message' => $f->message,
            'tip' => $f->tip,
            'blockId' => $f->blockId,
            'field' => $f->field,
        ], $result->findings);
        $this->set([
            'status' => 1,
            'ok' => !$result->hasErrors(),
            'messages' => $messages,
            'campaignId' => $campaign->id,
            'checkedAt' => DateTime::now()->toIso8601String(),
            'result' => $result->toArray(),
        ]);
        $this->viewBuilder()->setOption('serialize', ['status', 'ok', 'messages', 'campaignId', 'checkedAt', 'result']);
    }

}
