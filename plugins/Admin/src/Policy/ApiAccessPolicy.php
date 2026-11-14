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

namespace Admin\Policy;

use Authorization\IdentityInterface;
use Authorization\Policy\RequestPolicyInterface;
use Authorization\Policy\ResultInterface;
use Cake\Http\ServerRequest;

class ApiAccessPolicy implements RequestPolicyInterface
{
    public function canAccess(?IdentityInterface $identity, ServerRequest $request): bool|ResultInterface
    {
        if ($identity === null) {
            return false;
        }
        return $identity->isSuperadmin();
    }
}
