<?php
/**
 * Fast Search module settings page (rendered inside the admin shell content area).
 *
 * Variables (set by FastSearchModule::renderSettingsPage()):
 * @var array{fulltext_search: bool, limit_to_posts: bool} $fastSearchSettings Module settings.
 * @var array<string, array{table: string, name: string, columns: string, type: string, sql: string}> $fastSearchDefinitions Index definitions, in build order.
 * @var array<string, bool> $fastSearchStatuses Installed state per definition key.
 * @var bool $fastSearchReady Whether both FULLTEXT indexes are installed.
 * @var string $fastSearchMissingSql SQL of the missing indexes, one statement per line.
 * @var array{running: bool, total: int, completed: int, failed: int, done: array<int, string>, failed_keys: array<int, string>, results: array<int, array{key: string, name: string, installed: bool, message: string}>}|null $fastSearchJob Progress of a running build, or null when idle.
 * @var string $fastSearchNonceAction Nonce action (`traydigita-admin`).
 * @var string $fastSearchStartAction AJAX action starting a build (`traydigita_fast_search_start`).
 * @var string $fastSearchChunkAction AJAX action building one index (`traydigita_fast_search_chunk`).
 * @var string $fastSearchStatusAction AJAX action reading build progress (`traydigita_fast_search_status`).
 * @var string $fastSearchSaveAction AJAX action for the shell generic save endpoint (`traydigita_admin_save`).
 *
 * Behaviour:
 * - Status table: one row per index with its table, name, type,
 *   columns, and installed/missing badge. Row status cells carry
 *   data-fast-search-row="<key>" so the build-progress script can
 *   flip them to "Installed" as each index finishes.
 * - Build button (outside the settings form): starts a chunked
 *   build via the admin-fast-search script - one index per AJAX
 *   request, with a visible progress bar and a "processing"
 *   notice while it runs. Disabled while a build is running.
 * - Missing SQL: shown in a read-only textarea for manual
 *   installation (phpMyAdmin / MySQL console) or WP-CLI
 *   (`wp td-search install`).
 * - Settings form: posts to the shell generic save endpoint
 *   (`data-traydigita-module-form` + `data-module="fast-search"`).
 *   Field names are flat: `fulltext_search`, `limit_to_posts`.
 *   The hidden `0` field before each checkbox guarantees the
 *   field is always submitted, even when unchecked.
 *
 * Framework (R4-5): the settings fields are rendered with
 * SettingsControls (toggles) inside a framework section
 * (`td-section-behaviour`), and the form ends in the sticky
 * framework save bar. The index table, build button and missing
 * SQL keep their own markup - they are status/tools, not
 * settings fields.
 */
declare(strict_types=1);

?>
<div
    class="traydigita-fast-search-settings"
    data-fast-search-admin
    data-start-action="<?php echo esc_attr($fastSearchStartAction); ?>"
    data-chunk-action="<?php echo esc_attr($fastSearchChunkAction); ?>"
    data-status-action="<?php echo esc_attr($fastSearchStatusAction); ?>"
    data-msg-starting="<?php esc_attr_e('Starting index build…', 'traydigita-core'); ?>"
    data-msg-building="<?php esc_attr_e('Building search indexes…', 'traydigita-core'); ?>"
    data-msg-done="<?php esc_attr_e('All search indexes are installed.', 'traydigita-core'); ?>"
    data-msg-done-errors="<?php esc_attr_e('Index build finished with errors. See the index status below.', 'traydigita-core'); ?>"
    data-msg-running="<?php esc_attr_e('An index build is already running.', 'traydigita-core'); ?>"
    data-msg-error="<?php esc_attr_e('The index build could not run. Please try again.', 'traydigita-core'); ?>"
    data-msg-installed="<?php esc_attr_e('Installed', 'traydigita-core'); ?>"
    data-msg-missing="<?php esc_attr_e('Missing', 'traydigita-core'); ?>"
    data-msg-failed="<?php esc_attr_e('Failed', 'traydigita-core'); ?>"
