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
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
use Cake\Utility\Text;
use Cake\Core\Configure;
use Cake\ORM\Query\SelectQuery;

if (empty($blogPosts) || ($blogPosts instanceof SelectQuery && $blogPosts->count() == 0)) {
    return;
}

if (! isset($useCarousel) || $useCarousel) {
    $this->element('addScript', [
        'script' => Configure::read('app.jsNamespace') . ".Helper.initBlogPostCarousel();"
    ]);
}

if (isset($style) && $style != '') {
    $style = ' style="' . $style . '"';
} else {
    $style = '';
}

$blogPostList = is_array($blogPosts) ? $blogPosts : iterator_to_array($blogPosts, false);
$categoriesByBlogPost = \Cake\ORM\TableRegistry::getTableLocator()->get('BlogCategories')
    ->getAssignedByBlogPostIds(array_map(fn ($blogPost) => (int) $blogPost->id_blog_post, $blogPostList));

echo '<div class="blog-wrapper"' . $style . '>';
foreach ($blogPostList as $blogPost) {
    $cardCategories = $categoriesByBlogPost[(int) $blogPost->id_blog_post] ?? [];
    $cardIcon = $cardCategories[0]['icon'] ?? '';
    $cardNames = array_map(fn ($category) => $category['name'], $cardCategories);
    echo '<a class="blog-post-wrapper swiper-slide" href="' . $this->Slug->getBlogPostDetail($blogPost->id_blog_post, $blogPost->title) . '">';
    echo '<span class="img-wrapper">';
    echo '<img class="blog-post-image lazyload" data-src="' . $this->Html->getBlogPostImageSrc($blogPost, 'single') . '" />';
    echo '</span>';
    echo '<span class="content">';
        echo '<h3>' . $blogPost->title . '</h3>';
        if ($blogPost->short_description != '') {
            $contentText = $blogPost->short_description;
        } else {
            $contentText = Text::truncate(strip_tags($blogPost->content), 150);
        }
        echo '<span class="desc">' . $contentText . '</span>';
        echo '<span class="blog-card-footer">';
            echo '<span class="blog-card-categories">';
            if ($cardIcon !== '') {
                echo '<i class="fas ' . h($cardIcon) . '"></i> ';
            }
            echo h(implode(', ', $cardNames));
            echo '</span>';
            echo '<span class="blog-card-more">Weiterlesen &rarr;</span>';
        echo '</span>';
    echo '</span>';
    echo '<span class="date">' . $blogPost->modified->i18nFormat(Configure::read('app.timeHelper')->getI18Format('DateNTimeShort')) . '</span>';
    echo '</a>';
}
echo '</div>';
