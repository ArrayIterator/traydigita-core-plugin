<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\DynamicSidebars;

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function absint;
use function add_action;
use function admin_url;
use function apply_filters;
use function array_map;
use function count;
use function current_user_can;
use function esc_html__;
use function file_exists;
use function get_categories;
use function get_option;
use function get_post;
use function get_queried_object;
use function get_tags;
use function get_term;
use function get_the_category;
use function get_the_tags;
use function get_the_title;
use function get_user_by;
use function get_users;
use function in_array;
use function is_array;
use function is_string;
use function is_wp_error;
use function mb_strlen;
use function mb_substr;
use function register_sidebar;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sanitize_title;
use function str_starts_with;
use function update_option;
use function usort;
use function wp_get_sidebars_widgets;
use function wp_unslash;

/**
 * Dynamic Sidebars module.
 *
 * Admins create custom widget areas ("sidebars") and attach rules
 * to them. A rule picks WHEN a custom sidebar replaces one of the
 * theme's default areas: the rule names the default area it can
 * replace (`traydigita-home-left`, `traydigita-home-right`, or
 * `traydigita-sidebar-article`) and optional conditions — category,
 * tag, author, or a specific post/page — which must ALL match the
 * current request. Rules are ordered by priority (lower wins);
 * the first matching rule wins and anything else falls back to
 * the theme's default area for that spot.
 *
 * Why the area IS the context: the theme renders a sidebar in
 * exactly three places — the two homepage columns
 * (`templates/sections/sidebar-left.php` /
 * `sidebar-right.php`) and the single-post/page rail
 * (`templates/sections/article-sidebar.php`). Archive, search,
 * and 404 templates render no sidebar at all (the archive is a
 * full-width news list), so no rule can target them; the
 * extension filter below lets a builder add a call site later.
 * Conditions only apply to the article sidebar: the homepage has
 * no current post, so a rule for a home area always has its
 * conditions forced to "any" on save.
 *
 * Registration: custom sidebars are registered with
 * register_sidebar() on `widgets_init` (id
 * `traydigita-dynamic-{slug}`), so they appear in Appearance →
 * Widgets like a normal area. Deleting a sidebar removes only
 * its definition from the module option — WordPress keeps the
 * widget instances, which reappear under Inactive Widgets. When
 * the module is disabled, custom sidebars are not registered and
 * templates fall back to their default areas.
 *
 * Extension points (hook prefix from Core::HOOK_PREFIX):
 * - `traydigita:core:dynamic-sidebars/sidebars` — filter the
 *   list of sidebar definitions (stored ones first, then the
 *   filter). A builder adds a sidebar programmatically:
 *
 *       add_filter('traydigita:core:dynamic-sidebars/sidebars', function (array $sidebars): array {
 *           $sidebars[] = [
 *               'id' => 'traydigita-dynamic-press',
 *               'name' => 'Press',
 *               'description' => '',
 *               'area' => 'traydigita-sidebar-article',
 *               'priority' => 5,
 *               'conditions' => ['category' => 0, 'tag' => 0, 'author' => 0, 'post' => 0],
 *           ];
 *           return $sidebars;
 *       });
 *
 * - `traydigita:core:dynamic-sidebars/resolved-area` — filter
 *   the resolved area id, receiving ($area, $defaultArea).
 *
 * Settings live in the single option `traydigita_dynamic_sidebars`
 * (autoload `no`): `sidebars` is a list of sidebar definitions.
 * The admin UI (list + edit form) lives in
 * `templates/admin/modules/dynamic-sidebars.php`; creates, edits,
 * and deletes go through DynamicSidebarsAjax (own AJAX actions),
 * and saveSettings() routes the same operations so the generic
 * dispatcher stays a valid entry point.
 */
final class DynamicSidebarsModule implements ModuleInterface
{
    /**
     * Option name for all dynamic-sidebar data (autoload `no`).
     */
    public const string OPTION = 'traydigita_dynamic_sidebars';

    /**
     * Prefix for every dynamically registered sidebar id.
     */
    public const string SIDEBAR_ID_PREFIX = 'traydigita-dynamic-';

    /**
     * Filter exposing the sidebar definitions to builders.
     */
    public const string FILTER_SIDEBARS = Core::HOOK_PREFIX . ':dynamic-sidebars/sidebars';

    /**
     * Filter exposing the resolved area id to builders.
     */
    public const string FILTER_RESOLVED_AREA = Core::HOOK_PREFIX . ':dynamic-sidebars/resolved-area';

