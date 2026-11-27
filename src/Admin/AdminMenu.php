<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Modules\Email\EmailModule;
use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilderModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use function __;
use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function admin_url;
use function apply_filters;
use function current_user_can;
use function did_action;
use function esc_html__;
use function file_exists;
use function in_array;
use function is_admin;
use function is_array;
use function is_string;
use function method_exists;
use function sanitize_key;
use function usort;
use function wp_die;
use function wp_safe_redirect;
use function wp_unslash;

/**
 * TrayDigita admin menu and page shell.
 *
 * Menu structure (admin rebuild, Part A2; curated submenu, Round 3):
 *
 * - Parent "TrayDigita" (position 61) whose first page is the
 *   Modules list.
 * - Submenu "Modules": the JavaScript module list
 *   (templates/admin/modules-page.php).
 * - Submenu "Settings": the General/Settings hub page
 *   (templates/admin/hub-page.php), which lists the settings of
 *   the ACTIVE modules - each entry links to that module's
 *   settings page, only for modules that actually have settings.
 *   The hub is the way into module settings.
 * - Submenu "Setup": the first-run setup checklist
 *   (templates/admin/setup-page.php, via SetupPage), registered
 *   directly - not module-driven - right after the Settings hub.
 * - Submenu "Site Health": a menu link straight to WordPress
 *   core Site Health (site-health.php) - the theme
 *   has no custom health page; its environment checks are added
 *   to core Site Health through the `site_status_tests` filter
 *   (src/Admin/SiteHealth.php).
 * - Data-driven submenu entries: a module that opts in by
 *   declaring a static showInSubmenu() method returning true
 *   gets one submenu entry linking to its open link
 *   (ModuleRegistry::openTarget() - its own settings page by
 *   default, or a declared openLink() target such as another
 *   module's page or a WordPress admin file). The default is
 *   off for every module. Entries are ordered by their declared
 *   submenuPosition() (ModuleRegistry::submenuPosition(),
 *   default 0; Site Health sits at position 0 by definition):
 *   entries with a position below 0 register before Site
 *   Health, entries at 0 or above after it, each group sorted
 *   by position ascending, registry order breaking ties. Today
 *   the Installer (-10) and Integrations (-5) entries sit before
 *   Site Health and SEO (0) after it. There is no Customizer
 *   entry: the Customizer stays reachable through WordPress
 *   core (Appearance > Customize).
 *
 * Only the curated submenus above exist, in this order:
 * Modules, Settings, Setup, Plugin Suggestions, Integrations,
 * Site Health, SEO.
 * Module settings pages are NOT menu entries by default: every
 * module page is registered with a null parent
 * (add_submenu_page(null, ...)), so the menu stays short while
 * the deep links (admin.php?page=traydigita-<module>) keep
 * working with the capability check. The one exception is a
 * module that opts into the submenu AND opens its own settings
 * page: it is additionally registered as a real submenu entry
 * under the TrayDigita parent, so WordPress highlights it (and
 * the parent) while its page is open. A module that opts in
 * with a different open link is registered with the no-callback
 * link trick instead (same as Site Health): the open target
 * itself is the submenu slug, so the entry is a plain link and
 * the target page renders through its own registration.
 * The page hook suffixes are recorded, so the `admin` Vite entry
 * still loads on those pages.
 *
 * Every page renders through templates/admin/shell.php, which
 * carries the header with a back link, the optional tab bar, the
 * content area and the toast area. Settings pages use the standard
 * shell tab mechanism: a module declares its tabs through an
 * optional static tabs() method, extensions may add tabs through
 * the `traydigita:core:admin:module_tabs` filter, and a module
 * without declared tabs gets no tab bar at all - the tabbed shell
 * only renders when a module declares at least two tabs, so no
 * page ever shows a lonely single tab.
 *
 * The admin palette (AdminPalette) is printed as CSS variables in
 * admin_head on TrayDigita pages only.
 *
 * AJAX wiring lives in AdminAjax; its endpoints are registered
 * here, in init(), because this is the single place that owns
 * admin wiring. Module booting happens in init() too, but before
 * the is_admin() gate: enabled modules own frontend/login/mail
 * hooks, so ModuleRegistry::bootEnabled() must run on every
 * request.
 *
 * The `admin` bundle entry is enqueued only on TrayDigita pages:
 * registerMenus() records every page hook suffix and
 * enqueueAdminAssets() checks it. AssetLoader::enqueueAdminEntry()
 * does the actual enqueue.
 *
 * The Settings submenu uses the known WordPress trick of passing
 * 'customize.php' as the submenu slug: a slug that names a .php
 * file links straight to that admin file instead of rendering a
 * callback page. The Site Health entry and any opt-in module
 * entry whose open link is not its own settings page use the
 * same trick; there is no Customizer entry in this menu.
 *
 * The shell top bar (templates/admin/shell.php) lists the same
 * sections through navItems() - curated pages plus the opt-in
 * entries, in submenu order - so the dropdown can never go
 * stale. TrayDigita pages run full-screen: the WordPress
 * sidebar, admin bar and footer are hidden, scoped to these
 * pages by isTraydigitaPage().
 */
