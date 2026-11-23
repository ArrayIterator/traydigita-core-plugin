<?php
/**
 * E-Paper archive (issue list, newest first).
 *
 * Selected by EPaperFrontend::filterTemplate() for `/epaper/`
 * requests. View data is passed with
 * `set_query_var('traydigita_epaper_view', ...)` and read back
 * here with `get_query_var()`:
 * @var array{issues: array<int, array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}>, page: int, total_pages: int, total: int, archive_url: string} $epaperView
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\EPaper\EPaperFrontend;

$epaperView = get_query_var(EPaperFrontend::VIEW_QUERY_VAR);
if (!is_array($epaperView)) {
    $epaperView = ['issues' => [], 'page' => 1, 'total_pages' => 1, 'total' => 0, 'archive_url' => ''];
}
$epaperIssues = is_array($epaperView['issues'] ?? null) ? $epaperView['issues'] : [];
$epaperPage = (int) ($epaperView['page'] ?? 1);
$epaperTotalPages = (int) ($epaperView['total_pages'] ?? 1);
$epaperArchiveUrl = (string) ($epaperView['archive_url'] ?? '');

get_header();
?>
<main class="epaper-archive">
    <header class="epaper-archive-header">
        <h1 class="epaper-archive-title"><?php esc_html_e('E-Paper', 'traydigita-core'); ?></h1>
        <p class="epaper-archive-description"><?php esc_html_e('Read the newspaper as it appeared in print — every issue as a PDF, newest first.', 'traydigita-core'); ?></p>
    </header>

    <?php if ($epaperIssues === []) : ?>
        <p class="epaper-empty"><?php esc_html_e('No E-Paper issues have been published yet.', 'traydigita-core'); ?></p>
    <?php else : ?>
        <div class="epaper-grid">
            <?php foreach ($epaperIssues as $epaperIssue) : ?>
                <?php echo EPaperFrontend::renderIssueCard($epaperIssue, true); ?>
            <?php endforeach; ?>
        </div>

        <?php if ($epaperTotalPages > 1) : ?>
            <nav class="epaper-pagination" aria-label="<?php esc_attr_e('E-Paper pages', 'traydigita-core'); ?>">
                <?php if ($epaperPage > 1) : ?>
                    <a class="epaper-pagination-link epaper-pagination-prev" href="<?php echo esc_url(add_query_arg('epage', $epaperPage - 1, $epaperArchiveUrl)); ?>"><?php esc_html_e('Newer issues', 'traydigita-core'); ?></a>
                <?php endif; ?>
                <span class="epaper-pagination-current">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %1$d is the current page, %2$d is the total number of pages. */
                            __('Page %1$d of %2$d', 'traydigita-core'),
                            $epaperPage,
                            $epaperTotalPages
                        )
                    );
                    ?>
                </span>
                <?php if ($epaperPage < $epaperTotalPages) : ?>
                    <a class="epaper-pagination-link epaper-pagination-next" href="<?php echo esc_url(add_query_arg('epage', $epaperPage + 1, $epaperArchiveUrl)); ?>"><?php esc_html_e('Older issues', 'traydigita-core'); ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php
get_footer();
