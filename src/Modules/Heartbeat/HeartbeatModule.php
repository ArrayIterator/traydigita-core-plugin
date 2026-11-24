<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Heartbeat;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function _n;
use function add_action;
use function add_filter;
use function current_user_can;
use function get_current_screen;
use function get_option;
use function in_array;
use function is_admin;
use function is_array;
use function is_numeric;
use function post_type_exists;
use function sanitize_key;
use function update_option;
use function wp_deregister_script;
use function wp_unslash;

/**
 * Heartbeat Control module.
 *
 * Controls how often the WordPress Heartbeat API (`admin-ajax.php`,
 * action `heartbeat`) pulses, per context: the dashboard, the post
 * editor, and the frontend. The frontend heartbeat can be switched
 * off entirely; the post editor heartbeat can never be switched off
 * and never runs slower than once every 60 seconds.
 *
 * Why the post editor is special: the editor heartbeat drives
 * autosave and the post lock (the "another user is editing this
 * post" warning). Disabling it — or slowing it far down — would let
 * two editors overwrite each other silently and would stop
 * autosaves, so the editor interval is clamped to a minimum of
 * 60 seconds and there is no off switch for it. The settings page
 * says the same thing in plain language.
 *
 * Implementation: the `heartbeat_settings` filter sets the
 * `interval` (seconds) for the context the heartbeat script is
 * being localised for. The filter runs while the page is rendered
 * (admin or frontend), so the current screen is known there; when
 * the filter runs inside an AJAX heartbeat request instead, the
 * posted `screen_id` is used. Disabling the frontend heartbeat
 * deregisters the `heartbeat` script on the frontend so the
 * browser never starts polling.
 *
 * Settings live in the single option `traydigita_heartbeat`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and allowlists every interval value.
 *
 * The settings form is rendered inline here (no template partial),
 * following SeoModule, so the module stays self-contained in its
 * own directory.
 */
final class HeartbeatModule implements ModuleInterface
{
    /**
     * Option name for all heartbeat settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_heartbeat';

    /**
     * Context: the WordPress dashboard and other admin screens.
     */
    public const string CONTEXT_DASHBOARD = 'dashboard';

    /**
     * Context: the post/page editor.
     */
    public const string CONTEXT_EDITOR = 'editor';

    /**
     * Context: the frontend.
     */
    public const string CONTEXT_FRONTEND = 'frontend';

    /**
     * Interval choices (seconds) for the dashboard and frontend.
     *
     * WordPress itself pulses at most every 15 seconds, so there
     * is no faster choice; 120 seconds is the slowest useful
     * interval before the heartbeat stops being a heartbeat.
     *
     * @var array<int>
     */
    public const array INTERVAL_CHOICES = [15, 30, 45, 60, 120];

