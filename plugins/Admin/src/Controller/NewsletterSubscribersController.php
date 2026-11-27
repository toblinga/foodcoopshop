<?php
declare(strict_types=1);

namespace Admin\Controller;

use App\Model\Entity\NewsletterSubscriber;
use App\Services\Newsletter\NewsletterSubscriberService;
use Cake\Http\Response;
use Cake\ORM\Query\SelectQuery;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Newsletter-Abonnent:innen: Liste mit Suche/Filter, manuell abmelden, CSV-Export.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterSubscribersController extends AdminAppController
{

    public const STATUS_LABELS = [
        'active' => 'aktiv',
        'pending' => 'unbestätigt',
        'unsubscribed' => 'abgemeldet',
        'bounced' => 'unzustellbar',
    ];

    public const SOURCE_LABELS = [
        'startseite' => 'Website',
        'kundenkonto' => 'Kundenkonto',
        'import' => 'Import',
        'admin' => 'Admin',
    ];

    /**
     * @return SelectQuery<\App\Model\Entity\NewsletterSubscriber>
     */
    protected function filteredQuery(): SelectQuery
    {
        $request = $this->getRequest();
        $query = $this->getTableLocator()->get('NewsletterSubscribers')->find();
        $q = trim((string)$request->getQuery('q'));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(['OR' => [
                'NewsletterSubscribers.email LIKE' => $like,
                'NewsletterSubscribers.first_name LIKE' => $like,
                'NewsletterSubscribers.last_name LIKE' => $like,
            ]]);
        }
        $status = (string)$request->getQuery('status');
        if (isset(self::STATUS_LABELS[$status])) {
            $query->where(['NewsletterSubscribers.status' => $status]);
        }
        $source = (string)$request->getQuery('source');
        if (isset(self::SOURCE_LABELS[$source])) {
            $query->where(['NewsletterSubscribers.source' => $source]);
        }
        return $query;
    }

    public function index(): void
    {
        $subscribers = $this->paginate($this->filteredQuery(), [
            'limit' => 500,
            'maxLimit' => 1000,
            'sortableFields' => ['NewsletterSubscribers.email', 'NewsletterSubscribers.first_name', 'NewsletterSubscribers.status', 'NewsletterSubscribers.source', 'NewsletterSubscribers.consent_at', 'NewsletterSubscribers.created'],
            'order' => ['NewsletterSubscribers.created' => 'DESC'],
        ]);
        $counts = [];
        $rows = $this->getTableLocator()->get('NewsletterSubscribers')->find()
            ->select(['status', 'n' => 'COUNT(*)'])->groupBy(['status'])->disableHydration()->all();
        foreach ($rows as $row) {
            $counts[$row['status']] = (int)$row['n'];
        }
        $this->set(compact('subscribers', 'counts'));
        $this->set('q', (string)$this->getRequest()->getQuery('q'));
        $this->set('status', (string)$this->getRequest()->getQuery('status'));
        $this->set('source', (string)$this->getRequest()->getQuery('source'));
        $this->set('statusLabels', self::STATUS_LABELS);
        $this->set('sourceLabels', self::SOURCE_LABELS);
        $this->set('title_for_layout', 'Newsletter: Abonnent:innen');
    }

    public function unsubscribe(int $id): ?Response
    {
        $this->getRequest()->allowMethod(['post']);
        /** @var NewsletterSubscriber $subscriber */
        $subscriber = $this->getTableLocator()->get('NewsletterSubscribers')->get($id);
        (new NewsletterSubscriberService())->unsubscribe($subscriber);
        $this->Flash->success(h($subscriber->email) . ' wurde abgemeldet.');
        return $this->redirect($this->referer('/admin/newsletter-subscribers'));
    }

    public function export(): Response
    {
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            throw new \RuntimeException('temp stream');
        }
        // BOM, damit Excel Umlaute richtig liest
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['E-Mail', 'Vorname', 'Nachname', 'Status', 'Quelle', 'Einwilligung am', 'Einwilligung IP', 'Bestätigt am', 'Abgemeldet am', 'Kundennummer', 'Angelegt'], ';', '"', '');
        $fmt = fn($d) => $d ? $d->format('Y-m-d H:i:s') : '';
        foreach ($this->filteredQuery()->orderBy(['NewsletterSubscribers.email' => 'ASC'])->all() as $s) {
            // Namen kommen aus dem oeffentlichen Formular: Formel-Injektion in Excel verhindern
            $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            fputcsv($out, [
                $safe($s->email), $safe($s->first_name), $safe($s->last_name),
                self::STATUS_LABELS[$s->status] ?? $s->status,
                self::SOURCE_LABELS[$s->source] ?? $s->source,
                $fmt($s->consent_at), $s->consent_ip, $fmt($s->confirmed_at), $fmt($s->unsubscribed_at),
                $s->id_customer, $fmt($s->created),
            ], ';', '"', '');
        }
        rewind($out);
        $csv = (string)stream_get_contents($out);
        fclose($out);
        return $this->response
            ->withType('text/csv')
            ->withCharset('UTF-8')
            ->withDownload('newsletter-abonnenten-' . date('Y-m-d') . '.csv')
            ->withStringBody($csv);
    }

}