>
    <div class="traydigita-fast-search-status" role="status">
        <?php if ($fastSearchReady) : ?>
            <span class="traydigita-fast-search-status-badge traydigita-fast-search-status-badge-ready"><?php esc_html_e('Active', 'traydigita-core'); ?></span>
            <p class="traydigita-fast-search-status-text"><?php esc_html_e('FULLTEXT search is active on frontend search queries.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <span class="traydigita-fast-search-status-badge traydigita-fast-search-status-badge-fallback"><?php esc_html_e('Fallback mode', 'traydigita-core'); ?></span>
            <p class="traydigita-fast-search-status-text"><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('Required FULLTEXT indexes are missing.', 'traydigita-core'), __('WordPress is safely using standard search until you install the indexes below.', 'traydigita-core')); ?></p>
        <?php endif; ?>
    </div>

    <div class="traydigita-fast-search-progress" data-fast-search-progress aria-live="polite" <?php echo $fastSearchJob === null ? 'hidden' : ''; ?>>
        <p class="traydigita-fast-search-progress-text" data-fast-search-progress-text>
            <?php
            if ($fastSearchJob !== null) {
                /* translators: %1$d is the number of indexes built so far, %2$d is the total number of indexes to build. */
                echo esc_html(sprintf(__('Building search indexes… %1$d of %2$d done.', 'traydigita-core'), (int) $fastSearchJob['completed'], (int) $fastSearchJob['total']));
            }
            ?>
        </p>
        <progress
            class="traydigita-fast-search-progress-bar"
            data-fast-search-progress-bar
            max="<?php echo esc_attr((string) ($fastSearchJob['total'] ?? count($fastSearchDefinitions))); ?>"
            value="<?php echo esc_attr((string) ($fastSearchJob['completed'] ?? 0)); ?>"
        ></progress>
    </div>

    <div class="traydigita-fast-search-notice" data-fast-search-notice aria-live="polite" hidden></div>

    <section class="traydigita-ui-section td-settings-section" id="td-section-indexes" aria-labelledby="td-section-indexes-title">
    <h2 class="traydigita-ui-section-title" id="td-section-indexes-title"><?php esc_html_e('Index status', 'traydigita-core'); ?></h2>

    <table class="widefat striped traydigita-fast-search-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Table', 'traydigita-core'); ?></th>
                <th><?php esc_html_e('Index name', 'traydigita-core'); ?></th>
                <th><?php esc_html_e('Type', 'traydigita-core'); ?></th>
                <th><?php esc_html_e('Columns', 'traydigita-core'); ?></th>
                <th><?php esc_html_e('Status', 'traydigita-core'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fastSearchDefinitions as $key => $definition) : ?>
                <tr>
                    <td><code><?php echo esc_html($definition['table']); ?></code></td>
                    <td><code><?php echo esc_html($definition['name']); ?></code></td>
                    <td><?php echo esc_html($definition['type']); ?></td>
                    <td><code><?php echo esc_html($definition['columns']); ?></code></td>
                    <td data-fast-search-row="<?php echo esc_attr($key); ?>">
                        <?php if (!empty($fastSearchStatuses[$key])) : ?>
                            <span class="traydigita-fast-search-badge traydigita-fast-search-badge-installed"><?php esc_html_e('Installed', 'traydigita-core'); ?></span>
                        <?php else : ?>
                            <span class="traydigita-fast-search-badge traydigita-fast-search-badge-missing"><?php esc_html_e('Missing', 'traydigita-core'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="traydigita-fast-search-build-actions">
        <button
            type="button"
            class="traydigita-admin-button traydigita-admin-button-primary"
            data-fast-search-build
            <?php disabled($fastSearchJob !== null); ?>
        ><?php esc_html_e('Build indexes', 'traydigita-core'); ?></button>
        <span class="description"><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('Installs the missing indexes one at a time.', 'traydigita-core'), __('Large tables can take a while per index; the page stays responsive and shows progress. WP-CLI (`wp td-search install`) is the faster route on very large sites.', 'traydigita-core')); ?></span>
    </p>
    </section>

    <?php if ($fastSearchMissingSql !== '') : ?>
        <section class="traydigita-ui-section td-settings-section" id="td-section-sql" aria-labelledby="td-section-sql-title">
        <h2 class="traydigita-ui-section-title" id="td-section-sql-title"><?php esc_html_e('Missing SQL statements', 'traydigita-core'); ?></h2>
        <p><?php esc_html_e('Run these statements in the MariaDB / MySQL console (or through WP-CLI) to install the missing indexes manually, then reload this page.', 'traydigita-core'); ?></p>
        <textarea readonly rows="<?php echo esc_attr((string) (count(explode("\n", $fastSearchMissingSql)) + 1)); ?>" class="traydigita-ui-textarea code traydigita-fast-search-sql"><?php echo esc_textarea($fastSearchMissingSql); ?></textarea>
        <p><code>wp td-search install</code> &nbsp;·&nbsp; <code>wp td-search status</code></p>
        </section>
    <?php endif; ?>

    <section class="traydigita-ui-section td-settings-section" id="td-section-behaviour" aria-labelledby="td-section-behaviour-title">
    <h2 class="traydigita-ui-section-title" id="td-section-behaviour-title"><?php esc_html_e('Search behaviour', 'traydigita-core'); ?></h2>

    <form class="traydigita-fast-search-form" method="post" data-traydigita-module-form data-traydigita-module="fast-search" data-module="fast-search" data-action="<?php echo esc_attr($fastSearchSaveAction); ?>">
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($fastSearchNonceAction)); ?>">
        <input type="hidden" name="module" value="fast-search">

        <?php
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
            'id' => 'fast-search-fulltext',
            'name' => 'fulltext_search',
            'label' => __('Use FULLTEXT search', 'traydigita-core'),
            'checked' => $fastSearchSettings['fulltext_search'],
            'help' => __('When on (and the indexes are installed), frontend search uses MATCH … AGAINST in boolean mode, including matches on category and tag names.', 'traydigita-core'),
            'help_long' => __('When off, or while the indexes are missing, standard WordPress search is used.', 'traydigita-core'),
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
            'id' => 'fast-search-limit-posts',
            'name' => 'limit_to_posts',
            'label' => __('Limit frontend search to posts', 'traydigita-core'),
            'checked' => $fastSearchSettings['limit_to_posts'],
            'help' => __('A search with no post type requested only searches posts, not pages or attachments.', 'traydigita-core'),
            'help_long' => __('A search that explicitly asks for another post type is left alone.', 'traydigita-core'),
        ]);
        ?>

        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
    </form>
    </section>
</div>
