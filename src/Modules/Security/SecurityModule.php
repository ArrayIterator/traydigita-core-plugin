<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Security;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function apply_filters;
use function current_user_can;
use function defined;
use function dirname;
use function esc_html__;
use function file_exists;
use function fileperms;
use function filter_var;
use function function_exists;
use function get_bloginfo;
use function get_the_generator;
use function has_action;
use function ini_get;
use function is_array;
use function is_string;
use function str_contains;
use function username_exists;
use function wp_upload_dir;
use const ABSPATH;
use const FILTER_VALIDATE_BOOLEAN;
use const WP_CONTENT_DIR;

/**
 * Security Monitor module.
 *
 * Checks only — never reckless writes. The module audits six
 * security-relevant facts about the site and shows each as pass,
 * warning, or fail with a plain-language explanation and the
 * recommended fix:
 *
 * - `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` (with the exact
 *   `wp-config.php` lines to add, as copyable code).
 * - File permissions for `wp-config.php`, `.htaccess`, and the
 *   uploads directory (world-writable is a fail).
 * - Whether a user named `admin` exists.
 * - Whether PHP errors are displayed to visitors
 *   (`WP_DEBUG_DISPLAY` / `display_errors`).
 * - XML-RPC status (file present, `xmlrpc_enabled` filter).
 * - WordPress version disclosure in feeds and headers.
 *
 * The module never edits `wp-config.php`, never changes file
 * permissions, never deletes a user, and never disables a
 * feature: every fix is a manual step with the exact code shown,
 * because each of those actions is a one-way door for some sites
 * (or touches files the web server may not own). No fix is
 * offered as a button, and the settings page says why.
 *
 * No persistent settings exist: checks run on demand from the
 * settings page (Re-check button, AJAX) and `saveSettings()` is
 * a no-op success. `boot()` only registers the re-check AJAX
 * endpoint; the module adds no frontend hooks.
 *
 * Precedence note: the Security Headers module sends HTTP
 * response headers; this module only audits and never sends or
 * changes a header, so the two cannot fight. The version
 * disclosure check concerns the generator meta tag in feeds and
 * page markup, not a response header.
 */