    /**
     * Interval choices (seconds) for the post editor.
     *
     * The editor never runs slower than 60 seconds: autosave and
     * the post lock depend on the heartbeat, and a slower pulse
     * would leave edits unprotected for too long.
     *
     * @var array<int>
     */
    public const array EDITOR_INTERVAL_CHOICES = [60, 120];

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'heartbeat';
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
        return 'performance';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Heartbeat Control', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Set how often the WordPress Heartbeat pulses on the dashboard, in the post editor, and on the frontend — and switch the frontend heartbeat off. The editor heartbeat can never be disabled, so autosave and post locking always keep working.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'heart';
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
            __('Control Heartbeat frequency on dashboard, editor, and frontend.', 'traydigita-core'),
            __('Switch the frontend heartbeat off.', 'traydigita-core'),
            __('The editor heartbeat can never be disabled, so autosave and '
                . 'post locking keep working.', 'traydigita-core'),
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
    public static function settingsPageSlug(): string
    {
        return 'traydigita-heartbeat';
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
     * The defaults are valid intervals, so the module is
     * configured as soon as it is enabled.
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
     * One interval select per context plus a frontend on/off
     * checkbox. The editor select only offers 60 and 120 seconds,
     * and the copy next to it explains why the editor heartbeat
     * cannot be disabled.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings heartbeat-settings">
            <?php
            SettingsControls::intro(
                __('The Heartbeat API keeps the browser in touch with WordPress: it autosaves drafts, warns when another user is '
                    . 'editing the same post, and lets plugins refresh data without a page reload.', 'traydigita-core'),
                __('A slower pulse means fewer admin-ajax.php requests; a faster pulse means fresher autosaves and locks.', 'traydigita-core')
            );
            ?>
            <p class="heartbeat-editor-note">
                <?php
                SettingsControls::helpText(
                    __('The post editor heartbeat can never be turned off, and never runs '
                        . 'slower than once every 60 seconds.', 'traydigita-core'),
                    __('Autosave and the post lock depend on it — without it, two editors could overwrite each other without warning and drafts would stop autosaving.', 'traydigita-core')
                );
                ?>
            </p>

            <form class="heartbeat-settings-form" data-traydigita-module-form data-traydigita-module="heartbeat" data-module="heartbeat" method="post" action="">
                <?php
                $intervalOptions = [];
                foreach (self::INTERVAL_CHOICES as $seconds) {
                    $intervalOptions[$seconds] = self::formatIntervalLabel($seconds);
                }
                $editorIntervalOptions = [];
                foreach (self::EDITOR_INTERVAL_CHOICES as $seconds) {
                    $editorIntervalOptions[$seconds] = self::formatIntervalLabel($seconds);
                }

                SettingsControls::select([
                    'id' => 'heartbeat-dashboard-interval',
                    'name' => 'dashboard_interval',
                    'label' => __('Dashboard interval', 'traydigita-core'),
                    'value' => (string) $settings['dashboard_interval'],
                    'options' => $intervalOptions,
                    'help' => __('How often the heartbeat pulses on dashboard and other admin screens.', 'traydigita-core'),
                ]);

                SettingsControls::select([
                    'id' => 'heartbeat-editor-interval',
                    'name' => 'editor_interval',
                    'label' => __('Post editor interval', 'traydigita-core'),
                    'value' => (string) $settings['editor_interval'],
                    'options' => $editorIntervalOptions,
                    'help' => __('Minimum 60 seconds.', 'traydigita-core'),
                    'help_long' => __('Autosave and post locking need the editor heartbeat, so it cannot be disabled or slowed further.', 'traydigita-core'),
                ]);

                SettingsControls::select([
                    'id' => 'heartbeat-frontend-interval',
                    'name' => 'frontend_interval',
                    'label' => __('Frontend interval', 'traydigita-core'),
                    'value' => (string) $settings['frontend_interval'],
                    'options' => $intervalOptions,
                    'help' => __('How often the heartbeat pulses for logged-in visitors on the frontend.', 'traydigita-core'),
                    'help_long' => __('Only used when the frontend heartbeat is enabled below.', 'traydigita-core'),
                    'attrs' => $settings['frontend_enabled'] ? [] : ['disabled' => true],
                ]);

                SettingsControls::toggle([
                    'id' => 'heartbeat-frontend-enabled',
                    'name' => 'frontend_enabled',
                    'label' => __('Enable heartbeat on the frontend', 'traydigita-core'),
                    'checked' => (bool) $settings['frontend_enabled'],
                    'help' => __('Switch off to stop the frontend heartbeat entirely and skip its admin-ajax.php requests for logged-in visitors.', 'traydigita-core'),
                    'help_long' => __('Some plugins rely on the frontend heartbeat; switch it back on if a frontend feature stops refreshing.', 'traydigita-core'),
                ]);

                SettingsControls::saveBar();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `dashboard_interval`,
     * `editor_interval`, `frontend_interval` (seconds, allowlisted
     * per context) and `frontend_enabled` (`1` when checked; an
     * absent key means the checkbox was unchecked — the form also
     * posts a hidden `0` first, so the key is always present).
     * Values outside the allowlist fall back to the context
     * default instead of being stored. Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change heartbeat settings.', 'traydigita-core'),
            ];
        }
        $settings = [
            'dashboard_interval' => self::sanitizeInterval($data['dashboard_interval'] ?? null, self::CONTEXT_DASHBOARD),
            'editor_interval' => self::sanitizeInterval($data['editor_interval'] ?? null, self::CONTEXT_EDITOR),
            'frontend_interval' => self::sanitizeInterval($data['frontend_interval'] ?? null, self::CONTEXT_FRONTEND),
            'frontend_enabled' => !empty($data['frontend_enabled']),
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Heartbeat settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the heartbeat hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The `heartbeat_settings` filter sets the interval
     * for the current context; when the frontend heartbeat is off,
     * the `heartbeat` script is deregistered on the frontend so
     * the browser never starts polling.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_filter('heartbeat_settings', [self::class, 'filterHeartbeatSettings']);
        if (!self::getSettings()['frontend_enabled']) {
            add_action('wp_enqueue_scripts', [self::class, 'disableFrontendHeartbeat'], 100);
        }
    }

    /**
     * Set the heartbeat interval for the current context.
     *
     * Runs on the `heartbeat_settings` filter. The interval is
     * always one of the allowlisted values for the context, so a
     * stored value can never slow the editor below 60 seconds.
     *
     * @param array<string, mixed> $settings Heartbeat settings from WordPress core.
     * @return array<string, mixed> The settings with the context interval applied.
     */
    public static function filterHeartbeatSettings(array $settings): array
    {
        $context = self::currentContext();
        $stored = self::getSettings();
        $settings['interval'] = match ($context) {
            self::CONTEXT_EDITOR => $stored['editor_interval'],
            self::CONTEXT_FRONTEND => $stored['frontend_interval'],
            default => $stored['dashboard_interval'],
        };
        return $settings;
    }

    /**
     * Stop the frontend heartbeat by deregistering its script.
     *
     * Runs late on `wp_enqueue_scripts` so it wins over themes
     * and plugins that enqueue the heartbeat script on the
     * frontend. The admin heartbeat is untouched.
     *
     * @return void
     */
    public static function disableFrontendHeartbeat(): void
    {
        wp_deregister_script('heartbeat');
    }

    /**
     * Work out which context the heartbeat is running in.
     *
     * The `heartbeat_settings` filter usually runs while the page
     * is rendered, so the current admin screen is known. Inside an
     * AJAX heartbeat request the screen is not available, so the
     * posted `screen_id` is used instead: the post/page edit
     * screens send `post`/`page`, and any other registered post
     * type sends its own slug. Anything admin that is not the
     * editor is the dashboard context; anything outside admin is
     * the frontend.
     *
     * @return string One of the CONTEXT_* constants.
     */
    public static function currentContext(): string
    {
        $screenId = isset($_POST['screen_id']) && is_string($_POST['screen_id'])
            ? sanitize_key(wp_unslash($_POST['screen_id']))
            : '';
        if ($screenId !== '') {
            if ($screenId === 'post' || $screenId === 'page' || post_type_exists($screenId)) {
                return self::CONTEXT_EDITOR;
            }
            return self::CONTEXT_DASHBOARD;
        }
        if (is_admin() && function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen !== null && $screen->base === 'post') {
                return self::CONTEXT_EDITOR;
            }
            return self::CONTEXT_DASHBOARD;
        }
        return is_admin() ? self::CONTEXT_DASHBOARD : self::CONTEXT_FRONTEND;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * Every interval is re-validated against its context
     * allowlist, so a value stored by an older version (or edited
     * by hand in the database) can never slow the editor below
     * 60 seconds.
     *
     * @return array{dashboard_interval: int, editor_interval: int, frontend_interval: int, frontend_enabled: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        return [
            'dashboard_interval' => self::sanitizeInterval($settings['dashboard_interval'] ?? null, self::CONTEXT_DASHBOARD),
            'editor_interval' => self::sanitizeInterval($settings['editor_interval'] ?? null, self::CONTEXT_EDITOR),
            'frontend_interval' => self::sanitizeInterval($settings['frontend_interval'] ?? null, self::CONTEXT_FRONTEND),
            'frontend_enabled' => (bool) ($settings['frontend_enabled'] ?? true),
        ];
    }

    /**
     * Default settings.
     *
     * All contexts pulse once a minute (the WordPress default
     * behaviour); the frontend heartbeat is on until an
     * administrator switches it off.
     *
     * @return array{dashboard_interval: int, editor_interval: int, frontend_interval: int, frontend_enabled: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'dashboard_interval' => 60,
            'editor_interval' => 60,
            'frontend_interval' => 60,
            'frontend_enabled' => true,
        ];
    }

    /**
     * Validate one interval against its context allowlist.
     *
     * A non-numeric or out-of-list value falls back to the
     * context default (60 seconds), never to the fastest value.
     *
     * @param mixed $value Raw submitted value.
     * @param string $context One of the CONTEXT_* constants.
     * @return int A valid interval in seconds.
     */
    private static function sanitizeInterval(mixed $value, string $context): int
    {
        $defaults = self::getDefaults();
        $default = match ($context) {
            self::CONTEXT_EDITOR => $defaults['editor_interval'],
            self::CONTEXT_FRONTEND => $defaults['frontend_interval'],
            default => $defaults['dashboard_interval'],
        };
        if (!is_numeric($value)) {
            return $default;
        }
        $seconds = (int) $value;
        $allowed = $context === self::CONTEXT_EDITOR
            ? self::EDITOR_INTERVAL_CHOICES
            : self::INTERVAL_CHOICES;
        return in_array($seconds, $allowed, true) ? $seconds : $default;
    }

    /**
     * Human-readable label for an interval in seconds.
     *
     * @param int $seconds Interval in seconds.
     * @return string e.g. "Every 15 seconds" or "Every 2 minutes".
     */
    private static function formatIntervalLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf(
                /* translators: %d is a number of seconds. */
                __('Every %d seconds', 'traydigita-core'),
                $seconds
            );
        }
        $minutes = (int) ($seconds / 60);
        return sprintf(
            /* translators: %d is a number of minutes. */
            _n('Every %d minute', 'Every %d minutes', $minutes, 'traydigita-core'),
            $minutes
        );
    }
}
