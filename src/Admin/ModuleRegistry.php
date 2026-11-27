<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use TrayDigita\WP\Plugin\Core\Modules\Ads\AdsModule;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaModule;
use TrayDigita\WP\Plugin\Core\Modules\AuthorBox\AuthorBoxModule;
use TrayDigita\WP\Plugin\Core\Modules\Avatar\AvatarModule;
use TrayDigita\WP\Plugin\Core\Modules\Breadcrumb\BreadcrumbModule;
use TrayDigita\WP\Plugin\Core\Modules\Cache\CacheModule;
use TrayDigita\WP\Plugin\Core\Modules\CookieConsent\CookieConsentModule;
use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentsModule;
use TrayDigita\WP\Plugin\Core\Modules\Bookmarks\BookmarksModule;
use TrayDigita\WP\Plugin\Core\Modules\EditorialWorkflow\EditorialWorkflowModule;
use TrayDigita\WP\Plugin\Core\Modules\FrontendEditor\FrontendEditorModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\ContributorSplitMigration;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberProfile\MemberProfileModule;
use TrayDigita\WP\Plugin\Core\Modules\RoleAccess\RoleAccessModule;
use TrayDigita\WP\Plugin\Core\Modules\Core\MenuIconsModule;
use TrayDigita\WP\Plugin\Core\Modules\DashboardDesigner\DashboardDesignerModule;
use TrayDigita\WP\Plugin\Core\Modules\DbTools\DbToolsModule;
use TrayDigita\WP\Plugin\Core\Modules\DynamicSidebars\DynamicSidebarsModule;
use TrayDigita\WP\Plugin\Core\Modules\Email\EmailModule;
use TrayDigita\WP\Plugin\Core\Modules\EPaper\EPaperModule;
use TrayDigita\WP\Plugin\Core\Modules\FastSearch\FastSearchModule;
use TrayDigita\WP\Plugin\Core\Modules\Fonts\FontsModule;
use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilderModule;
use TrayDigita\WP\Plugin\Core\Modules\Headers\HeadersModule;
use TrayDigita\WP\Plugin\Core\Modules\Heartbeat\HeartbeatModule;
use TrayDigita\WP\Plugin\Core\Modules\ImageOptimizer\ImageOptimizerModule;
use TrayDigita\WP\Plugin\Core\Modules\Accessibility\AccessibilityToolbarModule;
use TrayDigita\WP\Plugin\Core\Modules\Installer\InstallerModule;
use TrayDigita\WP\Plugin\Core\Modules\Integrations\IntegrationsModule;
use TrayDigita\WP\Plugin\Core\Modules\Login\LoginModule;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\MailerModule;
use TrayDigita\WP\Plugin\Core\Modules\Maintenance\MaintenanceModule;
use TrayDigita\WP\Plugin\Core\Modules\Markets\MarketsModule;
use TrayDigita\WP\Plugin\Core\Modules\Passkeys\PasskeysModule;
use TrayDigita\WP\Plugin\Core\Modules\Paywall\PaywallModule;
use TrayDigita\WP\Plugin\Core\Modules\PostSeries\PostSeriesModule;
use TrayDigita\WP\Plugin\Core\Modules\Pwa\PwaModule;
use TrayDigita\WP\Plugin\Core\Modules\Push\PushModule;
use TrayDigita\WP\Plugin\Core\Modules\PrintArticle\PrintArticleModule;
use TrayDigita\WP\Plugin\Core\Modules\ReadAloud\ReadAloudModule;
use TrayDigita\WP\Plugin\Core\Modules\ReadingProgress\ReadingProgressModule;
use TrayDigita\WP\Plugin\Core\Modules\Recaptcha\RecaptchaModule;
use TrayDigita\WP\Plugin\Core\Modules\Redirects\RedirectsModule;
use TrayDigita\WP\Plugin\Core\Modules\RelatedPosts\RelatedPostsModule;
use TrayDigita\WP\Plugin\Core\Modules\RoleEditor\RoleEditorModule;
use TrayDigita\WP\Plugin\Core\Modules\Security\SecurityModule;
use TrayDigita\WP\Plugin\Core\Modules\SecurityHeaders\SecurityHeadersModule;
use TrayDigita\WP\Plugin\Core\Modules\Seo\SeoModule;
use TrayDigita\WP\Plugin\Core\Modules\Share\ShareModule;
use TrayDigita\WP\Plugin\Core\Modules\SiteInfo\SiteInfoModule;
use TrayDigita\WP\Plugin\Core\Modules\SiteKit\SiteKitModule;
use TrayDigita\WP\Plugin\Core\Modules\Toc\TocModule;
use TrayDigita\WP\Plugin\Core\Modules\Turnstile\TurnstileModule;
use TrayDigita\WP\Plugin\Core\Modules\TwoFactor\TwoFactorModule;
use TrayDigita\WP\Plugin\Core\Modules\UserManager\UserManagerModule;
use TrayDigita\WP\Plugin\Core\Modules\Weather\WeatherModule;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function add_option;
use function admin_url;
use function apply_filters;
use function array_filter;
use function array_keys;
use function array_map;
use function array_search;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function get_option;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function is_subclass_of;
use function method_exists;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;
use function ucwords;
use function update_option;
use function wp_trim_words;

/**
 * Registry of TrayDigita admin modules.
 *
 * The module class list is filterable for child themes through the
 * `traydigita:core:modules` filter (hook prefix from
 * Core::HOOK_PREFIX). A child theme adds or replaces modules with
 * add_filter - it never edits this class:
 *
 *     add_filter('traydigita:core:modules', function (array $classes) {
 *         $classes[] = MyModule::class;
 *         return $classes;
 *     });
 *
 * The filter also accepts an id-keyed map (id => class-string);
 * see idMap(). Either shape is normalised the same way, so a
 * caller that already knows the ids never has to load a class
 * to build the list.
 *
 * Lazy loading: a disabled module's class is never loaded on a
 * normal request. idMap() (backed by the static DEFAULT_MAP)
 * resolves ids to classes without autoloading anything, and
 * enabled(), isEnabled(), get() and bootEnabled() all work
 * from it - only enabled modules, and the one module a request
 * explicitly asks for, are ever loaded. all() still loads
 * every class, by design: the modules list and the hub need
 * every module's name and description, so those admin pages
 * load everything deliberately.
 *
 * Enabled module ids live in ONE option, `traydigita_modules` (an
 * array of ids, autoload 'no'). Push notifications used to be a
 * card only ('push' id, owned by the separate koperasi-kita-push
 * plugin), then the push half lived in the 'pwa' module (the
 * PWA & Push merge); since the 2026-11-23 split push is a module
 * of its own again ('push' id, PushModule), and a stored 'push'
 * id resolves to it. Sites that had 'pwa' enabled get 'push'
 * enabled by a one-time migration in
 * PushModule::migrateEnabledFromPwa(), called from
 * PwaModule::boot().
 * Missing module classes (other builders run in parallel)
 * are skipped silently, never fatal.
 *
 * Categories: every module resolves to exactly one category
 * (see defaultCategories() and categoryOf()). A module declares
 * its default category with an optional static category()
 * method; admins can create custom categories and reassign
 * modules without code (options `traydigita_module_categories`
 * and `traydigita_module_category_overrides`), and child themes
 * can register categories or reassign modules through the
 * `traydigita:core:modules/categories` filter.
 *
 * Boot note for the coordinator: call ModuleRegistry::bootEnabled()
 * on `init` (or earlier, before `template_redirect`) from a
 * non-admin-gated place. AdminMenu is admin-only and must not boot
 * modules, or frontend hooks would never register.
 */
final class ModuleRegistry
{
    /**
     * Option name holding the enabled module ids.
     */
    public const string OPTION = 'traydigita_modules';

    /**
     * Filter name exposing the module class list to child themes.
     * Built from Core::HOOK_PREFIX so a custom hook prefix applies.
     */
    public const string FILTER = Core::HOOK_PREFIX . ':modules';