final class SecurityModule implements ModuleInterface
{
    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'security-monitor';
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
        return 'security';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Security Monitor', 'traydigita-core');
    }

    /**
     * Module description shown on the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Checks the site for common security mistakes — file editing, permissions, the admin username, '
            . 'debug display, XML-RPC, and version disclosure — and shows how to fix each one. '
            . 'Checks only; it never changes anything.', 'traydigita-core');
    }

    /**
     * Dashicons slug for the module card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'shield';
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
            __('Checks for common security mistakes: file editing, permissions, admin username, '
                . 'debug display, XML-RPC, version disclosure.', 'traydigita-core'),
            __('Shows how to fix each finding.', 'traydigita-core'),
            __('Checks only — it never changes anything.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
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
        return 'traydigita-security-monitor';
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
     * Always true: the monitor needs no setup — checks run on
     * demand from the settings page.
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
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'checks', 'label' => __('Security checks', 'traydigita-core'), 'icon' => 'shield'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Runs all checks fresh on every page load and passes the
     * results to the `templates/admin/modules/security.php`
     * partial. The partial renders the check list, the Re-check
     * button, and the (settings-less) save form.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $checks = self::runChecks();
        $summary = Checks::summarize($checks);
        $recheckAction = SecurityAjax::AJAX_ACTION;
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/security.php';
        if (!file_exists($partial)) {
            echo '<p>' . esc_html__('The Security Monitor settings template is missing.', 'traydigita-core') . '</p>';
            return;
        }
        require $partial;
    }

    /**
     * Save the module settings.
     *
     * There are no persistent settings: the monitor only runs
     * checks on demand. This is a no-op success so the settings
     * form on the page (required by the module contract) submits
     * cleanly through the generic dispatcher.
     *
     * @param array<string, mixed> $data Sanitized form data (ignored).
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to do that.', 'traydigita-core'),
            ];
        }
        return [
            'success' => true,
            'message' => __('Security Monitor has no settings to save. '
                . 'Use the Re-check button to run the checks again.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module's hooks. Called only when enabled.
     *
     * The only hook is the re-check AJAX endpoint. The module
     * adds no frontend hooks: it audits, it does not change site
     * behaviour.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        SecurityAjax::register();
    }

    /**
     * Run all checks over the live environment.
     *
     * @return array<int, array<string, mixed>> Check results in display order.
     */
    public static function runChecks(): array
    {
        return Checks::run(self::collectEnvironment());
    }

    /**
     * Build the environment array the checks run over, from live
     * WordPress state.
     *
     * Read-only: `fileperms()`, `username_exists()`,
     * `apply_filters()`, and constant reads only. Nothing here
     * writes, creates, or deletes anything.
     *
     * @return array<string, mixed>
     */
    public static function collectEnvironment(): array
    {
        $wpConfig = self::locateWpConfig();
        $htaccess = ABSPATH . '.htaccess';
        $uploadsDir = self::locateUploadsDir();

        $version = (string) get_bloginfo('version');
        $generator = function_exists('get_the_generator') ? (string) get_the_generator('rss2') : '';
        $versionDisclosed = $version !== ''
            && (str_contains($generator, $version) || has_action('wp_head', 'wp_generator') !== false);

        return [
            'disallow_file_edit' => defined('DISALLOW_FILE_EDIT') ? (bool) DISALLOW_FILE_EDIT : null,
            'disallow_file_mods' => defined('DISALLOW_FILE_MODS') ? (bool) DISALLOW_FILE_MODS : null,
            'files' => [
                'wp-config.php' => self::readPerms($wpConfig),
                '.htaccess' => self::readPerms($htaccess),
                'uploads' => self::readPerms($uploadsDir),
            ],
            'admin_user_exists' => function_exists('username_exists') && username_exists('admin'),
            'wp_debug' => defined('WP_DEBUG') ? (bool) WP_DEBUG : false,
            'wp_debug_display' => defined('WP_DEBUG_DISPLAY') ? (bool) WP_DEBUG_DISPLAY : null,
            'display_errors' => filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN),
            'xmlrpc_file_exists' => file_exists(ABSPATH . 'xmlrpc.php'),
            'xmlrpc_enabled' => function_exists('apply_filters')
                ? (bool) apply_filters('xmlrpc_enabled', true)
                : true,
            'version_disclosed' => $versionDisclosed,
        ];
    }

    /**
     * Locate `wp-config.php`.
     *
     * WordPress allows `wp-config.php` one directory above
     * `ABSPATH`; check there too before giving up.
     *
     * @return string Absolute path (may not exist, when neither location has it).
     */
    private static function locateWpConfig(): string
    {
        $inRoot = ABSPATH . 'wp-config.php';
        if (file_exists($inRoot)) {
            return $inRoot;
        }
        $aboveRoot = dirname(ABSPATH) . '/wp-config.php';
        if (file_exists($aboveRoot)) {
            return $aboveRoot;
        }
        return $inRoot;
    }

    /**
     * Locate the uploads directory.
     *
     * @return string Absolute path.
     */
    private static function locateUploadsDir(): string
    {
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (is_array($uploads) && isset($uploads['basedir']) && is_string($uploads['basedir'])
                && $uploads['basedir'] !== ''
            ) {
                return $uploads['basedir'];
            }
        }
        return WP_CONTENT_DIR . '/uploads';
    }

    /**
     * Read permission bits for a path, or null when it does not
     * exist or cannot be read.
     *
     * @param string $path Absolute path.
     * @return int|null Permission bits from `fileperms()`, or null.
     */
    private static function readPerms(string $path): ?int
    {
        if (!file_exists($path)) {
            return null;
        }
        $perms = fileperms($path);
        return $perms === false ? null : $perms;
    }
}
