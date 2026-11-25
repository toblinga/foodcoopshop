<?php
declare(strict_types=1);
/**
 * Stichwort-Seite: alle Beitraege zu einem Stichwort, neueste zuerst
 */
?>
<h1><?php echo h($blogTag->name); ?></h1>
<?php
echo $this->element('blogPosts', [
    'blogPosts' => $blogPosts,
    'useCarousel' => false
]);
?>
