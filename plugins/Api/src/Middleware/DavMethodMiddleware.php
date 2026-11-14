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

namespace Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The CakePHP router only knows the usual HTTP methods and rejects WebDAV methods (PROPFIND, REPORT, ...).
 * For CardDAV requests the method is therefore replaced by POST before routing; the original method
 * stays available as request attribute "davMethod".
 */
class DavMethodMiddleware implements MiddlewareInterface
{
    private const ROUTER_METHODS = ['GET', 'PUT', 'POST', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        if (
            ($path === '/.well-known/carddav' || $path === '/api/v1/carddav' || str_starts_with($path, '/api/v1/carddav/'))
            && !in_array($method, self::ROUTER_METHODS, true)
        ) {
            $request = $request->withAttribute('davMethod', $method)->withMethod('POST');
        }
        return $handler->handle($request);
    }
}
