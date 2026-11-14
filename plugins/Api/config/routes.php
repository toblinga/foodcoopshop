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
        $builder->connect('/products', ['controller' => 'Products', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/products/{id}', ['controller' => 'Products', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['GET']);
        $builder->connect('/products/{id}', ['controller' => 'Products', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['PATCH']);
        $builder->connect('/products/{id}/attributes/{attributeId}', ['controller' => 'Products', 'action' => 'editAttribute'])
            ->setPatterns(['id' => '\d+', 'attributeId' => '\d+'])->setPass(['id', 'attributeId'])->setMethods(['PATCH']);
        $builder->connect('/manufacturers', ['controller' => 'Manufacturers', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/manufacturers', ['controller' => 'Manufacturers', 'action' => 'add'])->setMethods(['POST']);
        $builder->connect('/manufacturers/{id}', ['controller' => 'Manufacturers', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['GET']);
        $builder->connect('/manufacturers/{id}', ['controller' => 'Manufacturers', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['PATCH']);
        // further resources (customers, contacts) follow in their own feature branches
        $builder->connect('/*', ['controller' => 'Errors', 'action' => 'notFound']);
    });
};
