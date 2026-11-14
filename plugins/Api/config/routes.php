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

use Cake\Routing\RouteBuilder;

return function (RouteBuilder $routes) {
    $routes->plugin('Api', ['path' => '/api/v1'], function (RouteBuilder $builder) {
        $builder->connect('/me', ['controller' => 'Me', 'action' => 'view'])->setMethods(['GET']);
        // further resources (products, manufacturers, customers, contacts) are added per feature branch
        $builder->connect('/*', ['controller' => 'Errors', 'action' => 'notFound']);
    });
};
