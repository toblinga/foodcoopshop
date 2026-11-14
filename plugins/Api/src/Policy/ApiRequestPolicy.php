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

namespace Api\Policy;

use Authorization\IdentityInterface;
use Authorization\Policy\RequestPolicyInterface;
use Authorization\Policy\ResultInterface;
use Cake\Http\ServerRequest;

/**
 * The request is let through here on purpose: the API controllers answer with JSON
 * (401 without valid token, 403 without the explicit permission) instead of redirecting to the login.
 */
class ApiRequestPolicy implements RequestPolicyInterface
{
    public function canAccess(?IdentityInterface $identity, ServerRequest $request): bool|ResultInterface
    {
        return true;
    }
}