    /**
     * Option name holding the admin-created custom categories.
     *
     * A list of ['slug' => string, 'label' => string,
     * 'icon' => string] entries, autoload 'no'. Custom slugs
     * must never collide with a default category slug.
     */
    public const string CATEGORIES_OPTION = 'traydigita_module_categories';

    /**
     * Option name holding per-module category overrides.
     *
     * A map of module id => category slug, autoload 'no'. An
     * override wins over the module's declared category(); when
     * the override points at a category that no longer exists
     * (a deleted custom category) it is ignored, so a module is
     * never orphaned.
     */
    public const string CATEGORY_OVERRIDES_OPTION = 'traydigita_module_category_overrides';

    /**
     * Filter name exposing the final category list to child
     * themes and plugins. The value is the slug-keyed array
     * slug => ['label' => string, 'icon' => string,
     * 'modules' => array<int, string>] built from the defaults
     * below plus the stored custom categories; a callback may
     * add categories, change a label or icon, or reassign
     * modules by listing their ids in a category's 'modules'
     * entry. Entries without a label are dropped, unknown
     * module ids in 'modules' are dropped, and the default
     * category below is always re-added when a callback
     * removes it, so the list can never lose its fallback.
     */
    public const string CATEGORIES_FILTER = Core::HOOK_PREFIX . ':modules/categories';

    /**
     * Fallback category slug. Every module resolves to a real
     * category, never null: a module that declares no category,
     * declares an unknown one, or is reassigned to a deleted
     * category lands here.
     */
    public const string DEFAULT_CATEGORY = 'administration';

    /**
     * Allowlisted Material Symbols ligatures for category icons.
     *
     * Category icons render as Material Icons ligatures in the
     * modules-page filter UI (the same icon font the module
     * cards already use), so only known ligature names are
     * accepted from admins and filter callbacks; anything else
     * falls back to 'extension'. Keep the list to common,
     * stable ligatures - a name that does not exist in the
     * loaded icon font renders as blank text.
     *
     * @var array<int, string>
     */
    public const array CATEGORY_ICONS = [
        'article',
        'palette',
        'shield',
        'speed',
        'search',
        'payments',
        'mail',
        'extension',
        'settings',
        'dashboard',
        'grid_view',
        'widgets',
        'tune',
        'build',
        'security',
        'lock',
        'vpn_key',
        'bolt',
        'rocket_launch',
        'image',
        'storage',
        'database',
        'backup',
        'email',
        'send',
        'notifications',
        'link',
        'hub',
        'api',
        'store',
        'shopping_cart',
        'trending_up',
        'show_chart',
        'person',
        'group',
        'admin_panel_settings',
        'manage_accounts',
        'campaign',
        'forum',
        'comment',
        'share',
        'list',
        'menu_book',
        'newspaper',
        'event',
        'edit_note',
        'login',
        'home',
        'info',
        'star',
        'check_circle',
        'warning',
        'refresh',
        'sync',
        'cloud',
        'download',
        'folder',
        'code',
        'dns',
        'devices',
        'phone_iphone',
        'public',
        'language',
        'translate',
        'dark_mode',
        'visibility',
        'schedule',
        'calendar_month',
        'photo',
        'chat',
        'rss_feed',
        'badge',
        'verified',
        'key',
        'fingerprint',
        'history',
        'analytics',
        'monitoring',
        'cleaning_services',
        'layers',
        'category',
        'label',
        'tag',
        'apps',
    ];

    /**
     * Memoized filtered category map for the current request.
     *
     * filteredCategories() applies the CATEGORIES_FILTER filter;
     * resolving every module's category would re-run it dozens
     * of times per page load, so the sanitized result is cached
     * here and cleared by the category write methods below.
     *
     * @var array<string, array{label: string, icon: string, modules: array<int, string>}>|null
     */
    private static ?array $filteredCategoriesCache = null;

    /**
     * Module classes shipped with the theme, in card order.
     *
     * The five core modules come first, then the optional modules.
     * The classes may not exist yet while other builders work in
     * parallel; every reader below guards with class_exists().
     *
     * @var array<int, class-string<ModuleInterface>>
     */
    public const array DEFAULT_CLASSES = [
        MenuIconsModule::class,
        // Content modules come before Ads: the Table of Contents
        // filter (priority 12) must register before the Ads filter
        // (same priority) so it parses the content before any ad
        // markup is injected into it.
        ShareModule::class,
        BreadcrumbModule::class,
        TocModule::class,
        AuthorBoxModule::class,
        RelatedPostsModule::class,
        PostSeriesModule::class,
        CommentsModule::class,
        MemberAreaModule::class,
        FrontendEditorModule::class,
        BookmarksModule::class,
        MemberProfileModule::class,
        RoleAccessModule::class,
        EditorialWorkflowModule::class,
        DynamicSidebarsModule::class,
        ReadingProgressModule::class,
        ReadAloudModule::class,
        PrintArticleModule::class,
        AccessibilityToolbarModule::class,
        PaywallModule::class,
        FastSearchModule::class,
        PwaModule::class,
        PushModule::class,
        LoginModule::class,
        MaintenanceModule::class,
        MailerModule::class,
        EmailModule::class,
        FormBuilderModule::class,
        CookieConsentModule::class,
        SecurityHeadersModule::class,
        SeoModule::class,
        AdsModule::class,
        SiteKitModule::class,
        MarketsModule::class,
        WeatherModule::class,
        DbToolsModule::class,
        CacheModule::class,
        HeadersModule::class,
        HeartbeatModule::class,
        SiteInfoModule::class,
        SecurityModule::class,
        TurnstileModule::class,
        RecaptchaModule::class,
        TwoFactorModule::class,
        PasskeysModule::class,
        FontsModule::class,
        AvatarModule::class,
        InstallerModule::class,
        IntegrationsModule::class,
        ImageOptimizerModule::class,
        AgendaModule::class,
        EPaperModule::class,
        RoleEditorModule::class,
        UserManagerModule::class,
        RedirectsModule::class,
        DashboardDesignerModule::class,
        ActivityLogModule::class,
    ];

    /**
     * Module ids enabled by default on a fresh install.
     *
     * Optional modules only: core module ids are implied, never
     * stored (see enabled() and setEnabled()).
     *
     * @var array<int, string>
     */
    public const array DEFAULT_ENABLED = [
        'share',
        'breadcrumb',
        'table-of-contents',
        'author-box',
        'related-posts',
        'dynamic-sidebars',
        'reading-progress',
        'read-aloud',
        'accessibility-toolbar',
        'fast-search',
        'pwa',
        'push',
        'login',
        'maintenance',
        'smtp',
        'email',
        'cookie-consent',
        'security-headers',
        'seo',
        'site-kit',
        'markets',
        'weather',
        'headers',
        'heartbeat',
        'site-info',
        'security-monitor',
        'fonts',
        'avatar',
        'installer',
        'agenda',
        'user-manager',
        'redirects',
        'bookmarks',
        'passkeys',
        'activity-log',
    ];

