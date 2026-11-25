<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Controller\Component\StringComponent;
use App\Routing\Route\PageRoute;
use Cake\Cache\Cache;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Validation\Validator;
use ArrayObject;
use Cake\ORM\Query\SelectQuery;
/**
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
 * @extends \App\Model\Table\AppTable<\App\Model\Entity\Page>
 * @mixin \Cake\ORM\Behavior\TreeBehavior
 */
class PagesTable extends AppTable
{

    /**
     * @var array<int, string>
     */
    private array $flattenedArray = [];

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setPrimaryKey('id_page');
        $this->addBehavior('Tree', [
            'parent' => 'id_parent'
        ]);
        $this->addBehavior('Timestamp');
        $this->belongsTo('Customers', [
            'foreignKey' => 'id_customer'
        ]);
        $this->hasOne('HeaderPromos', [
            'foreignKey' => 'page_id',
            'dependent' => true,
        ]);
    }

    /**
     * @param EventInterface<\App\Model\Table\PagesTable> $event
     * @param ArrayObject<string, mixed> $options
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $entity->slug = $this->buildSlug($entity);
    }

    /**
     * @param EventInterface<\App\Model\Table\PagesTable> $event
     * @param ArrayObject<string, mixed> $options
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        // Unterseiten übernehmen die neue Adresse des Elternteils
        $this->rebuildAllSlugs((int) $entity->id_page);
        Cache::delete(PageRoute::CACHE_KEY);
    }

    /**
     * @param EventInterface<\App\Model\Table\PagesTable> $event
     * @param ArrayObject<string, mixed> $options
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        Cache::delete(PageRoute::CACHE_KEY);
    }

    /**
     * Adresse einer Seite: Adresse des Elternteils + eigener Titel, z. B. ueber-uns/darum-bio.
     */
    public function buildSlug(EntityInterface $page): string
    {
        $slug = strtolower(StringComponent::slugify((string) $page->title));
        $parentId = (int) ($page->id_parent ?? 0);
        if ($parentId > 0) {
            $parent = $this->find()->select(['slug'])->where(['id_page' => $parentId])->first();
            if ($parent !== null && !empty($parent->slug)) {
                $slug = $parent->slug . '/' . $slug;
            }
        }
        $candidate = $slug;
        $i = 2;
        $ownId = (int) ($page->id_page ?? 0);
        while ($this->exists(['slug' => $candidate, 'id_page !=' => $ownId])) {
            $candidate = $slug . '-' . $i;
            $i++;
        }
        return $candidate;
    }

    /**
     * Berechnet die Adressen aller Unterseiten von $parentId neu (rekursiv). Gibt die Anzahl zurück.
     */
    public function rebuildAllSlugs(int $parentId = 0): int
    {
        $count = 0;
        $pages = $this->find()->where(['id_parent' => $parentId])->orderBy(['id_page' => 'ASC'])->all();
        foreach ($pages as $page) {
            $slug = $this->buildSlug($page);
            $this->updateAll(['slug' => $slug], ['id_page' => $page->id_page]);
            $count++;
            $count += $this->rebuildAllSlugs((int) $page->id_page);
        }
        return $count;
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->notEmptyString('title', __('Please_enter_a_title.'));
        $validator->minLength('title', 2, __('Please_enter_at_least_{0}_characters.', [2]));
        $validator->range('position', [-1, 1001], __('Please_enter_a_number_between_{0}_and_{1}.', [0, 1000]));
        $validator->urlWithProtocol('extern_url', __('Please_enter_a_valid_internet_address.'));
        $validator->allowEmptyString('extern_url');
        return $validator;
    }

    /**
     * @param list<\App\Model\Entity\Page> $pages
     * @return array<int, string>
     */
    private function flattenNestedArrayWithChildren(array $pages, string $separator = ''): array
    {
        foreach ($pages as $page) {
            $statusString = '';
            if (!$page->active) {
                $statusString = ' ('.__('offline').')';
            }
            $this->flattenedArray[$page->id_page] = $separator . $page->title . $statusString;
            if (!empty($page->children)) {
                $this->flattenNestedArrayWithChildren($page->children, str_repeat('-',
                $this->getBehavior('Tree')->getLevel($page) + 1) // @phpstan-ignore-line
                 . ' ');
            }
        }

        return $this->flattenedArray;
    }

    /**
     * @param array<int|string, mixed> $conditions
     * @return SelectQuery<\App\Model\Entity\Page>
     */
    public function getThreaded(array $conditions = []): SelectQuery
    {
        $pages = $this->find('threaded',
        parentField: 'id_parent',
        conditions: $conditions,
        order: [
            'Pages.menu_type' => 'DESC',
            'Pages.position' => 'ASC',
            'Pages.title' => 'ASC',
        ],
        contain: [
            'Customers',
        ]);
        /** @var SelectQuery<\App\Model\Entity\Page> $pages */
        return $pages;
    }

    /**
     * @return array<int, string>
     */
    public function getForSelect(?int $excludePageId = null): array
    {
        $conditions = [];
        if ($excludePageId) {
            $conditions[] = 'Pages.active > ' . APP_DEL;
        }
        $pages = $this->getThreaded($conditions);
        $flattenedPages = $this->flattenNestedArrayWithChildren($pages->toArray());
        return $flattenedPages;
    }
}
