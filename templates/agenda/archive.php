<?php
/**
 * Agenda archive template (`/agenda/`).
 *
 * Selected by AgendaRenderer::templateInclude() through the
 * `template_include` filter when the `traydigita_agenda` query
 * variable is `archive`. Data comes from
 * AgendaRenderer::archiveData(): the event list, pagination, the
 * active filter, and the search term. Semantic classes only - no
 * Tailwind utilities.
 *
 * @package Tray Digita Theme
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaRenderer;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaTable;

$agendaData = AgendaRenderer::archiveData();
$agendaEvents = $agendaData['events'];
$agendaFilter = $agendaData['filter'];
$agendaSearch = $agendaData['search'];
$agendaArchiveUrl = AgendaRenderer::archiveUrl();

get_header();
?>
<main class="agenda-archive">
    <header class="agenda-archive-header">
        <h1 class="agenda-archive-title"><?php esc_html_e('Agenda', 'traydigita-core'); ?></h1>
        <nav class="agenda-archive-filters" aria-label="<?php esc_attr_e('Filter events', 'traydigita-core'); ?>">
            <a href="<?php echo esc_url($agendaArchiveUrl); ?>" class="agenda-filter-link<?php echo $agendaFilter === '' ? ' agenda-filter-link-current' : ''; ?>">
                <?php esc_html_e('All events', 'traydigita-core'); ?>
            </a>
            <?php foreach (AgendaTable::STATUSES as $statusKey) : ?>
                <a href="<?php echo esc_url(add_query_arg('filter', $statusKey, $agendaArchiveUrl)); ?>" class="agenda-filter-link<?php echo $agendaFilter === $statusKey ? ' agenda-filter-link-current' : ''; ?>">
                    <?php echo esc_html(AgendaRenderer::statusLabel($statusKey)); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <form class="agenda-archive-search" method="get" action="<?php echo esc_url($agendaArchiveUrl); ?>" role="search">
            <?php if ($agendaFilter !== '') : ?>
                <input type="hidden" name="filter" value="<?php echo esc_attr($agendaFilter); ?>">
            <?php endif; ?>
            <label class="screen-reader-text" for="agenda-archive-search-input"><?php esc_html_e('Search events', 'traydigita-core'); ?></label>
            <input type="search" id="agenda-archive-search-input" name="s" value="<?php echo esc_attr($agendaSearch); ?>" placeholder="<?php esc_attr_e('Search events…', 'traydigita-core'); ?>">
            <button type="submit"><?php esc_html_e('Search', 'traydigita-core'); ?></button>
        </form>
    </header>

    <?php if ($agendaSearch !== '') : ?>
        <p class="agenda-search-summary">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s is the search term. */
                    __('Results for “%s”', 'traydigita-core'),
                    $agendaSearch
                )
            );
            ?>
        </p>
    <?php endif; ?>

    <?php echo AgendaRenderer::renderListHtml($agendaEvents); ?>

    <?php if ($agendaData['pages'] > 1) : ?>
        <nav class="agenda-pagination" aria-label="<?php esc_attr_e('Events pages', 'traydigita-core'); ?>">
            <?php
            $links = paginate_links([
                'base' => add_query_arg('paged', '%#%', $agendaArchiveUrl),
                'format' => '',
                'current' => $agendaData['page'],
                'total' => $agendaData['pages'],
                'prev_text' => __('&laquo; Previous', 'traydigita-core'),
                'next_text' => __('Next &raquo;', 'traydigita-core'),
            ]);
            if (is_string($links)) {
                echo $links;
            }
            ?>
        </nav>
    <?php endif; ?>
</main>
<?php
get_footer();