    /**
     * Module id => module class, in the same order as
     * DEFAULT_CLASSES.
     *
     * The lazy-loading map: a disabled module's class must never
     * be loaded just to answer "is this id enabled?" or "which
     * class is this id?". DEFAULT_CLASSES is a plain list, so
     * resolving an id from it needs class_exists() (which
     * autoloads the file) plus $class::id() for every entry -
     * all 56 classes on every request, even with everything
     * disabled. This map answers id => class from static data
     * instead, so enabled(), isEnabled(), get() and
     * bootEnabled() only ever autoload the classes they actually
     * need: the enabled ones, or the one explicitly requested.
     *
     * MUST mirror each class's id() exactly: idMap() trusts
     * these keys without loading the class, and all() / get()
     * drop any entry whose loaded class reports a different id,
     * so a drifted key can never mislabel a module - the module
     * would disappear from the list instead of resolving to the
     * wrong class. When a module changes its id(), update this
     * map in the same commit.
     *
     * @var array<string, class-string<ModuleInterface>>
     */
    public const array DEFAULT_MAP = [
        'menu-icons' => MenuIconsModule::class,
        'share' => ShareModule::class,
        'breadcrumb' => BreadcrumbModule::class,
        'table-of-contents' => TocModule::class,
        'author-box' => AuthorBoxModule::class,
        'related-posts' => RelatedPostsModule::class,
        'post-series' => PostSeriesModule::class,
        'comments' => CommentsModule::class,
        'member-area' => MemberAreaModule::class,
        'frontend-editor' => FrontendEditorModule::class,
        'bookmarks' => BookmarksModule::class,
        'member-profile' => MemberProfileModule::class,
        'role-access' => RoleAccessModule::class,
        'editorial-workflow' => EditorialWorkflowModule::class,
        'dynamic-sidebars' => DynamicSidebarsModule::class,
        'reading-progress' => ReadingProgressModule::class,
        'read-aloud' => ReadAloudModule::class,
        'print-article' => PrintArticleModule::class,
        'accessibility-toolbar' => AccessibilityToolbarModule::class,
        'paywall' => PaywallModule::class,
        'fast-search' => FastSearchModule::class,
        'pwa' => PwaModule::class,
        'push' => PushModule::class,
        'login' => LoginModule::class,
        'maintenance' => MaintenanceModule::class,
        'smtp' => MailerModule::class,
        'email' => EmailModule::class,
        'form-builder' => FormBuilderModule::class,
        'cookie-consent' => CookieConsentModule::class,
        'security-headers' => SecurityHeadersModule::class,
        'seo' => SeoModule::class,
        'ads' => AdsModule::class,
        'site-kit' => SiteKitModule::class,
        'markets' => MarketsModule::class,
        'weather' => WeatherModule::class,
        'db-tools' => DbToolsModule::class,
        'cache' => CacheModule::class,
        'headers' => HeadersModule::class,
        'heartbeat' => HeartbeatModule::class,
        'site-info' => SiteInfoModule::class,
        'security-monitor' => SecurityModule::class,
        'turnstile' => TurnstileModule::class,
        'recaptcha' => RecaptchaModule::class,
        'two-factor' => TwoFactorModule::class,
        'passkeys' => PasskeysModule::class,
        'fonts' => FontsModule::class,
        'avatar' => AvatarModule::class,
        'installer' => InstallerModule::class,
        'integrations' => IntegrationsModule::class,
        'image-optimizer' => ImageOptimizerModule::class,
        'agenda' => AgendaModule::class,
        'epaper' => EPaperModule::class,
        'role-editor' => RoleEditorModule::class,
        'user-manager' => UserManagerModule::class,
        'redirects' => RedirectsModule::class,
        'dashboard-designer' => DashboardDesignerModule::class,
        'activity-log' => ActivityLogModule::class,
    ];

    /**
     * Ids of the core modules (isCore() true), in DEFAULT_MAP order.
     *
     * Core modules are always enabled and cannot be deactivated,
     * so enabled() must imply them without loading their classes
     * to ask isCore(). When a module flips isCore() to true, add
     * its id here in the same commit; when it flips back, remove
     * it. Third-party modules that report isCore() true are NOT
     * listed here - isCore($id) still loads that one class to ask
     * it, so they keep working.
     *
     * @var array<int, string>
     */
    public const array CORE_IDS = [
        'menu-icons',
        'installer',
        'integrations',
    ];

    /**
     * Resolved id => class map for this request, or null until
     * idMap() builds it. The filter is applied once per request.
     *
     * @var array<string, class-string<ModuleInterface>>|null
     */
    private static ?array $idMap = null;

    /**
     * Reentrancy guard for idMap(): a filter callback that asks
     * the registry for an id while the map is being built gets
     * the unfiltered DEFAULT_MAP instead of recursing.
     */
    private static bool $resolvingIdMap = false;

