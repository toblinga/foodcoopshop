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

use App\Controller\Component\StringComponent;

/**
 * @extends \App\Model\Table\AppTable<\Cake\Datasource\EntityInterface>
 */
class BlogTagsTable extends AppTable
{
    public const TABLE_MAP = 'fcs_blog_post_tags';

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setPrimaryKey('id_blog_tag');
    }

    /**
     * Alle aktiven Stichwörter, alphabetisch
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function getAllForSelect(): array
    {
        $result = [];
        $rows = $this->find('all',
            conditions: [$this->aliasField('active') => APP_ON],
            order: [$this->aliasField('name') => 'ASC'],
        )->all();
        foreach ($rows as $row) {
            $result[(int) $row->id_blog_tag] = [
                'id' => (int) $row->id_blog_tag,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
            ];
        }
        return $result;
    }

    /**
     * @return list<int>
     */
    public function getMappedIds(int $blogPostId): array
    {
        $rows = $this->getConnection()->selectQuery()
            ->select(['id_blog_tag'])
            ->from(self::TABLE_MAP)
            ->where(['id_blog_post' => $blogPostId])
            ->execute()
            ->fetchAll('assoc');
        return array_values(array_map(fn ($row) => (int) $row['id_blog_tag'], $rows));
    }

    /**
     * Stichwörter je Beitrag, alphabetisch
     * @param list<int> $blogPostIds
     * @return array<int, list<array{id: int, name: string, slug: string}>>
     */
    public function getAssignedByBlogPostIds(array $blogPostIds): array
    {
        $result = [];
        if (empty($blogPostIds)) {
            return $result;
        }
        $tags = $this->getAllForSelect();
        $rows = $this->getConnection()->selectQuery()
            ->select(['id_blog_post', 'id_blog_tag'])
            ->from(self::TABLE_MAP)
            ->where(['id_blog_post IN' => array_map('intval', $blogPostIds)])
            ->execute()
            ->fetchAll('assoc');
        foreach ($rows as $row) {
            $idTag = (int) $row['id_blog_tag'];
            if (isset($tags[$idTag])) {
                $result[(int) $row['id_blog_post']][] = $tags[$idTag];
            }
        }
        foreach ($result as $idBlogPost => $items) {
            usort($result[$idBlogPost], fn ($a, $b) => strcmp($a['name'], $b['name']));
        }
        return $result;
    }

    /**
     * Werte aus dem Chip-Feld: Zahlen sind vorhandene Stichwörter, Text legt ein neues an (oder nutzt ein vorhandenes gleichen Namens).
     *
     * @param list<int|string> $values
     */
    public function saveMappings(int $blogPostId, array $values): void
    {
        $idTags = [];
        foreach (array_unique(array_filter(array_map('trim', array_map('strval', $values)))) as $value) {
            if (ctype_digit($value)) {
                $idTags[] = (int) $value;
                continue;
            }
            $idTags[] = $this->findOrCreateByName($value);
        }

        $connection = $this->getConnection();
        $connection->transactional(function () use ($connection, $blogPostId, $idTags): void {
            $connection->delete(self::TABLE_MAP, ['id_blog_post' => $blogPostId]);
            foreach (array_unique(array_filter($idTags)) as $idTag) {
                $connection->insert(self::TABLE_MAP, ['id_blog_post' => $blogPostId, 'id_blog_tag' => $idTag]);
            }
        });
    }

    private function findOrCreateByName(string $name): int
    {
        $name = mb_substr($name, 0, 150);
        $existing = $this->find()
            ->select(['id_blog_tag'])
            ->where(['name' => $name])
            ->first();
        if ($existing) {
            return (int) $existing->id_blog_tag;
        }
        $tag = $this->newEntity([
            'name' => $name,
            'slug' => mb_substr(StringComponent::slugify($name), 0, 150),
            'active' => APP_ON,
        ]);
        $saved = $this->save($tag);
        return $saved ? (int) $saved->id_blog_tag : 0;
    }
}
