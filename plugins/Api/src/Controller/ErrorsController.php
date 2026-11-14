<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://www.foodcoopshop.com
 */

namespace Api\Controller;

use Cake\Http\Response;

class ErrorsController extends ApiAppController
{
    public function notFound(): Response
    {
        return $this->error(404, 'unknown endpoint');
    }
}
