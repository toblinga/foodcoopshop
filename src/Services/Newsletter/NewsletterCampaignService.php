<?php
declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Model\Entity\NewsletterCampaign;
use App\Model\Entity\NewsletterCampaignRecipient;
use App\Model\Entity\NewsletterSubscriber;
use App\Model\Table\NewsletterCampaignRecipientsTable;
use App\Model\Table\NewsletterCampaignsTable;
use App\Services\MailTransportService;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Mailer\Message;
use Cake\Mailer\TransportFactory;
use Cake\ORM\TableRegistry;
use RuntimeException;
use Throwable;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Versand-Kern für Kampagnen. Schnittstelle für Admin-UI (Phase 2):
 *  - create(array $data): NewsletterCampaign
 *  - render(NewsletterCampaign $campaign, bool $save = true): string   HTML mit Platzhaltern (html_cache)
 *  - preview(NewsletterCampaign $campaign): string                    HTML wie bei einer Empfänger:in (Testdaten)
 *  - sendTest(NewsletterCampaign $campaign, string $email): void      Testmail über die Queue
 *  - start(NewsletterCampaign $campaign): int                        Empfänger:innen eintragen, Versand anstoßen
 *  - resume(NewsletterCampaign $campaign, ?int $retryStuckAfterMinutes = null): int
 *  - processBatch(int $campaignId): array                            eine Portion verschicken (NewsletterBatchTask)
 *  - getProgress(NewsletterCampaign $campaign): array
 *
 * Doppelversand ist ausgeschlossen: unique (campaign_id, subscriber_id) beim Eintragen und vor jedem Versand
 * ein atomares UPDATE queued -> sending (nur eine:r gewinnt, auch bei parallelen Queue-Workern).
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterCampaignService
{

    public const QUEUE_TASK = 'NewsletterBatch';
    public const DEFAULT_SENDS_PER_MINUTE = 20;
    public const BATCH_INTERVAL_SECONDS = 60;

    protected NewsletterCampaignsTable $campaignsTable;
    protected NewsletterCampaignRecipientsTable $recipientsTable;
    protected NewsletterRenderService $renderService;

    public function __construct()
    {
        /** @var NewsletterCampaignsTable $campaignsTable */
        $campaignsTable = TableRegistry::getTableLocator()->get('NewsletterCampaigns');
        $this->campaignsTable = $campaignsTable;
        /** @var NewsletterCampaignRecipientsTable $recipientsTable */
        $recipientsTable = TableRegistry::getTableLocator()->get('NewsletterCampaignRecipients');
        $this->recipientsTable = $recipientsTable;
        $this->renderService = new NewsletterRenderService();
    }

    public static function getSendsPerMinute(): int
    {
        $value = (int)MailTransportService::readSetting('FCS_NEWSLETTER_SENDS_PER_MINUTE');
        return $value > 0 ? $value : self::DEFAULT_SENDS_PER_MINUTE;
    }

    public static function getQueueReference(int $campaignId): string
    {
        return 'newsletter-campaign-' . $campaignId;
    }

    /**
     * @param array<string, mixed> $data subject, preheader, blocks (array oder JSON), sender_name, sender_email
     */
    public function create(array $data): NewsletterCampaign
    {
        if (isset($data['blocks']) && is_array($data['blocks'])) {
            $data['blocks'] = json_encode($data['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $data['status'] = NewsletterCampaign::STATUS_DRAFT;
        $campaign = $this->campaignsTable->newEntity($data);
        if (!$this->campaignsTable->save($campaign)) {
            throw new RuntimeException('Kampagne ungültig: ' . json_encode($campaign->getErrors()));
        }
        return $campaign;
    }

    public function get(int $campaignId): NewsletterCampaign
    {
        return $this->campaignsTable->get($campaignId);
    }

    public function render(NewsletterCampaign $campaign, bool $save = true): string
    {
        $html = $this->renderService->render($campaign);
        if ($save) {
            $campaign->html_cache = $html;
            $this->campaignsTable->save($campaign);
        }
        return $html;
    }

    public function preview(NewsletterCampaign $campaign): string
    {
        $subscriber = new NewsletterSubscriber(['first_name' => 'Vorname', 'email' => 'vorschau@example.test']);
        return $this->renderService->personalize($this->render($campaign, false), $subscriber, '#abmelden', '#webansicht');
    }

    /**
     * Testmail an eine beliebige Adresse (Abmeldelink zeigt auf die allgemeine Newsletter-Seite).
     */
    public function sendTest(NewsletterCampaign $campaign, string $email): void
    {
        $html = $this->render($campaign, false);
        $subscriber = new NewsletterSubscriber(['first_name' => 'Test', 'email' => $email]);
        $unsubscribeUrl = Configure::read('App.fullBaseUrl') . '/newsletter';
        $message = $this->buildMessage($campaign, $html, $subscriber, $unsubscribeUrl, '[Test] ');
        $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
        $queuedJobsTable->createJob('AppEmail', [
            'class' => Message::class,
            'settings' => $message->__serialize(),
            'serialized' => true,
            'afterRunParams' => [],
        ]);
    }

    /**
     * Prüft, ob die Kampagne verschickt werden darf (Inhalt, Abmeldelink, Betreff).
     *
     * @throws RuntimeException
     */
    public function assertReadyToSend(NewsletterCampaign $campaign): void
    {
        if ($campaign->status === NewsletterCampaign::STATUS_SENT) {
            throw new RuntimeException('Die Kampagne wurde bereits verschickt.');
        }
        if ((string)$campaign->html_cache === '' || !str_contains((string)$campaign->html_cache, '{{unsubscribe_url}}')) {
            throw new RuntimeException('Die Kampagne hat keinen Inhalt oder keinen Abmeldelink.');
        }
        if (trim((string)$campaign->subject) === '') {
            throw new RuntimeException('Der Betreff fehlt.');
        }
    }

    /**
     * Geplante Entwürfe, deren Termin erreicht ist, starten. Schlägt eine Prüfung fehl,
     * wird der Termin entfernt und der Grund gemeldet (die Kampagne bleibt als Entwurf).
     *
     * @return array{started: list<int>, failed: array<int, string>}
     */
    public function sendDue(): array
    {
        $result = ['started' => [], 'failed' => []];
        $campaigns = $this->campaignsTable->find()
            ->where([
                'status' => NewsletterCampaign::STATUS_DRAFT,
                'scheduled_at IS NOT' => null,
                'scheduled_at <=' => DateTime::now(),
            ])
            ->orderBy(['scheduled_at' => 'ASC']);
        foreach ($campaigns as $campaign) {
            try {
                $this->assertReadyToSend($campaign);
                $this->start($campaign);
                $result['started'][] = (int)$campaign->id;
            } catch (Throwable $e) {
                $this->campaignsTable->updateAll(['scheduled_at' => null], ['id' => $campaign->id]);
                $result['failed'][(int)$campaign->id] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Trägt alle aktiven Abonnent:innen als Empfänger:innen ein und stößt den Versand an.
     * Mehrfaches Aufrufen ist ungefährlich (INSERT IGNORE auf unique campaign+subscriber).
     */
    public function start(NewsletterCampaign $campaign): int
    {
        if ($campaign->status === NewsletterCampaign::STATUS_SENT) {
            throw new RuntimeException('Kampagne ' . $campaign->id . ' wurde bereits verschickt.');
        }
        if ($campaign->status === NewsletterCampaign::STATUS_SENDING) {
            return $this->resume($campaign);
        }

        $this->render($campaign);

        $connection = $this->recipientsTable->getConnection();
        $connection->execute(
            'INSERT IGNORE INTO ' . $this->recipientsTable->getTable() . ' (campaign_id, subscriber_id, email, status, created, modified) '
            . 'SELECT ?, s.id, s.email, ?, NOW(), NOW() FROM ' . TableRegistry::getTableLocator()->get('NewsletterSubscribers')->getTable() . ' s '
            . 'WHERE s.status = ? ORDER BY s.id',
            [$campaign->id, NewsletterCampaignRecipient::STATUS_QUEUED, NewsletterSubscriber::STATUS_ACTIVE],
        );

        // nur draft -> sending (atomar), damit zwei gleichzeitige Starts nicht zweimal anstoßen
        $this->campaignsTable->updateAll(
            ['status' => NewsletterCampaign::STATUS_SENDING, 'started_at' => DateTime::now()],
            ['id' => $campaign->id, 'status' => NewsletterCampaign::STATUS_DRAFT],
        );
        $campaign = $this->campaignsTable->get($campaign->id);
        $this->updateCounts($campaign);
        $this->enqueueBatch($campaign->id);
        return $campaign->count_total;
    }

    /**
     * Wiederaufnahme nach Abbruch: stößt den Versand der noch offenen Empfänger:innen erneut an.
     * $retryStuckAfterMinutes: Empfänger:innen, die länger als so viele Minuten in "sending" hängen
     * (Worker abgestürzt), wieder auf "queued" setzen. Achtung: kann in seltenen Fällen doppelt zustellen,
     * wenn die Mail vor dem Absturz noch rausging. Ohne Angabe bleiben sie unangetastet.
     */
    public function resume(NewsletterCampaign $campaign, ?int $retryStuckAfterMinutes = null): int
    {
        if ($campaign->status !== NewsletterCampaign::STATUS_SENDING) {
            return 0;
        }
        if ($retryStuckAfterMinutes !== null) {
            $this->recipientsTable->updateAll(
                ['status' => NewsletterCampaignRecipient::STATUS_QUEUED],
                [
                    'campaign_id' => $campaign->id,
                    'status' => NewsletterCampaignRecipient::STATUS_SENDING,
                    'modified <' => DateTime::now()->subMinutes($retryStuckAfterMinutes),
                ],
            );
        }
        $open = $this->countByStatus($campaign->id, NewsletterCampaignRecipient::STATUS_QUEUED);
        if ($open > 0) {
            $this->enqueueBatch($campaign->id);
        } else {
            $this->finishIfDone($campaign->id);
        }
        return $open;
    }

    /**
     * Legt einen Queue-Job für die nächste Portion an, wenn nicht schon einer wartet.
     */
    public function enqueueBatch(int $campaignId, int $delaySeconds = 0): bool
    {
        $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
        $waiting = $queuedJobsTable->find()->where([
            'job_task' => self::QUEUE_TASK,
            'reference' => self::getQueueReference($campaignId),
            'completed IS' => null,
            'fetched IS' => null,
        ])->count();
        if ($waiting > 0) {
            return false;
        }
        $config = ['reference' => self::getQueueReference($campaignId)];
        if ($delaySeconds > 0) {
            $config['notBefore'] = DateTime::now()->addSeconds($delaySeconds);
        }
        $queuedJobsTable->createJob(self::QUEUE_TASK, ['campaignId' => $campaignId], $config);
        return true;
    }

    /**
     * Verschickt eine Portion (höchstens FCS_NEWSLETTER_SENDS_PER_MINUTE je 60 Sekunden, zusätzlich
     * begrenzt durch FCS_MAIL_MAX_PER_MINUTE) und plant die nächste Portion ein.
     *
     * @return array{sent: int, failed: int, skipped: int, remaining: int, budget: int}
     */
    public function processBatch(int $campaignId, bool $scheduleNext = true): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'remaining' => 0, 'budget' => 0];
        /** @var NewsletterCampaign|null $campaign */
        $campaign = $this->campaignsTable->find()->where(['NewsletterCampaigns.id' => $campaignId])->first();
        if ($campaign === null || $campaign->status !== NewsletterCampaign::STATUS_SENDING) {
            return $result;
        }

        $budget = $this->getBudget();
        $result['budget'] = $budget;

        if ($budget > 0) {
            $html = (string)$campaign->html_cache;
            if ($html === '') {
                $html = $this->render($campaign);
            }
            $transport = TransportFactory::get(MailTransportService::getTransportName());

            $ids = $this->recipientsTable->find()
                ->select(['id'])
                ->where([
                    'NewsletterCampaignRecipients.campaign_id' => $campaignId,
                    'NewsletterCampaignRecipients.status' => NewsletterCampaignRecipient::STATUS_QUEUED,
                ])
                ->orderBy(['NewsletterCampaignRecipients.id' => 'ASC'])
                ->limit($budget)
                ->all()
                ->extract('id')
                ->toList();

            foreach ($ids as $id) {
                // atomare Sperre: nur wer queued -> sending umstellt, darf verschicken
                $claimed = $this->recipientsTable->updateAll(
                    ['status' => NewsletterCampaignRecipient::STATUS_SENDING, 'modified' => DateTime::now()],
                    ['id' => $id, 'status' => NewsletterCampaignRecipient::STATUS_QUEUED],
                );
                if ($claimed !== 1) {
                    continue;
                }
                /** @var NewsletterCampaignRecipient $recipient */
                $recipient = $this->recipientsTable->get($id, contain: ['NewsletterSubscribers']);
                $subscriber = $recipient->newsletter_subscriber;
                if ($subscriber === null || $subscriber->status !== NewsletterSubscriber::STATUS_ACTIVE) {
                    $this->markRecipient($id, NewsletterCampaignRecipient::STATUS_SKIPPED, 'Abonnent:in nicht mehr aktiv');
                    $result['skipped']++;
                    continue;
                }
                try {
                    $unsubscribeUrl = NewsletterSubscriberService::getUnsubscribeUrl($subscriber);
                    $message = $this->buildMessage($campaign, $html, $subscriber, $unsubscribeUrl);
                    $transport->send($message);
                    $this->markRecipient($id, NewsletterCampaignRecipient::STATUS_SENT, null, DateTime::now());
                    $result['sent']++;
                } catch (Throwable $e) {
                    $this->markRecipient($id, NewsletterCampaignRecipient::STATUS_FAILED, mb_substr($e->getMessage(), 0, 2000));
                    $result['failed']++;
                }
            }
        }

        $this->updateCounts($campaign);
        $result['remaining'] = $this->countByStatus($campaignId, NewsletterCampaignRecipient::STATUS_QUEUED);
        if ($result['remaining'] > 0) {
            if ($scheduleNext) {
                $this->enqueueBatch($campaignId, self::BATCH_INTERVAL_SECONDS);
            }
        } else {
            $this->finishIfDone($campaignId);
        }
        return $result;
    }

    /**
     * Wie viele Newsletter-Mails dürfen jetzt raus (gleitendes 60-Sekunden-Fenster)?
     */
    public function getBudget(): int
    {
        $recent = $this->recipientsTable->find()->where([
            'NewsletterCampaignRecipients.sent_at >=' => DateTime::now()->subSeconds(60),
        ])->count();
        $budget = max(0, self::getSendsPerMinute() - $recent);
        $global = MailTransportService::getRemainingBudget();
        if ($global !== null) {
            $budget = min($budget, $global);
        }
        return $budget;
    }

    public function buildMessage(NewsletterCampaign $campaign, string $html, NewsletterSubscriber $subscriber, string $unsubscribeUrl, string $subjectPrefix = ''): Message
    {
        $personalized = $this->renderService->personalize($html, $subscriber, $unsubscribeUrl, NewsletterWebviewService::url((int)$campaign->id));
        $from = NewsletterSender::getFrom($campaign);
        $fromEmail = (string)array_key_first($from);

        $message = new Message();
        $message->setFrom($fromEmail, $from[$fromEmail])
            ->setTo($subscriber->email)
            ->setSubject($subjectPrefix . $campaign->subject)
            ->setEmailFormat(Message::MESSAGE_BOTH)
            ->setCharset('utf-8')
            ->setHeaderCharset('utf-8')
            ->addHeaders([
                'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
                'Precedence' => 'bulk',
                'X-Auto-Response-Suppress' => 'OOF, AutoReply',
            ]);
        if ($subjectPrefix === '') {
            // RFC 8058 nur für echte Empfänger:innen (Testmails haben keinen Abmelde-Token)
            $message->addHeaders(['List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
        }
        $message->setBodyHtml($personalized);
        $message->setBodyText($this->renderService->toText($personalized, $unsubscribeUrl));
        return $message;
    }

    /**
     * @return array{status: string, total: int, queued: int, sending: int, sent: int, failed: int, skipped: int}
     */
    public function getProgress(NewsletterCampaign $campaign): array
    {
        $counts = $this->getStatusCounts($campaign->id);
        return [
            'status' => $campaign->status,
            'total' => array_sum($counts),
            'queued' => $counts[NewsletterCampaignRecipient::STATUS_QUEUED] ?? 0,
            'sending' => $counts[NewsletterCampaignRecipient::STATUS_SENDING] ?? 0,
            'sent' => $counts[NewsletterCampaignRecipient::STATUS_SENT] ?? 0,
            'failed' => $counts[NewsletterCampaignRecipient::STATUS_FAILED] ?? 0,
            'skipped' => $counts[NewsletterCampaignRecipient::STATUS_SKIPPED] ?? 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function getStatusCounts(int $campaignId): array
    {
        $rows = $this->recipientsTable->find()
            ->select(['status', 'cnt' => $this->recipientsTable->find()->func()->count('*')])
            ->where(['NewsletterCampaignRecipients.campaign_id' => $campaignId])
            ->groupBy(['status'])
            ->disableHydration()
            ->all();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['status']] = (int)$row['cnt'];
        }
        return $counts;
    }

    protected function countByStatus(int $campaignId, string $status): int
    {
        return $this->recipientsTable->find()->where([
            'NewsletterCampaignRecipients.campaign_id' => $campaignId,
            'NewsletterCampaignRecipients.status' => $status,
        ])->count();
    }

    protected function markRecipient(int $id, string $status, ?string $error, ?DateTime $sentAt = null): void
    {
        $fields = ['status' => $status, 'error' => $error, 'modified' => DateTime::now()];
        if ($sentAt !== null) {
            $fields['sent_at'] = $sentAt;
        }
        $this->recipientsTable->updateAll($fields, ['id' => $id]);
    }

    protected function updateCounts(NewsletterCampaign $campaign): void
    {
        $counts = $this->getStatusCounts($campaign->id);
        $this->campaignsTable->updateAll([
            'count_total' => array_sum($counts),
            'count_sent' => $counts[NewsletterCampaignRecipient::STATUS_SENT] ?? 0,
            'count_failed' => $counts[NewsletterCampaignRecipient::STATUS_FAILED] ?? 0,
        ], ['id' => $campaign->id]);
        $campaign->count_total = array_sum($counts);
        $campaign->count_sent = $counts[NewsletterCampaignRecipient::STATUS_SENT] ?? 0;
        $campaign->count_failed = $counts[NewsletterCampaignRecipient::STATUS_FAILED] ?? 0;
    }

    protected function finishIfDone(int $campaignId): void
    {
        $open = $this->recipientsTable->find()->where([
            'NewsletterCampaignRecipients.campaign_id' => $campaignId,
            'NewsletterCampaignRecipients.status IN' => [NewsletterCampaignRecipient::STATUS_QUEUED, NewsletterCampaignRecipient::STATUS_SENDING],
        ])->count();
        if ($open === 0) {
            $this->campaignsTable->updateAll(
                ['status' => NewsletterCampaign::STATUS_SENT, 'sent_at' => DateTime::now()],
                ['id' => $campaignId, 'status' => NewsletterCampaign::STATUS_SENDING],
            );
        }
    }

}
