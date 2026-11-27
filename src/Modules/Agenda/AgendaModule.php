<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Agenda;

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Widgets\AgendaWidget;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function add_shortcode;
use function array_merge;
use function ceil;
use function current_user_can;
use function did_action;
use function esc_html__;
use function file_exists;
use function flush_rewrite_rules;
use function get_option;
use function in_array;
use function is_array;
use function max;
use function register_widget;
use function sanitize_key;
use function sanitize_text_field;
use function update_option;
use function wp_unslash;

/**
 * Agenda / Event Manager module.
 *
 * Events are stored in their own custom table
 * (`$wpdb->prefix . 'traydigita_agenda'`, see AgendaTable) - never
 * in `wp_posts` - with a computed (never stored) status of
 * upcoming / ongoing / past. The frontend is served through
 * rewrite rules (`/agenda/`, `/agenda/<id>/`, `/agenda/<id>.ics`),
 * the `[traydigita_agenda]` shortcode lists upcoming events, and
 * the "Tray Digita: Upcoming Events" widget shows the next few.
 *
 * TABLE-CREATION DESIGN (read this before touching it): the module
 * registry has no activation hook, so there is no "on enable" event
 * to create the table in. Instead the table is created lazily:
 * every write (insert/update/delete) runs through
 * AgendaTable::ensureTableExists(), which checks once per request
 * and creates a missing table with one idempotent CREATE TABLE IF
 * NOT EXISTS - so a deleted table self-heals on the next write.
 * dbDelta is reserved for schema upgrades only
 * (AgendaTable::ensureTable(), admin-only, version-gated, checked
 * at most once an hour behind a transient, serialised by a 60s
 * transient lock), and is called from boot() and the settings
 * page. A second upgrade check inside the hour costs nothing and
 * never runs dbDelta() again.
 *
 * DATA RETENTION: deactivating the module keeps the table and all
 * events (the registry never drops data). Deleting data is a
 * separate explicit action on the module page ("Delete all agenda
 * data") that requires typing DELETE and drops the table plus the
 * version flag (see AgendaAjax::deleteAll()).
 *
 * REWRITE FLUSH: the rewrite rules are static, so settings changes
 * never need a flush. The one-time flush after the module is first
 * enabled is tracked by the `traydigita_agenda_rewrites_flushed`
 * option flag in boot(); a flush on every request would be a
 * performance bug, so the flag is what prevents it.
 *
 * Settings live in the single option `traydigita_agenda`
 * (autoload `no`): `archive_per_page`, `show_past_in_archive`
 * (past events in the unfiltered archive view), and `ics_enabled`.
 */
