<?php
declare(strict_types=1);

use App\Services\Newsletter\NewsletterRenderService;
use App\Services\Newsletter\NewsletterWebviewService;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Webansicht: Signatur der Adresse, Platzhalter in der Mail. Ohne Datenbank.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterWebviewTest extends TestCase
{

    public function testTokenIsValidForItsCampaignOnly(): void
    {
        $token = NewsletterWebviewService::token(42);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $this->assertTrue(NewsletterWebviewService::isValid(42, $token));
        $this->assertFalse(NewsletterWebviewService::isValid(43, $token));
        $this->assertFalse(NewsletterWebviewService::isValid(42, str_repeat('0', 32)));
        $this->assertFalse(NewsletterWebviewService::isValid(42, ''));
        $this->assertFalse(NewsletterWebviewService::isValid(0, NewsletterWebviewService::token(0)));
    }

    public function testUrlContainsBaseUrlIdAndToken(): void
    {
        $url = NewsletterWebviewService::url(7);

        $this->assertStringStartsWith(rtrim((string)Configure::read('App.fullBaseUrl'), '/') . '/newsletter/online/7/', $url);
        $this->assertStringEndsWith(NewsletterWebviewService::token(7), $url);
    }

    public function testPersonalizeReplacesWebviewPlaceholderAndFallsBackToHash(): void
    {
        $service = new NewsletterRenderService();
        $html = '<a href="{{webansicht_url}}">Im Browser</a> <a href="{{unsubscribe_url}}">Abmelden</a>';

        $withUrl = $service->personalize($html, null, 'https://shop.example/newsletter', 'https://shop.example/newsletter/online/7/abc');
        $this->assertStringContainsString('href="https://shop.example/newsletter/online/7/abc"', $withUrl);
        $this->assertStringNotContainsString('{{webansicht_url}}', $withUrl);

        $withoutUrl = $service->personalize($html, null, 'https://shop.example/newsletter');
        $this->assertStringContainsString('href="#"', $withoutUrl);
    }

}
