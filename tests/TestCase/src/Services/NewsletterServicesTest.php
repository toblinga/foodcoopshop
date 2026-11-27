<?php
declare(strict_types=1);

use App\Application;
use App\Model\Entity\NewsletterCampaign;
use App\Model\Entity\NewsletterCampaignRecipient;
use App\Model\Entity\NewsletterSubscriber;
use App\Services\MailTransportService;
use App\Services\Newsletter\NewsletterCampaignService;
use App\Services\Newsletter\NewsletterSubscriberService;
use App\Test\TestCase\AppCakeTestCase;
use Cake\I18n\DateTime;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\TestEmailTransport;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Kundenabgleich, Versand mit Drosselung/ohne Doppelversand, Mailweg-Schalter, Seed-Befehl.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterServicesTest extends AppCakeTestCase
{

    use EmailTrait;

    public function setUp(): void
    {
        parent::setUp();
        Application::registerGlobalListeners();
        $this->changeConfiguration('FCS_NEWSLETTER_ENABLED', 1);
    }

    private function createSubscriber(string $email, string $status = NewsletterSubscriber::STATUS_ACTIVE, ?int $customerId = null): NewsletterSubscriber
    {
        $table = $this->getTableLocator()->get('NewsletterSubscribers');
        $subscriber = $table->newEntity([
            'email' => $email,
            'status' => $status,
            'token' => NewsletterSubscriber::generateToken(),
            'source' => NewsletterSubscriber::SOURCE_IMPORT,
            'id_customer' => $customerId,
        ]);
        $table->saveOrFail($subscriber);
        return $subscriber;
    }

    private function getSubscriberByEmail(string $email): ?NewsletterSubscriber
    {
        /** @var NewsletterSubscriber|null $s */
        $s = $this->getTableLocator()->get('NewsletterSubscribers')->find()->where(['email' => $email])->first();
        return $s;
    }

    public function testEmailIsUniqueCaseInsensitive(): void
    {
        $this->createSubscriber('  Gross.Klein@Example.ORG ');
        $table = $this->getTableLocator()->get('NewsletterSubscribers');
        $duplicate = $table->newEntity([
            'email' => 'gross.klein@example.org',
            'status' => NewsletterSubscriber::STATUS_ACTIVE,
            'token' => NewsletterSubscriber::generateToken(),
            'source' => NewsletterSubscriber::SOURCE_IMPORT,
        ]);
        $this->assertFalse($table->save($duplicate));
        $this->assertEquals('exists', (new NewsletterSubscriberService())->importSubscriber(['email' => 'GROSS.KLEIN@example.org']));
        $this->assertEquals(1, $table->find()->count());
    }

    public function testExistingActiveSubscriberIsLinkedWhenCustomerGetsSameEmail(): void
    {
        $this->createSubscriber('erst.newsletter@example.test');
        $customersTable = $this->getTableLocator()->get('Customers');
        $customer = $customersTable->get(87);
        $customer = $customersTable->patchEntity($customer, ['email' => ' Erst.Newsletter@Example.test'], ['validate' => false]);
        $customersTable->saveOrFail($customer);

        $subscriber = $this->getSubscriberByEmail('erst.newsletter@example.test');
        $this->assertEquals(87, $subscriber->id_customer);
        $this->assertEquals(1, $customersTable->get(87)->newsletter_enabled);
        $this->runAndAssertQueue();
        $this->assertNoMailSent();
    }

    public function testCustomerTicksNewsletterCreatesPendingWithConfirmationMail(): void
    {
        $customersTable = $this->getTableLocator()->get('Customers');
        $customer = $customersTable->get(87);
        $customer = $customersTable->patchEntity($customer, ['newsletter_enabled' => 1], ['validate' => false]);
        $customersTable->saveOrFail($customer);

        $subscriber = $this->getSubscriberByEmail('fcs-demo-mitglied@mailinator.com');
        $this->assertNotNull($subscriber);
        $this->assertEquals(NewsletterSubscriber::STATUS_PENDING, $subscriber->status);
        $this->assertEquals(NewsletterSubscriber::SOURCE_CUSTOMER_ACCOUNT, $subscriber->source);
        $this->assertEquals(87, $subscriber->id_customer);
        $this->runAndAssertQueue();
        $this->assertMailCount(1);
        $this->assertMailContains('/newsletter/bestaetigen/' . $subscriber->token);
    }

    public function testCustomerUnticksNewsletterUnsubscribes(): void
    {
        $customersTable = $this->getTableLocator()->get('Customers');
        $customersTable->updateAll(['newsletter_enabled' => 1], ['id_customer' => 87]);
        $this->createSubscriber('fcs-demo-mitglied@mailinator.com', NewsletterSubscriber::STATUS_ACTIVE, 87);

        $customer = $customersTable->patchEntity($customersTable->get(87), ['newsletter_enabled' => 0], ['validate' => false]);
        $customersTable->saveOrFail($customer);
        $this->assertEquals(NewsletterSubscriber::STATUS_UNSUBSCRIBED, $this->getSubscriberByEmail('fcs-demo-mitglied@mailinator.com')->status);
    }

    public function testCustomerEmailChangeIsCopiedAndDeleteUnlinks(): void
    {
        $subscriber = $this->createSubscriber('fcs-demo-mitglied@mailinator.com', NewsletterSubscriber::STATUS_ACTIVE, 87);
        $customersTable = $this->getTableLocator()->get('Customers');
        $customer = $customersTable->patchEntity($customersTable->get(87), ['email' => 'Neue.Adresse@example.test'], ['validate' => false]);
        $customersTable->saveOrFail($customer);
        $reloaded = $this->getTableLocator()->get('NewsletterSubscribers')->get($subscriber->id);
        $this->assertEquals('neue.adresse@example.test', $reloaded->email);

        // Löschen: Eintrag bleibt, Verknüpfung weg
        (new App\Event\NewsletterCustomerListener())->afterDelete(
            new Cake\Event\Event('Model.afterDelete', $customersTable),
            $customersTable->get(87),
        );
        $reloaded = $this->getTableLocator()->get('NewsletterSubscribers')->get($subscriber->id);
        $this->assertNull($reloaded->id_customer);
        $this->assertEquals(NewsletterSubscriber::STATUS_ACTIVE, $reloaded->status);
    }

    public function testSyncCustomersCommandImportsNewsletterEnabledCustomers(): void
    {
        $this->getTableLocator()->get('Customers')->updateAll(['newsletter_enabled' => 1], ['id_customer IN' => [87, 88]]);
        $this->exec('newsletter sync_customers');
        $this->assertOutputContains('Neu: 2');
        $this->exec('newsletter sync_customers');
        $this->assertOutputContains('Neu: 0');
        $this->assertEquals(2, $this->getTableLocator()->get('NewsletterSubscribers')->find()->where(['status' => 'active'])->count());
    }

    public function testCampaignSendingIsThrottledResumableAndWithoutDuplicates(): void
    {
        $this->changeConfiguration('FCS_NEWSLETTER_SENDS_PER_MINUTE', 10);
        for ($i = 1; $i <= 25; $i++) {
            $this->createSubscriber('empfaenger' . $i . '@example.test');
        }
        $this->createSubscriber('offen@example.test', NewsletterSubscriber::STATUS_PENDING);
        $this->createSubscriber('weg@example.test', NewsletterSubscriber::STATUS_UNSUBSCRIBED);

        $service = new NewsletterCampaignService();
        $campaign = $service->create([
            'subject' => 'Test-Ausgabe',
            'blocks' => [
                ['type' => 'heading', 'text' => 'Hallo {{first_name}}', 'level' => 1],
                ['type' => 'text', 'html' => '<p>Inhalt <script>alert(1)</script></p>'],
                ['type' => 'button', 'label' => 'Shop', 'href' => '/shop'],
            ],
        ]);
        $this->assertEquals(25, $service->start($campaign));
        // erneutes Anstoßen trägt niemanden doppelt ein
        $service->start($service->get($campaign->id));
        $recipientsTable = $this->getTableLocator()->get('NewsletterCampaignRecipients');
        $this->assertEquals(25, $recipientsTable->find()->where(['campaign_id' => $campaign->id])->count());

        // ein paralleler Worker hat schon gesperrt: wird nicht noch einmal verschickt
        $firstId = $recipientsTable->find()->where(['campaign_id' => $campaign->id])->orderBy(['id' => 'ASC'])->first()->id;
        $recipientsTable->updateAll(['status' => NewsletterCampaignRecipient::STATUS_SENDING], ['id' => $firstId]);

        $result = $service->processBatch($campaign->id, false);
        $this->assertEquals(10, $result['budget']);
        $this->assertEquals(10, $result['sent']);
        $this->assertCount(10, TestEmailTransport::getMessages());

        // Drosselung: im selben 60-Sekunden-Fenster nichts mehr
        $result = $service->processBatch($campaign->id, false);
        $this->assertEquals(0, $result['budget']);
        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(14, $result['remaining']);
        $this->assertCount(10, TestEmailTransport::getMessages());

        // Fenster vorbei (sent_at zurückdatieren), Abmeldung zwischendurch => skipped
        $recipientsTable->updateAll(['sent_at' => DateTime::now()->subMinutes(2)], ['status' => NewsletterCampaignRecipient::STATUS_SENT]);
        $this->getTableLocator()->get('NewsletterSubscribers')->updateAll(['status' => NewsletterSubscriber::STATUS_UNSUBSCRIBED], ['email' => 'empfaenger25@example.test']);
        $service->processBatch($campaign->id, false);
        $recipientsTable->updateAll(['sent_at' => DateTime::now()->subMinutes(2)], ['status' => NewsletterCampaignRecipient::STATUS_SENT]);
        $result = $service->processBatch($campaign->id, false);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals(1, $result['skipped']);

        // hängender "sending"-Eintrag: erst Wiederaufnahme mit --retry-stuck verschickt ihn
        $this->assertEquals(NewsletterCampaign::STATUS_SENDING, $service->get($campaign->id)->status);
        $recipientsTable->updateAll(['modified' => DateTime::now()->subMinutes(30), 'sent_at' => null], ['id' => $firstId]);
        $recipientsTable->updateAll(['sent_at' => DateTime::now()->subMinutes(2)], ['status' => NewsletterCampaignRecipient::STATUS_SENT]);
        $this->assertEquals(1, $service->resume($service->get($campaign->id), 10));
        $service->processBatch($campaign->id, false);

        $progress = $service->getProgress($service->get($campaign->id));
        $this->assertEquals(['status' => 'sent', 'total' => 25, 'queued' => 0, 'sending' => 0, 'sent' => 24, 'failed' => 0, 'skipped' => 1], $progress);

        // jede Adresse genau einmal, mit Abmeldelink und One-Click-Header
        $messages = TestEmailTransport::getMessages();
        $this->assertCount(24, $messages);
        $to = array_map(fn($m) => array_key_first($m->getTo()), $messages);
        $this->assertCount(24, array_unique($to));
        $headers = $messages[0]->getHeaders(['List-Unsubscribe', 'List-Unsubscribe-Post']);
        $this->assertMatchesRegularExpression('#^<http.+/newsletter/abmelden/[a-f0-9]{64}>$#', $headers['List-Unsubscribe']);
        $this->assertEquals('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $html = $messages[0]->getBodyHtml();
        $this->assertStringContainsString('/newsletter/abmelden/', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('{{', $html);

        // abgeschlossene Kampagne kann nicht erneut gestartet werden
        $this->expectException(RuntimeException::class);
        $service->start($service->get($campaign->id));
    }

    public function testGlobalMailLimitAlsoThrottlesNewsletter(): void
    {
        $this->changeConfiguration('FCS_MAIL_MAX_PER_MINUTE', 3);
        $this->createSubscriber('a@example.test');
        $this->createSubscriber('b@example.test');
        $this->createSubscriber('c@example.test');
        $this->createSubscriber('d@example.test');
        $service = new NewsletterCampaignService();
        $campaign = $service->create(['subject' => 'Limit', 'blocks' => [['type' => 'text', 'html' => '<p>x</p>']]]);
        $service->start($campaign);
        $result = $service->processBatch($campaign->id, false);
        $this->assertEquals(3, $result['sent']);
        $this->assertEquals(1, $result['remaining']);
        $this->assertEquals(0, MailTransportService::getRemainingBudget());
    }

    public function testStartEnqueuesOnlyOneWaitingBatchJob(): void
    {
        $this->createSubscriber('a@example.test');
        $service = new NewsletterCampaignService();
        $campaign = $service->create(['subject' => 'Queue', 'blocks' => []]);
        $service->start($campaign);
        $service->resume($service->get($campaign->id));
        $service->enqueueBatch($campaign->id);
        $jobs = $this->getTableLocator()->get('Queue.QueuedJobs')->find()->where(['job_task' => 'NewsletterBatch'])->count();
        $this->assertEquals(1, $jobs);
        $this->runAndAssertQueue();
        $this->assertEquals(NewsletterCampaign::STATUS_SENT, $service->get($campaign->id)->status);
        $this->assertMailCount(1);
    }

    public function testMailTransportSwitch(): void
    {
        $this->assertEquals('default', MailTransportService::getTransportName());
        $this->changeConfiguration('FCS_MAIL_TRANSPORT', 'relay');
        // relay nicht konfiguriert => Rückfall auf default
        $this->assertEquals('default', MailTransportService::getTransportName());
        TransportFactory::setConfig('relay', ['className' => 'Debug']);
        try {
            $this->assertEquals('relay', MailTransportService::getTransportName());
        } finally {
            TransportFactory::drop('relay');
        }
    }

    public function testSendTestMailGoesThroughQueue(): void
    {
        $service = new NewsletterCampaignService();
        $campaign = $service->create(['subject' => 'Probe', 'blocks' => [['type' => 'text', 'html' => '<p>Probe</p>']]]);
        $service->sendTest($campaign, 'probe@example.test');
        $this->runAndAssertQueue();
        $this->assertMailCount(1);
        $this->assertMailSentTo('probe@example.test');
        $this->assertMailSubjectContainsAt(0, '[Test] Probe');
    }

    public function testSeedCommandCreatesAndResetsTestData(): void
    {
        $this->exec('newsletter_seed 40');
        $this->assertExitSuccess();
        $this->assertOutputContains('duplicates_rejected');
        $table = $this->getTableLocator()->get('NewsletterSubscribers');
        $this->assertEquals(40, $table->find()->count());
        foreach ($table->find() as $subscriber) {
            $this->assertEquals(mb_strtolower(trim($subscriber->email)), $subscriber->email);
            $this->assertMatchesRegularExpression('/@example\.(test|org|com)$/', $subscriber->email);
        }
        $this->assertGreaterThan(0, $table->find()->where(['id_customer IS NOT' => null])->count());
        $this->exec('newsletter_seed --reset');
        $this->assertOutputContains('entfernt: 40');
        $this->assertEquals(0, $table->find()->count());
    }

}
