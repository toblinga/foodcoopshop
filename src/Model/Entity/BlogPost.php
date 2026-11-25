<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 4.2.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
class BlogPost extends AppEntity
{
    /**
     * Rezept, wenn der Inhalt den Abschnitt "Zutaten" enthaelt. Einzige Stelle fuer diese Erkennung.
     */
    public function isRecipe(): bool
    {
        return str_contains((string) $this->content, 'Zutaten');
    }

    public function getCategoryLabel(): string
    {
        return $this->isRecipe() ? __('Category_recipe') : __('Category_general');
    }

    public function getCategoryCssClass(): string
    {
        return $this->isRecipe() ? 'blog-category-recipe' : 'blog-category-general';
    }
}