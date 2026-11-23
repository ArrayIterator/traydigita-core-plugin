<?php
/**
 * Breaking news ticker widget.
 *
 * Variables (extracted by WidgetBase::renderPartial):
 * @var array<int, array{id: int, title: string, url: string, date: string,
 *     excerpt: string, comment_count: int, views: int,
 *     thumbnail_url: string, thumbnail_alt: string, author: string}> $posts
 * @var int $interval Auto-scroll interval in milliseconds.
 *
 * Auto-scroll behaviour lives in the frontend JS (Track C); it reads
 * the interval from the data attribute and honours
 * `prefers-reduced-motion`.
 */
declare(strict_types=1);

?>
<div class="widget-breaking-news" data-ticker
    data-ticker-interval="<?php echo esc_attr((string) $interval); ?>">
    <?php if (!empty($posts)) : ?>
        <span class="breaking-news-label"><?php esc_html_e('Breaking', 'traydigita-core'); ?></span>
        <ul class="breaking-news-list">
            <?php foreach ($posts as $post) : ?>
                <li class="breaking-news-item">
                    <a class="breaking-news-link" href="<?php echo esc_url($post['url']); ?>">
                        <?php echo esc_html($post['title']); ?>
                    </a>
                    <time class="breaking-news-date" datetime="<?php echo esc_attr($post['date']); ?>">
                        <?php echo esc_html($post['date']); ?>
                    </time>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="widget-empty"><?php esc_html_e('No breaking news right now.', 'traydigita-core'); ?></p>
    <?php endif; ?>
</div>
