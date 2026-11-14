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

/**
 * GET /api/v1/me - who is calling and with which permissions (useful to test a token).
 */
class MeController extends ApiAppController
{
    public function view(): Response
    {
        $c = $this->customer;
        return $this->json([
            'id' => $c->id_customer,
            'name' => trim($c->firstname . ' ' . $c->lastname),
            'is_api_user' => (bool) $c->is_api_user,
            'token' => $c->get('api_token_name'),
            'permissions' => $this->getTableLocator()->get('CustomerPermissions')->getForCustomer($c->id_customer),
        ]);
    }
}