    /**
     * All known module classes, filtered for child themes.
     *
     * Entries that do not exist, are not strings, or do not
     * implement ModuleInterface are dropped. Duplicate ids are
     * dropped (first class wins).
     *
     * @return array<int, class-string<ModuleInterface>>
     */
    public static function all(): array
    {
        $classes = apply_filters(self::FILTER, self::DEFAULT_CLASSES);
        if (!is_array($classes)) {
            return [];
        }
        $valid = [];
        $seenIds = [];
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, ModuleInterface::class)) {
                continue;
            }
            $id = $class::id();
            if ($id === '' || in_array($id, $seenIds, true)) {
                continue;
            }
            $seenIds[] = $id;
            $valid[] = $class;
        }
        return $valid;
    }

    /**
     * Id => class map for this request, filtered for child themes.
     *
     * This is the lazy-loading counterpart of all(): it resolves
     * the same filter without autoloading any built-in class.
     * Built-in entries are matched against DEFAULT_MAP by class
     * string, so their ids come from static data; only a class
     * the map does not know (a third-party module added through
     * the filter) is loaded once to learn its id. A filter entry
     * that omits a built-in class removes it from the map, just
     * as all() drops it from the list.
     *
     * The filter accepts both shapes: the historical list of
     * class-strings, and an id-keyed map (id => class-string)
     * for callers that already know the ids. In the id-keyed
     * shape the keys are trusted without loading anything. A
     * mixed list is handled per entry. A non-array filter
     * result resolves to an empty map, mirroring all().
     *
     * The result is cached per request; the filter runs once.
     *
     * @return array<string, class-string<ModuleInterface>>
     */
    public static function idMap(): array
    {
        if (self::$idMap !== null) {
            return self::$idMap;
        }
        if (self::$resolvingIdMap) {
            return self::DEFAULT_MAP;
        }
        self::$resolvingIdMap = true;
        try {
            $map = self::DEFAULT_MAP;
            $filtered = apply_filters(self::FILTER, self::DEFAULT_CLASSES);
            if (!is_array($filtered)) {
                self::$idMap = [];
                return self::$idMap;
            }
            $hasStringKeys = false;
            $hasIntKeys = false;
            foreach (array_keys($filtered) as $key) {
                if (is_string($key)) {
                    $hasStringKeys = true;
                } else {
                    $hasIntKeys = true;
                }
            }
            if ($hasStringKeys && !$hasIntKeys) {
                $resolved = [];
                foreach ($filtered as $id => $class) {
                    if (!is_string($id) || $id === '' || !is_string($class) || $class === '') {
                        continue;
                    }
                    if (!isset($resolved[$id])) {
                        $resolved[$id] = $class;
                    }
                }
                self::$idMap = $resolved;
                return self::$idMap;
            }
            $listed = [];
            foreach ($filtered as $key => $class) {
                if (is_string($key) && $key !== '') {
                    if (is_string($class) && $class !== '' && !isset($listed[$key])) {
                        $listed[$key] = $class;
                    }
                    continue;
                }
                if (!is_string($class) || $class === '') {
                    continue;
                }
                $knownId = array_search($class, $map, true);
                if (is_string($knownId)) {
                    if (!isset($listed[$knownId])) {
                        $listed[$knownId] = $class;
                    }
                    continue;
                }
                if (!class_exists($class) || !is_subclass_of($class, ModuleInterface::class)) {
                    continue;
                }
                $id = $class::id();
                if ($id !== '' && !isset($listed[$id])) {
                    $listed[$id] = $class;
                }
            }
            self::$idMap = $listed;
            return self::$idMap;
        } finally {
            self::$resolvingIdMap = false;
        }
    }

    /**
     * Find a module class by its settings page slug, or null.
     *
     * Every shipped module uses the slug `traydigita-<id>` (see
     * idMap()), so the usual path resolves the id from the slug
     * and loads only that one class. A slug in any other shape
     * (a third-party module with its own slug) falls back to
     * scanning all(), which loads every class - that path is
     * admin-only and rare.
     *
     * @param string $slug Settings page slug, e.g. 'traydigita-login'.
     * @return class-string<ModuleInterface>|null
     */
    public static function getBySlug(string $slug): ?string
    {
        if (str_starts_with($slug, 'traydigita-')) {
            $id = substr($slug, 11);
            if ($id !== '') {
                $class = self::get($id);
                if ($class !== null && $class::settingsPageSlug() === $slug) {
                    return $class;
                }
            }
        }
        foreach (self::all() as $class) {
            if ($class::settingsPageSlug() === $slug) {
                return $class;
            }
        }
        return null;
    }

    /**
     * Find a module class by its id, or null when unknown/missing.
     *
     * Resolved through idMap(), so only this one class is ever
     * loaded: a missing file, a class that does not implement
     * ModuleInterface, or a class whose id() disagrees with the
     * map key all resolve to null, exactly as all() drops them.
     *
     * @param string $id Module id.
     * @return class-string<ModuleInterface>|null
     */
    public static function get(string $id): ?string
    {
        $class = self::idMap()[$id] ?? null;
        if ($class === null) {
            return null;
        }
        if (!class_exists($class) || !is_subclass_of($class, ModuleInterface::class)) {
            return null;
        }
        if ($class::id() !== $id) {
            return null;
        }
        return $class;
    }

    /**
     * Whether a module id is registered (exists in the module list).
     *
     * This is the module-exists check: it only asks whether the
     * id resolves to a module class, not whether the module is
     * enabled. Use isEnabled() when the caller also needs the
     * module to be active, or isAvailable() for both at once.
     * Unknown ids never exist.
     *
     * @param string $id Module id.
     * @return bool
     */
    public static function exists(string $id): bool
    {
        return self::get($id) !== null;
    }

    /**
     * Whether a module id exists and is currently enabled.
     *
     * Exists means the id resolves to a module class (see
     * exists()); enabled means the module is active (see
     * isEnabled()). A module that is registered but disabled
     * is not available.
     *
     * @param string $id Module id.
     * @return bool
     */
    public static function isAvailable(string $id): bool
    {
        return self::exists($id) && self::isEnabled($id);
    }

    /**
     * Enabled module ids, sanitized against the known module list.
     *
     * A missing or malformed option falls back to DEFAULT_ENABLED.
     * Stored ids of unknown modules are dropped; the option
     * itself is never written by a read. (A stored 'push' id from
     * before the PWA & Push merge resolves to the Push module
     * again since the 2026-11-23 split - no migration step is
     * needed for the id itself.)
     *
     * Core modules (see isCore()) are always included, even when
     * the stored option does not list them - a core module cannot
     * be deactivated, so its id is not stored state. The core
     * ids come from CORE_IDS, so this read loads no class at all.
     *
     * @return array<int, string>
     */
    public static function enabled(): array
    {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored)) {
            $enabled = self::DEFAULT_ENABLED;
        } else {
            $known = array_keys(self::idMap());
            $enabled = [];
            foreach ($stored as $id) {
                if (!is_string($id)) {
                    continue;
                }
                if (!in_array($id, $known, true)) {
                    continue;
                }
                if (!in_array($id, $enabled, true)) {
                    $enabled[] = $id;
                }
            }
        }
        $map = self::idMap();
        foreach (self::CORE_IDS as $coreId) {
            if (isset($map[$coreId]) && !in_array($coreId, $enabled, true)) {
                $enabled[] = $coreId;
            }
        }
        return $enabled;
    }

    /**
     * Whether a module id is currently enabled.
     *
     * Unknown ids are never enabled. Core modules are always enabled.
     *
     * @param string $id Module id.
     * @return bool
     */
    public static function isEnabled(string $id): bool
    {
        return in_array($id, self::enabled(), true);
    }

    /**
     * Whether a module id is a core module.
     *
     * Core modules are always enabled and cannot be deactivated.
     * Unknown ids are never core. Shipped core modules answer
     * from CORE_IDS without loading any class; a third-party
     * module that reports isCore() true still answers true - it
     * is asked directly, which loads that one class.
     *
     * @param string $id Module id.
     * @return bool
     */
    public static function isCore(string $id): bool
    {
        if (in_array($id, self::CORE_IDS, true)
            && (self::idMap()[$id] ?? null) === (self::DEFAULT_MAP[$id] ?? null)
        ) {
            return true;
        }
        $class = self::get($id);
        if ($class === null) {
            return false;
        }
        return $class::isCore();
    }

    /**
     * Whether a module class ships with Core (is built-in).
     *
     * Built-in means the class is listed in DEFAULT_CLASSES -
     * it ships in src/Modules/ under the
     * TrayDigita\WP\Plugin\Core\Modules\ namespace. This is
     * NOT the same as isCore(): isCore() marks the few modules
     * that are always enabled and cannot be deactivated, while
     * every shipped module - optional or not - is built-in.
     * A module registered through the public
     * `traydigita:core:modules` filter is never built-in, even
     * when it extends a shipped class, because membership is
     * checked against the exact class. This is the single
     * source of truth for the "Core" badge on module cards and
     * in the module info popup.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return bool
     */
    public static function isBuiltIn(string $moduleClass): bool
    {
        return in_array($moduleClass, self::DEFAULT_CLASSES, true);
    }

    /**
     * Enable or disable one module and persist the list.
     *
     * Unknown ids are rejected (false). A core
     * module cannot be disabled (false); enabling one is a no-op
     * success because core modules are always enabled. The option
     * is stored with autoload 'no' so the admin module list is never
     * loaded on every frontend request.
     *
     * @param string $id Module id.
     * @param bool $enabled True to enable, false to disable.
     * @return bool True when the stored list changed (or already matched).
     */
    public static function setEnabled(string $id, bool $enabled): bool
    {
        if (self::get($id) === null) {
            return false;
        }
        if (self::isCore($id)) {
            return $enabled;
        }
        $current = self::enabled();
        if ($enabled) {
            if (!in_array($id, $current, true)) {
                $current[] = $id;
            }
        } else {
            $current = array_values(array_filter(
                $current,
                static fn (string $storedId): bool => $storedId !== $id
            ));
        }
        $current = array_values(array_unique($current));
        // Core ids are implied, never stored: keep the option to
        // the optional modules only.
        $current = array_values(array_filter(
            $current,
            static fn (string $storedId): bool => !self::isCore($storedId)
        ));
        // No-op guard: update_option() returns false when the
        // stored value is unchanged, which the toggle endpoint
        // used to report as a 500 even though the module was
        // already in the requested state. When nothing changes,
        // report success without touching the option.
        $before = array_values(array_filter(
            self::enabled(),
            static fn (string $storedId): bool => !self::isCore($storedId)
        ));
        if ($current === $before) {
            return true;
        }
        if (get_option(self::OPTION, null) === false) {
            $saved = add_option(self::OPTION, $current, '', false);
        } else {
            $saved = update_option(self::OPTION, $current);
        }
        if ($saved) {
            self::recordToggle($id, $enabled);
        }
        return $saved;
    }

    /**
     * Log a module toggle to the Activity Log.
     *
     * Called only when the stored list actually changed (the
     * no-op guard above returns before this point), so enabling
     * an already-enabled module never logs a spurious event.
     * The class_exists() guard keeps this safe while other
     * builders work in parallel: when the Activity Log class
     * does not exist yet, the toggle simply goes unlogged.
     * record() itself is fail-open and checks that the Activity
     * Log module is enabled, so disabling the Activity Log
     * itself logs nothing - which is the correct outcome.
     *
     * @param string $id Module id that was toggled.
     * @param bool $enabled True when it was enabled.
     * @return void
     */
    private static function recordToggle(string $id, bool $enabled): void
    {
        if (!class_exists(ActivityLogModule::class)) {
            return;
        }
        ActivityLogModule::record(
            $id,
            $enabled ? 'module-enabled' : 'module-disabled',
            sprintf("Module '%s' %s.", $id, $enabled ? 'enabled' : 'disabled')
        );
    }

    /**
     * Author name shown for a module on the admin module list.
     *
     * A module may declare its own author with an optional static
     * author() method; every shipped module does and returns
     * 'TrayDigita'. Modules from a child theme that do not declare
     * one fall back to 'TrayDigita' here, so the card author is
     * never empty. The method is optional on purpose: adding it to
     * ModuleInterface would force every existing module class to
     * implement it.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return string
     */
    public static function author(string $moduleClass): string
    {
        if (method_exists($moduleClass, 'author')) {
            $author = $moduleClass::author();
            if (is_string($author) && $author !== '') {
                return $author;
            }
        }
        return 'TrayDigita';
    }

    /**
     * Whether a module is recommended.
     *
     * A module opts in by declaring an optional static
     * isRecommended() method returning true (see
     * ModuleInterface). Recommended modules ship inactive but
     * carry a "Recommended" note on their module card, so the
     * admin sees the suggestion without the module turning
     * itself on. A non-boolean return is treated as false.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return bool
     */
    public static function isRecommended(string $moduleClass): bool
    {
        if (!method_exists($moduleClass, 'isRecommended')) {
            return false;
        }
        $recommended = $moduleClass::isRecommended();
        return is_bool($recommended) && $recommended;
    }

    /**
     * Whether a module opts into the TrayDigita admin submenu.
     *
     * A module opts in by declaring an optional static
     * showInSubmenu() method returning true (see
     * ModuleInterface). The default is false: the submenu stays
     * clean and only modules that are opened often get an entry.
     * A non-boolean return is treated as false.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return bool
     */
    public static function showInSubmenu(string $moduleClass): bool
    {
        if (!method_exists($moduleClass, 'showInSubmenu')) {
            return false;
        }
        $shown = $moduleClass::showInSubmenu();
        return is_bool($shown) && $shown;
    }

    /**
     * A module's declared submenu position, or 0.
     *
     * A module may declare an optional static submenuPosition()
     * method returning an int; AdminMenu orders the data-driven
     * submenu entries by it (ascending, registry order breaking
     * ties) and registers entries below 0 before Site Health and
     * entries at 0 or above after it - Site Health sits at
     * position 0 by definition. A missing method or a
     * non-integer return means 0. The method is optional on
     * purpose: adding it to ModuleInterface would force every
     * existing module class to implement it.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return int Declared position, or 0.
     */
    public static function submenuPosition(string $moduleClass): int
    {
        if (!method_exists($moduleClass, 'submenuPosition')) {
            return 0;
        }
        $position = $moduleClass::submenuPosition();
        return is_int($position) ? $position : 0;
    }

    /**
     * A module's declared open link, or null.
     *
     * A module may declare an optional static openLink() method
     * returning an admin-relative target (for example
     * 'customize.php', 'tools.php', or
     * 'admin.php?page=traydigita-login'). The target must name a
     * WordPress admin file: it must contain '.php' and must not
     * be an absolute or external URL (no '://', no leading
     * slash). Anything else - a missing method, a non-string, an
     * empty string, or a target that fails validation - returns
     * null, which means the module's own settings page (see
     * openTarget()).
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return string|null Validated admin-relative target, or null.
     */
    public static function openLink(string $moduleClass): ?string
    {
        if (!method_exists($moduleClass, 'openLink')) {
            return null;
        }
        $link = $moduleClass::openLink();
        if (!is_string($link) || $link === '') {
            return null;
        }
        if (!str_contains($link, '.php')) {
            return null;
        }
        if (str_contains($link, '://') || str_starts_with($link, '/')) {
            return null;
        }
        return $link;
    }

    /**
     * A module's resolved open target (admin-relative).
     *
     * The module's declared openLink() when it validates, else
     * its own settings page ('admin.php?page=' .
     * settingsPageSlug()). This is the single source of truth
     * for the module's open link: AdminMenu::moduleUrl() builds
     * the card "Open" URL from it, the module list JSON 'url'
     * field carries it, and the submenu entry links to it - so
     * the card button and the submenu item always use one
     * identical value.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return string Admin-relative target, e.g. 'admin.php?page=traydigita-seo'.
     */
    public static function openTarget(string $moduleClass): string
    {
        return self::openLink($moduleClass)
            ?? 'admin.php?page=' . $moduleClass::settingsPageSlug();
    }

    /**
     * A module's declared feature list, or [].
     *
     * A module may declare an optional static features()
     * method returning a list of short strings (see
     * ModuleInterface): each string is one thing the module
     * actually does. The list is capped at 12 entries and
     * empty or non-string entries are dropped, so a sloppy
     * declaration can never break the popup. A missing method
     * or an empty list means the module info popup shows the
     * module's short description in the Features section
     * instead. The method is optional on purpose: adding it
     * to ModuleInterface would force every existing module
     * class to implement it.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return array<int, string> Cleaned feature list, never null.
     */
    public static function features(string $moduleClass): array
    {
        if (!method_exists($moduleClass, 'features')) {
            return [];
        }
        $features = $moduleClass::features();
        if (!is_array($features)) {
            return [];
        }
        $clean = [];
        foreach ($features as $feature) {
            if (!is_string($feature)) {
                continue;
            }
            $feature = trim($feature);
            if ($feature === '') {
                continue;
            }
            $clean[] = $feature;
            if (count($clean) >= 12) {
                break;
            }
        }
        return $clean;
    }

    /**
     * A module's declared changelog, with a fallback entry.
     *
     * A module may declare an optional static changelog()
     * method returning a list of entries shaped
     * ['version' => string, 'changes' => array<int, string>]
     * (see ModuleInterface). Entries with an empty version or
     * no usable changes are dropped and the list is capped at
     * 8 entries, newest first as declared. A missing method
     * or an empty list falls back to a single entry built from
     * version() - "Initial release in TrayDigita Core." - so
     * the popup's Changelog section is never empty and no
     * history is ever invented. The method is optional on
     * purpose, for the same reason as features() above.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return array<int, array{version: string, changes: array<int, string>}> At least one entry.
     */
    public static function changelog(string $moduleClass): array
    {
        $entries = [];
        if (method_exists($moduleClass, 'changelog')) {
            $raw = $moduleClass::changelog();
            if (is_array($raw)) {
                foreach ($raw as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $version = isset($entry['version']) && is_string($entry['version'])
                        ? trim($entry['version'])
                        : '';
                    if ($version === '') {
                        continue;
                    }
                    $changes = [];
                    if (isset($entry['changes']) && is_array($entry['changes'])) {
                        foreach ($entry['changes'] as $change) {
                            if (!is_string($change)) {
                                continue;
                            }
                            $change = trim($change);
                            if ($change === '') {
                                continue;
                            }
                            $changes[] = $change;
                            if (count($changes) >= 8) {
                                break;
                            }
                        }
                    }
                    if ($changes === []) {
                        continue;
                    }
                    $entries[] = ['version' => $version, 'changes' => $changes];
                    if (count($entries) >= 8) {
                        break;
                    }
                }
            }
        }
        if ($entries === []) {
            $entries[] = [
                'version' => $moduleClass::version(),
                'changes' => [__('Initial release in TrayDigita Core.', 'traydigita-core')],
            ];
        }
        return $entries;
    }

    /**
     * Everything the module info popup needs, in one array.
     *
     * The single source of truth for the popup data: the
     * context rail embeds it for the current module
     * (templates/admin/shell.php) and moduleListData() embeds
     * it per module for the Modules page cards, so both
     * openings render the same popup from the same shape.
     * The data is server-rendered JSON - no new AJAX or REST
     * endpoint, no new nonce or capability surface. Status
     * uses the same exclusive bucket as moduleListData():
     * not available, core, needs setup (enabled only),
     * enabled, disabled.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return array<string, mixed> Popup data (see the keys below).
     */
    public static function moduleInfoData(string $moduleClass): array
    {
        $id = $moduleClass::id();
        $available = $moduleClass::isAvailable();
        $configured = $moduleClass::isConfigured();
        $isCore = $moduleClass::isCore();
        $enabled = self::isEnabled($id);
        if (!$available) {
            $status = 'not-available';
            $statusLabel = __('Not available', 'traydigita-core');
        } elseif ($isCore) {
            $status = 'core';
            $statusLabel = __('Core module', 'traydigita-core');
        } elseif ($enabled && !$configured) {
            $status = 'needs-setup';
            $statusLabel = __('Needs setup', 'traydigita-core');
        } elseif ($enabled) {
            $status = 'enabled';
            $statusLabel = __('Enabled', 'traydigita-core');
        } else {
            $status = 'disabled';
            $statusLabel = __('Disabled', 'traydigita-core');
        }
        $category = self::categoryOf($moduleClass);
        return [
            'id' => $id,
            'name' => $moduleClass::name(),
            'description' => $moduleClass::description(),
            'icon' => $moduleClass::icon(),
            'version' => $moduleClass::version(),
            'author' => self::author($moduleClass),
            'category' => $category,
            'category_label' => self::categoryLabel($category),
            'status' => $status,
            'statusLabel' => $statusLabel,
            'enabled' => $enabled,
            'available' => $available,
            'core' => $isCore,
            'built_in' => self::isBuiltIn($moduleClass),
            'configured' => $configured,
            'settings_url' => self::moduleUrl($moduleClass),
            'features' => self::features($moduleClass),
            'changelog' => self::changelog($moduleClass),
        ];
    }

    /**
     * Default categories: slug => label + Material icon slug.
     *
     * Nine categories cover all 46 shipped modules with at
     * least two modules each (no singletons): Content (10),
     * Design (9), Security (6), Performance (6), Administration
     * (5), Communication (3), SEO & Discovery (3),
     * Monetization (2), Integrations (2). Labels are English
     * source strings; Indonesian lives only in
     * languages/traydigita-id_ID.po.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function defaultCategories(): array
    {
        return [
            'content' => [
                'label' => __('Content', 'traydigita-core'),
                'icon' => 'article',
            ],
            'design' => [
                'label' => __('Design', 'traydigita-core'),
                'icon' => 'palette',
            ],
            'security' => [
                'label' => __('Security', 'traydigita-core'),
                'icon' => 'shield',
            ],
            'performance' => [
                'label' => __('Performance', 'traydigita-core'),
                'icon' => 'speed',
            ],
            'seo-discovery' => [
                'label' => __('SEO & Discovery', 'traydigita-core'),
                'icon' => 'search',
            ],
            'monetization' => [
                'label' => __('Monetization', 'traydigita-core'),
                'icon' => 'payments',
            ],
            'communication' => [
                'label' => __('Communication', 'traydigita-core'),
                'icon' => 'mail',
            ],
            'integrations' => [
                'label' => __('Integrations', 'traydigita-core'),
                'icon' => 'extension',
            ],
            'administration' => [
                'label' => __('Administration', 'traydigita-core'),
                'icon' => 'settings',
            ],
        ];
    }

    /**
     * Default category per module id.
     *
     * The registry-level table behind categoryOf(): it is the
     * fallback for a module that does not declare category()
     * (for example a child-theme module) and the home a module
     * returns to when its custom category is deleted. Shipped
     * modules also declare the same slug via category(), which
     * wins over this table when the two disagree.
     *
     * @return array<string, string> Module id => category slug.
     */
    public static function defaultCategoryMap(): array
    {
        return [
            'share' => 'content',
            'breadcrumb' => 'content',
            'table-of-contents' => 'content',
            'author-box' => 'content',
            'related-posts' => 'content',
            'post-series' => 'content',
            'comments' => 'content',
            'member-area' => 'content',
            'frontend-editor' => 'content',
            'bookmarks' => 'content',
            'member-profile' => 'content',
            'editorial-workflow' => 'content',
            'role-access' => 'security',
            'reading-progress' => 'content',
            'print-article' => 'content',
            'agenda' => 'content',
            'epaper' => 'content',
            'widgets' => 'design',
            'styles' => 'design',
            'menu-icons' => 'design',
            'theme-support' => 'design',
            'customizer' => 'design',
            'fonts' => 'design',
            'avatar' => 'design',
            'dynamic-sidebars' => 'design',
            'dashboard-designer' => 'design',
            'login' => 'security',
            'cookie-consent' => 'security',
            'security-headers' => 'security',
            'security-monitor' => 'security',
            'turnstile' => 'security',
            'recaptcha' => 'security',
            'two-factor' => 'security',
            'passkeys' => 'security',
            'cache' => 'performance',
            'fast-search' => 'performance',
            'heartbeat' => 'performance',
            'image-optimizer' => 'performance',
            'headers' => 'performance',
            'pwa' => 'performance',
            'seo' => 'seo-discovery',
            'redirects' => 'seo-discovery',
            'site-kit' => 'seo-discovery',
            'ads' => 'monetization',
            'markets' => 'monetization',
            'smtp' => 'communication',
            'email' => 'communication',
            'integrations' => 'integrations',
            'installer' => 'integrations',
            'maintenance' => 'administration',
            'db-tools' => 'administration',
            'role-editor' => 'administration',
            'user-manager' => 'administration',
            'activity-log' => 'administration',
            'site-info' => 'administration',
        ];
    }

    /**
     * Whether an icon slug is a known Material icon ligature.
     *
     * @param string $icon Icon slug, or '' for "no icon".
     * @return bool
     */
    public static function isValidCategoryIcon(string $icon): bool
    {
        return $icon === '' || in_array($icon, self::CATEGORY_ICONS, true);
    }

    /**
     * Admin-created custom categories, sanitized.
     *
     * Reads the CATEGORIES_OPTION option: a list of
     * ['slug' => string, 'label' => string, 'icon' => string]
     * entries. Entries with an empty slug or label, a slug
     * that collides with a default category, or a duplicate
     * slug are dropped; an unknown icon falls back to
     * 'extension'. A missing or malformed option reads as no
     * custom categories, and the option itself is never
     * written by a read.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function customCategories(): array
    {
        $stored = get_option(self::CATEGORIES_OPTION, null);
        if (!is_array($stored)) {
            return [];
        }
        $defaults = self::defaultCategories();
        $custom = [];
        foreach ($stored as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = sanitize_key((string) ($entry['slug'] ?? ''));
            $label = sanitize_text_field((string) ($entry['label'] ?? ''));
            if ($slug === '' || $label === '') {
                continue;
            }
            if (isset($defaults[$slug]) || isset($custom[$slug])) {
                continue;
            }
            $icon = sanitize_key((string) ($entry['icon'] ?? ''));
            if (!self::isValidCategoryIcon($icon) || $icon === '') {
                $icon = 'extension';
            }
            $custom[$slug] = ['label' => $label, 'icon' => $icon];
        }
        return $custom;
    }

    /**
     * Per-module category overrides, sanitized.
     *
     * Reads the CATEGORY_OVERRIDES_OPTION option: a map of
     * module id => category slug. Overrides naming an unknown
     * module or an unknown category slug are dropped, so a
     * stale override can never orphan a module.
     *
     * @return array<string, string> Module id => category slug.
     */
    public static function categoryOverrides(): array
    {
        $stored = get_option(self::CATEGORY_OVERRIDES_OPTION, null);
        if (!is_array($stored)) {
            return [];
        }
        $knownModules = array_map(
            static fn (string $class): string => $class::id(),
            self::all()
        );
        $knownCategories = self::filteredCategories();
        $overrides = [];
        foreach ($stored as $id => $slug) {
            $id = sanitize_key((string) $id);
            $slug = sanitize_key((string) $slug);
            if ($id === '' || $slug === '') {
                continue;
            }
            if (!in_array($id, $knownModules, true)) {
                continue;
            }
            if (!isset($knownCategories[$slug])) {
                continue;
            }
            $overrides[$id] = $slug;
        }
        return $overrides;
    }

    /**
     * Final category map: defaults + customs, filter-applied.
     *
     * The base is the default categories plus the stored custom
     * categories, then the CATEGORIES_FILTER filter runs over
     * it (see the filter constant for the contract). The
     * sanitized result is memoized for the request; category
     * writes clear it via invalidateCategoryCache().
     *
     * @return array<string, array{label: string, icon: string, modules: array<int, string>}>
     */
    public static function filteredCategories(): array
    {
        if (self::$filteredCategoriesCache !== null) {
            return self::$filteredCategoriesCache;
        }
        $base = [];
        foreach (self::defaultCategories() as $slug => $entry) {
            $base[$slug] = [
                'label' => $entry['label'],
                'icon' => $entry['icon'],
                'modules' => [],
            ];
        }
        foreach (self::customCategories() as $slug => $entry) {
            $base[$slug] = [
                'label' => $entry['label'],
                'icon' => $entry['icon'],
                'modules' => [],
            ];
        }
        $filtered = apply_filters(self::CATEGORIES_FILTER, $base);
        if (!is_array($filtered)) {
            $filtered = $base;
        }
        $knownModules = array_map(
            static fn (string $class): string => $class::id(),
            self::all()
        );
        $clean = [];
        foreach ($filtered as $slug => $entry) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || !is_array($entry)) {
                continue;
            }
            $label = isset($entry['label']) && is_string($entry['label'])
                ? sanitize_text_field($entry['label'])
                : '';
            if ($label === '') {
                continue;
            }
            $icon = isset($entry['icon']) && is_string($entry['icon'])
                ? sanitize_key($entry['icon'])
                : '';
            if (!self::isValidCategoryIcon($icon) || $icon === '') {
                $icon = 'extension';
            }
            $modules = [];
            if (isset($entry['modules']) && is_array($entry['modules'])) {
                foreach ($entry['modules'] as $moduleId) {
                    $moduleId = sanitize_key((string) $moduleId);
                    if ($moduleId !== '' && in_array($moduleId, $knownModules, true)) {
                        $modules[] = $moduleId;
                    }
                }
            }
            $clean[$slug] = ['label' => $label, 'icon' => $icon, 'modules' => $modules];
        }
        if (!isset($clean[self::DEFAULT_CATEGORY])) {
            $defaults = self::defaultCategories();
            $clean[self::DEFAULT_CATEGORY] = [
                'label' => $defaults[self::DEFAULT_CATEGORY]['label'],
                'icon' => $defaults[self::DEFAULT_CATEGORY]['icon'],
                'modules' => [],
            ];
        }
        self::$filteredCategoriesCache = $clean;
        return $clean;
    }

    /**
     * Clear the memoized filtered category map.
     *
     * Called after every category write so the next read
     * re-applies the filter over the fresh options.
     *
     * @return void
     */
    private static function invalidateCategoryCache(): void
    {
        self::$filteredCategoriesCache = null;
    }

    /**
     * A module's base category: filter reassignment, then its
     * declared category(), then the default map, then the
     * fallback. Overrides are NOT applied here - see
     * categoryOf() - so assignModuleToCategory() can compare
     * against the base and drop a redundant override.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return string Resolved base category slug, never ''.
     */
    public static function baseCategory(string $moduleClass): string
    {
        $id = $moduleClass::id();
        $categories = self::filteredCategories();
        foreach ($categories as $slug => $entry) {
            if (in_array($id, $entry['modules'], true)) {
                return $slug;
            }
        }
        if (method_exists($moduleClass, 'category')) {
            $declared = $moduleClass::category();
            if (is_string($declared)) {
                $declared = sanitize_key($declared);
                if ($declared !== '' && isset($categories[$declared])) {
                    return $declared;
                }
            }
        }
        $map = self::defaultCategoryMap();
        if (isset($map[$id]) && isset($categories[$map[$id]])) {
            return $map[$id];
        }
        return self::DEFAULT_CATEGORY;
    }

    /**
     * A module's resolved category, with overrides applied.
     *
     * Precedence: stored per-module override, then
     * baseCategory() (filter reassignment, declared category(),
     * default map, fallback). The result is always a slug from
     * the final category list, never null and never orphaned.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return string Resolved category slug.
     */
    public static function categoryOf(string $moduleClass): string
    {
        $overrides = self::categoryOverrides();
        $id = $moduleClass::id();
        $categories = self::filteredCategories();
        if (isset($overrides[$id]) && isset($categories[$overrides[$id]])) {
            return $overrides[$id];
        }
        return self::baseCategory($moduleClass);
    }

    /**
     * Display label for a category slug.
     *
     * Unknown slugs fall back to a humanized slug, so a label
     * is never empty even for a slug a filter removed.
     *
     * @param string $slug Category slug.
     * @return string
     */
    public static function categoryLabel(string $slug): string
    {
        $categories = self::filteredCategories();
        if (isset($categories[$slug])) {
            return $categories[$slug]['label'];
        }
        return ucwords(str_replace('-', ' ', sanitize_key($slug)));
    }

    /**
     * Full category list for the modules-page filter UI.
     *
     * Defaults first (in definition order), then any
     * filter-added categories, then custom categories, each
     * with its live module count and a `custom` flag so the UI
     * can show management affordances only for admin-created
     * categories. Categories with zero modules are still
     * listed (an empty custom category is waiting for an
     * assignment, not an error).
     *
     * @return array<int, array{slug: string, label: string, icon: string, count: int, custom: bool}>
     */
    public static function categories(): array
    {
        $filtered = self::filteredCategories();
        $customSlugs = array_keys(self::customCategories());
        $counts = [];
        foreach ($filtered as $slug => $entry) {
            $counts[$slug] = 0;
        }
        foreach (self::all() as $class) {
            $category = self::categoryOf($class);
            if (isset($counts[$category])) {
                $counts[$category]++;
            }
        }
        $list = [];
        foreach ($filtered as $slug => $entry) {
            $list[] = [
                'slug' => $slug,
                'label' => $entry['label'],
                'icon' => $entry['icon'],
                'count' => $counts[$slug],
                'custom' => in_array($slug, $customSlugs, true),
            ];
        }
        return $list;
    }

    /**
     * Persist the custom category list (autoload 'no').
     *
     * @param array<string, array{label: string, icon: string}> $custom Slug => entry.
     * @return bool
     */
    private static function saveCustomCategories(array $custom): bool
    {
        $stored = [];
        foreach ($custom as $slug => $entry) {
            $stored[] = [
                'slug' => sanitize_key((string) $slug),
                'label' => sanitize_text_field((string) ($entry['label'] ?? '')),
                'icon' => sanitize_key((string) ($entry['icon'] ?? '')),
            ];
        }
        self::invalidateCategoryCache();
        if (get_option(self::CATEGORIES_OPTION, null) === false) {
            return add_option(self::CATEGORIES_OPTION, $stored, '', false);
        }
        return update_option(self::CATEGORIES_OPTION, $stored);
    }

    /**
     * Persist the per-module override map (autoload 'no').
     *
     * @param array<string, string> $overrides Module id => category slug.
     * @return bool
     */
    private static function saveCategoryOverrides(array $overrides): bool
    {
        self::invalidateCategoryCache();
        if (get_option(self::CATEGORY_OVERRIDES_OPTION, null) === false) {
            return add_option(self::CATEGORY_OVERRIDES_OPTION, $overrides, '', false);
        }
        return update_option(self::CATEGORY_OVERRIDES_OPTION, $overrides);
    }

    /**
     * Create an admin-managed custom category.
     *
     * The slug must not collide with a default category slug
     * or an existing custom slug, and the icon must be a known
     * Material icon slug or '' (stored as 'extension').
     *
     * @param string $slug Sanitized category slug.
     * @param string $label Category label.
     * @param string $icon Material icon slug, or ''.
     * @return bool False when the slug collides or the input is invalid.
     */
    public static function createCustomCategory(string $slug, string $label, string $icon): bool
    {
        $slug = sanitize_key($slug);
        $label = sanitize_text_field($label);
        $icon = sanitize_key($icon);
        if ($slug === '' || $label === '') {
            return false;
        }
        if (!self::isValidCategoryIcon($icon)) {
            return false;
        }
        if ($icon === '') {
            $icon = 'extension';
        }
        $defaults = self::defaultCategories();
        $custom = self::customCategories();
        if (isset($defaults[$slug]) || isset($custom[$slug])) {
            return false;
        }
        $custom[$slug] = ['label' => $label, 'icon' => $icon];
        return self::saveCustomCategories($custom);
    }

    /**
     * Rename (relabel / re-icon) an admin-managed category.
     *
     * The slug is immutable: renaming a slug would break the
     * stored overrides pointing at it. Built-in categories
     * cannot be renamed here - their labels live in
     * defaultCategories().
     *
     * @param string $slug Custom category slug.
     * @param string $label New label.
     * @param string $icon New Material icon slug, or ''.
     * @return bool False when the slug is not a custom category or the input is invalid.
     */
    public static function renameCustomCategory(string $slug, string $label, string $icon): bool
    {
        $slug = sanitize_key($slug);
        $label = sanitize_text_field($label);
        $icon = sanitize_key($icon);
        if ($slug === '' || $label === '') {
            return false;
        }
        if (!self::isValidCategoryIcon($icon)) {
            return false;
        }
        if ($icon === '') {
            $icon = 'extension';
        }
        $custom = self::customCategories();
        if (!isset($custom[$slug])) {
            return false;
        }
        $custom[$slug] = ['label' => $label, 'icon' => $icon];
        return self::saveCustomCategories($custom);
    }

    /**
     * Delete an admin-managed custom category.
     *
     * Only custom categories can be deleted. Every module
     * assigned to the deleted category has its override
     * removed server-side, so it falls back to its base
     * category (declared category() / default map) - a delete
     * never orphans a module.
     *
     * @param string $slug Custom category slug.
     * @return int Number of modules moved back to their base categories, or -1 when the slug is not a custom category.
     */
    public static function deleteCustomCategory(string $slug): int
    {
        $slug = sanitize_key($slug);
        $custom = self::customCategories();
        if (!isset($custom[$slug])) {
            return -1;
        }
        unset($custom[$slug]);
        $overrides = self::categoryOverrides();
        $moved = 0;
        foreach ($overrides as $id => $override) {
            if ($override === $slug) {
                unset($overrides[$id]);
                $moved++;
            }
        }
        if (!self::saveCustomCategories($custom)) {
            return -1;
        }
        self::saveCategoryOverrides($overrides);
        return $moved;
    }

    /**
     * Assign one module to a category.
     *
     * The category must exist in the final list (default,
     * custom, or filter-added). Assigning the module's own
     * base category removes any stored override instead of
     * writing a redundant one, so the overrides option only
     * ever holds real deviations.
     *
     * @param string $id Module id.
     * @param string $slug Target category slug.
     * @return bool False when the module or the category is unknown.
     */
    public static function assignModuleToCategory(string $id, string $slug): bool
    {
        $id = sanitize_key($id);
        $slug = sanitize_key($slug);
        $class = self::get($id);
        if ($class === null) {
            return false;
        }
        $categories = self::filteredCategories();
        if (!isset($categories[$slug])) {
            return false;
        }
        $overrides = self::categoryOverrides();
        if ($slug === self::baseCategory($class)) {
            unset($overrides[$id]);
        } else {
            $overrides[$id] = $slug;
        }
        return self::saveCategoryOverrides($overrides);
    }

    /**
     * The module list as the modules page and the list AJAX
     * endpoint both serve it: one entry per module plus the
     * full category list with counts.
     *
     * This is the single source of truth for the list JSON -
     * templates/admin/modules-page.php renders it into the
     * data-modules / data-categories attributes and
     * AdminAjax::listModules() returns it as JSON - so the
     * first render and every refresh after a category change
     * agree. Every existing field is kept; each entry gains
     * `category` (resolved slug) and `category_label`.
     *
     * Each entry also carries `description_short`: the
     * description truncated server-side to 20 words, which is
     * what the cards and rows display - the truncation happens
     * in PHP, never in the browser. The full `description` is
     * kept alongside it for search (the list search matches
     * against the full text) and for the module info popup,
     * which shows the untruncated copy. The 3-line CSS clamp
     * stays as a visual safety net for narrow viewports only.
     *
     * Status is one exclusive bucket, in this order: not
     * available, core, needs setup (only when the module is
     * enabled), enabled, disabled.
     *
     * @return array{modules: array<int, array<string, mixed>>, categories: array<int, array{slug: string, label: string, icon: string, count: int, custom: bool}>}
     */
    public static function moduleListData(): array
    {
        $modules = [];
        foreach (self::all() as $moduleClass) {
            $id = $moduleClass::id();
            $available = $moduleClass::isAvailable();
            $configured = $moduleClass::isConfigured();
            $isCore = $moduleClass::isCore();
            $enabled = self::isEnabled($id);
            if (!$available) {
                $status = 'not-available';
                $statusLabel = __('Not available', 'traydigita-core');
                $openUrl = self::moduleUrl($moduleClass);
            } elseif ($isCore) {
                $status = 'core';
                $statusLabel = __('Core module', 'traydigita-core');
                $openUrl = self::moduleUrl($moduleClass);
            } elseif ($enabled && !$configured) {
                // Needs setup is an enabled-module state only: a
                // disabled module that is also unconfigured falls
                // through to Disabled below, so the tab counts
                // match the registry.
                $status = 'needs-setup';
                $statusLabel = __('Needs setup', 'traydigita-core');
                $openUrl = self::moduleUrl($moduleClass);
            } elseif ($enabled) {
                $status = 'enabled';
                $statusLabel = __('Enabled', 'traydigita-core');
                $openUrl = self::moduleUrl($moduleClass);
            } else {
                $status = 'disabled';
                $statusLabel = __('Disabled', 'traydigita-core');
                $openUrl = self::moduleUrl($moduleClass);
            }
            $category = self::categoryOf($moduleClass);
            $isRecommended = self::isRecommended($moduleClass);
            $modules[] = [
                'id' => $id,
                'name' => $moduleClass::name(),
                'description' => $moduleClass::description(),
                'description_short' => wp_trim_words($moduleClass::description(), 20, '…'),
                'version' => $moduleClass::version(),
                'icon' => $moduleClass::icon(),
                'author' => self::author($moduleClass),
                'status' => $status,
                'statusLabel' => $statusLabel,
                'url' => $openUrl,
                'enabled' => $enabled,
                'available' => $available,
                'core' => $isCore,
                'built_in' => self::isBuiltIn($moduleClass),
                'recommended' => $isRecommended,
                'push' => false,
                'note' => $isCore ? __('Core module', 'traydigita-core') : ($isRecommended ? __('Recommended', 'traydigita-core') : ''),
                'category' => $category,
                'category_label' => self::categoryLabel($category),
                'info' => self::moduleInfoData($moduleClass),
            ];
        }
        return ['modules' => $modules, 'categories' => self::categories()];
    }

    /**
     * Admin URL of a module's open target.
     *
     * AdminMenu::moduleUrl() is the normal path; when the
     * AdminMenu class is not loaded (for example a bare AJAX
     * context) the target is built directly from the
     * registry's openTarget() instead, so the list JSON never
     * fatals for a missing class.
     *
     * @param class-string<ModuleInterface> $moduleClass Module class.
     * @return string
     */
    private static function moduleUrl(string $moduleClass): string
    {
        if (class_exists(AdminMenu::class)) {
            return AdminMenu::moduleUrl($moduleClass);
        }
        return admin_url(self::openTarget($moduleClass));
    }

    /**
     * Boot every enabled and available module.
     *
     * Only modules that are enabled AND available get boot() called,
     * so a disabled module registers no hooks and enqueues nothing.
     * Core modules are always enabled (see enabled()), so they boot
     * unconditionally - unless they are unavailable. Missing classes
     * are skipped (see all()).
     *
     * Iteration is over idMap() in card order (the DEFAULT_MAP
     * order), so boot order matches the old all() order - the
     * Table of Contents filter still registers before the Ads
     * filter - while only enabled classes are ever loaded.
     *
     * The Contact Form fold migration runs first
     * (FormBuilderModule::migrateEnabledFromContactForm()): it must
     * not live in FormBuilderModule::boot(), which only runs when
     * FormBuilder is already enabled - a site with 'contact-form'
     * enabled but 'form-builder' disabled would never migrate.
     * Running it here, before enabled() is read, also lets the
     * newly enabled FormBuilder boot in this same request.
     *
     * @return void
     */
    public static function bootEnabled(): void
    {
        ContributorSplitMigration::migrate();
        FormBuilderModule::migrateEnabledFromContactForm();
        $enabled = self::enabled();
        foreach (array_keys(self::idMap()) as $id) {
            if (!in_array($id, $enabled, true)) {
                continue;
            }
            $resolved = self::get($id);
            if ($resolved === null || !$resolved::isAvailable()) {
                continue;
            }
            $resolved::boot();
        }
    }
}
