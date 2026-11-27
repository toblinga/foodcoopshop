<?php
declare(strict_types=1);

use App\Model\Entity\NewsletterCampaign;
use App\Services\Newsletter\NewsletterCampaignService;
use Cake\TestSuite\TestCase;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Geplanter Versand: Prüfung vor dem Start (Inhalt, Abmeldelink, Betreff, Status). Ohne Datenbank.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterScheduleTest extends TestCase
{

    private function campaign(array $overrides = []): NewsletterCampaign
    {
        return new NewsletterCampaign(array_merge([
            'subject' => 'Testbetreff',
            'status' => NewsletterCampaign::STATUS_DRAFT,
            'html_cache' => '<html><body>Hallo <a href="{{unsubscribe_url}}">Abmelden</a></body></html>',
        ], $overrides));
    }

    public function testReadyCampaignPasses(): void
    {
        (new NewsletterCampaignService())->assertReadyToSend($this->campaign());
        $this->assertTrue(true);
    }

    public function testSentCampaignIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new NewsletterCampaignService())->assertReadyToSend($this->campaign(['status' => NewsletterCampaign::STATUS_SENT]));
    }

    public function testCampaignWithoutUnsubscribeLinkIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Abmeldelink');
        (new NewsletterCampaignService())->assertReadyToSend($this->campaign(['html_cache' => '<html>ohne Link</html>']));
    }

    public function testCampaignWithoutSubjectIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Betreff');
        (new NewsletterCampaignService())->assertReadyToSend($this->campaign(['subject' => '  ']));
    }

}
