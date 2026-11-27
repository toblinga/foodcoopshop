<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Verschickt eine Portion einer Newsletter-Kampagne und plant die nächste (notBefore + 60 s) ein.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */

namespace App\Queue\Task;

use App\Services\Newsletter\NewsletterCampaignService;
use Queue\Queue\Task;

class NewsletterBatchTask extends Task
{

    public ?int $timeout = 300;

    // kein automatischer Neuversuch: offene Empfänger:innen holt der nächste Job bzw. `bin/cake newsletter resume`
    public ?int $retries = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function run(array $data, int $jobId): void
    {
        $campaignId = (int)($data['campaignId'] ?? 0);
        if ($campaignId === 0) {
            return;
        }
        (new NewsletterCampaignService())->processBatch($campaignId);
    }

}
