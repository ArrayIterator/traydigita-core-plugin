<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\MemberArea;

use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function add_query_arg;
use function add_shortcode;
use function array_map;
use function array_merge;
use function current_user_can;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function file_exists;
use function get_option;
use function get_permalink;
use function get_post;
use function home_url;
use function implode;
use function in_array;
use function is_array;
use function is_page;
use function is_string;
use function is_user_logged_in;
use function sanitize_key;
use function str_starts_with;
use function trim;
use function update_option;
use function wp_dequeue_style;
use function wp_die;
use function wp_dropdown_pages;
use function wp_login_url;
use function wp_nonce_field;
use function wp_safe_redirect;

/**
 * Member Area module.
 *
 * The frontend dashboard shell for every logged-in user: the
 * configured member page is the dashboard, with sections from
 * the member registry (Overview, Posts, My Contributions, plus
 * sections other modules register through the
 * `traydigita:core:member/sections` filter — Write from the
 * Frontend Editor, Bookmarks, Profile and Change Email).
 * Sections are manageable from the module settings
 * (enable/disable, reorder). A section renders only when the
 * user has the capability for it AND its feature is available.
 *
 * Split out of the Contributor Frontend Editor module
 * (MUST-4, 2026-11-24): the shell no longer dies with the
 * block editor. Disabling the Frontend Editor module hides
 * the Write section; disabling this module leaves the editor
 * working on its own (see FrontendEditor::shortcode()).
 *
 * Settings live in the single option `traydigita_member_area`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability
 * itself and re-sanitizes every field for its own type. Sites
 * upgraded from the old combined module get their settings
 * from the one-time split migration (see
 * ContributorSplitMigration).
 */
