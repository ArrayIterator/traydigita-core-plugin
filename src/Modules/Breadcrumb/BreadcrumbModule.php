<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Breadcrumb;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Seo\SeoDetector;
use TrayDigita\WP\Plugin\Core\Modules\Seo\SeoModule;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function add_action;
use function add_filter;
use function array_reverse;
use function checked;
use function class_exists;
use function count;
use function current_user_can;
use function esc_html_e;
use function get_ancestors;
use function get_category;
use function get_category_link;
use function get_option;
use function get_permalink;
use function get_post;
use function get_post_ancestors;
use function get_post_type_archive_link;
use function get_post_type_object;
use function get_theme_mod;
use function get_the_category;
use function get_the_title;
use function home_url;
use function in_array;
use function is_admin;
use function is_array;
use function is_feed;
use function is_singular;
use function is_string;
use function is_wp_error;
use function ob_get_clean;
use function ob_start;
use function selected;
use function set_theme_mod;
use function sprintf;
use function update_option;
use function wp_json_encode;
use function wp_nonce_field;
use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
use const JSON_UNESCAPED_UNICODE;

/**
 * Breadcrumb module.
 *
 * Visible trail (Home / Category / Post title) with a matching
 * BreadcrumbList JSON-LD block, modelled on the verified
 * padangkita.com reference: "Beranda › Berita Utama › [full
 * article title]" above the headline, the first two items
 * linked. The category parent chain is respected (Home /
 * Parent / Child / Post), and pages use their ancestor chain.
 *
 * Position is ONE setting, exposed in both the module page and
 * the Customizer: `above-title` (default, directly above the
 * entry header — the reference placement) or `below-header`
 * (directly below the entry header). The module option
 * `traydigita_breadcrumb` is the source of truth; the
 * Customizer control writes the same value through the
 * `traydigita:core:customizer/definitions` filter, and the two
 * stores are kept in sync (see `syncPositionFromCustomizer()`
 * and `saveSettings()`), so the setting never drifts between
 * the two surfaces.
 *
 * Structured data is emitted only when no SEO plugin provides
 * it: the check reuses `SeoDetector` (never re-implemented),
 * and the theme's own SEO module is also respected — when the
 * SEO module is enabled with its breadcrumb output on, it owns
 * the JSON-LD and this module stays silent, so the page never
 * carries two BreadcrumbList blocks.
 *
 * Rendering: `render()` returns the trail HTML for the current
 * post by capturing `templates/sections/breadcrumb.php`
 * through the theme templating loader. `boot()` hooks the
 * theme's own `entry_header:before` / `entry_header:after`
 * hooks (fired by `templates/sections/entry-header.php`
 * around the entry header layout), so no entry template has to
 * be edited. A coordinator that prefers explicit placement can
 * call `BreadcrumbModule::render()` directly instead — e.g. at
 * the top of `layouts/entry/header.php`, before the `<h1>` —
 * and must then not rely on the boot() hooks, or the trail
 * will appear twice.
 *
 * Settings live in the single option `traydigita_breadcrumb`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type.
 */
final class BreadcrumbModule implements ModuleInterface
{
    /**
     * Option name for all breadcrumb settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_breadcrumb';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_breadcrumb_save';

    /**
     * Customizer setting id for the position (a theme_mod).
     */
    public const string CUSTOMIZER_SETTING_ID = 'breadcrumb_position';

