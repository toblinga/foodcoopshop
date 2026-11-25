<?php
declare(strict_types=1);
/**
 * Kategorie-Seite: alle Beitraege einer Kategorie, neueste zuerst
 */
?>
<h1><?php echo h($blogCategory->name); ?></h1>
<?php
echo $this->element('blogPosts', [
    'blogPosts' => $blogPosts,
    'useCarousel' => false
]);
?>
