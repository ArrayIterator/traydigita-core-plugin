<?php
/**
 * Shared post list for the post widgets.
 *
 * Variables (extracted by WidgetBase::renderPartial):
 * @var array<int, array{id: int, title: string, url: string, date: string,
 *     excerpt: string, comment_count: int, views: int,
 *     thumbnail_url: string, thumbnail_html: string, thumbnail_alt: string, author: string}> $posts
 * @var bool $show_thumbnail Whether thumbnails are shown.
 *
 * The thumbnail is rendered from $post['thumbnail_html'] (the
 * complete escaped <img> tag built by ThemeImage via
 * WidgetBase::normalizePost()). Rows cached before thumbnail_html
 * existed fall back to the plain thumbnail_url <img> below.
 */
declare(strict_types=1);

?>
<?php if (!empty($posts)) : ?>
    <ul class="widget-post-list">
        <?php foreach ($posts as $post) : ?>
            <li class="widget-post-item">
                <?php if ($show_thumbnail) : ?>
                    <?php if (($post['thumbnail_html'] ?? '') !== '') : ?>
                        <a class="widget-post-thumbnail" href="<?php echo esc_url($post['url']); ?>"
                            tabindex="-1" aria-hidden="true">
                            <?php echo $post['thumbnail_html']; ?>
                        </a>
                    <?php elseif (($post['thumbnail_url'] ?? '') !== '') : ?>
                        <a class="widget-post-thumbnail" href="<?php echo esc_url($post['url']); ?>"
                            tabindex="-1" aria-hidden="true">
                            <img src="<?php echo esc_url($post['thumbnail_url']); ?>"
                                alt="<?php echo esc_attr($post['thumbnail_alt']); ?>" loading="lazy">
                        </a>
                    <?php else : ?>
                        <span class="widget-post-thumbnail-placeholder" aria-hidden="true"></span>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="widget-post-content">
                    <h3 class="widget-post-title">
                        <a href="<?php echo esc_url($post['url']); ?>">
                            <?php echo esc_html($post['title']); ?>
                        </a>
                    </h3>
                    <p class="widget-post-meta">
                        <time class="widget-post-date" datetime="<?php echo esc_attr($post['date']); ?>">
                            <?php echo esc_html($post['date']); ?>
                        </time>
                        <?php if (($post['views'] ?? 0) > 0) : ?>
                            <span class="widget-post-views">
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: %s is the view count. */
                                        _n('%s view', '%s views', (int) $post['views'], 'traydigita-core'),
                                        number_format_i18n((int) $post['views'])
                                    )
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else : ?>
    <p class="widget-empty"><?php esc_html_e('No articles found.', 'traydigita-core'); ?></p>
<?php endif; ?>
