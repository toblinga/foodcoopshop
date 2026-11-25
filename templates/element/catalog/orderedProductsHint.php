<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://www.foodcoopshop.com
 */

use Cake\Core\Configure;

if (Configure::read('app.showOrderedProductsTotalAmountInCatalog') && !is_null($orderedTotalAmount)) {
    if ($product->next_delivery_day != 'delivery-rhythm-triggered-delivery-break') {
        $pickupDayString = $this->Time->getDateFormattedWithWeekday(strtotime($product->next_delivery_day));
        $tooltip = __('{0}_times_ordered_for_pickup_day_{1}.', [
            '<b>' . $orderedTotalAmount . '</b>',
            '<b>' . $pickupDayString . '</b>',
        ]);
        echo '<div title="' . $tooltip . '" class="ordered-products-total-amount">' . $orderedTotalAmount . '</div>';
    }
}