final class AdminMenu
{
    /**
     * Parent menu slug (also the Modules page slug).
     */
    public const string MENU_SLUG = 'traydigita';

    /**
     * Settings hub page slug.
     */
    public const string HUB_SLUG = 'traydigita-settings';

    /**
     * Menu position: right after Appearance (60).
     */
    public const int MENU_POSITION = 61;

    /**
     * Capability required for every TrayDigita admin page.
     */
    public const string CAPABILITY = 'manage_options';

    /**
     * Hook suffixes of the registered TrayDigita admin pages, as
     * returned by add_menu_page()/add_submenu_page(). Used to scope
     * the `admin` Vite entry to these pages only.
     *
     * @var array<int, string>
     */
    private array $pageHooks = [];

    /**
     * @param Core $core
     */
    public function __construct(public readonly Core $core)
    {
    }

    /**
     * Register the admin menu, the admin AJAX endpoints, and boot
     * the enabled modules.
     *
     * Module booting is NOT admin-gated: enabled modules register
     * frontend/login/mail hooks (maintenance gate, login styles,
     * PHPMailer, wp_mail wrapping, security headers, cookie banner),
     * so ModuleRegistry::bootEnabled() runs on every request, from
     * after_setup_theme (when Core initialises its dependencies).
     * Only the menu, the pages and the AJAX registration are
     * admin-only.
     *
     * Booting is deferred to after_setup_theme on purpose:
     * booting translates (module names, settings defaults), and
     * WordPress 6.7+ refuses translation loading before
     * after_setup_theme - a translated call any earlier raises
     * the `_load_textdomain_just_in_time was called incorrectly`
     * notice on every request, even when the languages path
     * itself is correct. Priority 5 runs the boot before
     * ThemeSupport::register (priority 10) and before the
     * theme's own after_setup_theme callbacks.
     */
    public function init(): void
    {
        if (did_action('after_setup_theme')) {
            ModuleRegistry::bootEnabled();
        } else {
            add_action('after_setup_theme', [ModuleRegistry::class, 'bootEnabled'], 5);
        }
        if (!is_admin()) {
            return;
        }
        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_init', [$this, 'redirectLegacyContributorSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_head', [$this, 'printAdminPalette']);
        add_filter('admin_body_class', [$this, 'addFullscreenBodyClass']);
        AdminAjax::register();
        SetupAjax::register();
        AdminPointerTour::register();
        SiteHealth::register();
    }

