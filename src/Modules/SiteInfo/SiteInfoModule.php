<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\SiteInfo;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function _get_cron_array;
use function array_keys;
use function array_unique;
use function array_values;
use function constant;
use function count;
use function date_i18n;
use function defined;
use function esc_html;
use function esc_html_e;
use function esc_textarea;
use function function_exists;
use function get_bloginfo;
use function get_file_data;
use function get_option;
use function get_plugins;
use function get_site_option;
use function home_url;
use function implode;
use function ini_get;
use function is_array;
use function is_multisite;
use function is_readable;
use function is_string;
use function mb_strlen;
use function site_url;
use function size_format;
use function sprintf;
use function str_repeat;
use function wp_get_environment_type;
use wpdb;
use function wp_get_registered_image_subsizes;
use function wp_get_theme;
use function wp_max_upload_size;

/**
 * Site Info module.
 *
 * A read-only, Site-Health-style overview of the installation:
 * WordPress, PHP, and MySQL versions, theme and traydigita-stack
 * versions, active plugins, upload and memory limits, debug
 * constants, WP-Cron status, and every registered image size.
 * Nothing on the page writes anything - there are no settings to
 * save (saveSettings() is a no-op success, as the interface
 * requires), and no network request is made while rendering: every
 * value comes from WordPress core data already available in the
 * admin.
 *
 * A copy-to-clipboard button copies the whole report as plain
 * text (the same data the tables show, rendered once into a
 * hidden textarea by PHP so the copied text never depends on the
 * DOM). The button is wired by
 * `resources/js/modules/admin-site-info.ts`, which the coordinator
 * imports into the `admin` entry; the module file itself is not a
 * Vite entry.
 *
 * The settings page is rendered inline here (no template partial),
 * following SeoModule, so the module stays self-contained in its
 * own directory.
 */
