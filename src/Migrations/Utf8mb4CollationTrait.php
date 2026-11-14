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

namespace App\Migrations;

/**
 * Picks a utf8mb4 collation the connected server supports.
 *
 * utf8mb4_0900_ai_ci only exists in MySQL 8. MariaDB 10.10+ offers the equivalent
 * utf8mb4_uca1400_ai_ci (Unicode 14, accent-insensitive); older MariaDB versions fall back
 * to utf8mb4_unicode_ci. Use it in migrations that convert tables.
 */
trait Utf8mb4CollationTrait
{
    protected function getUtf8mb4Collation(): string
    {
        $version = (string) ($this->fetchRow('SELECT VERSION() AS version')['version'] ?? '');
        if (stripos($version, 'mariadb') === false) {
            return 'utf8mb4_0900_ai_ci';
        }
        $uca1400 = $this->fetchRow("SHOW COLLATION LIKE 'utf8mb4_uca1400_ai_ci'");
        return $uca1400 ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_unicode_ci';
    }
}
