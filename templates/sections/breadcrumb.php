<?php
/**
 * Breadcrumb trail (single posts and pages).
 *
 * Visible trail "Home > Category > Post title" with the
 * category parent chain (or the page ancestor chain) in
 * between, matching the padangkita.com reference ("Home >
 * Top News > [title]" above the headline). The first items
 * are links; the current page is plain text with
 * aria-current="page". The separator is rendered by CSS, so it
 * is never read out by screen readers.
 *
 * The trail data comes from `BreadcrumbModule::buildTrail()`
 * for the current post in the loop. The matching
 * BreadcrumbList JSON-LD is printed in `wp_head` by the
 * module itself, not here.
 *
 * @package Tray Digita Theme
 * @since 1.0.0
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\Breadcrumb\BreadcrumbModule;

(static function ($args) {
    $post = get_post();
    if (!$post instanceof WP_Post) {
        return;
    }
    if (!class_exists(BreadcrumbModule::class)) {
        return;
    }
    $trail = BreadcrumbModule::buildTrail($post);
    if (count($trail) < 2) {
        return;
    }
    $lastIndex = count($trail) - 1;
    ?>
    <nav class="td-breadcrumb" aria-label="<?php esc_attr_e('Breadcrumb', 'traydigita-core'); ?>">
        <ol class="td-breadcrumb-list">
            <?php foreach ($trail as $index => $item) : ?>
                <?php if ($index === $lastIndex) : ?>
                    <li class="td-breadcrumb-item td-breadcrumb-item--current">
                        <span class="td-breadcrumb-current" aria-current="page"><?php echo esc_html($item['name']); ?></span>
                    </li>
                <?php else : ?>
                    <li class="td-breadcrumb-item">
                        <a class="td-breadcrumb-link" href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['name']); ?></a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php
})($template_arguments ?? null);