    /**
     * Default areas a custom sidebar may replace, with labels.
     *
     * @var array<string, string>
     */
    public const array REPLACEABLE_AREAS = [
        'traydigita-home-left' => 'Tray Digita: Home Left',
        'traydigita-home-right' => 'Tray Digita: Home Right',
        'traydigita-sidebar-article' => 'Tray Digita: Article Sidebar',
    ];

    /**
     * Areas that have a current post, so conditions can apply.
     * Home areas never have one; their conditions are forced to
     * "any" on save and ignored by the resolver.
     *
     * @var array<int, string>
     */
    public const array CONDITION_AREAS = ['traydigita-sidebar-article'];

    /**
     * Per-request cache of the normalized sidebar list.
     *
     * @var array<int, array{id: string, name: string, description: string, area: string,
     *     priority: int, conditions: array{category: int, tag: int, author: int, post: int}}>|null
     */
    private static ?array $sidebarsCache = null;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'dynamic-sidebars';
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
        return 'design';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Dynamic Sidebars', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Create custom widget areas and show them by rule — per category, tag, author, or post — '
            . 'with the theme defaults as fallback. Rules are ordered by priority; first match wins.',
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
        return 'layout';
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
        return 'traydigita-dynamic-sidebars';
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
     * Configured means at least one custom sidebar exists; with
     * none, every area simply falls back to its default.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::getSidebars() !== [];
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
     * Register the module hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. Custom sidebars register on `widgets_init` (which
     * fires after `init`, so the hook registered here always
     * lands), and the module AJAX endpoints register here as well.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_action('widgets_init', [self::class, 'registerSidebars']);
        DynamicSidebarsAjax::register();
    }

    /**
     * Register every custom sidebar as a widget area.
     *
     * Uses the same wrappers as the fixed areas in Sidebars (a
     * `section` per widget, `h2` title), so widgets render
     * identically wherever the area is shown. Filter-added
     * definitions register here too, through getSidebars().
     *
     * @return void
     */
    public static function registerSidebars(): void
    {
        foreach (self::getSidebars() as $sidebar) {
            register_sidebar([
                'id' => $sidebar['id'],
                'name' => $sidebar['name'],
                'description' => $sidebar['description'],
                'before_widget' => '<section id="%1$s" class="widget %2$s">',
                'after_widget' => '</section>',
                'before_title' => '<h2 class="widget-title">',
                'after_title' => '</h2>',
            ]);
        }
    }

    /**
     * Resolve which sidebar area to render for a default area.
     *
     * Returns the default area unchanged when the module is
     * disabled, when no rule matches, or when the default area is
     * not replaceable. Otherwise returns the first matching custom
     * sidebar id (lowest priority wins; ties keep creation order
     * — usort() is stable since PHP 8.0). The result passes
     * through the resolved-area filter, so a builder can override
     * it. The caller still guards with is_active_sidebar(), so an
     * empty custom sidebar renders no widget markup at all.
     *
     * Cheap by design: one option read per request at most (static
     * cache), no network, no heavy queries — condition checks read
     * only the already-queried post and its terms.
     *
     * @param string $defaultArea Default area id being resolved.
     * @return string Custom sidebar id, or the default area id.
     */
    public static function resolveArea(string $defaultArea): string
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return $defaultArea;
        }
        $area = $defaultArea;
        if (isset(self::REPLACEABLE_AREAS[$defaultArea])) {
            foreach (self::getSidebars() as $sidebar) {
                if ($sidebar['area'] !== $defaultArea) {
                    continue;
                }
                if (!self::conditionsMatch($sidebar['conditions'], $defaultArea)) {
                    continue;
                }
                $area = $sidebar['id'];
                break;
            }
        }
        $filtered = apply_filters(self::FILTER_RESOLVED_AREA, $area, $defaultArea);
        return is_string($filtered) && $filtered !== '' ? $filtered : $defaultArea;
    }

    /**
     * Whether a rule's conditions match the current request.
     *
     * All non-zero conditions must match (AND). A rule with no
     * conditions always matches. Conditions never apply to home
     * areas (no current post there) — such rules are stored with
     * all conditions at zero, and this method treats them as a
     * match without looking at the post. Missing terms, users, or
     * posts simply never match; nothing here can fatal.
     *
     * @param array{category: int, tag: int, author: int, post: int} $conditions Rule conditions.
     * @param string $area Default area being resolved.
     * @return bool
     */
    public static function conditionsMatch(array $conditions, string $area): bool
    {
        $category = (int) ($conditions['category'] ?? 0);
        $tag = (int) ($conditions['tag'] ?? 0);
        $author = (int) ($conditions['author'] ?? 0);
        $postId = (int) ($conditions['post'] ?? 0);
        if ($category === 0 && $tag === 0 && $author === 0 && $postId === 0) {
            return true;
        }
        if (!in_array($area, self::CONDITION_AREAS, true)) {
            return true;
        }
        $post = get_queried_object();
        if (!$post instanceof \WP_Post) {
            $post = get_post();
        }
        if (!$post instanceof \WP_Post) {
            return false;
        }
        if ($postId !== 0 && (int) $post->ID !== $postId) {
            return false;
        }
        if ($author !== 0 && (int) $post->post_author !== $author) {
            return false;
        }
        if ($category !== 0) {
            $categories = get_the_category((int) $post->ID);
            if (!is_array($categories)) {
                return false;
            }
            $found = false;
            foreach ($categories as $term) {
                if ((int) $term->term_id === $category) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        if ($tag !== 0) {
            $tags = get_the_tags((int) $post->ID);
            if (!is_array($tags)) {
                return false;
            }
            $found = false;
            foreach ($tags as $term) {
                if ((int) $term->term_id === $tag) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }

    /**
     * All custom sidebar definitions, normalized and prioritized.
     *
     * Stored definitions come first (creation order), then the
     * sidebars filter runs so builders can add or alter entries.
     * Invalid entries (bad shape, unknown area, empty name) are
     * dropped. Result is cached per request; save/delete clear it.
     *
     * @return array<int, array{id: string, name: string, description: string, area: string,
     *     priority: int, conditions: array{category: int, tag: int, author: int, post: int}}>
     */
    public static function getSidebars(): array
    {
        if (self::$sidebarsCache !== null) {
            return self::$sidebarsCache;
        }
        $stored = get_option(self::OPTION, []);
        $list = [];
        if (is_array($stored) && isset($stored['sidebars']) && is_array($stored['sidebars'])) {
            foreach ($stored['sidebars'] as $raw) {
                $sidebar = self::normalizeSidebar($raw);
                if ($sidebar !== null) {
                    $list[] = $sidebar;
                }
            }
        }
        $filtered = apply_filters(self::FILTER_SIDEBARS, $list);
        if (is_array($filtered)) {
            $list = [];
            foreach ($filtered as $raw) {
                $sidebar = self::normalizeSidebar($raw);
                if ($sidebar !== null) {
                    $list[] = $sidebar;
                }
            }
        }
        usort($list, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        self::$sidebarsCache = $list;
        return $list;
    }

    /**
     * Find one sidebar definition by id, or null.
     *
     * @param string $id Sidebar id.
     * @return array{id: string, name: string, description: string, area: string,
     *     priority: int, conditions: array{category: int, tag: int, author: int, post: int}}|null
     */
    public static function getSidebar(string $id): ?array
    {
        foreach (self::getSidebars() as $sidebar) {
            if ($sidebar['id'] === $id) {
                return $sidebar;
            }
        }
        return null;
    }

    /**
     * Normalize one raw sidebar definition, or null when invalid.
     *
     * Names are capped (120 chars) and descriptions (500 chars);
     * the area must be replaceable; priority is clamped to 1–999;
     * conditions are integer ids (0 = any). Home-area rules get
     * their conditions forced to zero (no current post there).
     *
     * @param mixed $raw Raw definition.
     * @return array{id: string, name: string, description: string, area: string,
     *     priority: int, conditions: array{category: int, tag: int, author: int, post: int}}|null
     */
    private static function normalizeSidebar(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $id = isset($raw['id']) && is_string($raw['id']) ? sanitize_key($raw['id']) : '';
        if ($id === '' || !str_starts_with($id, self::SIDEBAR_ID_PREFIX)) {
            return null;
        }
        $name = isset($raw['name']) && is_string($raw['name']) ? sanitize_text_field($raw['name']) : '';
        if ($name === '') {
            return null;
        }
        $area = isset($raw['area']) && is_string($raw['area']) ? $raw['area'] : '';
        if (!isset(self::REPLACEABLE_AREAS[$area])) {
            return null;
        }
        $description = isset($raw['description']) && is_string($raw['description'])
            ? sanitize_textarea_field($raw['description'])
            : '';
        $priority = absint($raw['priority'] ?? 10);
        if ($priority < 1 || $priority > 999) {
            $priority = 10;
        }
        $conditions = self::normalizeConditions($raw['conditions'] ?? []);
        if (!in_array($area, self::CONDITION_AREAS, true)) {
            $conditions = ['category' => 0, 'tag' => 0, 'author' => 0, 'post' => 0];
        }
        return [
            'id' => $id,
            'name' => mb_strlen($name) > 120 ? (string) mb_substr($name, 0, 120) : $name,
            'description' => mb_strlen($description) > 500 ? (string) mb_substr($description, 0, 500) : $description,
            'area' => $area,
            'priority' => $priority,
            'conditions' => $conditions,
        ];
    }

    /**
     * Normalize a raw conditions array to integer ids.
     *
     * @param mixed $raw Raw conditions.
     * @return array{category: int, tag: int, author: int, post: int}
     */
    private static function normalizeConditions(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }
        return [
            'category' => absint($raw['category'] ?? 0),
            'tag' => absint($raw['tag'] ?? 0),
            'author' => absint($raw['author'] ?? 0),
            'post' => absint($raw['post'] ?? 0),
        ];
    }

    /**
     * Create or update one sidebar from form data.
     *
     * Expected keys: `sidebar_id` (existing id when editing, empty
     * when creating), `name` (required), `description`, `area`
     * (allowlisted), `priority`, and `category`, `tag`, `author`,
     * `post` (ids, 0 = any). Referenced terms, users, and posts are
     * validated to exist; unknown ids are field errors. The sidebar
     * id is generated once at creation (`traydigita-dynamic-{slug}`,
     * made unique) and never changes on edit, so widgets assigned
     * to it stay put when it is renamed.
     *
     * @param array<string, mixed> $data Raw form data.
     * @return array{success: bool, message: string, errors?: array<string, string>, id?: string}
     */
    public static function saveSidebar(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change sidebar settings.', 'traydigita-core'),
            ];
        }
        $name = isset($data['name']) && is_string($data['name']) ? sanitize_text_field($data['name']) : '';
        $description = isset($data['description']) && is_string($data['description'])
            ? sanitize_textarea_field($data['description'])
            : '';
        $area = isset($data['area']) && is_string($data['area']) ? $data['area'] : '';
        $priority = absint($data['priority'] ?? 10);
        $conditions = self::normalizeConditions([
            'category' => $data['category'] ?? 0,
            'tag' => $data['tag'] ?? 0,
            'author' => $data['author'] ?? 0,
            'post' => $data['post'] ?? 0,
        ]);
        $errors = [];
        if ($name === '') {
            $errors['name'] = __('Enter a name for the sidebar.', 'traydigita-core');
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = __('The name must be at most 120 characters.', 'traydigita-core');
        }
        if (mb_strlen($description) > 500) {
            $errors['description'] = __('The description must be at most 500 characters.', 'traydigita-core');
        }
        if (!isset(self::REPLACEABLE_AREAS[$area])) {
            $errors['area'] = __('Choose which default area this sidebar replaces.', 'traydigita-core');
        }
        if ($priority < 1 || $priority > 999) {
            $errors['priority'] = __('Priority must be between 1 and 999.', 'traydigita-core');
        }
        if ($conditions['category'] !== 0) {
            $term = get_term($conditions['category'], 'category');
            if ($term === null || is_wp_error($term)) {
                $errors['category'] = __('That category no longer exists.', 'traydigita-core');
            }
        }
        if ($conditions['tag'] !== 0) {
            $term = get_term($conditions['tag'], 'post_tag');
            if ($term === null || is_wp_error($term)) {
                $errors['tag'] = __('That tag no longer exists.', 'traydigita-core');
            }
        }
        if ($conditions['author'] !== 0 && get_user_by('id', $conditions['author']) === false) {
            $errors['author'] = __('That author no longer exists.', 'traydigita-core');
        }
        if ($conditions['post'] !== 0 && get_post($conditions['post']) === null) {
            $errors['post'] = __('That post or page no longer exists.', 'traydigita-core');
        }
        if ($errors !== []) {
            return [
                'success' => false,
                'message' => __('The sidebar could not be saved. Please check the form.', 'traydigita-core'),
                'errors' => $errors,
            ];
        }
        if (!in_array($area, self::CONDITION_AREAS, true)) {
            $conditions = ['category' => 0, 'tag' => 0, 'author' => 0, 'post' => 0];
        }
        $stored = get_option(self::OPTION, []);
        $list = is_array($stored) && isset($stored['sidebars']) && is_array($stored['sidebars'])
            ? $stored['sidebars']
            : [];
        $existing = [];
        foreach ($list as $raw) {
            $sidebar = self::normalizeSidebar($raw);
            if ($sidebar !== null) {
                $existing[] = $sidebar;
            }
        }
        $sidebarId = isset($data['sidebar_id']) && is_string($data['sidebar_id'])
            ? sanitize_key($data['sidebar_id'])
            : '';
        $isEdit = false;
        if ($sidebarId !== '') {
            foreach ($existing as $sidebar) {
                if ($sidebar['id'] === $sidebarId) {
                    $isEdit = true;
                    break;
                }
            }
            if (!$isEdit) {
                return [
                    'success' => false,
                    'message' => __('That sidebar no longer exists.', 'traydigita-core'),
                ];
            }
        } else {
            $sidebarId = self::uniqueSidebarId($name, $existing);
        }
        $record = [
            'id' => $sidebarId,
            'name' => $name,
            'description' => $description,
            'area' => $area,
            'priority' => $priority,
            'conditions' => $conditions,
        ];
        $updated = false;
        foreach ($existing as $index => $sidebar) {
            if ($sidebar['id'] === $sidebarId) {
                $existing[$index] = $record;
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            $existing[] = $record;
        }
        update_option(self::OPTION, ['sidebars' => $existing], false);
        self::$sidebarsCache = null;
        return [
            'success' => true,
            'message' => __('Sidebar saved.', 'traydigita-core'),
            'id' => $sidebarId,
        ];
    }

    /**
     * Delete one sidebar definition by id.
     *
     * Only the definition is removed. WordPress keeps the widget
     * instances assigned to it; they reappear under Inactive
     * Widgets in Appearance → Widgets.
     *
     * @param string $id Sidebar id.
     * @return array{success: bool, message: string}
     */
    public static function deleteSidebar(string $id): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change sidebar settings.', 'traydigita-core'),
            ];
        }
        $id = sanitize_key($id);
        $stored = get_option(self::OPTION, []);
        $list = is_array($stored) && isset($stored['sidebars']) && is_array($stored['sidebars'])
            ? $stored['sidebars']
            : [];
        $kept = [];
        $found = false;
        foreach ($list as $raw) {
            $sidebar = self::normalizeSidebar($raw);
            if ($sidebar === null) {
                continue;
            }
            if ($sidebar['id'] === $id) {
                $found = true;
                continue;
            }
            $kept[] = $sidebar;
        }
        if (!$found) {
            return [
                'success' => false,
                'message' => __('That sidebar no longer exists.', 'traydigita-core'),
            ];
        }
        update_option(self::OPTION, ['sidebars' => $kept], false);
        self::$sidebarsCache = null;
        return [
            'success' => true,
            'message' => __('Sidebar deleted. Its widgets are kept under Inactive Widgets.', 'traydigita-core'),
        ];
    }

    /**
     * Generate a unique sidebar id from a name.
     *
     * Slug comes from the name; a numeric suffix is appended on
     * collision. The id never changes afterwards, so widgets stay
     * assigned when the sidebar is renamed.
     *
     * @param string $name Sidebar name.
     * @param array<int, array{id: string, name: string, description: string, area: string,
     *     priority: int, conditions: array{category: int, tag: int, author: int, post: int}}> $existing
     *     Existing sidebars.
     * @return string
     */
    private static function uniqueSidebarId(string $name, array $existing): string
    {
        $slug = sanitize_title($name);
        if ($slug === '') {
            $slug = 'sidebar';
        }
        $taken = [];
        foreach ($existing as $sidebar) {
            $taken[] = $sidebar['id'];
        }
        $candidate = self::SIDEBAR_ID_PREFIX . $slug;
        if (!in_array($candidate, $taken, true)) {
            return $candidate;
        }
        $suffix = 2;
        while (in_array(self::SIDEBAR_ID_PREFIX . $slug . '-' . $suffix, $taken, true)) {
            $suffix++;
        }
        return self::SIDEBAR_ID_PREFIX . $slug . '-' . $suffix;
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'add', 'label' => __('Add sidebar', 'traydigita-core'), 'icon' => 'plus-alt'],
            ['id' => 'sidebars', 'label' => __('Sidebars', 'traydigita-core'), 'icon' => 'layout'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Lists the custom sidebars with edit/delete actions, or shows
     * the add/edit form when `?edit=<id>` (or `?edit=new`) is
     * present. Variables are consumed by
     * `templates/admin/modules/dynamic-sidebars.php` in the same
     * scope (see its header comment for the full list).
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
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $dsSidebars = self::getSidebars();
        $dsEditId = isset($_GET['edit']) && is_string($_GET['edit']) ? sanitize_key(wp_unslash($_GET['edit'])) : '';
        $dsEditSidebar = null;
        $dsIsNew = false;
        $dsInvalidEdit = false;
        if ($dsEditId === 'new') {
            $dsIsNew = true;
            $dsEditSidebar = [
                'id' => '',
                'name' => '',
                'description' => '',
                'area' => 'traydigita-sidebar-article',
                'priority' => 10,
                'conditions' => ['category' => 0, 'tag' => 0, 'author' => 0, 'post' => 0],
            ];
        } elseif ($dsEditId !== '') {
            $dsEditSidebar = self::getSidebar($dsEditId);
            if ($dsEditSidebar === null) {
                $dsInvalidEdit = true;
            }
        }
        $dsCategories = get_categories(['hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        $dsTags = get_tags(['hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        $dsAuthors = get_users(['capability' => 'edit_posts', 'fields' => ['ID', 'display_name']]);
        $dsPostTitle = '';
        if ($dsEditSidebar !== null && $dsEditSidebar['conditions']['post'] !== 0) {
            $title = get_the_title($dsEditSidebar['conditions']['post']);
            $dsPostTitle = is_string($title) ? $title : '';
        }
        $dsWidgetCounts = [];
        $assigned = wp_get_sidebars_widgets();
        if (is_array($assigned)) {
            foreach ($dsSidebars as $sidebar) {
                $widgets = $assigned[$sidebar['id']] ?? [];
                $dsWidgetCounts[$sidebar['id']] = is_array($widgets) ? count($widgets) : 0;
            }
        }
        $dsAreas = array_map(static fn (string $label): string => __($label, 'traydigita-core'), self::REPLACEABLE_AREAS);
        $dsConditionAreas = self::CONDITION_AREAS;
        $dsSaveAction = DynamicSidebarsAjax::AJAX_SAVE;
        $dsDeleteAction = DynamicSidebarsAjax::AJAX_DELETE;
        $dsNonceAction = AdminAjax::NONCE_ACTION;
        $dsBaseUrl = admin_url('admin.php?page=traydigita-dynamic-sidebars');
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/dynamic-sidebars.php';
        if (!file_exists($file)) {
            return;
        }
        require $file;
    }

    /**
     * Save the module settings from already deep-sanitized data.
     *
     * The module has no flat settings of its own — the sidebar
     * list is managed through DynamicSidebarsAjax — but the
     * generic dispatcher can still reach here, so the same
     * operations are routed by the `ds_action` field (`save` /
     * `delete`). Unknown actions are rejected.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change sidebar settings.', 'traydigita-core'),
            ];
        }
        $action = isset($data['ds_action']) && is_string($data['ds_action']) ? $data['ds_action'] : '';
        if ($action === 'delete') {
            $id = isset($data['sidebar_id']) && is_string($data['sidebar_id']) ? $data['sidebar_id'] : '';
            return self::deleteSidebar($id);
        }
        if ($action === 'save') {
            $result = self::saveSidebar($data);
            $response = [
                'success' => $result['success'],
                'message' => $result['message'],
            ];
            if (isset($result['errors'])) {
                $response['errors'] = $result['errors'];
            }
            if (isset($result['id'])) {
                $response['data'] = ['id' => $result['id']];
            }
            return $response;
        }
        return [
            'success' => false,
            'message' => __('Unknown sidebar action.', 'traydigita-core'),
        ];
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * The module stores only the sidebar list; there are no other
     * settings. Exposed for the admin shell and for tests.
     *
     * @return array{sidebars: array<int, array{id: string, name: string, description: string, area: string,
     *     priority: int, conditions: array{category: int, tag: int, author: int, post: int}}>}
     */
    public static function getSettings(): array
    {
        return ['sidebars' => self::getSidebars()];
    }

    /**
     * Default settings: no custom sidebars.
     *
     * @return array{sidebars: array{}}
     */
    public static function getDefaults(): array
    {
        return ['sidebars' => []];
    }
}
