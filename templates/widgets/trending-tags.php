<?php
/**
 * Trending tags / topics widget.
 *
 * Variables (extracted by WidgetBase::renderPartial):
 * @var array<int, array{name: string, url: string, count: int}> $terms
 * @var string $taxonomy Taxonomy shown ("post_tag" or "category").
 */
declare(strict_types=1);

?>
<div class="widget-trending-tags" data-trending-taxonomy="<?php echo esc_attr($taxonomy); ?>">
    <?php if (!empty($terms)) : ?>
        <ul class="trending-tags-list">
            <?php foreach ($terms as $term) : ?>
                <li class="trending-tag-item">
                    <a class="trending-tag-link" href="<?php echo esc_url($term['url']); ?>">
                        <?php echo esc_html($term['name']); ?>
                        <span class="trending-tag-count">
                            <?php echo esc_html(number_format_i18n((int) $term['count'])); ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="widget-empty"><?php esc_html_e('No trending topics yet.', 'traydigita-core'); ?></p>
    <?php endif; ?>
</div>
