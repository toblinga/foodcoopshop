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
    // address book discovery of CardDAV clients (RFC 6764)
    $routes->connect('/.well-known/carddav', ['plugin' => 'Api', 'controller' => 'Carddav', 'action' => 'wellKnown']);

    $routes->plugin('Api', ['path' => '/api/v1'], function (RouteBuilder $builder) {
        // CardDAV (read-only address book); PROPFIND/REPORT/... all end up in one action
        $builder->connect('/carddav', ['controller' => 'Carddav', 'action' => 'handle']);
        $builder->connect('/carddav/*', ['controller' => 'Carddav', 'action' => 'handle']);
        $builder->connect('/me', ['controller' => 'Me', 'action' => 'view'])->setMethods(['GET']);
        $builder->connect('/products', ['controller' => 'Products', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/products/{id}', ['controller' => 'Products', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['GET']);
        $builder->connect('/products/{id}', ['controller' => 'Products', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['PATCH']);
        $builder->connect('/products/{id}/attributes/{attributeId}', ['controller' => 'Products', 'action' => 'editAttribute'])
            ->setPatterns(['id' => '\d+', 'attributeId' => '\d+'])->setPass(['id', 'attributeId'])->setMethods(['PATCH']);
        $builder->connect('/orders', ['controller' => 'Orders', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/manufacturers', ['controller' => 'Manufacturers', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/orders', ['controller' => 'Orders', 'action' => 'add'])->setMethods(['POST']);
        $builder->connect('/manufacturers', ['controller' => 'Manufacturers', 'action' => 'add'])->setMethods(['POST']);
        $builder->connect('/manufacturers/{id}', ['controller' => 'Manufacturers', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['GET']);
        $builder->connect('/manufacturers/{id}', ['controller' => 'Manufacturers', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['PATCH']);
        $builder->connect('/customers', ['controller' => 'Customers', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/customers/{id}', ['controller' => 'Customers', 'action' => 'view'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['GET']);
        $builder->connect('/customers/{id}', ['controller' => 'Customers', 'action' => 'edit'])
            ->setPatterns(['id' => '\d+'])->setPass(['id'])->setMethods(['PATCH']);
        $builder->connect('/contacts', ['controller' => 'Contacts', 'action' => 'index'])->setMethods(['GET']);
        $builder->connect('/contacts.vcf', ['controller' => 'Contacts', 'action' => 'index', '_ext' => 'vcf'])->setMethods(['GET']);
        $builder->connect('/*', ['controller' => 'Errors', 'action' => 'notFound']);
    });
};
