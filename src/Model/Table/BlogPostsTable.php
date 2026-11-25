<?php
declare(strict_types=1);
namespace App\Model\Table;

use Cake\Core\Configure;
use Cake\Database\Expression\QueryExpression;
use Cake\Validation\Validator;
use Cake\Routing\Router;
use Cake\ORM\Query\SelectQuery;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 1.0.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 *
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\BlogPost>
 */
class BlogPostsTable extends AppTable
{

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setPrimaryKey('id_blog_post');
        $this->belongsTo('Customers', [
            'foreignKey' => 'id_customer'
        ]);
        $this->belongsTo('Manufacturers', [
            'foreignKey' => 'id_manufacturer'
        ]);
        $this->addBehavior('Timestamp');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('title', __('Please_enter_a_title.'));
        $validator->minLength('title', 3, __('Please_enter_at_least_{0}_characters.', [3]));
        $validator->allowEmptyString('content');
        $validator->minLength('content', 3, __('Please_enter_at_least_{0}_characters.', [3]));
        $validator->allowEmptyString('short_description');
        $validator->maxLength('short_description', 100, __('Please_enter_max_{0}_characters.', [100]));
        $validator->allowEmptyDate('show_on_start_page_until');
        return $validator;
    }

    /**
     * @return SelectQuery<\App\Model\Entity\BlogPost>|list<\App\Model\Entity\BlogPost>
     */
    public function findBlogPosts(?int $manufacturerId = null, bool $showOnStartPage = false): SelectQuery|array
    {

        if (!Configure::read('app.isBlogFeatureEnabled')) {
            /** @var list<\App\Model\Entity\BlogPost> $empty */
            $empty = [];
            return $empty;
        }

        $conditions = [
            $this->aliasField('active') => APP_ON,
        ];

        $identity = Router::getRequest()->getAttribute('identity');
        
        if ($identity === null) {
            $conditions[$this->aliasField('is_private')] = APP_OFF;
            $conditions[] = '(Manufacturers.is_private IS NULL OR Manufacturers.is_private = ' . APP_OFF.')';
        }
        if ($manufacturerId) {
            $conditions[$this->aliasField('id_blog_post') . ' IN'] = $this->getBlogPostIdsSubquery(self::TABLE_MAP_MANUFACTURERS, 'id_manufacturer', $manufacturerId);
        }

        $blogPosts = $this->find('all',
            conditions: $conditions,
            order: [
                $this->aliasField('modified') => 'DESC',
            ],
            contain: [
                'Manufacturers',
            ],
        );
        /** @var SelectQuery<\App\Model\Entity\BlogPost> $blogPosts */

        return $this->getConditionShowOnStartPage($blogPosts, $showOnStartPage);
    }

    public const TABLE_MAP_MANUFACTURERS = 'fcs_blog_post_manufacturers';
    public const TABLE_MAP_PRODUCTS = 'fcs_blog_post_products';

    private function getBlogPostIdsSubquery(string $table, string $column, int $id): \Cake\Database\Query\SelectQuery
    {
        return $this->getConnection()->selectQuery()
            ->select(['id_blog_post'])
            ->from($table)
            ->where([$column => $id]);
    }

    /**
     * @return list<int>
     */
    public function getMappedManufacturerIds(int $blogPostId): array
    {
        return $this->getMappedIds(self::TABLE_MAP_MANUFACTURERS, 'id_manufacturer', $blogPostId);
    }

    /**
     * @return list<int>
     */
    public function getMappedProductIds(int $blogPostId): array
    {
        return $this->getMappedIds(self::TABLE_MAP_PRODUCTS, 'id_product', $blogPostId);
    }

    /**
     * @return list<int>
     */
    private function getMappedIds(string $table, string $column, int $blogPostId): array
    {
        $rows = $this->getConnection()->selectQuery()
            ->select([$column])
            ->from($table)
            ->where(['id_blog_post' => $blogPostId])
            ->execute()
            ->fetchAll('assoc');
        return array_values(array_map(fn ($row) => (int) $row[$column], $rows));
    }

    /**
     * @param list<int|string> $manufacturerIds
     * @param list<int|string> $productIds
     */
    public function saveMappings(int $blogPostId, array $manufacturerIds, array $productIds): void
    {
        $connection = $this->getConnection();
        $connection->transactional(function () use ($connection, $blogPostId, $manufacturerIds, $productIds): void {
            $connection->delete(self::TABLE_MAP_MANUFACTURERS, ['id_blog_post' => $blogPostId]);
            $connection->delete(self::TABLE_MAP_PRODUCTS, ['id_blog_post' => $blogPostId]);
            foreach (array_unique(array_filter(array_map('intval', $manufacturerIds))) as $idManufacturer) {
                $connection->insert(self::TABLE_MAP_MANUFACTURERS, ['id_blog_post' => $blogPostId, 'id_manufacturer' => $idManufacturer]);
            }
            foreach (array_unique(array_filter(array_map('intval', $productIds))) as $idProduct) {
                $connection->insert(self::TABLE_MAP_PRODUCTS, ['id_blog_post' => $blogPostId, 'id_product' => $idProduct]);
            }
        });
    }

    /**
     * Beiträge, die einem Produkt direkt oder über dessen Lieferanten zugeordnet sind.
     * @return array{posts: list<\App\Model\Entity\BlogPost>, count: int}
     */
    public function findBlogPostsForProduct(int $productId, int $manufacturerId, int $limit): array
    {
        if (!Configure::read('app.isBlogFeatureEnabled')) {
            return ['posts' => [], 'count' => 0];
        }

        $conditions = [
            $this->aliasField('active') => APP_ON,
            'OR' => [
                [$this->aliasField('id_blog_post') . ' IN' => $this->getBlogPostIdsSubquery(self::TABLE_MAP_PRODUCTS, 'id_product', $productId)],
                [$this->aliasField('id_blog_post') . ' IN' => $this->getBlogPostIdsSubquery(self::TABLE_MAP_MANUFACTURERS, 'id_manufacturer', $manufacturerId)],
            ],
        ];

        $identity = Router::getRequest()->getAttribute('identity');
        if ($identity === null) {
            $conditions[$this->aliasField('is_private')] = APP_OFF;
            $conditions[] = '(Manufacturers.is_private IS NULL OR Manufacturers.is_private = ' . APP_OFF . ')';
        }

        $query = $this->find('all',
            conditions: $conditions,
            order: [
                $this->aliasField('modified') => 'DESC',
            ],
            contain: [
                'Manufacturers',
            ],
        );
        $count = $query->count();
        $posts = $query->limit($limit)->all()->toArray();

        return ['posts' => $posts, 'count' => $count];
    }

    /**
     * @template T of array|\Cake\Datasource\EntityInterface
     * @param SelectQuery<T> $query
     * @return SelectQuery<T>
     */
    public function getConditionShowOnStartPage(SelectQuery $query, bool $showOnStartPage): SelectQuery
    {
    $query->where(function (QueryExpression $exp, SelectQuery $q) use ($showOnStartPage) {
            $key = 'DATE_FORMAT(BlogPosts.show_on_start_page_until, "%Y-%m-%d")';
            $value = Configure::read('app.timeHelper')->getCurrentDateForDatabase();
            if ($showOnStartPage) {
                return $exp->gte($key, $value);
            } else {
                return $exp->or([
                    $q->expr()->lt($key, $value),
                    $q->expr()->isNull('BlogPosts.show_on_start_page_until'),
                ]);
            }
        });
        return $query;
    }

}
