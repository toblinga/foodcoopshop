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

namespace App\Model\Table;

/**
 * @extends \App\Model\Table\AppTable<\Cake\Datasource\EntityInterface>
 */
class BlogCategoriesTable extends AppTable
{
    public const TABLE_MAP = 'fcs_blog_post_categories';

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setPrimaryKey('id_blog_category');
    }

    /**
     * Alle aktiven Kategorien als Baum-Liste (Eltern vor Kindern), mit Pfadnamen wie "Rezepte › Hauptspeise".
     * @return array<int, array{id: int, name: string, icon: string, path: string, parent_id: int}>
     */
    public function getAllAsTree(): array
    {
        return array_values($this->getCategoriesById());
    }

    /**
     * @return list<int>
     */
    public function getMappedIds(int $blogPostId): array
    {
        $rows = $this->getConnection()->selectQuery()
            ->select(['id_blog_category'])
            ->from(self::TABLE_MAP)
            ->where(['id_blog_post' => $blogPostId])
            ->execute()
            ->fetchAll('assoc');
        return array_values(array_map(fn ($row) => (int) $row['id_blog_category'], $rows));
    }

    /**
     * Zugeordnete Kategorien je Beitrag (in Baum-Reihenfolge).
     * @param list<int> $blogPostIds
     * @return array<int, list<array{id: int, name: string, icon: string, path: string, parent_id: int}>>
     */
    public function getAssignedByBlogPostIds(array $blogPostIds): array
    {
        $result = [];
        if (empty($blogPostIds)) {
            return $result;
        }
        $categories = $this->getCategoriesById();
        $rows = $this->getConnection()->selectQuery()
            ->select(['id_blog_post', 'id_blog_category'])
            ->from(self::TABLE_MAP)
            ->where(['id_blog_post IN' => array_map('intval', $blogPostIds)])
            ->execute()
            ->fetchAll('assoc');
        foreach ($rows as $row) {
            $idCategory = (int) $row['id_blog_category'];
            if (!isset($categories[$idCategory])) {
                continue;
            }
            $result[(int) $row['id_blog_post']][] = $categories[$idCategory];
        }
        foreach ($result as $idBlogPost => $items) {
            usort($result[$idBlogPost], fn ($a, $b) => strcmp($a['path'], $b['path']));
        }
        return $result;
    }

    /**
     * @param list<int|string> $categoryIds
     */
    public function saveMappings(int $blogPostId, array $categoryIds): void
    {
        $connection = $this->getConnection();
        $connection->transactional(function () use ($connection, $blogPostId, $categoryIds): void {
            $connection->delete(self::TABLE_MAP, ['id_blog_post' => $blogPostId]);
            foreach (array_unique(array_filter(array_map('intval', $categoryIds))) as $idCategory) {
                $connection->insert(self::TABLE_MAP, ['id_blog_post' => $blogPostId, 'id_blog_category' => $idCategory]);
            }
        });
    }

    /**
     * @return array<int, array{id: int, name: string, icon: string, path: string, parent_id: int}>
     */
    private function getCategoriesById(): array
    {
        $rows = $this->find('all',
            conditions: [$this->aliasField('active') => APP_ON],
            order: [
                $this->aliasField('parent_id') => 'ASC',
                $this->aliasField('position') => 'ASC',
                $this->aliasField('name') => 'ASC',
            ],
        )->all();

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row->id_blog_category] = (string) $row->name;
        }

        $result = [];
        foreach ($rows as $row) {
            $parentId = (int) $row->parent_id;
            $path = (string) $row->name;
            if ($parentId > 0 && isset($names[$parentId])) {
                $path = $names[$parentId] . ' › ' . $row->name;
            }
            $id = (int) $row->id_blog_category;
            $result[$id] = [
                'id' => $id,
                'name' => (string) $row->name,
                'icon' => (string) ($row->icon ?? ''),
                'path' => $path,
                'parent_id' => $parentId,
            ];
        }

        // Baum-Reihenfolge: jede Wurzel direkt gefolgt von ihren Unterkategorien
        $ordered = [];
        foreach ($result as $id => $item) {
            if ($item['parent_id'] === 0) {
                $ordered[$id] = $item;
                foreach ($result as $childId => $child) {
                    if ($child['parent_id'] === $id) {
                        $ordered[$childId] = $child;
                    }
                }
            }
        }
        foreach ($result as $id => $item) {
            if (!isset($ordered[$id])) {
                $ordered[$id] = $item;
            }
        }
        return $ordered;
    }
}
