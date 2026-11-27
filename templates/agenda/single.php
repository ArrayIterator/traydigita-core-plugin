<?php
/**
 * Agenda single-event template (`/agenda/<id>/`).
 *
 * Selected by AgendaRenderer::templateInclude() through the
 * `template_include` filter when the `traydigita_agenda` query
 * variable is `single`. The event comes from
 * AgendaRenderer::singleData(); an unknown id never reaches this
 * template (the filter answers 404 instead). Event JSON-LD is
 * printed by AgendaRenderer::renderJsonLd() on `wp_head`.
 * Semantic classes only - no Tailwind utilities.
 *
 * @package Tray Digita Theme
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaModule;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaRenderer;

$agendaEvent = AgendaRenderer::singleData();
if ($agendaEvent === null) {
    get_header();
    ?>
    <main class="agenda-single">
        <p class="agenda-empty"><?php esc_html_e('Event not found.', 'traydigita-core'); ?></p>
    </main>
    <?php
    get_footer();
    return;
}
$agendaStatus = (string) ($agendaEvent['status'] ?? '');
$agendaCover = AgendaRenderer::coverHtml($agendaEvent, 'traydigita-hero');
$agendaLink = trim((string) ($agendaEvent['link'] ?? ''));
$agendaLocation = trim((string) ($agendaEvent['location'] ?? ''));
$agendaCategory = trim((string) ($agendaEvent['category'] ?? ''));
$agendaSettings = AgendaModule::getSettings();

get_header();
?>
<main class="agenda-single">
    <nav class="agenda-single-breadcrumb" aria-label="<?php esc_attr_e('Breadcrumb', 'traydigita-core'); ?>">
        <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'traydigita-core'); ?></a>
        <span class="agenda-breadcrumb-separator" aria-hidden="true">›</span>
        <a href="<?php echo esc_url(AgendaRenderer::archiveUrl()); ?>"><?php esc_html_e('Agenda', 'traydigita-core'); ?></a>
        <span class="agenda-breadcrumb-separator" aria-hidden="true">›</span>
        <span class="agenda-breadcrumb-current"><?php echo esc_html((string) ($agendaEvent['title'] ?? '')); ?></span>
    </nav>

    <article class="agenda-event-single agenda-event-status-<?php echo esc_attr($agendaStatus); ?>">
        <header class="agenda-single-header">
            <h1 class="agenda-single-title"><?php echo esc_html((string) ($agendaEvent['title'] ?? '')); ?></h1>
            <p class="agenda-event-meta">
                <span class="agenda-event-status"><?php echo esc_html(AgendaRenderer::statusLabel($agendaStatus)); ?></span>
                <time class="agenda-event-dates" datetime="<?php echo esc_attr(AgendaRenderer::startDatetimeAttr($agendaEvent)); ?>">
                    <?php echo esc_html(AgendaRenderer::formatRange($agendaEvent)); ?>
                </time>
                <?php if ($agendaLocation !== '') : ?>
                    <span class="agenda-event-location"><?php echo esc_html($agendaLocation); ?></span>
                <?php endif; ?>
                <?php if ($agendaCategory !== '') : ?>
                    <span class="agenda-event-category"><?php echo esc_html($agendaCategory); ?></span>
                <?php endif; ?>
            </p>
        </header>

        <?php if ($agendaCover !== '') : ?>
            <div class="agenda-event-cover"><?php echo $agendaCover; ?></div>
        <?php endif; ?>

        <div class="agenda-single-description">
            <?php echo AgendaRenderer::renderDescription($agendaEvent); ?>
        </div>

        <footer class="agenda-single-footer">
            <?php if ($agendaLink !== '') : ?>
                <p class="agenda-event-link">
                    <a href="<?php echo esc_url($agendaLink); ?>"><?php esc_html_e('More information', 'traydigita-core'); ?></a>
                </p>
            <?php endif; ?>
            <?php if ($agendaSettings['ics_enabled']) : ?>
                <p class="agenda-event-ics">
                    <a href="<?php echo esc_url(AgendaRenderer::icsUrl($agendaEvent)); ?>"><?php esc_html_e('Add to calendar (.ics)', 'traydigita-core'); ?></a>
                </p>
            <?php endif; ?>
            <p class="agenda-single-back">
                <a href="<?php echo esc_url(AgendaRenderer::archiveUrl()); ?>"><?php esc_html_e('Back to the agenda', 'traydigita-core'); ?></a>
            </p>
        </footer>
    </article>
</main>
<?php
get_footer();
