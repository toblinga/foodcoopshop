<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 1.0.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */

namespace App\Routing\Route;

use Cake\Cache\Cache;
use Cake\ORM\TableRegistry;
use Cake\Routing\Route\DashedRoute;
use Cake\Utility\Inflector;

/**
 * Catch-all fuer Infoseiten unter ihrer Adresse (z. B. /ueber-uns/darum-bio).
 *
 * Die Route matcht nur, wenn die Adresse einer aktiven Seite entspricht und
 * nicht mit einem Controller-Namen beginnt. Alle anderen Adressen fallen
 * durch und werden von den folgenden Routen bzw. fallbacks() behandelt.
 */
class PageRoute extends DashedRoute
{
    public const CACHE_KEY = 'page_slugs';

    /**
     * @return array<string, mixed>|null
     */
    public function parse(string $url, string $method = ''): ?array
    {
        $slug = trim($url, '/');
        if ($slug === '' || !in_array($slug, self::getPageSlugs(), true)) {
            return null;
        }
        if ($this->isControllerPrefix($slug)) {
            return null;
        }

        return parent::parse($url, $method);
    }

    /**
     * @return list<string>
     */
    public static function getPageSlugs(): array
    {
        /** @var list<string> $slugs */
        $slugs = Cache::remember(self::CACHE_KEY, static function (): array {
            $pages = TableRegistry::getTableLocator()->get('Pages');
            $slugs = $pages->find()
                ->select(['slug'])
                ->where(['Pages.active' => APP_ON])
                ->all()
                ->extract('slug')
                ->filter(static fn (mixed $slug): bool => is_string($slug) && $slug !== '')
                ->toList();

            return array_values($slugs);
        });

        return $slugs;
    }

    private function isControllerPrefix(string $slug): bool
    {
        $firstSegment = explode('/', $slug)[0];
        $controller = Inflector::camelize(str_replace('-', '_', $firstSegment));

        return class_exists('App\\Controller\\' . $controller . 'Controller');
    }
}