    /**
     * Allowed positions for the trail.
     *
     * @var array<int, string>
     */
    public const array POSITIONS = ['above-title', 'below-header'];

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'breadcrumb';
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
        return __('Breadcrumb', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A Home / Category / Post trail above the headline, with matching BreadcrumbList structured data — emitted only when no SEO plugin already provides it.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'admin-home';
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
    public static function settingsPageSlug(): string
    {
        return 'traydigita-breadcrumb';
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
     * Configured means the trail is shown on posts or pages (or
     * both). With both toggles off the module renders nothing
     * on the frontend.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        return $settings['show_on_posts'] || $settings['show_on_pages'];
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
     * Shows a compatibility note when an SEO plugin (or the
     * theme's own SEO module) already provides breadcrumb
     * structured data, then the form: position select (the same
     * setting the Customizer exposes), show-on-posts/pages
     * toggles, and a structured-data toggle. The form is
     * submitted through the generic module-settings AJAX
     * dispatcher (see `data-traydigita-module-form`).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        $seoPlugin = SeoDetector::detectActivePlugin();
        $seoModuleProvides = self::seoModuleProvidesBreadcrumbs();
        ?>
        <div class="traydigita-module-settings breadcrumb-settings">
            <?php if ($seoPlugin !== null) : ?>
                <p class="breadcrumb-status">
                    <?php
                    [$breadcrumbShort, $breadcrumbLong] = SettingsControls::splitHelp(
                        sprintf(
                            /* translators: %s is the name of the active SEO plugin, e.g. "Yoast SEO". */
                            __('%s is active, so breadcrumb structured data is handled by %s. The visible trail below is still rendered by this module.', 'traydigita-core'),
                            $seoPlugin['name'],
                            $seoPlugin['name']
                        )
                    );
                    SettingsControls::helpText($breadcrumbShort, $breadcrumbLong);
                    ?>
                </p>
            <?php elseif ($seoModuleProvides) : ?>
                <p class="breadcrumb-status">
                    <?php
                    SettingsControls::helpText(
                        __('The theme’s SEO module already outputs breadcrumb structured data, so this module skips its own JSON-LD.', 'traydigita-core'),
                        __('The visible trail below is still rendered by this module.', 'traydigita-core')
                    );
                    ?>
                </p>
            <?php endif; ?>

            <form class="breadcrumb-settings-form" data-traydigita-module-form data-traydigita-module="breadcrumb" data-module="breadcrumb" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="breadcrumb-field">
                    <label for="breadcrumb-position"><?php esc_html_e('Position', 'traydigita-core'); ?></label>
                    <select id="breadcrumb-position" name="position">
                        <option value="above-title" <?php selected($settings['position'], 'above-title'); ?>><?php esc_html_e('Above the title (default)', 'traydigita-core'); ?></option>
                        <option value="below-header" <?php selected($settings['position'], 'below-header'); ?>><?php esc_html_e('Below the header', 'traydigita-core'); ?></option>
                    </select>
                    <span class="breadcrumb-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Above the title matches padangkita.com: the trail sits directly above the headline.', 'traydigita-core'),
                            __('Below the header puts it directly below the entry header (title, meta, and featured image). The same setting is also available in the Customizer, under Navigation.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="breadcrumb-field">
                    <label class="traydigita-ui-toggle" for="breadcrumb-show-on-posts"><input class="traydigita-ui-toggle-input" type="checkbox" id="breadcrumb-show-on-posts" name="show_on_posts" value="1" <?php checked($settings['show_on_posts']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show on single posts', 'traydigita-core'); ?></span></label>
                </p>

                <p class="breadcrumb-field">
                    <label class="traydigita-ui-toggle" for="breadcrumb-show-on-pages"><input class="traydigita-ui-toggle-input" type="checkbox" id="breadcrumb-show-on-pages" name="show_on_pages" value="1" <?php checked($settings['show_on_pages']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show on pages', 'traydigita-core'); ?></span></label>
                    <span class="breadcrumb-field-help">
                        <?php esc_html_e('Pages use their ancestor chain: Home / Parent page / Current page.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="breadcrumb-field">
                    <label class="traydigita-ui-toggle" for="breadcrumb-json-ld"><input class="traydigita-ui-toggle-input" type="checkbox" id="breadcrumb-json-ld" name="json_ld" value="1" <?php checked($settings['json_ld']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Output BreadcrumbList structured data', 'traydigita-core'); ?></span></label>
                    <span class="breadcrumb-field-help">
                        <?php esc_html_e('Only emitted when no SEO plugin — and not the theme’s own SEO module — already provides breadcrumb structured data.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="breadcrumb-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `position` (allowlisted),
     * `show_on_posts`, `show_on_pages`, `json_ld` (each `1`
     * when checked; an absent key means the checkbox was
     * unchecked). Every value is re-sanitized for its own type;
     * unknown keys are dropped. The position is also written to
     * the Customizer theme_mod so the Customizer control stays
     * in sync with the module page.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change breadcrumb settings.', 'traydigita-core'),
            ];
        }
        $position = isset($data['position']) && is_string($data['position']) ? $data['position'] : '';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'above-title';
        }
        $settings = [
            'position' => $position,
            'show_on_posts' => !empty($data['show_on_posts']),
            'show_on_pages' => !empty($data['show_on_pages']),
            'json_ld' => !empty($data['json_ld']),
        ];
        update_option(self::OPTION, $settings, false);
        set_theme_mod(self::CUSTOMIZER_SETTING_ID, $position);
        return [
            'success' => true,
            'message' => __('Breadcrumb settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. Registers the Customizer definition (so the
     * position setting also appears in the Customizer), the
     * Customizer-save sync, the JSON-LD output on `wp_head`, and
     * the visible trail on the theme's `entry_header:before`
     * (above-title) or `entry_header:after` (below-header) hook.
     * The Customizer control only appears while the module is
     * enabled — when the module is off there is no trail to
     * position.
     *
     * The frontend CSS (`resources/css/components/breadcrumb.css`)
     * is bundled into the theme's app entry by the coordinator;
     * this module registers no separate assets.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_filter('traydigita:core:customizer/definitions', [self::class, 'registerCustomizerDefinitions']);
        add_action('customize_save_after', [self::class, 'syncPositionFromCustomizer']);
        add_action('wp_head', [self::class, 'renderJsonLd'], 22);
        $hooks = Core::hooks();
        if (self::getSettings()['position'] === 'below-header') {
            $hooks->add('entry_header:after', [self::class, 'renderHook'], 10, 1);
        } else {
            $hooks->add('entry_header:before', [self::class, 'renderHook'], 10, 1);
        }
    }

    /**
     * Theme-hook callback that echoes the visible trail.
     *
     * The hook fires for every content render (singular views
     * and archive cards alike); `render()` itself gates on a
     * singular view and the show-on toggles, so archive cards
     * get nothing.
     *
     * @param mixed $args Template arguments passed by the hook (unused).
     * @return void
     */
    public static function renderHook(mixed $args): void
    {
        $html = self::render();
        if ($html !== '') {
            // The trail is already fully escaped by the partial.
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Render the breadcrumb trail for a post, or an empty string when it must not show.
     *
     * The markup comes from `templates/sections/breadcrumb.php`,
     * captured through the theme templating loader so a child
     * theme can override the partial.
     *
     * @param \WP_Post|null $post Post to render for; the current post when null.
     * @return string
     */
    public static function render(?\WP_Post $post = null): string
    {
        $post ??= get_post();
        if (!$post instanceof \WP_Post) {
            return '';
        }
        if (!self::isVisibleForPost($post)) {
            return '';
        }
        ob_start();
        Core::template()->loadSection('breadcrumb', null, Core::template()->getArguments());
        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * Whether the visible trail may appear for this post.
     *
     * Only on singular views: single posts when `show_on_posts`
     * is on, pages (and other singular post types) when
     * `show_on_pages` is on.
     *
     * @param \WP_Post $post Post to check.
     * @return bool
     */
    public static function isVisibleForPost(\WP_Post $post): bool
    {
        if (!is_singular()) {
            return false;
        }
        $settings = self::getSettings();
        if ($post->post_type === 'post') {
            return $settings['show_on_posts'] && is_singular('post');
        }
        if ($post->post_type === 'page') {
            return $settings['show_on_pages'] && is_singular('page');
        }
        return $settings['show_on_posts'] && is_singular($post->post_type);
    }

    /**
     * Breadcrumb trail for a post, from Home to the current page.
     *
     * Posts use their first category with the full parent chain
     * (Home / Parent / Child / Post); pages use their ancestor
     * chain (Home / Parent page / Current page); other post
     * types use the post-type archive when one exists. The last
     * item is always the current page and carries its URL too —
     * the partial renders it as plain text, while the JSON-LD
     * output links it, as schema.org expects.
     *
     * @param \WP_Post $post Post to build the trail for.
     * @return array<int, array{name: string, url: string}>
     */
    public static function buildTrail(\WP_Post $post): array
    {
        $trail = [
            [
                'name' => __('Home', 'traydigita-core'),
                'url' => home_url('/'),
            ],
        ];
        if ($post->post_type === 'post') {
            $categories = get_the_category($post->ID);
            if ($categories !== []) {
                $category = $categories[0];
                $ancestors = array_reverse(get_ancestors($category->term_id, 'category'));
                foreach ($ancestors as $ancestorId) {
                    $ancestor = get_category((int) $ancestorId);
                    if ($ancestor instanceof \WP_Term) {
                        $link = get_category_link($ancestor);
                        if (!is_wp_error($link)) {
                            $trail[] = [
                                'name' => $ancestor->name,
                                'url' => $link,
                            ];
                        }
                    }
                }
                $link = get_category_link($category);
                if (!is_wp_error($link)) {
                    $trail[] = [
                        'name' => $category->name,
                        'url' => $link,
                    ];
                }
            }
        } elseif ($post->post_type === 'page') {
            $ancestors = array_reverse(get_post_ancestors($post));
            foreach ($ancestors as $ancestorId) {
                $link = get_permalink((int) $ancestorId);
                $title = get_the_title((int) $ancestorId);
                if ($link !== false && $title !== '') {
                    $trail[] = [
                        'name' => $title,
                        'url' => $link,
                    ];
                }
            }
        } else {
            $archiveLink = get_post_type_archive_link($post->post_type);
            $postTypeObject = get_post_type_object($post->post_type);
            if ($archiveLink !== false && $postTypeObject !== null) {
                $trail[] = [
                    'name' => $postTypeObject->labels->name,
                    'url' => $archiveLink,
                ];
            }
        }
        $currentUrl = get_permalink($post);
        $currentTitle = get_the_title($post);
        if ($currentUrl !== false && $currentTitle !== '') {
            $trail[] = [
                'name' => $currentTitle,
                'url' => $currentUrl,
            ];
        }
        return $trail;
    }

    /**
     * Render the BreadcrumbList JSON-LD block in `wp_head`.
     *
     * Only when the structured-data toggle is on, the current
     * view is singular, no SEO plugin is active, and the
     * theme's own SEO module is not already providing
     * breadcrumb structured data. Nothing is printed when the
     * trail would be a single item.
     *
     * @return void
     */
    public static function renderJsonLd(): void
    {
        if (is_admin() || is_feed()) {
            return;
        }
        if (!self::getSettings()['json_ld'] || !is_singular()) {
            return;
        }
        if (SeoDetector::hasSeoPlugin() || self::seoModuleProvidesBreadcrumbs()) {
            return;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return;
        }
        $trail = self::buildTrail($post);
        if (count($trail) < 2) {
            return;
        }
        $elements = [];
        foreach ($trail as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }
        $json = wp_json_encode(
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $elements,
            ],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') {
            return;
        }
        echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /**
     * Add the breadcrumb position to the Customizer definitions.
     *
     * Registered through the
     * `traydigita:core:customizer/definitions` filter (never by
     * editing Definitions.php). The setting is a select in the
     * Navigation section, with the same values as the module
     * page. The sanitize callback only validates — the
     * module-option sync happens on `customize_save_after`
     * (see `syncPositionFromCustomizer()`), so merely previewing
     * a value in the Customizer never writes to the database.
     *
     * @param array<int, mixed> $definitions Existing definitions.
     * @return array<int, mixed> Definitions with the position setting appended.
     */
    public static function registerCustomizerDefinitions(array $definitions): array
    {
        $definitions[] = [
            'id' => self::CUSTOMIZER_SETTING_ID,
            'type' => 'select',
            'section' => 'traydigita_navigation',
            'default' => 'above-title',
            'label' => __('Breadcrumb position', 'traydigita-core'),
            'description' => __('Where the breadcrumb trail appears on single posts and pages.', 'traydigita-core'),
            'transport' => 'refresh',
            'choices' => [
                'above-title' => __('Above the title', 'traydigita-core'),
                'below-header' => __('Below the header', 'traydigita-core'),
            ],
            'sanitize_callback' => [self::class, 'sanitizeCustomizerPosition'],
        ];
        return $definitions;
    }

    /**
     * Validate a Customizer position value.
     *
     * Used as the sanitize_callback for the Customizer setting.
     * Unknown values fall back to the default; this method never
     * writes anything — syncing is `syncPositionFromCustomizer()`.
     *
     * @param mixed $value Raw value from the Customizer.
     * @return string A valid position.
     */
    public static function sanitizeCustomizerPosition(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::POSITIONS, true)) {
            return $value;
        }
        return 'above-title';
    }

    /**
     * Sync the module option from the Customizer after a Customizer save.
     *
     * Runs on `customize_save_after`, i.e. only when the user
     * actually saves in the Customizer — not while previewing.
     * Reads the saved theme_mod and stores it in the module
     * option, which is the single source of truth for rendering.
     *
     * @return void
     */
    public static function syncPositionFromCustomizer(): void
    {
        if (!current_user_can('edit_theme_options')) {
            return;
        }
        $position = get_theme_mod(self::CUSTOMIZER_SETTING_ID, 'above-title');
        if (!is_string($position) || !in_array($position, self::POSITIONS, true)) {
            return;
        }
        $settings = self::getSettings();
        if ($settings['position'] === $position) {
            return;
        }
        $settings['position'] = $position;
        update_option(self::OPTION, $settings, false);
    }

    /**
     * Whether the theme's own SEO module already outputs breadcrumb structured data.
     *
     * True only when the SEO module class exists, is enabled,
     * and has its `breadcrumbs` toggle on. When true, this
     * module skips its own JSON-LD so the page never carries
     * two BreadcrumbList blocks.
     *
     * @return bool
     */
    private static function seoModuleProvidesBreadcrumbs(): bool
    {
        if (!class_exists(SeoModule::class)) {
            return false;
        }
        if (!ModuleRegistry::isEnabled('seo')) {
            return false;
        }
        $seoSettings = SeoModule::getSettings();
        return ($seoSettings['breadcrumbs'] ?? false) === true;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{position: string, show_on_posts: bool, show_on_pages: bool, json_ld: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $position = isset($settings['position']) && is_string($settings['position']) ? $settings['position'] : '';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'above-title';
        }
        return [
            'position' => $position,
            'show_on_posts' => (bool) ($settings['show_on_posts'] ?? false),
            'show_on_pages' => (bool) ($settings['show_on_pages'] ?? false),
            'json_ld' => (bool) ($settings['json_ld'] ?? false),
        ];
    }

    /**
     * Default settings.
     *
     * Above the title (the reference placement), shown on posts
     * and pages, structured data on.
     *
     * @return array{position: string, show_on_posts: bool, show_on_pages: bool, json_ld: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'position' => 'above-title',
            'show_on_posts' => true,
            'show_on_pages' => true,
            'json_ld' => true,
        ];
    }
}
