<?php
/**
 * E-Paper single issue viewer.
 *
 * Selected by EPaperFrontend::filterTemplate() for
 * `/epaper/<issue-id>/` requests. View data is passed with
 * `set_query_var('traydigita_epaper_view', ...)` and read back
 * here with `get_query_var()`:
 * @var array{issue: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}, prev: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null, next: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null, pdf_url: string, cover_url: string|null, archive_url: string} $epaperView
 *
 * The PDF is shown with the browser's native PDF viewer
 * (`<object type="application/pdf">` with an `<embed>` fallback
 * and a download link for browsers without a PDF viewer). The
 * cover is the admin-uploaded media-library image when one
 * exists; otherwise a generated placeholder is shown — the
 * first page of the PDF is never rendered as a cover.
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\EPaper\EPaperFrontend;
use TrayDigita\WP\Plugin\Core\Modules\EPaper\EPaperStorage;

$epaperView = get_query_var(EPaperFrontend::VIEW_QUERY_VAR);
if (!is_array($epaperView) || !isset($epaperView['issue']) || !is_array($epaperView['issue'])) {
    status_header(404);
    $GLOBALS['wp_query']->set_404();
    get_template_part('404');
    return;
}
$epaperIssue = $epaperView['issue'];
/** @var array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null $epaperPrev */
$epaperPrev = isset($epaperView['prev']) && is_array($epaperView['prev']) ? $epaperView['prev'] : null;
/** @var array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null $epaperNext */
$epaperNext = isset($epaperView['next']) && is_array($epaperView['next']) ? $epaperView['next'] : null;
$epaperPdfUrl = (string) ($epaperView['pdf_url'] ?? '');
$epaperCoverUrl = isset($epaperView['cover_url']) && is_string($epaperView['cover_url']) ? $epaperView['cover_url'] : null;
$epaperArchiveUrl = (string) ($epaperView['archive_url'] ?? '');
$epaperDateLabel = date_i18n(get_option('date_format', 'F j, Y'), strtotime($epaperIssue['date']));

get_header();
?>
<main class="epaper-single">
    <header class="epaper-single-header">
        <p class="epaper-single-back"><a href="<?php echo esc_url($epaperArchiveUrl); ?>"><?php esc_html_e('All issues', 'traydigita-core'); ?></a></p>
        <h1 class="epaper-single-title"><?php echo esc_html($epaperIssue['title']); ?></h1>
        <p class="epaper-single-date">
            <time datetime="<?php echo esc_attr($epaperIssue['date']); ?>"><?php echo esc_html($epaperDateLabel); ?></time>
            <?php if ($epaperIssue['size'] > 0) : ?>
                <span class="epaper-single-size"><?php echo esc_html((string) size_format($epaperIssue['size'])); ?></span>
            <?php endif; ?>
        </p>
    </header>

    <div class="epaper-viewer-wrap">
        <?php if ($epaperCoverUrl !== null) : ?>
            <img class="epaper-single-cover" src="<?php echo esc_url($epaperCoverUrl); ?>" alt="">
        <?php endif; ?>
        <object class="epaper-viewer" data="<?php echo esc_url($epaperPdfUrl); ?>" type="application/pdf">
            <embed class="epaper-viewer-embed" src="<?php echo esc_url($epaperPdfUrl); ?>" type="application/pdf">
            <p class="epaper-viewer-fallback">
                <?php esc_html_e('Your browser cannot display the PDF inline.', 'traydigita-core'); ?>
                <a href="<?php echo esc_url($epaperPdfUrl); ?>" download><?php esc_html_e('Download the PDF', 'traydigita-core'); ?></a>
            </p>
        </object>
        <p class="epaper-download">
            <a class="epaper-download-link" href="<?php echo esc_url($epaperPdfUrl); ?>" download><?php esc_html_e('Download PDF', 'traydigita-core'); ?></a>
        </p>
    </div>

    <nav class="epaper-nav" aria-label="<?php esc_attr_e('Other issues', 'traydigita-core'); ?>">
        <?php if ($epaperPrev !== null) : ?>
            <a class="epaper-nav-link epaper-nav-prev" href="<?php echo esc_url(EPaperStorage::issueUrl($epaperPrev)); ?>">
                <span class="epaper-nav-label"><?php esc_html_e('Previous issue', 'traydigita-core'); ?></span>
                <span class="epaper-nav-title"><?php echo esc_html($epaperPrev['title']); ?></span>
            </a>
        <?php else : ?>
            <span class="epaper-nav-link epaper-nav-prev epaper-nav-empty" aria-hidden="true"></span>
        <?php endif; ?>
        <?php if ($epaperNext !== null) : ?>
            <a class="epaper-nav-link epaper-nav-next" href="<?php echo esc_url(EPaperStorage::issueUrl($epaperNext)); ?>">
                <span class="epaper-nav-label"><?php esc_html_e('Next issue', 'traydigita-core'); ?></span>
                <span class="epaper-nav-title"><?php echo esc_html($epaperNext['title']); ?></span>
            </a>
        <?php else : ?>
            <span class="epaper-nav-link epaper-nav-next epaper-nav-empty" aria-hidden="true"></span>
        <?php endif; ?>
    </nav>
</main>
<?php
get_footer();