final class AgendaModule implements ModuleInterface
{
    /**
     * Option name for all agenda settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_agenda';

    /**
     * Option flag recording that the rewrite rules were flushed once.
     */
    public const string REWRITES_FLUSHED_OPTION = 'traydigita_agenda_rewrites_flushed';

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'agenda';
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
        return 'content';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Agenda', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Manage events in their own table — with archive and single views, an upcoming-events widget '
            . 'and shortcode, and a per-event iCal download — without touching the post workflow.',
            'traydigita-core'
        );
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'calendar-alt';
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
            __('Events stored in their own table.', 'traydigita-core'),
            __('Archive and single event views.', 'traydigita-core'),
            __('Upcoming-events widget.', 'traydigita-core'),
            __('ICS export for events.', 'traydigita-core'),
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
        return 'traydigita-agenda';
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
     * Configured means the agenda table exists (the schema version
     * flag is set). A single option read - no schema query - so the
     * module grid stays cheap.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return AgendaTable::isInstalled();
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
     * Whether the module is enabled right now.
     *
     * Small wrapper around the registry, used by the AJAX
     * endpoints so they refuse to run while disabled.
     *
     * @return bool
     */
    public static function isEnabledForRequest(): bool
    {
        return ModuleRegistry::isEnabled(self::id());
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'event', 'label' => __('Event', 'traydigita-core'), 'icon' => 'calendar-alt'],
            ['id' => 'events', 'label' => __('Events', 'traydigita-core'), 'icon' => 'list-view'],
            ['id' => 'danger', 'label' => __('Danger zone', 'traydigita-core'), 'icon' => 'warning'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Calls ensureTable() first (see the class docblock: this is
     * the second of the two call points), then requires
     * `templates/admin/modules/agenda.php` with the settings, the
     * current event list (filter / search / page from the query
     * string), the category list, and the nonce / AJAX action
     * names. The event add/edit form on this page posts to the
     * module's own AJAX action, not the generic dispatcher, so
     * multi-line descriptions keep their newlines.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-error"><p>'
                . esc_html__('You are not allowed to do that.', 'traydigita-core')
                . '</p></div>';
            return;
        }
        $tableReady = AgendaTable::ensureTable();
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $agendaSettings = self::getSettings();
        $agendaTableReady = $tableReady;
        $agendaFilter = sanitize_key((string) ($_GET['filter'] ?? ''));
        if (!in_array($agendaFilter, AgendaTable::STATUSES, true)) {
            $agendaFilter = '';
        }
        $agendaSearch = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $agendaPage = max(1, absint($_GET['paged'] ?? 1));
        $agendaEditId = absint($_GET['edit'] ?? 0);
        $agendaEditEvent = $agendaEditId > 0 ? AgendaTable::get($agendaEditId) : null;
        $agendaList = $tableReady
            ? AgendaTable::list([
                'status' => $agendaFilter,
                'search' => $agendaSearch,
                'page' => $agendaPage,
                'per_page' => $agendaSettings['archive_per_page'],
            ])
            : ['events' => [], 'total' => 0];
        $agendaEvents = $agendaList['events'];
        $agendaTotal = $agendaList['total'];
        $agendaPages = max(1, (int) ceil($agendaTotal / $agendaSettings['archive_per_page']));
        $agendaCategories = $tableReady ? AgendaTable::categories() : [];
        $agendaArchiveUrl = AgendaRenderer::archiveUrl();
        $agendaSaveAction = AgendaAjax::AJAX_SAVE;
        $agendaDeleteAction = AgendaAjax::AJAX_DELETE;
        $agendaDeleteAllAction = AgendaAjax::AJAX_DELETE_ALL;
        $agendaDeleteAllWord = AgendaAjax::DELETE_ALL_CONFIRM_WORD;
        $agendaNonceAction = AdminAjax::NONCE_ACTION;
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/agenda.php';
        if (!file_exists($file)) {
            return;
        }
        require $file;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `archive_per_page` (1-100),
     * `show_past_in_archive` and `ics_enabled` (each `1` when
     * checked; the form always submits a hidden `0` first, so an
     * unchecked box arrives as `0`). Every value is cast to its
     * own type explicitly. Unknown keys are dropped. Event data is
     * never saved here - events go through AgendaAjax::saveEvent().
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change agenda settings.', 'traydigita-core'),
            ];
        }
        $perPage = absint($data['archive_per_page'] ?? self::getDefaults()['archive_per_page']);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = self::getDefaults()['archive_per_page'];
        }
        $settings = [
            'archive_per_page' => $perPage,
            'show_past_in_archive' => !empty($data['show_past_in_archive']),
            'ics_enabled' => !empty($data['ics_enabled']),
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Agenda settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. Calls ensureTable() (see the class docblock: this
     * is the first of the two call points), flushes the rewrite
     * rules once via the flushed flag, registers the rewrite rules
     * and query vars on `init` (or immediately when `init` already
     * fired), the ICS handler on `template_redirect`, the template
     * selector on `template_include`, Event JSON-LD on `wp_head`,
     * the shortcode, the widget, and the module AJAX endpoints.
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
        // Call point 1 of 2 for ensureTable(): the registry has no
        // activation hook, so the schema-upgrade check runs here,
        // the first time the enabled module boots. It is admin-only
        // and throttled to one check per hour behind a transient,
        // so repeat boots cost nothing; table creation itself is
        // lazy (ensureTableExists() on the first write).
        AgendaTable::ensureTable();
        if (!get_option(self::REWRITES_FLUSHED_OPTION, false)) {
            flush_rewrite_rules();
            update_option(self::REWRITES_FLUSHED_OPTION, 1, false);
        }
        if (did_action('init') > 0) {
            AgendaRenderer::registerRoutes();
        } else {
            add_action('init', [AgendaRenderer::class, 'registerRoutes']);
        }
        add_action('template_redirect', [AgendaRenderer::class, 'maybeServeIcs']);
        add_filter('template_include', [AgendaRenderer::class, 'templateInclude']);
        add_action('wp_head', [AgendaRenderer::class, 'renderJsonLd']);
        add_shortcode('traydigita_agenda', [self::class, 'shortcode']);
        add_action('widgets_init', [self::class, 'registerWidget']);
        AgendaAjax::register();
    }

    /**
     * Register the upcoming-events widget.
     *
     * @return void
     */
    public static function registerWidget(): void
    {
        register_widget(AgendaWidget::class);
    }

    /**
     * Shortcode `[traydigita_agenda]`: upcoming events list.
     *
     * Attributes: `count` (1-20, default 5), `category` (exact
     * match, default all), `show_cover` (`1` / `0`, default 1).
     * Renders nothing but the empty-state line when there are no
     * upcoming events, and nothing at all when the table is missing.
     *
     * @param array<string, mixed> $atts Shortcode attributes.
     * @return string
     */
    public static function shortcode(array $atts = []): string
    {
        if (!AgendaTable::isInstalled()) {
            return '';
        }
        $count = absint($atts['count'] ?? 5);
        if ($count < 1 || $count > 20) {
            $count = 5;
        }
        $category = sanitize_text_field((string) ($atts['category'] ?? ''));
        $showCover = !isset($atts['show_cover']) || (string) $atts['show_cover'] !== '0';
        $events = AgendaTable::upcoming($count, $category);
        return AgendaRenderer::renderListHtml($events, ['show_cover' => $showCover]);
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{archive_per_page: int, show_past_in_archive: bool, ics_enabled: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $perPage = absint($settings['archive_per_page'] ?? 10);
        return [
            'archive_per_page' => $perPage >= 1 && $perPage <= 100 ? $perPage : 10,
            'show_past_in_archive' => (bool) ($settings['show_past_in_archive'] ?? false),
            'ics_enabled' => (bool) ($settings['ics_enabled'] ?? true),
        ];
    }

    /**
     * Default settings.
     *
     * Ten events per archive page, past events hidden from the
     * archive unless the visitor filters for them, iCal export on.
     *
     * @return array{archive_per_page: int, show_past_in_archive: bool, ics_enabled: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'archive_per_page' => 10,
            'show_past_in_archive' => false,
            'ics_enabled' => true,
        ];
    }
}
