<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\NewsletterCampaign;
use App\Model\Entity\NewsletterSubscriber;
use App\Services\Newsletter\NewsletterRenderService;
use App\Services\Newsletter\NewsletterSubscriberService;
use App\Services\Newsletter\NewsletterWebviewService;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validation;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Öffentliche Newsletter-Seiten: Anmeldung (/newsletter), Danke-Seite, Bestätigung, Abmeldung.
 * Keine Auskunft, ob eine Adresse bereits in der Liste steht.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterController extends FrontendController
{

    public const RATE_LIMIT_MAX = 10;
    public const RATE_LIMIT_WINDOW_SECONDS = 3600;
    public const HONEYPOT_FIELD = 'website';

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated([
            'index',
            'thanks',
            'confirm',
            'unsubscribe',
            'online',
        ]);
        if ($this->components()->has('FormProtection')) {
            // One-Click-POST aus dem Mailprogramm (RFC 8058) hat kein Formular-Token
            $this->FormProtection->setConfig('unlockedActions', ['confirm', 'unsubscribe']);
        }
        if (!NewsletterSubscriberService::isEnabled()) {
            throw new NotFoundException();
        }
    }

    public function index(): ?Response
    {
        $this->set('title_for_layout', 'Newsletter');
        $this->set('consentText', NewsletterSubscriberService::getConsentText());
        $errors = [];

        if ($this->getRequest()->is('post')) {
            $data = (array)$this->getRequest()->getData('Newsletter');

            // Honeypot: Bots füllen das versteckte Feld aus => so tun, als wäre alles gut
            if (trim((string)($data[self::HONEYPOT_FIELD] ?? '')) !== '') {
                return $this->redirect('/newsletter/danke');
            }

            $email = NewsletterSubscriber::normalizeEmail((string)($data['email'] ?? ''));
            $firstName = trim(strip_tags((string)($data['first_name'] ?? '')));
            if ($email === '' || !Validation::email($email) || mb_strlen($email) > 255) {
                $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein.';
            }
            if (empty($data['consent'])) {
                $errors['consent'] = 'Bitte bestätige die Einwilligung.';
            }
            if (mb_strlen($firstName) > 100) {
                $errors['first_name'] = 'Bitte höchstens 100 Zeichen.';
            }

            if (empty($errors) && $this->isRateLimited()) {
                $errors['email'] = 'Zu viele Anmeldungen in kurzer Zeit. Bitte versuche es später noch einmal.';
            }

            if (empty($errors)) {
                (new NewsletterSubscriberService())->subscribe(
                    $email,
                    $firstName,
                    NewsletterSubscriber::SOURCE_HOME,
                    $this->getRequest()->clientIp(),
                    NewsletterSubscriberService::getConsentText() . ' (Formular: ' . $this->getFormSource((string)($data['form'] ?? '')) . ')',
                );
                // gleiche Antwort, egal ob neu, schon aktiv oder noch offen
                return $this->redirect('/newsletter/danke');
            }
            $this->set('formData', [
                'email' => (string)($data['email'] ?? ''),
                'first_name' => $firstName,
                'consent' => !empty($data['consent']),
            ]);
        }

        $this->set('errors', $errors);
        return null;
    }

    public function thanks(): void
    {
        $this->set('title_for_layout', 'Fast geschafft');
    }

    /**
     * Webansicht: "Diese Mail im Browser ansehen". Nur für versendete Kampagnen mit gültiger Signatur.
     */
    public function online(): ?Response
    {
        $campaignId = (int)$this->getRequest()->getParam('campaignId');
        $token = (string)$this->getRequest()->getParam('token');
        if (!NewsletterWebviewService::isValid($campaignId, $token)) {
            throw new NotFoundException();
        }
        $campaign = TableRegistry::getTableLocator()->get('NewsletterCampaigns')->find()
            ->where(['id' => $campaignId, 'status IN' => [NewsletterCampaign::STATUS_SENDING, NewsletterCampaign::STATUS_SENT]])
            ->first();
        if ($campaign === null) {
            throw new NotFoundException();
        }
        $renderService = new NewsletterRenderService();
        // Ohne Empfänger:in: Platzhalter bekommen die Ersatztexte, Abmelden führt zur Newsletter-Seite
        $html = $renderService->personalize(
            $renderService->render($campaign),
            null,
            rtrim((string)Configure::read('App.fullBaseUrl'), '/') . '/newsletter',
            NewsletterWebviewService::url($campaignId),
        );

        return $this->response
            ->withType('html')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withStringBody($html);
    }

    /**
     * GET zeigt nur die Bestätigungsseite mit Button. Erst der POST ändert etwas,
     * damit Link-Scanner von Mailanbietern die Anmeldung nicht auslösen.
     */
    public function confirm(): void
    {
        $token = (string)$this->getRequest()->getParam('token');
        $service = new NewsletterSubscriberService();
        if ($this->getRequest()->is('post')) {
            $result = $service->confirm($token);
        } else {
            $result = $service->checkConfirmable($token);
        }
        $this->set('result', $result);
        $this->set('actionUrl', '/newsletter/bestaetigen/' . $token);
        $this->set('title_for_layout', 'Newsletter bestätigen');
    }

    /**
     * GET zeigt nur das Abmelde-Formular (ohne Änderung). POST meldet ab: der One-Click-POST aus dem
     * Mailprogramm (RFC 8058, Body "List-Unsubscribe=One-Click") bekommt Text, das Formular eine HTML-Seite.
     */
    public function unsubscribe(): ?Response
    {
        $token = (string)$this->getRequest()->getParam('token');
        $service = new NewsletterSubscriberService();

        if ($this->getRequest()->is('post')) {
            // unbekannte Tokens bekommen dieselbe Antwort (keine Auskunft nach außen)
            $service->unsubscribeByToken($token);
            if ((string)$this->getRequest()->getData('List-Unsubscribe') === 'One-Click') {
                return $this->getResponse()
                    ->withType('text/plain')
                    ->withStringBody('Du bist abgemeldet.');
            }
            $this->set('done', true);
        } else {
            $this->set('done', false);
        }
        $this->set('actionUrl', '/newsletter/abmelden/' . $token);
        $this->set('title_for_layout', 'Vom Newsletter abmelden');
        return null;
    }

    private function getFormSource(string $form): string
    {
        return $form === 'teaser' ? 'Startseite' : 'Seite /newsletter';
    }

    private function isRateLimited(): bool
    {
        $ip = (string)$this->getRequest()->clientIp();
        $key = 'newsletter_rate_' . md5($ip);
        $now = time();
        $entry = Cache::read($key);
        if (!is_array($entry) || ($entry['start'] ?? 0) < $now - self::RATE_LIMIT_WINDOW_SECONDS) {
            $entry = ['start' => $now, 'count' => 0];
        }
        $entry['count']++;
        Cache::write($key, $entry);
        if ($entry['count'] > self::RATE_LIMIT_MAX) {
            return true;
        }
        // zusätzlich unabhängig vom Cache: Anmeldungen dieser IP in der letzten Stunde
        $recent = $this->getTableLocator()->get('NewsletterSubscribers')->find()->where([
            'consent_ip' => mb_substr($ip, 0, 45),
            'consent_at >=' => DateTime::now()->subSeconds(self::RATE_LIMIT_WINDOW_SECONDS),
        ])->count();
        return $recent >= self::RATE_LIMIT_MAX;
    }

}
