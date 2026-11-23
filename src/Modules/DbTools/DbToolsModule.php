<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\DbTools;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function array_merge;
use function current_user_can;
use function file_exists;
use function get_option;
use function is_array;
use function preg_match;
use function sanitize_text_field;
use function update_option;
use function wp_create_nonce;

/**
 * DB Optimizer & Checker module (power tool, disabled by default).
 *
 * Settings page with three tabs: Tables (engine, rows, data /
 * index size, overhead, FULLTEXT indexes, per-table MyISAM to
 * InnoDB conversion and OPTIMIZE TABLE), Cleaner (expired
 * transients + orphaned timeout rows, post revisions, auto-drafts,
 * trashed posts/comments, spam comments, orphaned postmeta — each
 * category separately selectable with its count shown before
 * deleting), and Optimize (OPTIMIZE TABLE for all tables).
 *
 * Safety rules, enforced server-side (the JavaScript dialogs are
 * only a convenience layer):
 * - Table names are accepted only from the TableInspector
 *   allowlist (`$wpdb->tables()` plus `SHOW TABLE STATUS`,
 *   filtered by the site's table prefix).
 * - A MyISAM to InnoDB conversion runs only after the safety
 *   check passes; a FULLTEXT index the server's InnoDB cannot
 *   carry refuses the conversion with an explanation.
 * - Bulk deletes and optimize-all require the configured
 *   confirmation word typed exactly (default `DELETE`).
 * - Only one destructive job runs at a time (transient lock in
 *   DbToolsAjax); every action reports exactly what it did
 *   ("Deleted N rows from …").
 * - No user input ever reaches SQL or DDL unvalidated; all
 *   values go through `$wpdb` placeholders.
 *
 * The module has no frontend behaviour: `boot()` only registers
 * its AJAX endpoints. Settings live in the single option
 * `traydigita_db_tools` (autoload `no`); the only setting is
 * the confirmation word.
 */
final class DbToolsModule implements ModuleInterface
{
    /**
     * Option name for all DB Tools settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_db_tools';

    /**
     * Shared nonce action for the admin AJAX endpoints.
     *
     * The nonce itself must be sent as `_ajax_nonce` (see
     * `check_ajax_referer()`), never as a field named `nonce`.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Default confirmation word for bulk deletes / optimize-all.
     */
    public const string DEFAULT_CONFIRM_WORD = 'DELETE';

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id used by the module registry and the shell save endpoint.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'db-tools';
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
        return __('DB Optimizer & Checker', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Inspect database tables, convert MyISAM tables to InnoDB only when it is safe, clean expired transients and leftover content, and optimize tables. A power tool: every bulk delete needs a typed confirmation.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'database';
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
        return 'traydigita-db-tools';
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
     * The only setting is the confirmation word, which always
     * has a default, so the module is configured as soon as it
     * is enabled.
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
     * False: the module is optional and toggleable — and disabled
     * by default, because it deletes data and alters tables.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether the module is enabled, for the AJAX handlers.
     *
     * The AJAX handlers cannot call `ModuleRegistry::isEnabled()`
     * through the module class itself without a small indirection;
     * this is that indirection, kept public so DbToolsAjax can
     * refuse requests while the module is disabled.
     *
     * @return bool
     */
    public static function isEnabledForAjax(): bool
    {
        return ModuleRegistry::isEnabled(self::id());
    }

    /**
     * Settings-page tabs shown by the admin shell (R4-5): the
     * shell renders them as vertical tab buttons in the left
     * navigation, replacing the module's old in-page tab bar.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public static function tabs(): array
    {
        return [
            ['id' => 'tables', 'label' => __('Tables', 'traydigita-core')],
            ['id' => 'cleaner', 'label' => __('Cleaner', 'traydigita-core')],
            ['id' => 'optimize', 'label' => __('Optimize', 'traydigita-core')],
            ['id' => 'settings', 'label' => __('Settings', 'traydigita-core')],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Loads `templates/admin/modules/db-tools.php` with the table
     * list, the cleaner counts, the settings, and the server
     * version. The template renders the tabs, the table list, the
     * cleaner form, and the optimize-all form.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $tables = TableInspector::getTables();
        $counts = Cleaner::counts();
        $labels = Cleaner::categoryLabels();
        $descriptions = Cleaner::categoryDescriptions();
        $settings = self::getSettings();
        $serverVersion = TableInspector::serverVersion();
        $isMariaDb = TableInspector::isMariaDb($serverVersion);
        $supportsInnodbFulltext = TableInspector::supportsInnodbFulltext($serverVersion);
        $adminNonce = wp_create_nonce(self::NONCE_ACTION);
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/db-tools.php';
        if (file_exists($partial)) {
            require $partial;
        }
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * The only setting is `confirm_word`: 3–20 letters or digits,
     * stored exactly as typed (the typed confirmation compares
     * case-sensitively). An invalid word is rejected with a field
     * error and nothing is saved.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, errors?: array<string, string>}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change DB Tools settings.', 'traydigita-core'),
            ];
        }
        $settings = self::getSettings();
        if (isset($data['confirm_word'])) {
            $word = sanitize_text_field((string) $data['confirm_word']);
            if (preg_match('/^[A-Za-z0-9]{3,20}$/', $word) !== 1) {
                return [
                    'success' => false,
                    'message' => __('The confirmation word must be 3–20 letters or digits.', 'traydigita-core'),
                    'errors' => [
                        'confirm_word' => __('Use 3–20 letters or digits, no spaces.', 'traydigita-core'),
                    ],
                ];
            }
            $settings['confirm_word'] = $word;
        }
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('DB Tools settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module's AJAX endpoints. Called only when enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The module has no frontend hooks: it only acts
     * when an administrator uses its settings page.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        DbToolsAjax::register();
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{confirm_word: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $word = isset($settings['confirm_word']) ? (string) $settings['confirm_word'] : '';
        if (preg_match('/^[A-Za-z0-9]{3,20}$/', $word) !== 1) {
            $word = self::DEFAULT_CONFIRM_WORD;
        }
        return ['confirm_word' => $word];
    }

    /**
     * Default settings.
     *
     * @return array{confirm_word: string}
     */
    public static function getDefaults(): array
    {
        return ['confirm_word' => self::DEFAULT_CONFIRM_WORD];
    }
}