final class MemberAreaModule implements ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'member-area';

    /**
     * Option name for all module settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_member_area';

    /**
     * Shortcode rendering the frontend dashboard.
     *
     * Kept identical to the old combined module's shortcode so
     * existing member pages keep working.
     */
    public const string SHORTCODE = 'traydigita_contributor_editor';

    /**
     * Filter registering member-area sections.
     *
     * Receives the section list (see memberSections()) and
     * returns it, optionally with extra sections appended. A
     * section is `['id' => string, 'label' => string,
     * 'capability' => callable|null, 'available' => callable|null,
     * 'render' => callable|null]`: capability and availability
     * default to always true, render defaults to the built-in
     * renderer for the id (custom sections must provide one).
     */
    public const string SECTIONS_FILTER = 'traydigita:core:member/sections';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_member_area_save';

    /**
     * Query arg flagging a just-saved submission.
     */
    public const string SAVED_FLAG = 'traydigita_saved';

    /**
     * Query arg carrying a save error code.
     */
    public const string ERROR_FLAG = 'traydigita_error';

    /**
     * Query arg carrying an info notice code.
     */
    public const string NOTICE_FLAG = 'traydigita_notice';

    /**
     * Query arg set by the admin redirect (see
     * RoleAccessModule::maybeRedirectAdmin()).
     */
    public const string NO_DASHBOARD_FLAG = 'traydigita_no_dashboard';

    /**
     * Query arg carrying the post id to edit.
     */
    public const string EDIT_FLAG = 'traydigita_edit';

    /**
     * Query arg carrying the dashboard section.
     */
    public const string VIEW_FLAG = 'traydigita_view';

    /**
     * Query arg carrying the list status filter.
     */
    public const string STATUS_FLAG = 'traydigita_status';

    /**
     * Query arg carrying the list page number.
     */
    public const string PAGE_FLAG = 'traydigita_page';

    /**
     * Cached member sections (filter result) for this request.
     *
     * @var array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>|null
     */
    private static ?array $sectionsCache = null;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * Default category slug for the module list.
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
        return __('Member Area', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Frontend dashboard for every logged-in user: Overview, Posts and My Contributions in one shell, plus sections other modules plug in (Write, Bookmarks, Profile, Change Email). The configured member page becomes the dashboard; sections are manageable from the settings.',
            'traydigita-core'
        );
    }

    /**
     * Dashicon slug for the module grid card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'groups';
    }

    /**
     * Module version.
     *
     * 1.0.0 splits the dashboard shell out of the Contributor
     * Frontend Editor module (MUST-4).
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
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Member dashboard shell for every logged-in user.', 'traydigita-core'),
            __('Overview, Posts and My Contributions sections.', 'traydigita-core'),
            __('Sections other modules plug in through a filter.', 'traydigita-core'),
            __('Hide and reorder sections from the settings.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Split out of the Contributor Frontend Editor module.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin page slug for this module's settings page.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-member-area';
    }

    /**
     * Whether the module can run (it has no hard dependencies).
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * The dashboard works with no configuration at all, so the
     * module is always considered configured.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * The member page id is a published page id, or 0 for the
     * homepage. Disabled sections and the section order are
     * validated against the registered section ids, so ids from
     * modules that are currently disabled are dropped from the
     * returned list (never from the stored option).
     *
     * @return array{member_page_id: int, sections_disabled: array<int, string>, section_order: array<int, string>}
     */
    public static function getSettings(): array
    {
        ContributorSplitMigration::migrate();
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $pageId = absint($settings['member_page_id'] ?? 0);
        if ($pageId > 0) {
            $page = get_post($pageId);
            if ($page === null || $page->post_type !== 'page' || $page->post_status !== 'publish') {
                $pageId = 0;
            }
        }
        $sectionIds = array_map(
            static fn (array $section): string => (string) $section['id'],
            self::memberSections()
        );
        $disabled = [];
        $rawDisabled = $settings['sections_disabled'] ?? [];
        if (is_array($rawDisabled)) {
            foreach ($rawDisabled as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $id = sanitize_key($id);
                if (in_array($id, $sectionIds, true) && !in_array($id, $disabled, true)) {
                    $disabled[] = $id;
                }
            }
        }
        $order = [];
        $rawOrder = $settings['section_order'] ?? [];
        if (is_array($rawOrder)) {
            foreach ($rawOrder as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $id = sanitize_key($id);
                if (in_array($id, $sectionIds, true) && !in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }
        return [
            'member_page_id' => $pageId,
            'sections_disabled' => $disabled,
            'section_order' => $order,
        ];
    }

    /**
     * Default settings: the member area is the homepage and no
     * section is hidden or reordered.
     *
     * @return array{member_page_id: int, sections_disabled: array<int, string>, section_order: array<int, string>}
     */
    public static function getDefaults(): array
    {
        return [
            'member_page_id' => 0,
            'sections_disabled' => [],
            'section_order' => [],
        ];
    }

    /**
     * Member-area sections, filterable by other code.
     *
     * Each section is `['id' => string, 'label' => string,
     * 'capability' => callable|null, 'available' =>
     * callable|null, 'render' => callable|null]`. A null
     * capability or availability means always true; a null
     * render means the built-in renderer for the id (custom
     * sections must provide one). The list is cached per
     * request; the filter runs once.
     *
     * This module registers the three built-in sections only.
     * Write, Bookmarks, Profile and Change Email are registered
     * by their own modules through this filter, so disabling
     * one of them removes its section without any change here.
     *
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     */
    public static function memberSections(): array
    {
        if (self::$sectionsCache !== null) {
            return self::$sectionsCache;
        }
        $sections = [
            [
                'id' => 'overview',
                'label' => __('Overview', 'traydigita-core'),
                'capability' => null,
                'available' => null,
                'render' => null,
            ],
            [
                'id' => 'posts',
                'label' => __('Posts', 'traydigita-core'),
                'capability' => null,
                'available' => null,
                'render' => null,
            ],
            [
                'id' => 'contributions',
                'label' => __('My Contributions', 'traydigita-core'),
                'capability' => null,
                'available' => null,
                'render' => null,
            ],
        ];
        $filtered = apply_filters(self::SECTIONS_FILTER, $sections);
        if (!is_array($filtered)) {
            return self::$sectionsCache = $sections;
        }
        $clean = [];
        $seen = [];
        foreach ($filtered as $section) {
            if (!is_array($section) || !isset($section['id']) || !is_string($section['id'])) {
                continue;
            }
            $id = sanitize_key($section['id']);
            if ($id === '' || in_array($id, $seen, true)) {
                continue;
            }
            $seen[] = $id;
            $clean[] = [
                'id' => $id,
                'label' => isset($section['label']) && is_string($section['label']) && $section['label'] !== ''
                    ? $section['label']
                    : $id,
                'capability' => isset($section['capability']) && is_callable($section['capability'])
                    ? $section['capability']
                    : null,
                'available' => isset($section['available']) && is_callable($section['available'])
                    ? $section['available']
                    : null,
                'render' => isset($section['render']) && is_callable($section['render'])
                    ? $section['render']
                    : null,
            ];
        }
        return self::$sectionsCache = $clean === [] ? $sections : $clean;
    }

    /**
     * Enabled sections in display order.
     *
     * The stored order wins; sections missing from it (new
     * sections, or a cleared setting) append in registration
     * order. Disabled sections are dropped.
     *
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     */
    public static function enabledSections(): array
    {
        $settings = self::getSettings();
        $disabled = $settings['sections_disabled'];
        $order = $settings['section_order'];
        $byId = [];
        foreach (self::memberSections() as $section) {
            $byId[(string) $section['id']] = $section;
        }
        $ordered = [];
        foreach ($order as $id) {
            if (isset($byId[$id]) && !in_array($id, $disabled, true)) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        foreach ($byId as $id => $section) {
            if (!in_array($id, $disabled, true)) {
                $ordered[] = $section;
            }
        }
        return $ordered;
    }

    /**
     * Whether a section may render for the current user.
     *
     * BOTH conditions must hold: the capability callback (when
     * set) must pass, AND the availability callback (when set)
     * must pass. A section failing either is hidden entirely
     * — never shown greyed-out — and its URL redirects away
     * (see MemberDashboard::resolveView()).
     *
     * @param array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null} $section Section definition.
     * @return bool
     */
    public static function sectionVisible(array $section): bool
    {
        $capability = $section['capability'] ?? null;
        if ($capability !== null) {
            try {
                if (!$capability()) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }
        $available = $section['available'] ?? null;
        if ($available !== null) {
            try {
                if (!$available()) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the current request is the configured member page.
     *
     * The dashboard template and its access gate only apply when
     * a member page is configured (a real published page). With
     * no member page the shortcode still renders inline wherever
     * it is placed, and the homepage is never gated — gating it
     * would lock the whole front page for guests.
     *
     * @return bool
     */
    public static function isMemberPage(): bool
    {
        $pageId = self::getSettings()['member_page_id'];
        if ($pageId <= 0) {
            return false;
        }
        return is_page($pageId);
    }

    /**
     * URL of the frontend member area.
     *
     * The configured member page, or the homepage when none is
     * configured (or the configured page is gone).
     *
     * @return string
     */
    public static function memberAreaUrl(): string
    {
        $pageId = self::getSettings()['member_page_id'];
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        $home = home_url('/');
        return is_string($home) ? $home : '/';
    }

    /**
     * URL of the page the shortcode is rendering on.
     *
     * Used for the login redirect and for building dashboard
     * view URLs. The configured member page wins: during an
     * admin-post.php request there is no queried object, so
     * falling back to it there would send every post-save
     * redirect to the homepage instead of the member area.
     * Without a member page the queried object (the page the
     * shortcode renders on) is used, and only then the
     * homepage.
     *
     * @return string
     */
    public static function currentPageUrl(): string
    {
        $pageId = self::getSettings()['member_page_id'];
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        $pageId = get_queried_object_id();
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        $home = home_url('/');
        return is_string($home) ? $home : '/';
    }

    /**
     * Dashboard URL for a section, optionally with extra args.
     *
     * @param string $view Section id (overview, posts, contributions, write, bookmark, profile, change-email).
     * @param array<string, string|int> $extra Extra query args.
     * @return string
     */
    public static function viewUrl(string $view, array $extra = []): string
    {
        return add_query_arg(array_merge([self::VIEW_FLAG => $view], $extra), self::currentPageUrl());
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * The form uses the shell's generic AJAX save endpoint (see
     * data-traydigita-module-form).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        $settings = self::getSettings();
        $shortcode = '[' . self::SHORTCODE . ']';
        ?>
        <div class="traydigita-module-settings member-area-settings">
            <?php
            SettingsControls::intro(
                __('The member dashboard for every logged-in user: Overview, Posts and My Contributions in one shell.', 'traydigita-core'),
                __('Write, Bookmarks, Profile and Change Email plug in as sections from their own modules — disabling one of them removes its section from the dashboard without touching anything here.', 'traydigita-core')
            );
            ?>
            <p class="member-area-settings-intro">
                <?php esc_html_e('Place this shortcode on the member page to show the dashboard:', 'traydigita-core'); ?>
                <code><?php echo esc_html($shortcode); ?></code>
            </p>

            <form class="member-area-settings-form" data-traydigita-module-form data-traydigita-module="member-area" data-module="member-area" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="member-area-field">
                    <label for="member-area-member-page"><?php esc_html_e('Member area page', 'traydigita-core'); ?></label><br>
                    <?php
                    wp_dropdown_pages([
                        'name' => 'member_page_id',
                        'id' => 'member-area-member-page',
                        'selected' => $settings['member_page_id'],
                        'show_option_none' => __('Homepage', 'traydigita-core'),
                        'option_none_value' => '0',
                    ]);
                    ?>
                    <span class="member-area-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Put the dashboard shortcode on this page.', 'traydigita-core'),
                            __('The page becomes the member dashboard for every logged-in user: guests go to the login page, and sections inside are shown only when the user has the capability and the feature is available. When no page is picked, the homepage is used and no dashboard template is applied.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <?php
                $sectionOptions = [];
                foreach (self::memberSections() as $section) {
                    $sectionOptions[(string) $section['id']] = (string) $section['label'];
                }
                SettingsControls::sectionOpen(
                    'member-area-sections',
                    __('Dashboard sections', 'traydigita-core'),
                    __('Hide and reorder the dashboard sections.', 'traydigita-core'),
                    __('Sections are registered through the traydigita:core:member/sections filter and managed here: tick a section to hide it from the dashboard, and list section ids in order to reorder them. A hidden section is never reachable by URL either — the dashboard redirects to Overview. Sections a user may not use (for example Write for non-contributors) are already hidden by capability and need no ticking here.', 'traydigita-core')
                );
                SettingsControls::checkboxGroup([
                    'id' => 'member-area-sections-disabled',
                    'name' => 'sections_disabled[]',
                    'label' => __('Hidden sections', 'traydigita-core'),
                    'options' => $sectionOptions,
                    'checked' => $settings['sections_disabled'],
                    'help' => __('Tick a section to hide it from the dashboard.', 'traydigita-core'),
                    'help_long' => __('A hidden section disappears from the sidebar and its URL redirects to Overview.', 'traydigita-core'),
                ]);
                SettingsControls::input([
                    'id' => 'member-area-section-order',
                    'name' => 'section_order',
                    'label' => __('Section order', 'traydigita-core'),
                    'value' => implode(',', $settings['section_order']),
                    'help' => __('Comma-separated section ids, in display order.', 'traydigita-core'),
                    'help_long' => __('For example: overview,posts,contributions,write,bookmark,profile,change-email. Sections left out keep their registered order after the listed ones. Unknown ids are ignored.', 'traydigita-core'),
                    'mono' => true,
                ]);
                SettingsControls::sectionClose();
                ?>

                <p class="member-area-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Save the module settings from already deep-sanitized data.
     *
     * Re-sanitizes every field for its own type: the member page
     * must be a published page or it falls back to 0; hidden
     * sections and the order are intersected with the registered
     * section ids.
     *
     * @param array<string, mixed> $data Submitted data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change member area settings.', 'traydigita-core'),
            ];
        }
        $pageId = absint($data['member_page_id'] ?? 0);
        if ($pageId > 0) {
            $page = get_post($pageId);
            if ($page === null || $page->post_type !== 'page' || $page->post_status !== 'publish') {
                $pageId = 0;
            }
        }
        $sectionIds = array_map(
            static fn (array $section): string => (string) $section['id'],
            self::memberSections()
        );
        $disabled = [];
        $rawDisabled = $data['sections_disabled'] ?? [];
        if (is_array($rawDisabled)) {
            foreach ($rawDisabled as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $id = sanitize_key($id);
                if (in_array($id, $sectionIds, true) && !in_array($id, $disabled, true)) {
                    $disabled[] = $id;
                }
            }
        }
        $order = [];
        $rawOrder = $data['section_order'] ?? '';
        if (is_string($rawOrder) && $rawOrder !== '') {
            foreach (explode(',', $rawOrder) as $id) {
                $id = sanitize_key(trim($id));
                if ($id !== '' && in_array($id, $sectionIds, true) && !in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }
        update_option(self::OPTION, [
            'member_page_id' => $pageId,
            'sections_disabled' => $disabled,
            'section_order' => $order,
        ], false);
        return [
            'success' => true,
            'message' => __('Member area settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The shortcode and the dashboard template are
     * registered here; the admin bar is hidden on the member
     * page (the dashboard has its own top bar).
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_filter('show_admin_bar', [self::class, 'filterShowAdminBar']);
        add_action('wp_enqueue_scripts', [self::class, 'maybeStripVariantStyles'], 100);
        add_shortcode(self::SHORTCODE, [MemberDashboard::class, 'shortcode']);
        add_filter('template_include', [self::class, 'templateInclude']);
    }

    /**
     * Serve the contributor dashboard template for the member page.
     *
     * Hooked on `template_include`. The gate runs first: guests
     * go to wp-login (see dashboardGateRedirect()). Every
     * logged-in user may use the dashboard shell — sections
     * inside it are gated by capability and feature
     * availability, and only the Write section is
     * contributor-only (non-contributors hitting it are
     * redirected to wp-admin by the shortcode). The template
     * itself is a standalone shell: no theme masthead,
     * sidebars or bottom tab bar, and no theme style variant.
     *
     * @param string $template Template WordPress selected.
     * @return string Template path to load.
     */
    public static function templateInclude(string $template): string
    {
        if (!self::isMemberPage()) {
            return $template;
        }
        $redirect = self::dashboardGateRedirect();
        if ($redirect !== '') {
            wp_safe_redirect($redirect);
            exit;
        }
        $file = Core::pluginDir() . '/templates/contributor/dashboard.php';
        return file_exists($file) ? $file : $template;
    }

    /**
     * Where a member-page visitor who may not use the dashboard
     * is sent, or an empty string when they may stay.
     *
     * Guests go to wp-login (back to the member area after
     * login). Every logged-in user may use the dashboard shell:
     * sections inside it are gated by capability and feature
     * availability, so there is nothing to redirect a logged-in
     * user away from at the gate. The contributor-only rule
     * lives on the Write section (see
     * MemberDashboard::resolveView()).
     *
     * @return string Redirect URL, or '' when the visitor may use the dashboard.
     */
    public static function dashboardGateRedirect(): string
    {
        if (!is_user_logged_in()) {
            return wp_login_url(self::memberAreaUrl());
        }
        return '';
    }

    /**
     * Hide the admin bar on the member page.
     *
     * The member page hides the bar for every dashboard user —
     * the dashboard has its own top bar. Frontend-only users
     * lose the bar everywhere through
     * RoleAccessModule::filterShowAdminBar(), hooked on the
     * same filter.
     *
     * @param bool $show Whether the admin bar would show.
     * @return bool
     */
    public static function filterShowAdminBar(bool $show): bool
    {
        if (self::isMemberPage()) {
            return false;
        }
        return $show;
    }

    /**
     * Drop the theme style variant on the member page.
     *
     * The dashboard is theme-independent: it uses its own
     * visual language and ignores the active style variant.
     * The base app stylesheet stays (it carries the dashboard
     * styles and the Tailwind preflight), only the variant
     * stylesheet is dequeued.
     *
     * @return void
     */
    public static function maybeStripVariantStyles(): void
    {
        if (!self::isMemberPage()) {
            return;
        }
        global $wp_styles;
        if (!isset($wp_styles) || !is_object($wp_styles)) {
            return;
        }
        $registered = $wp_styles->registered ?? [];
        if (!is_array($registered)) {
            return;
        }
        foreach (array_keys($registered) as $handle) {
            if (!is_string($handle)) {
                continue;
            }
            if (str_starts_with($handle, 'traydigita-theme-style-')) {
                wp_dequeue_style($handle);
            }
        }
    }
}
