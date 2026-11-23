<?php
/**
 * Popular Posts widget.
 *
 * Variables (extracted by WidgetBase::renderPartial):
 * @var array<int, array{id: int, title: string, url: string, date: string,
 *     excerpt: string, comment_count: int, views: int,
 *     thumbnail_url: string, thumbnail_html: string, thumbnail_alt: string, author: string}> $posts
 * @var bool $show_thumbnail Whether thumbnails are shown.
 */
declare(strict_types=1);

?>
<div class="widget-popular-posts">
    <?php require __DIR__ . '/post-list.php'; ?>
</div>
