<?php
declare(strict_types=1);

namespace Admin\Controller;

use App\Model\Entity\NewsletterCampaign;
use App\Model\Entity\NewsletterSubscriber;
use App\Services\Newsletter\NewsletterCampaignService;
use App\Services\Newsletter\NewsletterEditorService;
use App\View\Helper\MyHtmlHelper;
use App\View\Helper\SlugHelper;
use Cake\Core\Configure;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\View\View;
use Throwable;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Newsletter-Kampagnen: Liste, Editor (Bausteine), JSON-Endpunkte fuer den Editor.
 * Versandlogik nur ueber NewsletterCampaignService.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterCampaignsController extends AdminAppController
{

    protected function campaignService(): NewsletterCampaignService
    {
        return new NewsletterCampaignService();
    }

    protected function editorService(): NewsletterEditorService
    {
        return new NewsletterEditorService();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return $this->response
            ->withType('application/json')
            ->withStatus($status)
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * JSON-Body (der Editor sendet application/json; FCS hat keinen BodyParserMiddleware) oder Formulardaten.
     *
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

    protected function jsonError(string $msg, int $status = 400): Response
    {
        return $this->json(['status' => 0, 'ok' => false, 'msg' => $msg], $status);
    }

    protected function getCampaign(int $campaignId): NewsletterCampaign
    {
        /** @var NewsletterCampaign|null $campaign */
        $campaign = $this->getTableLocator()->get('NewsletterCampaigns')->find()->where(['id' => $campaignId])->first();
        if ($campaign === null) {
            throw new NotFoundException();
        }
        return $campaign;
    }

    public function index(): void
    {
        $campaigns = $this->getTableLocator()->get('NewsletterCampaigns')->find()
            ->select(['id', 'subject', 'preheader', 'status', 'count_total', 'count_sent', 'count_failed', 'started_at', 'sent_at', 'scheduled_at', 'created', 'modified'])
            ->orderBy(['modified' => 'DESC'])
            ->all()
            // laufende zuerst, dann Entwuerfe, dann verschickte; innerhalb neueste zuerst
            ->sortBy(fn($c) => (['sending' => 0, 'draft' => 1, 'sent' => 2][$c->status] ?? 3) * 10000000000 - $c->modified->getTimestamp(), SORT_ASC, SORT_NUMERIC)
            ->toList();
        $activeCount = $this->getTableLocator()->get('NewsletterSubscribers')->find()
            ->where(['status' => NewsletterSubscriber::STATUS_ACTIVE])->count();
        $this->set(compact('campaigns', 'activeCount'));
        $this->set('title_for_layout', 'Newsletter');
    }

    public function add(): ?Response
    {
        $templateId = (int)$this->getRequest()->getQuery('template');
        $campaign = $this->editorService()->createDraft($templateId > 0 ? $templateId : null);
        return $this->redirect('/admin/newsletter-campaigns/edit/' . $campaign->id);
    }

    public function duplicate(int $campaignId): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        $source = $this->getCampaign($campaignId);
        $data = [
            'subject' => 'Kopie: ' . $source->subject,
            'preheader' => $source->preheader,
            'blocks' => $source->blocks,
            'html_cache' => $source->html_cache,
            'sender_name' => $source->sender_name,
            'sender_email' => $source->sender_email,
        ];
        // Importierte Mail (nur HTML): als editierbarer Entwurf mit Originalinhalt-Baustein kopieren
        if (NewsletterEditorService::isArchiveBlocks($source->blocks)) {
            $data['blocks'] = $this->editorService()->getArchiveCopyDocument((string)$source->html_cache);
            $data['html_cache'] = null;
        }
        $campaign = $this->campaignService()->create($data);
        $this->Flash->success('Kampagne kopiert.');
        return $this->redirect('/admin/newsletter-campaigns/edit/' . $campaign->id);
    }

    public function delete(int $campaignId): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        $campaign = $this->getCampaign($campaignId);
        if ($campaign->status !== NewsletterCampaign::STATUS_DRAFT) {
            $this->Flash->error('Nur Entwürfe können gelöscht werden.');
        } else {
            $this->getTableLocator()->get('NewsletterCampaigns')->delete($campaign);
            $this->Flash->success('Entwurf gelöscht.');
        }
        return $this->redirect('/admin/newsletter-campaigns');
    }

    public function edit(int $campaignId): void
    {
        $campaign = $this->getCampaign($campaignId);
        $this->set('campaign', $campaign);
        $this->set('initial', $this->editorService()->getEditorState($campaign));
        $this->set('progress', $this->campaignService()->getProgress($campaign));
        $this->set('logoUrl', Configure::read('App.fullBaseUrl') . '/files/images/' . Configure::read('app.logoFileName'));
        $this->set('title_for_layout', 'Newsletter: ' . $campaign->subject);
    }

    /**
     * POST JSON {blocks, html, subject, preheader, sender_name, sender_email} – nur Entwuerfe.
     */
    public function save(int $campaignId): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $campaign = $this->getCampaign($campaignId);
        if ($campaign->status !== NewsletterCampaign::STATUS_DRAFT) {
            return $this->jsonError('Die Kampagne wird schon verschickt und kann nicht mehr geändert werden.', 409);
        }
        try {
            $this->editorService()->saveFromEditor($campaign, $this->requestData());
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
        return $this->json(['status' => 1, 'ok' => true, 'savedAt' => date('c'), 'campaignId' => $campaign->id]);
    }

    /**
     * Personalisierte Vorschau (fuer iframe), auch fuer verschickte Kampagnen.
     */
    public function preview(int $campaignId): Response
    {
        $campaign = $this->getCampaign($campaignId);
        $html = $this->campaignService()->preview($campaign);
        return $this->response->withType('text/html')->withStringBody($html);
    }

    public function sendTest(int $campaignId): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $campaign = $this->getCampaign($campaignId);
        $email = trim((string)($this->requestData()['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('Bitte eine gültige E-Mail-Adresse eingeben.');
        }
        if ((string)$campaign->html_cache === '') {
            return $this->jsonError('Die Kampagne ist noch leer.');
        }
        $this->campaignService()->sendTest($campaign, $email);
        return $this->json(['status' => 1, 'ok' => true, 'msg' => 'Testmail an ' . $email . ' ist unterwegs (Warteschlange, meist unter einer Minute).']);
    }

    public function recipientCount(int $campaignId): Response
    {
        $this->getCampaign($campaignId);
        $count = $this->getTableLocator()->get('NewsletterSubscribers')->find()
            ->where(['status' => NewsletterSubscriber::STATUS_ACTIVE])->count();
        return $this->json(['status' => 1, 'count' => $count]);
    }

    /**
     * Versand planen: scheduled_at (Ortszeit, z. B. "2026-12-04T09:30"). Leer = Planung aufheben.
     */
    public function schedule(int $campaignId): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $campaign = $this->getCampaign($campaignId);
        if ($campaign->status !== NewsletterCampaign::STATUS_DRAFT) {
            return $this->jsonError('Nur Entwürfe lassen sich planen.', 409);
        }
        $raw = trim((string)($this->requestData()['scheduled_at'] ?? ''));
        $table = $this->getTableLocator()->get('NewsletterCampaigns');
        if ($raw === '') {
            $table->updateAll(['scheduled_at' => null], ['id' => $campaignId]);
            return $this->json(['status' => 1, 'ok' => true, 'scheduled_at' => null]);
        }
        try {
            $when = new \Cake\I18n\DateTime($raw);
        } catch (Throwable $e) {
            return $this->jsonError('Das Datum ist ungültig.', 422);
        }
        if ($when <= \Cake\I18n\DateTime::now()->addMinutes(1)) {
            return $this->jsonError('Der Termin muss in der Zukunft liegen.', 422);
        }
        try {
            $this->campaignService()->assertReadyToSend($campaign);
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
        $table->updateAll(['scheduled_at' => $when], ['id' => $campaignId]);

        return $this->json(['status' => 1, 'ok' => true, 'scheduled_at' => $when->format('d.m.Y H:i')]);
    }

    public function start(int $campaignId): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $campaign = $this->getCampaign($campaignId);
        if ($campaign->status === NewsletterCampaign::STATUS_SENT) {
            return $this->jsonError('Die Kampagne wurde bereits verschickt.', 409);
        }
        if ((string)$campaign->html_cache === '' || !str_contains((string)$campaign->html_cache, '{{unsubscribe_url}}')) {
            return $this->jsonError('Die Kampagne hat keinen Inhalt oder keinen Abmeldelink.', 422);
        }
        if (trim((string)$campaign->subject) === '') {
            return $this->jsonError('Der Betreff fehlt.', 422);
        }
        try {
            $total = $this->campaignService()->start($campaign);
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
        return $this->json(['status' => 1, 'ok' => true, 'total' => $total]);
    }

    public function progress(int $campaignId): Response
    {
        $campaign = $this->getCampaign($campaignId);
        $p = $this->campaignService()->getProgress($campaign);
        $p['sent_or_done'] = $p['sent'] + $p['failed'] + $p['skipped'];
        // Form fuer den Editor: {status, sent, total}
        return $this->json(['status' => $p['status'], 'sent' => $p['sent_or_done'], 'total' => $p['total'], 'detail' => $p]);
    }

    // ------------------------------------------------------------- Daten fuer den Editor

    public function products(): Response
    {
        $q = trim((string)$this->getRequest()->getQuery('q'));
        $ids = array_filter(array_map('intval', explode(',', (string)$this->getRequest()->getQuery('ids'))));
        $productsTable = $this->getTableLocator()->get('Products');
        $query = $productsTable->find()
            ->contain(['Images', 'Taxes', 'Manufacturers', 'ProductAttributes', 'UnitProducts'])
            ->where(['Products.active' => APP_ON])
            ->orderBy(['Products.name' => 'ASC'])
            ->limit(40);
        if ($ids) {
            $query->where(['Products.id_product IN' => $ids]);
        } elseif ($q !== '') {
            $query->where(['Products.name LIKE' => '%' . $q . '%']);
        }
        $view = new View();
        $html = new MyHtmlHelper($view);
        $slug = new SlugHelper($view);
        $base = (string)Configure::read('App.fullBaseUrl');
        $items = [];
        foreach ($query->all() as $p) {
            $taxRate = $p->tax->rate ?? 0;
            // Produkte mit Varianten haben den Preis an der Variante: Standardvariante, sonst die guenstigste
            $netPrice = (float)$p->price;
            if (!empty($p->product_attributes)) {
                $attrs = $p->product_attributes;
                usort($attrs, fn($a, $b) => [(int)!$a->default_on, (float)$a->price] <=> [(int)!$b->default_on, (float)$b->price]);
                $netPrice = (float)$attrs[0]->price;
            }
            // Preis nach Gewicht (z. B. € 12,63 / kg): Grundpreis inkl. USt. aus fcs_units
            $unitPrice = null;
            $unitLabel = '';
            $unit = $p->unit_product ?? null;
            if ($unit && $unit->price_per_unit_enabled && (float)$unit->price_incl_per_unit > 0) {
                $unitPrice = round((float)$unit->price_incl_per_unit, 2);
                $unitLabel = ((float)$unit->amount != 1.0 ? rtrim(rtrim(number_format((float)$unit->amount, 3, ',', ''), '0'), ',') . ' ' : '') . $unit->name;
            }
            $imageId = $p->image->id_image ?? 0;
            $image = $imageId ? $html->getProductImageSrc((int)$imageId, 'home') : '';
            $items[] = [
                'productId' => $p->id_product,
                'name' => html_entity_decode(strip_tags((string)$p->name)),
                'manufacturer' => $p->manufacturer ? html_entity_decode((string)$p->manufacturer->name) : '',
                'unit' => $unitPrice !== null ? $unitLabel : (string)$p->unity,
                'price' => $unitPrice ?? round($productsTable->getGrossPrice($netPrice, $taxRate), 2),
                'image' => $image !== '' ? $this->absoluteUrl($image) : '',
                'url' => $base . $slug->getProductDetail($p->id_product, (string)$p->name),
            ];
        }
        return $this->json(['status' => 1, 'items' => $items]);
    }

    public function posts(): Response
    {
        $q = trim((string)$this->getRequest()->getQuery('q'));
        $table = $this->getTableLocator()->get('BlogPosts');
        $query = $table->find()->where(['BlogPosts.active' => APP_ON])->orderBy(['BlogPosts.modified' => 'DESC'])->limit(40);
        if ($q !== '') {
            $query->where(['BlogPosts.title LIKE' => '%' . $q . '%']);
        }
        $view = new View();
        $html = new MyHtmlHelper($view);
        $slug = new SlugHelper($view);
        $base = (string)Configure::read('App.fullBaseUrl');
        $items = [];
        foreach ($query->all() as $post) {
            $items[] = [
                'postId' => $post->id_blog_post,
                'title' => html_entity_decode(strip_tags((string)$post->title)),
                'text' => html_entity_decode(strip_tags((string)$post->short_description)),
                'image' => $this->absoluteUrl($html->getBlogPostImageSrc($post, 'single')),
                'url' => $base . $slug->getBlogPostDetail($post->id_blog_post, (string)$post->title),
            ];
        }
        return $this->json(['status' => 1, 'items' => $items]);
    }

    public function media(): Response
    {
        return $this->json(['status' => 1, 'items' => $this->editorService()->listMedia()]);
    }

    public function upload(): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $file = $this->getRequest()->getUploadedFile('image');
        if ($file === null) {
            return $this->jsonError('Keine Datei erhalten.');
        }
        try {
            $result = $this->editorService()->storeUpload($file);
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
        return $this->json(['status' => 1] + $result);
    }

    protected function absoluteUrl(string $src): string
    {
        $src = (string)preg_replace('/\?.*$/', '', $src);
        if ($src === '' || preg_match('#^https?://#', $src)) {
            return $src;
        }
        return Configure::read('App.fullBaseUrl') . '/' . ltrim($src, '/');
    }

}