    /**
     * Redirect the retired contributor settings slug.
     *
     * The Contributor Frontend Editor module split into six
     * modules (MUST-4, 2026-11-24) and its settings page
     * `admin.php?page=traydigita-contributor-frontend` no
     * longer exists. A bookmarked or linked visit to it is
     * sent to the Member Area settings page instead (301, so
     * bookmarks update). Only administrators can reach an
     * admin page at all, but the capability is checked
     * explicitly before redirecting.
     *
     * @return void
     */
    public function redirectLegacyContributorSettings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page = isset($_GET['page']) && is_string($_GET['page'])
            ? sanitize_key(wp_unslash($_GET['page']))
            : '';
        if ($page !== 'traydigita-contributor-frontend') {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=' . MemberAreaModule::settingsPageSlug()), 301);
        exit;
    }

    /**
     * Enqueue the `admin` bundle entry on TrayDigita pages only.
     *
     * The hook suffix arrives from admin_enqueue_scripts; the menu
     * registration (admin_menu) runs before it, so $pageHooks is
     * already populated. Other admin screens never load the entry.
     * Core has no Vite: the copied admin bundle is enqueued
     * through AssetLoader::enqueueAdminEntry().
     *
     * @param string $hookSuffix Current admin page hook suffix.
     * @return void
     */
    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if (!in_array($hookSuffix, $this->pageHooks, true)) {
            return;
        }
        Core::assets()->enqueueAdminEntry();
        $page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
        if ($page === EmailModule::settingsPageSlug()
            || $page === FormBuilderModule::settingsPageSlug()
        ) {
            Core::assets()->enqueueEmailBuilderAssets();
        }
    }

    /**
     * Whether the current admin screen is a TrayDigita page.
     *
     * The `page` query var names the parent slug, the hub slug,
     * the setup slug, or a module's settings slug. Anything else
     * - including core Site Health, which the submenu links to -
     * is not a TrayDigita page and keeps the WordPress chrome.
     */
    private static function isTraydigitaPage(): bool
    {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
        if ($page === '') {
            return false;
        }
        return $page === self::MENU_SLUG
            || $page === self::HUB_SLUG
            || $page === SetupPage::SLUG
            || ModuleRegistry::getBySlug($page) !== null;
    }

    /**
     * Mark TrayDigita pages for full-screen app mode.
     *
     * The class is the CSS hook that hides the WordPress sidebar,
     * admin bar and footer and resets their layout offsets
     * (resources/css/admin.css). Other admin screens are returned
     * untouched, so the Dashboard keeps its normal chrome.
     *
     * @param string $classes Current body classes.
     * @return string
     */
    public function addFullscreenBodyClass(string $classes): string
    {
        if (!self::isTraydigitaPage()) {
            return $classes;
        }
        return trim($classes . ' traydigita-fullscreen');
    }

    /**
     * Print the admin palette as CSS variables on TrayDigita pages.
     *
     * Runs on admin_head; other admin screens get no TrayDigita
     * variables.
     *
     * @return void
     */
    public function printAdminPalette(): void
    {
        if (!self::isTraydigitaPage()) {
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminPalette::inlineStyle() only prints known palette hex colours.
        echo AdminPalette::inlineStyle();
    }

    /**
     * Register the parent menu, the curated submenus, the
     * data-driven opt-in module entries, and the hidden module
     * pages.
     *
     * Curated submenu order: Modules (the grid), Settings hub,
     * Setup, then the opt-in module entries with a submenu
     * position below 0 (Installer, Integrations), Site Health,
     * then the opt-in module entries at position 0 or above
     * (SEO today). Nothing else is ever added here: module pages
     * are registered with a null parent
     * (add_submenu_page(null, ...)): WordPress keeps them
     * reachable at their deep link with the capability check, but
     * never lists them in the menu - so the menu never shows ~46
     * module entries. (Register-then-remove_submenu_page() does
     * NOT work: WordPress then refuses access with 403, because
     * the page is no longer registered as reachable.) The one
     * exception is an opt-in module whose open link is its own
     * settings page: it is registered ONLY as a real submenu
     * entry, never also as a hidden page - the same slug +
     * callback registered twice would give WordPress an identical
     * hook suffix and the page callback would run twice, rendering
     * the whole page twice. Its hook suffix is recorded like the
     * others, so the `admin` Vite entry still loads on it.
     *
     * @return void
     */
    public function registerMenus(): void
    {
        $hook = add_menu_page(
            __('TrayDigita', 'traydigita-core'),
            __('TrayDigita', 'traydigita-core'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderModulesPage'],
            \TrayDigita\WP\Plugin\Core\Brand::menuIconUrl(),
            self::MENU_POSITION
        );
        if (is_string($hook) && $hook !== '') {
            $this->pageHooks[] = $hook;
        }
        $hook = add_submenu_page(
            self::MENU_SLUG,
            __('Modules', 'traydigita-core'),
            __('Modules', 'traydigita-core'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderModulesPage']
        );
        if (is_string($hook) && $hook !== '') {
            $this->pageHooks[] = $hook;
        }
        $hook = add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'traydigita-core'),
            __('Settings', 'traydigita-core'),
            self::CAPABILITY,
            self::HUB_SLUG,
            [$this, 'renderHubPage']
        );
        if (is_string($hook) && $hook !== '') {
            $this->pageHooks[] = $hook;
        }
        // Setup is registered directly, not module-driven: the
        // first-run checklist lives on its own page (SetupPage)
        // right after the Settings hub.
        $hook = add_submenu_page(
            self::MENU_SLUG,
            __('Setup', 'traydigita-core'),
            __('Setup', 'traydigita-core'),
            self::CAPABILITY,
            SetupPage::SLUG,
            [$this, 'renderSetupPage']
        );
        if (is_string($hook) && $hook !== '') {
            $this->pageHooks[] = $hook;
        }
        // Data-driven submenu entries: only modules that opt in
        // (ModuleRegistry::showInSubmenu(), default false) appear
        // here, ordered by their declared submenu position
        // (ModuleRegistry::submenuPosition(), default 0):
        // position ascending, registry order breaking ties.
        // Entries below 0 register before Site Health; entries at
        // 0 or above register after it. Site Health sits at
        // position 0 by definition.
        $optInEntries = self::sortedOptInEntries();
        foreach ($optInEntries as $entry) {
            if ($entry['position'] >= 0) {
                continue;
            }
            $this->registerOptInSubmenuEntry($entry['class']);
        }
        // Site Health is a plain menu link to WordPress core
        // Site Health (site-health.php), using the
        // no-callback link trick: with no callback, WordPress
        // renders the slug itself as the href instead of routing
        // through admin.php?page=... - so the entry opens core
        // Site Health, where the theme's checks (SiteHealth, via
        // the `site_status_tests` filter) appear. The theme
        // has no custom health page. This entry is not a
        // TrayDigita page, so its hook is not recorded and the
        // `admin` Vite entry never loads on it. The opt-in
        // module entries use the same trick when their open link
        // is not their own settings page.
        add_submenu_page(
            self::MENU_SLUG,
            __('Site Health', 'traydigita-core'),
            __('Site Health', 'traydigita-core'),
            self::CAPABILITY,
            'site-health.php'
        );
        foreach ($optInEntries as $entry) {
            if ($entry['position'] < 0) {
                continue;
            }
            $this->registerOptInSubmenuEntry($entry['class']);
        }
        foreach (ModuleRegistry::all() as $moduleClass) {
            // Skip modules already registered as a real submenu
            // entry above: they open their own settings page, so
            // their hook suffix is already that page's hook.
            // Registering the same slug + callback a second time
            // here would give WordPress an identical hook suffix,
            // and the page callback would run twice (the whole
            // settings page rendered twice). A module that opted
            // into the submenu with a different open link keeps
            // its hidden registration: its submenu entry is a
            // plain link (no callback), so the deep link still
            // needs this registration to render.
            $opensOwnPage = ModuleRegistry::openTarget($moduleClass) === 'admin.php?page=' . $moduleClass::settingsPageSlug();
            if (ModuleRegistry::showInSubmenu($moduleClass) && $opensOwnPage) {
                continue;
            }
            // Null parent = hidden deep link: reachable via
            // admin.php?page=<slug> but not shown as a submenu (the hub
            // is the way in). WordPress still finds the page in $submenu
            // for its capability check, so the URL keeps working.
            $hook = add_submenu_page(
                // @phpstan-ignore-next-line Null parent is the core-supported hidden-page idiom.
                null,
                $moduleClass::name(),
                $moduleClass::name(),
                self::CAPABILITY,
                $moduleClass::settingsPageSlug(),
                function () use ($moduleClass): void {
                    $this->renderModulePage($moduleClass);
                }
            );
            if (is_string($hook) && $hook !== '') {
                $this->pageHooks[] = $hook;
                // Hidden deep-link pages have no menu parent, so
                // get_admin_page_title() cannot find their title in
                // $submenu and leaves the global $title null: on
                // PHP 8.3 admin-header.php then calls
                // strip_tags(null) (deprecated) and the browser tab
                // title comes out empty. The load hook runs before
                // admin-header, so setting the global here fixes
                // both. Pages with a real submenu parent resolve
                // their title through $submenu and need no help.
                add_action('load-' . $hook, static function () use ($moduleClass): void {
                    $GLOBALS['title'] = $moduleClass::name();
                });
            }
        }
    }

    /**
     * Opt-in modules, ordered the way the submenu registers them:
     * position ascending, registry order breaking ties.
     *
     * @return array<int, array{class: class-string<ModuleInterface>, position: int, index: int}>
     */
    private static function sortedOptInEntries(): array
    {
        $entries = [];
        foreach (ModuleRegistry::all() as $index => $moduleClass) {
            if (!ModuleRegistry::showInSubmenu($moduleClass)) {
                continue;
            }
            $entries[] = [
                'class' => $moduleClass,
                'position' => ModuleRegistry::submenuPosition($moduleClass),
                'index' => $index,
            ];
        }
        usort(
            $entries,
            static function (array $first, array $second): int {
                if ($first['position'] !== $second['position']) {
                    return $first['position'] <=> $second['position'];
                }
                return $first['index'] <=> $second['index'];
            }
        );
        return $entries;
    }

    /**
     * Sections for the shell top-bar dropdown, in submenu order.
     *
     * Built from the same source as registerMenus() - the curated
     * pages, the opt-in modules either side of Site Health - so
     * the dropdown lists exactly what the menu has. A module's
     * id is its settings slug, which is also what
     * currentNavId() returns for its page, so the active entry
     * matches by construction.
     *
     * @return array<int, array{id: string, label: string, url: string}>
     */
    public static function navItems(): array
    {
        $items = [
            [
                'id' => self::MENU_SLUG,
                'label' => __('Modules', 'traydigita-core'),
                'url' => admin_url('admin.php?page=' . self::MENU_SLUG),
            ],
            [
                'id' => self::HUB_SLUG,
                'label' => __('Settings', 'traydigita-core'),
                'url' => self::hubUrl(),
            ],
            [
                'id' => SetupPage::SLUG,
                'label' => __('Setup', 'traydigita-core'),
                'url' => admin_url('admin.php?page=' . SetupPage::SLUG),
            ],
        ];
        $optIn = self::sortedOptInEntries();
        foreach ($optIn as $entry) {
            if ($entry['position'] >= 0) {
                continue;
            }
            $items[] = self::optInNavItem($entry['class']);
        }
        $items[] = [
            'id' => 'site-health',
            'label' => __('Site Health', 'traydigita-core'),
            'url' => admin_url('site-health.php'),
        ];
        foreach ($optIn as $entry) {
            if ($entry['position'] < 0) {
                continue;
            }
            $items[] = self::optInNavItem($entry['class']);
        }
        return $items;
    }

    /**
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return array{id: string, label: string, url: string}
     */
    private static function optInNavItem(string $moduleClass): array
    {
        return [
            'id' => $moduleClass::settingsPageSlug(),
            'label' => $moduleClass::name(),
            'url' => self::moduleUrl($moduleClass),
        ];
    }

    /**
     * Nav id of the page being rendered. A module page that never
     * opted into the submenu returns its own settings slug, which
     * matches no dropdown entry, so nothing is marked active.
     *
     * @param class-string<ModuleInterface>|null $moduleClass Module class, or null for the grid / hub.
     * @return string
     */
    public static function currentNavId(?string $moduleClass, bool $isHub, bool $isSetup): string
    {
        if ($isHub) {
            return self::HUB_SLUG;
        }
        if ($isSetup) {
            return SetupPage::SLUG;
        }
        if ($moduleClass !== null) {
            return $moduleClass::settingsPageSlug();
        }
        return self::MENU_SLUG;
    }

    /**
     * Register one data-driven opt-in submenu entry.
     *
     * The entry links to the module's open link
     * (ModuleRegistry::openTarget()): its own settings page by
     * default, or a declared openLink() target. A module that
     * opens its own settings page is registered as a real submenu
     * entry (same callback as its hidden page), so WordPress
     * highlights it while the page is open; any other target uses
     * the no-callback link trick, like Site Health - the target
     * itself is the slug, so the entry is a plain link and the
     * target renders through its own registration.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return void
     */
    private function registerOptInSubmenuEntry(string $moduleClass): void
    {
        $openTarget = ModuleRegistry::openTarget($moduleClass);
        if ($openTarget === 'admin.php?page=' . $moduleClass::settingsPageSlug()) {
            $hook = add_submenu_page(
                self::MENU_SLUG,
                $moduleClass::name(),
                $moduleClass::name(),
                self::CAPABILITY,
                $moduleClass::settingsPageSlug(),
                function () use ($moduleClass): void {
                    $this->renderModulePage($moduleClass);
                }
            );
            if (is_string($hook) && $hook !== '') {
                $this->pageHooks[] = $hook;
            }
            return;
        }
        add_submenu_page(
            self::MENU_SLUG,
            $moduleClass::name(),
            $moduleClass::name(),
            self::CAPABILITY,
            $openTarget
        );
    }

    /**
     * Render the Modules grid page.
     *
     * @return void
     */
    public function renderModulesPage(): void
    {
        $this->renderPage(null, __('Modules', 'traydigita-core'));
    }

    /**
     * Render the General/Settings hub page.
     *
     * The hub lists the settings of the active modules only. Core
     * modules link to the screen that owns their settings; the
     * push card links to the push plugin page when it is installed.
     *
     * @return void
     */
    public function renderHubPage(): void
    {
        $this->renderPage(null, __('Settings', 'traydigita-core'), true);
    }

    /**
     * Render the Setup checklist page.
     *
     * @return void
     */
    public function renderSetupPage(): void
    {
        $this->renderPage(null, __('Setup', 'traydigita-core'), false, true);
    }

    /**
     * Render one module's settings page through the shell.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return void
     */
    public function renderModulePage(string $moduleClass): void
    {
        $this->renderPage($moduleClass, $moduleClass::name());
    }

    /**
     * Admin URL of a module's open link.
     *
     * The module's resolved open target
     * (ModuleRegistry::openTarget()): its own settings page by
     * default, or a declared openLink() target. This is the
     * single value the module list JSON 'url' field, the Settings
     * hub links, and the submenu entry all use, so the card
     * "Open" button and the submenu item always point at one
     * identical URL.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return string
     */
    public static function moduleUrl(string $moduleClass): string
    {
        return admin_url(ModuleRegistry::openTarget($moduleClass));
    }

    /**
     * Admin URL of the Settings hub page.
     *
     * @return string
     */
    public static function hubUrl(): string
    {
        return admin_url('admin.php?page=' . self::HUB_SLUG);
    }

    /**
     * Tab list for a module's settings page.
     *
     * The ModuleInterface contract does not require tabs; a module
     * may add an optional static tabs() method returning a list of
     * ['id' => string, 'label' => string] pairs. Extensions may add
     * or change tabs through the
     * `traydigita:core:admin:module_tabs` filter, which receives
     * the tab list and the module class. A module without declared
     * tabs gets no tabs back: the shell renders the tab bar only
     * when this returns at least two tabs, so a page never shows a
     * lonely single tab. The admin JavaScript switches panels by
     * hash without a reload. A tab may also carry an optional
     * 'disabled' => true entry (used by the PWA module's Message
     * tab until a valid push config is saved): the shell renders
     * the button disabled and the JavaScript never activates it,
     * including from a #hash deep link.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return array<int, array{id: string, label: string, disabled?: bool}>
     */
    public static function moduleTabs(string $moduleClass): array
    {
        $tabs = [];
        if (method_exists($moduleClass, 'tabs')) {
            $declared = $moduleClass::tabs();
            if (is_array($declared)) {
                $tabs = $declared;
            }
        }
        /** @var mixed $filtered */
        $filtered = apply_filters(
            Core::HOOK_PREFIX . ':admin:module_tabs',
            $tabs,
            $moduleClass
        );
        $clean = [];
        if (is_array($filtered)) {
            foreach ($filtered as $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $id = $tab['id'] ?? '';
                $label = $tab['label'] ?? '';
                if (!is_string($id) || $id === '' || !is_string($label) || $label === '') {
                    continue;
                }
                $clean[] = [
                    'id' => $id,
                    'label' => $label,
                    'disabled' => !empty($tab['disabled']),
                ];
            }
        }
        return $clean;
    }

    /**
     * Render a page through the admin shell template.
     *
     * Template variables (read by templates/admin/shell.php):
     * - $traydigita_title: page title.
     * - $traydigita_module_class: module class or null (modules grid / hub).
     * - $traydigita_tabs: tab list for the module, if any.
     * - $traydigita_is_hub: true for the Settings hub page.
     * - $traydigita_setup_page: true for the Setup checklist page.
     * - $traydigita_topbar_items: sections for the top-bar dropdown
     *   (AdminMenu::navItems()), in submenu order.
     * - $traydigita_topbar_current: id of the section being rendered
     *   (AdminMenu::currentNavId()), for the active dropdown entry.
     * - $traydigita_back_url / $traydigita_back_label: back link
     *   shown in the shell header (module page -> hub, hub ->
     *   modules, setup -> modules).
     * - $traydigita_pointer_tour: onboarding pointer tour payload (AdminPointerTour::tourData()).
     *
     * @param class-string<ModuleInterface>|null $moduleClass Module class, or null for the grid / hub.
     * @param string $title Page title.
     * @param bool $isHub True when rendering the Settings hub page.
     * @param bool $isSetup True when rendering the Setup checklist page.
     * @return void
     */
    private function renderPage(
        ?string $moduleClass,
        string $title,
        bool $isHub = false,
        bool $isSetup = false
    ): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $traydigita_title = $title;
        $traydigita_module_class = $moduleClass;
        $traydigita_tabs = $moduleClass !== null ? self::moduleTabs($moduleClass) : [];
        $traydigita_is_hub = $isHub;
        $traydigita_setup_page = $isSetup;
        $traydigita_topbar_items = self::navItems();
        $traydigita_topbar_current = self::currentNavId($moduleClass, $isHub, $isSetup);
        $traydigita_pointer_tour = AdminPointerTour::tourData();
        $traydigita_back_url = $moduleClass !== null
            ? self::hubUrl()
            : (($isHub || $isSetup) ? admin_url('admin.php?page=' . self::MENU_SLUG) : '');
        $traydigita_back_label = $moduleClass !== null
            ? __('Back to Settings', 'traydigita-core')
            : (($isHub || $isSetup) ? __('Back to Modules', 'traydigita-core') : '');
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $shell = Core::pluginDir() . '/templates/admin/shell.php';
        if (!file_exists($shell)) {
            wp_die(esc_html__('The TrayDigita admin shell template is missing.', 'traydigita-core'));
        }
        require $shell;
    }
}
