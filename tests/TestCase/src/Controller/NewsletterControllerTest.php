<?php
declare(strict_types=1);

use App\Model\Entity\NewsletterSubscriber;
use App\Test\TestCase\AppCakeTestCase;
use Cake\Cache\Cache;
use Cake\I18n\DateTime;
use Cake\TestSuite\EmailTrait;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Öffentliche Newsletter-Seiten: Anmeldung mit Double-Opt-in, Bestätigung, Abmeldung.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterControllerTest extends AppCakeTestCase
{

    use EmailTrait;

    public function setUp(): void
    {
        parent::setUp();
        Cache::clear(); // Rate-Limit pro IP zurücksetzen
        $this->changeConfiguration('FCS_NEWSLETTER_ENABLED', 1);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function postSubscribe(array $override = []): void
    {
        $this->post('/newsletter', [
            'Newsletter' => array_merge([
                'form' => 'teaser',
                'email' => '  Neue.Abonnentin@Example.TEST ',
                'first_name' => 'Jürgen',
                'website' => '',
                'consent' => '1',
            ], $override),
        ]);
    }

    private function getSubscriber(string $email): ?NewsletterSubscriber
    {
        /** @var NewsletterSubscriber|null $subscriber */
        $subscriber = $this->getTableLocator()->get('NewsletterSubscribers')->find()->where(['email' => $email])->first();
        return $subscriber;
    }

    public function testPagesAreNotAvailableWhenNewsletterDisabled(): void
    {
        $this->changeConfiguration('FCS_NEWSLETTER_ENABLED', 0);
        $this->get('/newsletter');
        $this->assertResponseCode(404);
    }

    public function testFormIsShownOnHomeAndOwnPage(): void
    {
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseContains('id="newsletter-teaser-form"');
        $this->get('/newsletter');
        $this->assertResponseOk();
        $this->assertResponseContains('id="newsletter-page-form"');
    }

    public function testSubscribeCreatesPendingSubscriberAndSendsConfirmationMail(): void
    {
        $this->postSubscribe();
        $this->assertRedirect('/newsletter/danke');

        $subscriber = $this->getSubscriber('neue.abonnentin@example.test');
        $this->assertNotNull($subscriber);
        $this->assertEquals(NewsletterSubscriber::STATUS_PENDING, $subscriber->status);
        $this->assertEquals(NewsletterSubscriber::SOURCE_HOME, $subscriber->source);
        $this->assertEquals('Jürgen', $subscriber->first_name);
        $this->assertNotEmpty($subscriber->consent_at);
        $this->assertStringContainsString('Startseite', (string)$subscriber->consent_text);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $subscriber->token);

        $this->runAndAssertQueue();
        $this->assertMailCount(1);
        $this->assertMailSentTo('neue.abonnentin@example.test');
        $this->assertMailContains('/newsletter/bestaetigen/' . $subscriber->token);
    }

    public function testSubscribeSameAddressTwiceCreatesNoDuplicateAndRevealsNothing(): void
    {
        $this->postSubscribe();
        $this->assertRedirect('/newsletter/danke');
        $this->postSubscribe(['email' => 'NEUE.ABONNENTIN@example.test']);
        $this->assertRedirect('/newsletter/danke');

        $this->assertEquals(1, $this->getTableLocator()->get('NewsletterSubscribers')->find()->count());
        $this->runAndAssertQueue();
        // zweite Bestätigungsmail erst nach einer Stunde
        $this->assertMailCount(1);
    }

    public function testSubscribeActiveAddressSendsNoMail(): void
    {
        $this->postSubscribe();
        $subscriber = $this->getSubscriber('neue.abonnentin@example.test');
        $subscriber->status = NewsletterSubscriber::STATUS_ACTIVE;
        $this->getTableLocator()->get('NewsletterSubscribers')->save($subscriber);
        $this->runAndAssertQueue();
        $this->cleanupEmailTrait();
        \Cake\TestSuite\TestEmailTransport::replaceAllTransports();

        $this->postSubscribe();
        $this->assertRedirect('/newsletter/danke');
        $this->runAndAssertQueue();
        $this->assertNoMailSent();
        $this->assertEquals(NewsletterSubscriber::STATUS_ACTIVE, $this->getSubscriber('neue.abonnentin@example.test')->status);
    }

    public function testHoneypotPretendsSuccessButStoresNothing(): void
    {
        $this->postSubscribe(['website' => 'http://spam.example.com']);
        $this->assertRedirect('/newsletter/danke');
        $this->assertEquals(0, $this->getTableLocator()->get('NewsletterSubscribers')->find()->count());
    }

    public function testConsentAndValidEmailAreRequired(): void
    {
        $this->postSubscribe(['consent' => '0', 'email' => 'kein-at-zeichen']);
        $this->assertResponseOk();
        $this->assertResponseContains('Bitte gib eine gültige E-Mail-Adresse ein.');
        $this->assertResponseContains('Bitte bestätige die Einwilligung.');
        $this->assertEquals(0, $this->getTableLocator()->get('NewsletterSubscribers')->find()->count());
    }

    public function testRateLimitPerIp(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postSubscribe(['email' => 'rate' . $i . '@example.test']);
            $this->assertRedirect('/newsletter/danke');
        }
        $this->postSubscribe(['email' => 'rate-zu-viel@example.test']);
        $this->assertResponseOk();
        $this->assertResponseContains('Zu viele Anmeldungen');
        $this->assertNull($this->getSubscriber('rate-zu-viel@example.test'));
    }

    public function testConfirmActivatesAndLinksCustomer(): void
    {
        $customerEmail = 'fcs-demo-mitglied@mailinator.com';
        $this->postSubscribe(['email' => strtoupper($customerEmail)]);
        $subscriber = $this->getSubscriber($customerEmail);

        // GET (Link-Scanner, Vorschau): zeigt nur den Button, ändert nichts
        $this->get('/newsletter/bestaetigen/' . $subscriber->token);
        $this->assertResponseOk();
        $this->assertResponseContains('Anmeldung bestätigen</button>');
        $this->assertResponseNotContains('deine Anmeldung ist bestätigt');
        $this->get('/newsletter/bestaetigen/' . $subscriber->token);
        $this->assertEquals(NewsletterSubscriber::STATUS_PENDING, $this->getSubscriber($customerEmail)->status);
        $this->assertEquals(0, $this->getTableLocator()->get('Customers')->get(87)->newsletter_enabled);

        // POST (Button): bestätigt
        $this->post('/newsletter/bestaetigen/' . $subscriber->token);
        $this->assertResponseOk();
        $this->assertResponseContains('deine Anmeldung ist bestätigt');

        $subscriber = $this->getSubscriber($customerEmail);
        $this->assertEquals(NewsletterSubscriber::STATUS_ACTIVE, $subscriber->status);
        $this->assertNotEmpty($subscriber->confirmed_at);
        $this->assertEquals(87, $subscriber->id_customer);
        $customer = $this->getTableLocator()->get('Customers')->get(87);
        $this->assertEquals(1, $customer->newsletter_enabled);

        // zweiter Klick: weiterhin ok
        $this->post('/newsletter/bestaetigen/' . $subscriber->token);
        $this->assertResponseContains('deine Anmeldung ist bestätigt');
    }

    public function testExpiredConfirmationLinkAndPurge(): void
    {
        $this->postSubscribe();
        $subscribersTable = $this->getTableLocator()->get('NewsletterSubscribers');
        $subscriber = $this->getSubscriber('neue.abonnentin@example.test');
        $subscribersTable->updateAll(['confirmation_sent_at' => DateTime::now()->subDays(8)], ['id' => $subscriber->id]);

        $this->get('/newsletter/bestaetigen/' . $subscriber->token);
        $this->assertResponseContains('abgelaufen');
        $this->assertEquals(NewsletterSubscriber::STATUS_PENDING, $this->getSubscriber('neue.abonnentin@example.test')->status);

        $this->exec('newsletter purge_pending');
        $this->assertOutputContains('Gelöscht: 1');
        $this->assertNull($this->getSubscriber('neue.abonnentin@example.test'));
    }

    public function testInvalidConfirmationToken(): void
    {
        $this->get('/newsletter/bestaetigen/' . str_repeat('a', 64));
        $this->assertResponseOk();
        $this->assertResponseContains('nicht gültig');
    }

    public function testUnsubscribeByLinkAndOneClickPost(): void
    {
        $customerEmail = 'fcs-demo-mitglied@mailinator.com';
        $this->postSubscribe(['email' => $customerEmail]);
        $subscriber = $this->getSubscriber($customerEmail);
        $this->post('/newsletter/bestaetigen/' . $subscriber->token);
        $this->assertEquals(1, $this->getTableLocator()->get('Customers')->get(87)->newsletter_enabled);

        // GET (Link-Scanner, Vorschau): nur Formular, keine Abmeldung
        $this->get('/newsletter/abmelden/' . $subscriber->token);
        $this->assertResponseOk();
        $this->assertResponseContains('Jetzt abmelden');
        $this->assertEquals(NewsletterSubscriber::STATUS_ACTIVE, $this->getSubscriber($customerEmail)->status);
        $this->assertEquals(1, $this->getTableLocator()->get('Customers')->get(87)->newsletter_enabled);

        // RFC 8058: One-Click-POST ohne CSRF- und Formular-Token
        $this->_csrfToken = false;
        $this->_securityToken = false;
        $this->post('/newsletter/abmelden/' . $subscriber->token, ['List-Unsubscribe' => 'One-Click']);
        $this->assertResponseOk();
        $this->assertResponseContains('Du bist abgemeldet.');
        $subscriber = $this->getSubscriber($customerEmail);
        $this->assertEquals(NewsletterSubscriber::STATUS_UNSUBSCRIBED, $subscriber->status);
        $this->assertNotEmpty($subscriber->unsubscribed_at);
        $this->assertEquals(0, $this->getTableLocator()->get('Customers')->get(87)->newsletter_enabled);

        // Formular-POST (Button): HTML-Seite "abgemeldet"; gleiche Antwort bei unbekanntem Token
        $this->post('/newsletter/abmelden/' . $subscriber->token);
        $this->assertResponseOk();
        $this->assertResponseContains('Du bist abgemeldet</h1>');
        $this->post('/newsletter/abmelden/' . str_repeat('b', 64));
        $this->assertResponseOk();
        $this->assertResponseContains('Du bist abgemeldet</h1>');
    }

}