final class SiteInfoModule implements ModuleInterface
{
    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'site-info';
    }

    /**
     * Default category slug for the module list.
     *
     * Optional: ModuleRegistry::categoryOf() falls back to the
     * 'administration' category when a module does not declare
     * one, so a card is never uncategorised.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'administration';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Site Info', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A read-only overview of the site — WordPress, PHP, and database versions, theme and plugin versions, limits, debug settings, cron status, and image sizes — with a button to copy the whole report as plain text.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'info';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Read-only overview: WordPress, PHP, and database versions.', 'traydigita-core'),
            __('Theme and plugin versions, limits, debug settings.', 'traydigita-core'),
            __('Cron status and image sizes.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here - never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    /**
     * Author shown on the module card in the admin module list.
     *
     * Modules may override this to name their own author; the
     * registry falls back to "TrayDigita" when a module does not
     * declare one, so every card always shows an author.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    public static function settingsPageSlug(): string
    {
        return 'traydigita-site-info';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * The page is read-only and has nothing to configure.
     *
     * @return bool Always true.
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * One table per report section, plus a copy button and a
     * hidden textarea holding the plain-text report (rendered by
     * PHP from the same data, so the copied text always matches
     * the tables). There is no settings form: the page is
     * read-only.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $report = self::getReport();
        $plainText = self::buildPlainTextReport($report);
        ?>
        <div class="traydigita-module-settings site-info-settings" data-site-info data-msg-copied="<?php echo esc_attr(__('Copied to clipboard.', 'traydigita-core')); ?>" data-msg-copy-failed="<?php echo esc_attr(__('Could not copy. Select the report text and copy it manually.', 'traydigita-core')); ?>">
            <?php
            SettingsControls::intro(
                __('Everything below is read-only information about this installation.', 'traydigita-core'),
                __('Use the copy button to paste the whole report into a support request.', 'traydigita-core')
            );
            ?>

            <p class="site-info-actions">
                <button type="button" class="traydigita-admin-button traydigita-admin-button-primary" data-site-info-copy>
                    <?php esc_html_e('Copy site info', 'traydigita-core'); ?>
                </button>
                <span class="site-info-copy-status" data-site-info-copy-status role="status" aria-live="polite"></span>
            </p>

            <textarea class="site-info-report-text" data-site-info-report readonly hidden><?php echo esc_textarea($plainText); ?></textarea>

            <?php foreach ($report as $section) : ?>
                <h2 class="site-info-section-title"><?php echo esc_html($section['title']); ?></h2>
                <table class="widefat striped site-info-table">
                    <tbody>
                        <?php foreach ($section['rows'] as $row) : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html($row['label']); ?></th>
                                <td><?php echo esc_html($row['value']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Save the module settings.
     *
     * There is nothing to save: the page is read-only. This is a
     * no-op success so the generic save dispatcher still gets a
     * well-formed answer if it is ever called for this module.
     *
     * @param array<string, mixed> $data Sanitized form data (ignored).
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        return [
            'success' => true,
            'message' => __('Site Info has no settings to save.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module's hooks. Called only when the module is enabled.
     *
     * There is nothing to register: the module renders a read-only
     * admin page and touches no frontend, login, or cron hook.
     * The guard stays so a direct call is safe too.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
    }

    /**
     * Gather the whole report, grouped into sections.
     *
     * Every value is a plain string, gathered from WordPress core
     * data only - no network request, no file scan, no write. A
     * value that cannot be determined is reported as unknown
     * rather than guessed.
     *
     * @return array<int, array{title: string, rows: array<int, array{label: string, value: string}>}>
     */
    public static function getReport(): array
    {
        return [
            [
                'title' => __('WordPress', 'traydigita-core'),
                'rows' => self::wordpressRows(),
            ],
            [
                'title' => __('Server', 'traydigita-core'),
                'rows' => self::serverRows(),
            ],
            [
                'title' => __('Theme & plugins', 'traydigita-core'),
                'rows' => self::themePluginRows(),
            ],
            [
                'title' => __('Limits', 'traydigita-core'),
                'rows' => self::limitRows(),
            ],
            [
                'title' => __('Debug & environment', 'traydigita-core'),
                'rows' => self::debugRows(),
            ],
            [
                'title' => __('Cron', 'traydigita-core'),
                'rows' => self::cronRows(),
            ],
            [
                'title' => __('Registered image sizes', 'traydigita-core'),
                'rows' => self::imageSizeRows(),
            ],
        ];
    }

    /**
     * Render the report as plain text for the copy button.
     *
     * Built from the same data as the tables, so the copied text
     * always matches what is on screen.
     *
     * @param array<int, array{title: string, rows: array<int, array{label: string, value: string}>}> $report Report from getReport().
     * @return string Plain-text report.
     */
    public static function buildPlainTextReport(array $report): string
    {
        $lines = [];
        $lines[] = sprintf(
            /* translators: %s is the site URL. */
            __('Site info for %s', 'traydigita-core'),
            site_url()
        );
        $lines[] = '';
        foreach ($report as $section) {
            $lines[] = $section['title'];
            $lines[] = str_repeat('-', mb_strlen($section['title']));
            foreach ($section['rows'] as $row) {
                $lines[] = $row['label'] . ': ' . $row['value'];
            }
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    /**
     * WordPress core rows: version, URLs, multisite.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function wordpressRows(): array
    {
        return [
            [
                'label' => __('WordPress version', 'traydigita-core'),
                'value' => get_bloginfo('version'),
            ],
            [
                'label' => __('Site URL', 'traydigita-core'),
                'value' => site_url(),
            ],
            [
                'label' => __('Home URL', 'traydigita-core'),
                'value' => home_url(),
            ],
            [
                'label' => __('Multisite', 'traydigita-core'),
                'value' => self::formatBool(is_multisite()),
            ],
            [
                'label' => __('Language', 'traydigita-core'),
                'value' => get_bloginfo('language'),
            ],
        ];
    }

    /**
     * Server rows: PHP, MySQL, web server software.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function serverRows(): array
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        $mysqlVersion = ($wpdb instanceof wpdb)
            ? (string) $wpdb->db_version()
            : __('Unknown', 'traydigita-core');
        $serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) && is_string($_SERVER['SERVER_SOFTWARE'])
            ? $_SERVER['SERVER_SOFTWARE']
            : __('Unknown', 'traydigita-core');
        return [
            [
                'label' => __('PHP version', 'traydigita-core'),
                'value' => PHP_VERSION,
            ],
            [
                'label' => __('MySQL version', 'traydigita-core'),
                'value' => $mysqlVersion,
            ],
            [
                'label' => __('Web server', 'traydigita-core'),
                'value' => $serverSoftware,
            ],
        ];
    }

    /**
     * Theme and plugin rows.
     *
     * The theme row names the active theme (and its parent, for a
     * child theme) with versions. The traydigita-stack row reads
     * the plugin header directly from its file, so it reports the
     * installed version even if the plugin is inactive. Active
     * plugins follow as one row per plugin; network-activated
     * plugins are included on multisite.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function themePluginRows(): array
    {
        $theme = wp_get_theme();
        $themeValue = $theme->get('Name') . ' ' . $theme->get('Version');
        $parent = $theme->parent();
        if ($parent !== false) {
            $themeValue .= sprintf(
                /* translators: %s is the parent theme name and version, e.g. "Parent 1.0". */
                __(' (child of %s)', 'traydigita-core'),
                $parent->get('Name') . ' ' . $parent->get('Version')
            );
        }
        $rows = [
            [
                'label' => __('Active theme', 'traydigita-core'),
                'value' => $themeValue,
            ],
            [
                'label' => __('traydigita-stack version', 'traydigita-core'),
                'value' => self::stackVersion(),
            ],
        ];
        $active = self::activePlugins();
        $rows[] = [
            'label' => __('Active plugins', 'traydigita-core'),
            'value' => (string) count($active),
        ];
        foreach ($active as $plugin) {
            $rows[] = [
                'label' => $plugin['name'],
                'value' => $plugin['version'],
            ];
        }
        return $rows;
    }

    /**
     * Read the installed traydigita-stack plugin version.
     *
     * Reads the `Version` header from the plugin file itself, so
     * the value is correct whether the plugin is active or not.
     * When the file is missing the stack is not installed.
     *
     * @return string Version number, or a "not installed" note.
     */
    private static function stackVersion(): string
    {
        if (!defined('WP_PLUGIN_DIR')) {
            return __('Unknown', 'traydigita-core');
        }
        $file = WP_PLUGIN_DIR . '/traydigita-stack/traydigita-stack.php';
        if (!is_readable($file)) {
            return __('Not installed', 'traydigita-core');
        }
        $data = get_file_data($file, ['Version' => 'Version'], 'plugin');
        $version = is_array($data) ? (string) ($data['Version'] ?? '') : '';
        if ($version === '') {
            return __('Unknown', 'traydigita-core');
        }
        return $version;
    }

    /**
     * List every active plugin (including network-activated ones).
     *
     * Names and versions come from get_plugins() when it is
     * available (it is, on admin screens); otherwise the plugin
     * file path is shown with an unknown version instead of
     * guessing.
     *
     * @return array<int, array{name: string, version: string}>
     */
    private static function activePlugins(): array
    {
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            $active = [];
        }
        if (is_multisite()) {
            $networkActive = get_site_option('active_sitewide_plugins', []);
            if (is_array($networkActive)) {
                foreach (array_keys($networkActive) as $pluginFile) {
                    $active[] = $pluginFile;
                }
            }
        }
        $active = array_values(array_unique($active));
        $installed = function_exists('get_plugins') ? get_plugins() : [];
        $plugins = [];
        foreach ($active as $pluginFile) {
            if (!is_string($pluginFile)) {
                continue;
            }
            $details = $installed[$pluginFile] ?? null;
            $plugins[] = [
                'name' => is_array($details) && isset($details['Name']) ? (string) $details['Name'] : $pluginFile,
                'version' => is_array($details) && isset($details['Version']) && $details['Version'] !== ''
                    ? (string) $details['Version']
                    : __('Version unknown', 'traydigita-core'),
            ];
        }
        return $plugins;
    }

    /**
     * Limit rows: upload size, memory, execution time.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function limitRows(): array
    {
        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');
        $maxExecution = ini_get('max_execution_time');
        return [
            [
                'label' => __('Max upload size', 'traydigita-core'),
                'value' => (string) size_format(wp_max_upload_size()),
            ],
            [
                'label' => __('PHP upload_max_filesize', 'traydigita-core'),
                'value' => is_string($uploadMax) && $uploadMax !== '' ? $uploadMax : __('Unknown', 'traydigita-core'),
            ],
            [
                'label' => __('PHP post_max_size', 'traydigita-core'),
                'value' => is_string($postMax) && $postMax !== '' ? $postMax : __('Unknown', 'traydigita-core'),
            ],
            [
                'label' => __('WordPress memory limit', 'traydigita-core'),
                'value' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : __('Unknown', 'traydigita-core'),
            ],
            [
                'label' => __('PHP memory_limit', 'traydigita-core'),
                'value' => (string) ini_get('memory_limit'),
            ],
            [
                'label' => __('WordPress max memory limit', 'traydigita-core'),
                'value' => defined('WP_MAX_MEMORY_LIMIT') ? (string) WP_MAX_MEMORY_LIMIT : __('Unknown', 'traydigita-core'),
            ],
            [
                'label' => __('PHP max_execution_time', 'traydigita-core'),
                'value' => is_string($maxExecution) && $maxExecution !== '' ? $maxExecution : __('Unknown', 'traydigita-core'),
            ],
        ];
    }

    /**
     * Debug and environment rows.
     *
     * Every constant is read with defined() first, so an undefined
     * constant is reported as "Not defined" instead of triggering
     * a warning.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function debugRows(): array
    {
        return [
            [
                'label' => __('Environment type', 'traydigita-core'),
                'value' => wp_get_environment_type(),
            ],
            [
                'label' => __('WP_DEBUG', 'traydigita-core'),
                'value' => self::formatConstant('WP_DEBUG'),
            ],
            [
                'label' => __('WP_DEBUG_LOG', 'traydigita-core'),
                'value' => self::formatConstant('WP_DEBUG_LOG'),
            ],
            [
                'label' => __('WP_DEBUG_DISPLAY', 'traydigita-core'),
                'value' => self::formatConstant('WP_DEBUG_DISPLAY'),
            ],
            [
                'label' => __('SCRIPT_DEBUG', 'traydigita-core'),
                'value' => self::formatConstant('SCRIPT_DEBUG'),
            ],
            [
                'label' => __('DISALLOW_FILE_EDIT', 'traydigita-core'),
                'value' => self::formatConstant('DISALLOW_FILE_EDIT'),
            ],
            [
                'label' => __('DISALLOW_FILE_MODS', 'traydigita-core'),
                'value' => self::formatConstant('DISALLOW_FILE_MODS'),
            ],
        ];
    }

    /**
     * WP-Cron rows.
     *
     * WP-Cron is a pseudo-cron: it only runs when the site gets a
     * visit, so "working" here means scheduled events exist and
     * nothing disables it - it cannot prove a run happened. When
     * DISABLE_WP_CRON is defined the status says so plainly, and
     * the next-event row is omitted because no event will run
     * without a real system cron.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function cronRows(): array
    {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return [
                [
                    'label' => __('WP-Cron status', 'traydigita-core'),
                    'value' => __('Disabled (DISABLE_WP_CRON is defined)', 'traydigita-core'),
                ],
            ];
        }
        $rows = [
            [
                'label' => __('WP-Cron status', 'traydigita-core'),
                'value' => __('Enabled (runs when the site gets visits)', 'traydigita-core'),
            ],
        ];
        $cron = function_exists('_get_cron_array') ? _get_cron_array() : false;
        if (!is_array($cron) || $cron === []) {
            $rows[] = [
                'label' => __('Scheduled events', 'traydigita-core'),
                'value' => '0',
            ];
            return $rows;
        }
        $totalEvents = 0;
        $nextTimestamp = null;
        $nextHook = '';
        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                if (!is_array($events)) {
                    continue;
                }
                $totalEvents += count($events);
                if ($nextTimestamp === null || $timestamp < $nextTimestamp) {
                    $nextTimestamp = (int) $timestamp;
                    $nextHook = (string) $hook;
                }
            }
        }
        $rows[] = [
            'label' => __('Scheduled events', 'traydigita-core'),
            'value' => (string) $totalEvents,
        ];
        if ($nextTimestamp !== null) {
            $rows[] = [
                'label' => __('Next scheduled event', 'traydigita-core'),
                'value' => sprintf(
                    /* translators: 1: cron hook name, 2: date and time of the next run. */
                    __('%1$s at %2$s', 'traydigita-core'),
                    $nextHook,
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $nextTimestamp)
                ),
            ];
        }
        return $rows;
    }

    /**
     * Registered image size rows.
     *
     * Uses wp_get_registered_image_subsizes(), which covers core
     * sizes and every size registered with add_image_size() -
     * including the theme's own sizes. A height of 0 means the
     * size scales proportionally instead of cropping to a fixed
     * height.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function imageSizeRows(): array
    {
        $sizes = wp_get_registered_image_subsizes();
        if ($sizes === []) {
            return [
                [
                    'label' => __('Registered image sizes', 'traydigita-core'),
                    'value' => __('None', 'traydigita-core'),
                ],
            ];
        }
        $rows = [];
        foreach ($sizes as $name => $size) {
            $width = (int) ($size['width'] ?? 0);
            $height = (int) ($size['height'] ?? 0);
            $crop = !empty($size['crop']);
            $rows[] = [
                'label' => (string) $name,
                'value' => sprintf(
                    '%d × %d (%s)',
                    $width,
                    $height,
                    $crop ? __('cropped', 'traydigita-core') : __('proportional', 'traydigita-core')
                ),
            ];
        }
        return $rows;
    }

    /**
     * Format a boolean as a translated Yes/No.
     *
     * @param bool $value Value to format.
     * @return string Translated "Yes" or "No".
     */
    private static function formatBool(bool $value): string
    {
        return $value ? __('Yes', 'traydigita-core') : __('No', 'traydigita-core');
    }

    /**
     * Format a debug constant: its boolean value, or "Not defined".
     *
     * @param string $name Constant name, e.g. 'WP_DEBUG'.
     * @return string Translated Yes/No, or "Not defined".
     */
    private static function formatConstant(string $name): string
    {
        if (!defined($name)) {
            return __('Not defined', 'traydigita-core');
        }
        return self::formatBool((bool) constant($name));
    }
}
