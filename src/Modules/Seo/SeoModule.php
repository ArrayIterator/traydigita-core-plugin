<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use TrayDigita\WP\Plugin\Core\Admin\AdminNotice;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Integrations\IntegrationsModule;
use TrayDigita\WP\Plugin\Core\Modules\SiteKit\SiteKitDetector;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function admin_url;
use function array_merge;
use function current_user_can;
use function date_i18n;
use function delete_post_meta;
use function did_action;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function esc_url;
use function flush_rewrite_rules;
use function get_option;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function is_array;
use function is_string;
use function mb_substr;
use function number_format_i18n;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function trim;
use function update_option;
use function update_post_meta;
use function wp_nonce_field;
use function wp_unslash;
use function wp_verify_nonce;
use WP_Post;

/**
 * SEO module.
 *
 * Compatibility first: when a known SEO plugin (Yoast SEO, Rank
 * Math, All in One SEO, or SEOPress) is active, the theme outputs
 * no duplicate title, meta, canonical, Open Graph, Twitter, or
 * structured-data tags and serves no duplicate sitemap — the
 * plugin is in charge, and the settings page says so with a
 * connector card linking to that plugin's own settings page. When
 * no SEO plugin is active, the theme outputs a minimal, correct
 * set itself: templated meta titles and descriptions, canonical,
 * Open Graph and Twitter cards, robots, NewsArticle JSON-LD on
 * single posts, breadcrumb JSON-LD on singular posts and pages,
 * XML sitemaps (posts, pages, categories) plus the optional Google
 * News sitemap (news-sitemap.xml, posts from the last 48 hours).
 *
 * Instant indexing (both opt-in, OFF by default, and both gated
 * on the same no-SEO-plugin rule): IndexNow (Bing/Yandex) with a
 * key file served from the site root and a ping on publish/update
 * plus a visible last-ping log, and the Google Indexing API with a
 * service-account JSON stored in its own option and used only for
 * Google's endpoint.
 *
 * AI meta suggestions use WordPress core's AI Client API
 * (wp_ai_client_prompt(), since WordPress 7.0) and only run when a
 * real provider supports text generation; otherwise the panel
 * explains honestly that no AI provider is available. Suggestions
 * are per-post and applied explicitly as post-meta overrides.
 *
 * The SEO dashboard shows Search Console data (top queries and
 * top pages, clicks and impressions) through Google Site Kit's
 * own REST datapoint when Site Kit is connected with Search
 * Console active; otherwise it shows a "connect Site Kit" card.
 * The theme injects no tracking of its own.
 *
 * Settings live in the single option `traydigita_seo` (autoload
 * `no`) and are saved through the phase-2 admin AJAX dispatcher,
 * which verifies the nonce and the `manage_options` capability;
 * saveSettings() still checks the capability itself and sanitizes
 * every field. The Google service-account JSON lives in its own
 * option (`traydigita_seo_google_sa`) and is saved through a
 * classic POST form handled inside renderSettingsPage() — the
 * admin shell leaves forms without a data-action marker alone —
 * because the JSON must not pass through the generic dispatcher's
 * sanitize_text_field(), which would corrupt the private key, and
 * because the JSON must never be returned in any response. The AI
 * suggestion forms are classic POST forms for the same reason:
 * the generated suggestion needs a full page render to display.
 *
 * The settings page is rendered inline here (no template partial)
 * with the settings framework (SettingsControls sections +
 * controls), so the module stays self-contained in its own directory.
 * `isConfigured()` means at least one output behaviour is switched
 * on; with everything off the module does nothing on the frontend.
 */
final class SeoModule implements ModuleInterface
{
    /**
     * Option name for all SEO settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_seo';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_seo_save';

    /**
     * Nonce action for the classic POST forms (Google JSON, AI suggestions).
     */
    public const string CLASSIC_NONCE_ACTION = 'traydigita_seo_classic';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'seo';
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
        return 'seo-discovery';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('SEO', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Rank well without fighting your SEO plugin: the theme defers to Yoast, Rank Math, All in One SEO, or SEOPress when one is active, and outputs its own templated meta tags, sitemaps, NewsArticle data, and optional instant indexing otherwise.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'search';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.1.0';
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
            __('Templated meta tags, sitemaps, and NewsArticle structured data.', 'traydigita-core'),
            __('Defers to Yoast, Rank Math, All in One SEO, or SEOPress when one is active.', 'traydigita-core'),
            __('Optional instant indexing.', 'traydigita-core'),
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
                'version' => '1.1.0',
                'changes' => [
                    __('Current version in TrayDigita Core.', 'traydigita-core'),
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
        return 'traydigita-seo';
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
     * Configured means at least one output behaviour is switched
     * on. With every toggle off, the module registers its hooks
     * but emits nothing on the frontend.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        return $settings['meta_output']
            || $settings['json_ld']
            || $settings['breadcrumbs']
            || $settings['news_sitemap']
            || $settings['xml_sitemap']
            || $settings['indexnow_enabled']
            || $settings['google_indexing_enabled'];
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable. A site that
     * prefers its SEO plugin alone can disable the theme's SEO
     * output entirely from the module grid.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether the module appears in the TrayDigita submenu.
     *
     * True: SEO settings are opened often, so the module opts
     * into the data-driven submenu (ModuleRegistry::showInSubmenu()
     * default is false). Its open link is its own settings page
     * (no openLink() override), so the submenu entry is a real
     * submenu entry for that page.
     *
     * @return bool Always true.
     */
    public static function showInSubmenu(): bool
    {
        return true;
    }

    /**
     * Register the SEO plugins entry on the Integrations hub.
     *
     * Registered centrally by IntegrationsModule::registerEntries()
     * (not from boot(), so the entry appears even while this
     * module is disabled). One entry for the whole connector mode,
     * reusing SeoDetector::detectActivePlugin(): 'active' with the
     * detected plugin's name and settings URL when Yoast SEO,
     * Rank Math, All in One SEO or SEOPress is active,
     * 'not-configured' linking to this module's own settings page
     * when none is active (the theme then outputs its own meta
     * tags), 'module-disabled' with a link to the Modules page
     * when this module is disabled.
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the SEO plugins entry appended.
     */
    public static function registerIntegrationEntry(array $entries): array
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            $entries[] = [
                'id' => 'seo-plugins',
                'name' => 'SEO plugins',
                'description' => __(
                    'The SEO module is disabled — enable it in Modules to use SEO plugin connectors.',
                    'traydigita-core'
                ),
                'status' => 'module-disabled',
                'url' => IntegrationsModule::modulesUrl(),
                'module' => self::id(),
                'icon' => 'search',
            ];
            return $entries;
        }
        $detected = SeoDetector::detectActivePlugin();
        if ($detected !== null) {
            $entries[] = [
                'id' => 'seo-plugins',
                'name' => $detected['name'],
                'description' => sprintf(
                    /* translators: %s is the name of the active SEO plugin, e.g. "Rank Math". */
                    __('%s is active — the theme outputs no duplicate meta tags.', 'traydigita-core'),
                    $detected['name']
                ),
                'status' => 'active',
                'url' => $detected['settings_url'],
                'module' => self::id(),
                'icon' => 'search',
            ];
        } else {
            $entries[] = [
                'id' => 'seo-plugins',
                'name' => 'SEO plugins',
                'description' => __(
                    'No SEO plugin is active — the theme outputs its own meta tags, sitemaps and structured data.',
                    'traydigita-core'
                ),
                'status' => 'not-configured',
                'url' => admin_url('admin.php?page=' . self::settingsPageSlug()),
                'module' => self::id(),
                'icon' => 'search',
            ];
        }
        return $entries;
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'search-console', 'label' => __('Search Console dashboard', 'traydigita-core'), 'icon' => 'chart-area'],
            ['id' => 'output', 'label' => __('Native SEO output', 'traydigita-core'), 'icon' => 'search'],
            ['id' => 'templates', 'label' => __('Title and description templates', 'traydigita-core'), 'icon' => 'edit'],
            ['id' => 'indexnow', 'label' => __('IndexNow', 'traydigita-core'), 'icon' => 'update'],
            ['id' => 'google', 'label' => __('Google Indexing API', 'traydigita-core'), 'icon' => 'admin-site'],
            ['id' => 'ai', 'label' => __('AI meta suggestions', 'traydigita-core'), 'icon' => 'lightbulb'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Sections, in order: compatibility status card (with a link to
     * the active SEO plugin's settings when one is in charge), the
     * Search Console dashboard (Site Kit data, or a connect-Site
     * Kit card), the native output settings form (toggles plus
     * title/description templates), the sitemap URLs, the IndexNow
     * panel (key, key-file URL, last-ping log, manual ping form),
     * the Google Indexing panel (service-account JSON classic form
     * plus last-notification log), and the AI suggestions panel
     * (classic forms: generate, apply, remove).
     *
     * Framework (R4-5): the native-output form uses
     * SettingsControls inside framework sections (`td-section-*`,
     * matching settingsSections() above) and ends in the sticky
     * framework save bar; the dashboard and indexing panels are
     * framework sections too. Setting keys, field names, nonces
     * and saveSettings() are unchanged.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            AdminNotice::render('error', __('You are not allowed to do that.', 'traydigita-core'));
            return;
        }
        $classic = self::handleClassicPost();
        $settings = self::getSettings();
        $detected = SeoDetector::detectActivePlugin();
        $isConfigured = self::isConfigured();
        $aiStatus = SeoAi::status();
        ?>
        <div class="traydigita-module-settings seo-settings">
            <?php if ($classic['notice'] !== '') : ?>
                <div class="traydigita-admin-notice <?php echo $classic['error'] ? 'traydigita-admin-notice-error' : 'traydigita-admin-notice-success'; ?>">
                    <p><?php echo esc_html($classic['notice']); ?></p>
                </div>
            <?php endif; ?>

            <div class="seo-status">
                <?php if ($detected !== null) : ?>
                    <p class="seo-status-badge seo-status-plugin">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s is the name of the active SEO plugin, e.g. "Yoast SEO". */
                                __('%s is active — meta tags are handled by %s.', 'traydigita-core'),
                                $detected['name'],
                                $detected['name']
                            )
                        );
                        ?>
                    </p>
                    <p class="seo-status-help">
                        <?php
                        SettingsControls::helpText(
                            __('The theme outputs no duplicate title, meta, canonical, Open Graph, Twitter, or structured-data tags, '
                                . 'and serves no duplicate sitemap, while an SEO plugin is in charge.', 'traydigita-core'),
                            __('The toggles below only take effect when no SEO plugin is active.', 'traydigita-core')
                        );
                        ?>
                    </p>
                    <p>
                        <a class="traydigita-admin-button traydigita-admin-button-primary" href="<?php echo esc_url($detected['settings_url']); ?>">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s is the name of the active SEO plugin, e.g. "Rank Math". */
                                    __('Open %s settings', 'traydigita-core'),
                                    $detected['name']
                                )
                            );
                            ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p class="seo-status-badge seo-status-theme">
                        <?php esc_html_e('No SEO plugin detected — the theme outputs its own meta tags, Open Graph data, and structured data.', 'traydigita-core'); ?>
                    </p>
                <?php endif; ?>
                <?php if (!$isConfigured) : ?>
                    <p class="seo-status-warning">
                        <?php esc_html_e('Every SEO output is switched off. The module is enabled but emits nothing on the frontend.', 'traydigita-core'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php self::renderSearchConsoleSection(); ?>

            <form class="seo-settings-form" data-traydigita-module-form data-traydigita-module="seo" data-module="seo" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <?php SettingsControls::sectionOpen('output', __('Native SEO output', 'traydigita-core')); ?>
                <?php
                SettingsControls::toggle([
                    'id' => 'seo-meta-output',
                    'name' => 'meta_output',
                    'label' => __('Output meta tags', 'traydigita-core'),
                    'checked' => $settings['meta_output'],
                    'help' => __('Templated meta title and description, canonical URL, Open Graph and Twitter card tags, and a robots tag.', 'traydigita-core'),
                    'help_long' => __('Nothing is printed without real data behind it.', 'traydigita-core'),
                ]);
                SettingsControls::toggle([
                    'id' => 'seo-json-ld',
                    'name' => 'json_ld',
                    'label' => __('Output NewsArticle structured data', 'traydigita-core'),
                    'checked' => $settings['json_ld'],
                    'help' => __('NewsArticle JSON-LD on single posts: headline, publish and modification dates, author, publisher with logo, and the featured image.', 'traydigita-core'),
                    'help_long' => __('Each field only when the data exists.', 'traydigita-core'),
                ]);
                SettingsControls::toggle([
                    'id' => 'seo-breadcrumbs',
                    'name' => 'breadcrumbs',
                    'label' => __('Output breadcrumb structured data', 'traydigita-core'),
                    'checked' => $settings['breadcrumbs'],
                    'help' => __('BreadcrumbList JSON-LD on single posts and pages (home, category or page ancestors, then the current page).', 'traydigita-core'),
                    'help_long' => __('Only emitted when no SEO plugin provides breadcrumbs.', 'traydigita-core'),
                ]);
                SettingsControls::toggle([
                    'id' => 'seo-xml-sitemap',
                    'name' => 'xml_sitemap',
                    'label' => __('XML sitemaps (sitemap.xml)', 'traydigita-core'),
                    'checked' => $settings['xml_sitemap'],
                    'help' => __('Sitemap index at /sitemap.xml listing posts, pages, and categories, up to 1000 URLs each.', 'traydigita-core'),
                    'help_long' => __('Posts live at /sitemap-posts.xml, pages at /sitemap-pages.xml, and categories at /sitemap-categories.xml. Only served when no SEO plugin is active.', 'traydigita-core'),
                    'attrs' => $detected !== null ? ['disabled' => true] : [],
                ]);
                SettingsControls::toggle([
                    'id' => 'seo-news-sitemap',
                    'name' => 'news_sitemap',
                    'label' => __('Google News sitemap (news-sitemap.xml)', 'traydigita-core'),
                    'checked' => $settings['news_sitemap'],
                    'help' => __('Lists posts published in the last 48 hours at /news-sitemap.xml.', 'traydigita-core'),
                    'help_long' => __('Only served when no SEO plugin is active.', 'traydigita-core'),
                    'attrs' => $detected !== null ? ['disabled' => true] : [],
                ]);
                if ($detected !== null) {
                    ?>
                    <p class="traydigita-ui-field-help td-settings-help">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s is the name of the active SEO plugin, e.g. "Yoast SEO". */
                                __('Unavailable while %s is active, to avoid serving a '
                                    . 'duplicate news sitemap.', 'traydigita-core'),
                                $detected['name']
                            )
                        );
                        ?>
                    </p>
                    <?php
                }
                SettingsControls::toggle([
                    'id' => 'seo-indexnow-enabled',
                    'name' => 'indexnow_enabled',
                    'label' => __('Instant indexing: IndexNow (Bing, Yandex)', 'traydigita-core'),
                    'checked' => $settings['indexnow_enabled'],
                    'help' => __('Pings IndexNow when a post or page is published or updated.', 'traydigita-core'),
                    'help_long' => __('A key file is served from the site root. Only while no SEO plugin is active.', 'traydigita-core'),
                    'attrs' => $detected !== null ? ['disabled' => true] : [],
                ]);
                SettingsControls::toggle([
                    'id' => 'seo-google-indexing-enabled',
                    'name' => 'google_indexing_enabled',
                    'label' => __('Instant indexing: Google Indexing API', 'traydigita-core'),
                    'checked' => $settings['google_indexing_enabled'],
                    'help' => __('Notifies Google when a post or page is published or updated.', 'traydigita-core'),
                    'help_long' => __('Needs a service-account JSON below. Only while no SEO plugin is active.', 'traydigita-core'),
                    'attrs' => $detected !== null ? ['disabled' => true] : [],
                ]);
                SettingsControls::toggle([
                    'id' => 'seo-ai-enabled',
                    'name' => 'ai_enabled',
                    'label' => __('AI meta suggestions', 'traydigita-core'),
                    'checked' => $settings['ai_enabled'],
                    'help' => __('Shows the AI suggestions panel below.', 'traydigita-core'),
                    'help_long' => __('Suggestions are generated on demand through the WordPress AI Client API and applied explicitly as per-post overrides; they only affect the frontend while no SEO plugin is active.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose(); ?>
                <?php SettingsControls::sectionOpen('templates', __('Title and description templates', 'traydigita-core'), __('Variables: %title% %site% %sitetagline% %sep% %excerpt% %category% %tag% %author% %date% %page%.', 'traydigita-core'), __('A variable with no value for the current page expands to nothing. Applied only when no SEO plugin is active.', 'traydigita-core')); ?>
                <?php
                foreach (self::templateFields() as $field => $label) {
                    SettingsControls::input([
                        'id' => 'seo-' . $field,
                        'name' => $field,
                        'label' => $label,
                        'value' => (string) $settings[$field],
                        'attrs' => ['maxlength' => '200'],
                    ]);
                }
                SettingsControls::input([
                    'id' => 'seo-title-sep',
                    'name' => 'title_sep',
                    'label' => __('Title separator', 'traydigita-core'),
                    'value' => $settings['title_sep'],
                    'attrs' => ['maxlength' => '3'],
                    'input_class' => 'small-text',
                ]);
                SettingsControls::sectionClose();
                ?>

                <?php SettingsControls::saveBar(); ?>
            </form>

            <?php self::renderIndexNowSection($settings, $detected !== null); ?>
            <?php self::renderGoogleSection($settings, $detected !== null); ?>
            <?php self::renderAiSection($settings, $aiStatus, $classic, $detected !== null); ?>
        </div>
        <?php
    }

    /**
     * Template text fields, setting key => label.
     *
     * @return array<string, string>
     */
    private static function templateFields(): array
    {
        return [
            'title_post' => __('Post title template', 'traydigita-core'),
            'title_page' => __('Page title template', 'traydigita-core'),
            'title_home' => __('Home page title template', 'traydigita-core'),
            'title_archive' => __('Archive title template', 'traydigita-core'),
            'description_post' => __('Post description template', 'traydigita-core'),
            'description_home' => __('Home page description template', 'traydigita-core'),
            'description_archive' => __('Archive description template', 'traydigita-core'),
        ];
    }

    /**
     * Render the Search Console dashboard section.
     *
     * When Site Kit is connected with Search Console active, shows
     * the top 10 queries and top 10 pages (clicks, impressions,
     * average position) for the last 28 days plus totals and a
     * refresh form. Otherwise shows a "connect Site Kit" card
     * linking to the Plugin Suggestions page (or to Site Kit itself
     * when it is installed but its setup is incomplete).
     *
     * @return void
     */
    private static function renderSearchConsoleSection(): void
    {
        $status = SiteKitDetector::getStatus();
        ?>
        <?php SettingsControls::sectionOpen('search-console', __('Search Console dashboard', 'traydigita-core')); ?>
        <?php
        if (!$status['connected'] || !isset($status['modules']['search-console'])) {
            ?>
            <div class="traydigita-admin-notice">
                <p>
                    <?php
                    if (!$status['connected']) {
                        esc_html_e('Search Console data comes from Google Site Kit. Connect Site Kit to see your top search queries and pages here — the theme adds no tracking of its own.', 'traydigita-core');
                    } else {
                        esc_html_e('Site Kit is connected, but its Search Console module is not active. Activate Search Console in Site Kit to see your top search queries and pages here.', 'traydigita-core');
                    }
                    ?>
                </p>
                <p>
                    <?php if (!$status['installed']) : ?>
                        <a class="traydigita-admin-button traydigita-admin-button-primary" href="<?php echo esc_url(admin_url('admin.php?page=traydigita-installer')); ?>">
                            <?php esc_html_e('Install Site Kit (Plugin Suggestions)', 'traydigita-core'); ?>
                        </a>
                    <?php else : ?>
                        <a class="traydigita-admin-button traydigita-admin-button-primary" href="<?php echo esc_url(admin_url('admin.php?page=googlesitekit-dashboard')); ?>">
                            <?php esc_html_e('Open Site Kit', 'traydigita-core'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            </div>
            <?php
            SettingsControls::sectionClose();
            return;
        }
        $data = SeoSearchConsole::getData();
        if (!$data['available']) {
            ?>
            <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                <p><?php esc_html_e('Search Console data could not be loaded from Site Kit right now. Please try again later.', 'traydigita-core'); ?></p>
            </div>
            <?php
            SettingsControls::sectionClose();
            return;
        }
        ?>
        <p class="seo-field-help">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: start date, 2: end date, 3: total clicks, 4: total impressions. */
                    __('Top queries and pages from Search Console, %1$s to %2$s. Total '
                        . 'clicks: %3$s. Total impressions: %4$s.', 'traydigita-core'),
                    $data['start_date'],
                    $data['end_date'],
                    number_format_i18n($data['total_clicks']),
                    number_format_i18n($data['total_impressions'])
                )
            );
            ?>
            <?php if ($data['cached']) : ?>
                <?php esc_html_e('(Cached, up to one hour old.)', 'traydigita-core'); ?>
            <?php endif; ?>
        </p>
        <form class="seo-sc-refresh-form" method="post" data-action="<?php echo esc_attr(SeoAjax::ACTION_SC_REFRESH); ?>">
            <button type="submit" class="traydigita-admin-button"><?php esc_html_e('Refresh Search Console data', 'traydigita-core'); ?></button>
        </form>
        <?php self::renderSearchConsoleTable(__('Top queries', 'traydigita-core'), $data['queries']); ?>
        <?php self::renderSearchConsoleTable(__('Top pages', 'traydigita-core'), $data['pages']); ?>
        <?php
        SettingsControls::sectionClose();
    }

    /**
     * Render one Search Console table (queries or pages).
     *
     * @param string $heading Table heading.
     * @param array<int, array{label: string, clicks: int, impressions: int, position: float}> $rows Rows.
     * @return void
     */
    private static function renderSearchConsoleTable(string $heading, array $rows): void
    {
        ?>
        <h3 class="seo-section-title"><?php echo esc_html($heading); ?></h3>
        <?php if ($rows === []) { ?>
            <p class="seo-field-help"><?php esc_html_e('No data for this period.', 'traydigita-core'); ?></p>
            <?php
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Query / Page', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Clicks', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Impressions', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Position', 'traydigita-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['label']); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['clicks'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['impressions'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['position'], 1)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the IndexNow panel (key, key-file URL, last-ping log, manual ping form).
     *
     * @param array<string, mixed> $settings Module settings.
     * @param bool $pluginActive Whether an SEO plugin is active.
     * @return void
     */
    private static function renderIndexNowSection(array $settings, bool $pluginActive): void
    {
        ?>
        <?php SettingsControls::sectionOpen('indexnow', __('IndexNow', 'traydigita-core')); ?>
        <?php if ($pluginActive) : ?>
            <p class="seo-field-help"><?php esc_html_e('Unavailable while an SEO plugin is active, to avoid duplicate IndexNow submissions.', 'traydigita-core'); ?></p>
        <?php endif; ?>
        <?php if (!$settings['indexnow_enabled']) { ?>
            <p class="seo-field-help"><?php esc_html_e('IndexNow is off. Switch it on above to ping Bing and Yandex when a post or page is published or updated.', 'traydigita-core'); ?></p>
            <?php
            SettingsControls::sectionClose();
            return;
        }
        $keyUrl = SeoIndexing::keyFileUrl();
        $log = SeoIndexing::getIndexNowLog();
        ?>
        <p class="seo-field-help">
            <?php esc_html_e('IndexNow key (also served as a text file from the site root for verification):', 'traydigita-core'); ?>
            <code><?php echo esc_html((string) $settings['indexnow_key']); ?></code>
        </p>
        <?php if ($keyUrl !== '') : ?>
            <p>
                <a href="<?php echo esc_url($keyUrl); ?>" target="_blank" rel="noopener"><?php echo esc_html($keyUrl); ?></a>
            </p>
        <?php endif; ?>
        <?php if ($log !== null) : ?>
            <p class="seo-field-help">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: date and time of the last ping, 2: the pinged URL. */
                        __('Last ping: %1$s — %2$s', 'traydigita-core'),
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $log['time']),
                        $log['url']
                    )
                );
                ?>
                <?php if ($log['success']) : ?>
                    <span class="traydigita-admin-badge traydigita-admin-badge-success"><?php esc_html_e('Accepted', 'traydigita-core'); ?></span>
                <?php else : ?>
                    <span class="traydigita-admin-badge traydigita-admin-badge-warning"><?php esc_html_e('Not accepted', 'traydigita-core'); ?></span>
                <?php endif; ?>
            </p>
            <ul>
                <?php foreach ($log['endpoints'] as $endpoint) : ?>
                    <li>
                        <?php echo esc_html((string) ($endpoint['endpoint'] ?? '')); ?>
                        —
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d is an HTTP status code, or 0 when the endpoint could not be reached. */
                                __('HTTP %d', 'traydigita-core'),
                                (int) ($endpoint['code'] ?? 0)
                            )
                        );
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p class="seo-field-help"><?php esc_html_e('Nothing has been pinged yet.', 'traydigita-core'); ?></p>
        <?php endif; ?>
        <form class="seo-indexnow-ping-form" method="post" data-action="<?php echo esc_attr(SeoAjax::ACTION_INDEXNOW_PING); ?>">
            <p class="seo-field">
                <label for="seo-indexnow-post-id"><?php esc_html_e('Ping IndexNow now for post or page ID', 'traydigita-core'); ?></label>
                <input type="number" id="seo-indexnow-post-id" name="post_id" min="1" step="1" class="small-text" required>
                <button type="submit" class="traydigita-admin-button"><?php esc_html_e('Ping now', 'traydigita-core'); ?></button>
            </p>
        </form>
        <?php
        SettingsControls::sectionClose();
    }

    /**
     * Render the Google Indexing API panel (service-account JSON form, status, last log).
     *
     * The JSON textarea is a classic POST form: it carries no
     * data-action marker, so the admin shell leaves it alone and
     * the page reloads with the save result. The stored JSON is
     * never printed back into the textarea or anywhere else.
     *
     * @param array<string, mixed> $settings Module settings.
     * @param bool $pluginActive Whether an SEO plugin is active.
     * @return void
     */
    private static function renderGoogleSection(array $settings, bool $pluginActive): void
    {
        $configured = SeoIndexing::isGoogleConfigured();
        $log = SeoIndexing::getGoogleLog();
        ?>
        <?php SettingsControls::sectionOpen('google', __('Google Indexing API', 'traydigita-core')); ?>
        <?php if ($pluginActive) : ?>
            <p class="seo-field-help"><?php esc_html_e('Unavailable while an SEO plugin is active, to avoid duplicate indexing notifications.', 'traydigita-core'); ?></p>
        <?php endif; ?>
        <?php if ($configured) : ?>
            <p class="seo-status-badge seo-status-theme">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s is the service-account client email, e.g. "name@project.iam.gserviceaccount.com". */
                        __('Service account configured: %s', 'traydigita-core'),
                        SeoIndexing::googleClientEmail()
                    )
                );
                ?>
            </p>
        <?php else : ?>
            <p class="seo-status-warning">
                <?php
                SettingsControls::helpText(
                    __('Not configured: no Google service-account JSON has been saved.', 'traydigita-core'),
                    __('Create a service account in Google Cloud, enable the Indexing API for it, and paste its JSON key below.', 'traydigita-core')
                );
                ?>
            </p>
        <?php endif; ?>
        <?php if ($log !== null) : ?>
            <p class="seo-field-help">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: date and time, 2: the notified URL, 3: HTTP status code (0 when Google could not be reached). */
                        __('Last notification: %1$s — %2$s (HTTP %3$d)', 'traydigita-core'),
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $log['time']),
                        $log['url'],
                        $log['code']
                    )
                );
                ?>
                <?php if ($log['success']) : ?>
                    <span class="traydigita-admin-badge traydigita-admin-badge-success"><?php esc_html_e('Accepted', 'traydigita-core'); ?></span>
                <?php else : ?>
                    <span class="traydigita-admin-badge traydigita-admin-badge-warning"><?php esc_html_e('Failed', 'traydigita-core'); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <form class="seo-google-json-form" method="post" action="">
            <?php wp_nonce_field(self::CLASSIC_NONCE_ACTION); ?>
            <input type="hidden" name="traydigita_seo_classic_action" value="save_google_json">
            <?php
            SettingsControls::textarea([
                'id' => 'seo-google-json',
                'name' => 'google_json',
                'label' => __('Google service-account JSON', 'traydigita-core'),
                'value' => '',
                'variant' => 'code',
                'help' => __('The JSON is stored on this site only and is used solely to notify the Google Indexing API.', 'traydigita-core'),
                'help_long' => __('It is never shown again after saving.', 'traydigita-core'),
                'attrs' => [
                    'rows' => '8',
                    'placeholder' => __('Paste the service-account JSON here. Leave empty and save to remove it.', 'traydigita-core'),
                ],
                'input_class' => 'code',
            ]);
            ?>
            <p class="seo-form-actions">
                <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary"><?php esc_html_e('Save changes', 'traydigita-core'); ?></button>
            </p>
        </form>
        <?php
        SettingsControls::sectionClose();
    }

    /**
     * Render the AI meta suggestions panel.
     *
     * Shown only when the AI toggle is on. When no AI provider is
     * available, an honest notice explains what is missing instead
     * of a dead form. Otherwise a classic POST form takes a post
     * or page ID and generates a title and description; the result
     * is shown with an Apply form that stores both as per-post
     * overrides, and a Remove form when overrides already exist.
     *
     * @param array<string, mixed> $settings Module settings.
     * @param array{available: bool, reason: string} $aiStatus AI availability status.
     * @param array{notice: string, error: bool, ai_post_id: int, ai_title: string, ai_description: string, ai_generated: bool} $classic Classic POST result.
     * @param bool $pluginActive Whether an SEO plugin is active.
     * @return void
     */
    private static function renderAiSection(array $settings, array $aiStatus, array $classic, bool $pluginActive): void
    {
        ?>
        <?php SettingsControls::sectionOpen('ai', __('AI meta suggestions', 'traydigita-core')); ?>
        <?php if (!$settings['ai_enabled']) { ?>
            <p class="seo-field-help"><?php esc_html_e('AI suggestions are off. Switch them on above to generate meta titles and descriptions for a post.', 'traydigita-core'); ?></p>
            <?php
            SettingsControls::sectionClose();
            return;
        }
        if (!$aiStatus['available']) {
            ?>
            <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                <p><?php echo esc_html(SeoAi::statusMessage($aiStatus['reason'])); ?></p>
            </div>
            <?php
            SettingsControls::sectionClose();
            return;
        }
        if ($pluginActive) {
            ?>
            <p class="seo-field-help"><?php SettingsControls::helpText(__('An SEO plugin is active: suggestions can still be generated and stored.', 'traydigita-core'), __('They only affect the frontend while no SEO plugin is active.', 'traydigita-core')); ?></p>
            <?php
        }
        $postId = $classic['ai_post_id'];
        $post = $postId > 0 ? get_post($postId) : null;
        $existingTitle = '';
        $existingDescription = '';
        if ($post instanceof WP_Post) {
            $storedTitle = get_post_meta($post->ID, SeoOutput::META_TITLE, true);
            $storedDescription = get_post_meta($post->ID, SeoOutput::META_DESCRIPTION, true);
            $existingTitle = is_string($storedTitle) ? $storedTitle : '';
            $existingDescription = is_string($storedDescription) ? $storedDescription : '';
        }
        ?>
        <form class="seo-ai-generate-form" method="post" action="">
            <?php wp_nonce_field(self::CLASSIC_NONCE_ACTION); ?>
            <input type="hidden" name="traydigita_seo_classic_action" value="ai_generate">
            <p class="seo-field">
                <label for="seo-ai-post-id"><?php esc_html_e('Post or page ID', 'traydigita-core'); ?></label>
                <input type="number" id="seo-ai-post-id" name="ai_post_id" value="<?php echo $postId > 0 ? esc_attr((string) $postId) : ''; ?>" min="1" step="1" class="small-text" required>
                <button type="submit" class="traydigita-admin-button"><?php esc_html_e('Generate suggestions', 'traydigita-core'); ?></button>
            </p>
        </form>
        <?php if ($post instanceof WP_Post) : ?>
            <p class="seo-field-help">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s is the post title. */
                        __('Post: %s', 'traydigita-core'),
                        get_the_title($post)
                    )
                );
                ?>
            </p>
        <?php endif; ?>
        <?php if ($classic['ai_generated']) : ?>
            <h3 class="seo-section-title"><?php esc_html_e('Suggested meta', 'traydigita-core'); ?></h3>
            <p class="seo-field">
                <strong><?php esc_html_e('Title', 'traydigita-core'); ?>:</strong>
                <?php echo esc_html($classic['ai_title']); ?>
            </p>
            <p class="seo-field">
                <strong><?php esc_html_e('Description', 'traydigita-core'); ?>:</strong>
                <?php echo esc_html($classic['ai_description']); ?>
            </p>
            <form class="seo-ai-apply-form" method="post" action="">
                <?php wp_nonce_field(self::CLASSIC_NONCE_ACTION); ?>
                <input type="hidden" name="traydigita_seo_classic_action" value="ai_apply">
                <input type="hidden" name="ai_post_id" value="<?php echo esc_attr((string) $postId); ?>">
                <input type="hidden" name="ai_title" value="<?php echo esc_attr($classic['ai_title']); ?>">
                <input type="hidden" name="ai_description" value="<?php echo esc_attr($classic['ai_description']); ?>">
                <p class="seo-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary"><?php esc_html_e('Apply as post overrides', 'traydigita-core'); ?></button>
                </p>
            </form>
        <?php endif; ?>
        <?php if ($post instanceof WP_Post && ($existingTitle !== '' || $existingDescription !== '')) : ?>
            <h3 class="seo-section-title"><?php esc_html_e('Current overrides for this post', 'traydigita-core'); ?></h3>
            <?php if ($existingTitle !== '') : ?>
                <p class="seo-field">
                    <strong><?php esc_html_e('Title', 'traydigita-core'); ?>:</strong>
                    <?php echo esc_html($existingTitle); ?>
                </p>
            <?php endif; ?>
            <?php if ($existingDescription !== '') : ?>
                <p class="seo-field">
                    <strong><?php esc_html_e('Description', 'traydigita-core'); ?>:</strong>
                    <?php echo esc_html($existingDescription); ?>
                </p>
            <?php endif; ?>
            <form class="seo-ai-remove-form" method="post" action="">
                <?php wp_nonce_field(self::CLASSIC_NONCE_ACTION); ?>
                <input type="hidden" name="traydigita_seo_classic_action" value="ai_remove">
                <input type="hidden" name="ai_post_id" value="<?php echo esc_attr((string) $postId); ?>">
                <p class="seo-form-actions">
                    <button type="submit" class="traydigita-admin-button"><?php esc_html_e('Remove overrides', 'traydigita-core'); ?></button>
                </p>
            </form>
        <?php endif; ?>
        <?php
        SettingsControls::sectionClose();
    }

    /**
     * Handle a classic POST from the Google JSON or AI suggestion forms.
     *
     * The admin shell leaves forms without a data-action marker
     * alone, so these forms post normally and the page reloads
     * with the result. Only runs when the classic action field is
     * present; otherwise returns an empty result. The nonce and
     * the `manage_options` capability are verified before any
     * action runs. The service-account JSON is read raw (never
     * through sanitize_text_field(), which would corrupt the
     * private key); the AI fields are sanitized per field.
     *
     * @return array{notice: string, error: bool, ai_post_id: int, ai_title: string, ai_description: string, ai_generated: bool}
     */
    private static function handleClassicPost(): array
    {
        $result = [
            'notice' => '',
            'error' => false,
            'ai_post_id' => 0,
            'ai_title' => '',
            'ai_description' => '',
            'ai_generated' => false,
        ];
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $result;
        }
        $action = isset($_POST['traydigita_seo_classic_action'])
            ? sanitize_key((string) wp_unslash($_POST['traydigita_seo_classic_action']))
            : '';
        if ($action === '') {
            return $result;
        }
        $nonce = isset($_POST['_wpnonce']) ? sanitize_key((string) wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::CLASSIC_NONCE_ACTION)) {
            $result['notice'] = __('Security check failed. Please try again.', 'traydigita-core');
            $result['error'] = true;
            return $result;
        }
        if (!current_user_can('manage_options')) {
            $result['notice'] = __('You are not allowed to do that.', 'traydigita-core');
            $result['error'] = true;
            return $result;
        }
        if ($action === 'save_google_json') {
            $raw = isset($_POST['google_json']) ? (string) wp_unslash($_POST['google_json']) : '';
            $saved = SeoIndexing::saveGoogleJson($raw);
            $result['notice'] = $saved['message'];
            $result['error'] = !$saved['success'];
            return $result;
        }
        $postId = isset($_POST['ai_post_id']) ? absint($_POST['ai_post_id']) : 0;
        $result['ai_post_id'] = $postId;
        if ($action === 'ai_generate') {
            if ($postId <= 0) {
                $result['notice'] = __('Enter a post or page ID first.', 'traydigita-core');
                $result['error'] = true;
                return $result;
            }
            $suggestion = SeoAi::suggestForPost($postId);
            if (isset($suggestion['error'])) {
                $result['notice'] = $suggestion['error'];
                $result['error'] = true;
                return $result;
            }
            $result['ai_title'] = $suggestion['title'];
            $result['ai_description'] = $suggestion['description'];
            $result['ai_generated'] = true;
            $result['notice'] = __('Suggestions generated. Review them below and apply them if you like them.', 'traydigita-core');
            return $result;
        }
        if ($action === 'ai_apply') {
            if ($postId <= 0) {
                $result['notice'] = __('Enter a post or page ID first.', 'traydigita-core');
                $result['error'] = true;
                return $result;
            }
            $post = get_post($postId);
            if (!$post instanceof WP_Post) {
                $result['notice'] = __('That post could not be found.', 'traydigita-core');
                $result['error'] = true;
                return $result;
            }
            $title = isset($_POST['ai_title']) ? sanitize_text_field((string) wp_unslash($_POST['ai_title'])) : '';
            $description = isset($_POST['ai_description']) ? sanitize_text_field((string) wp_unslash($_POST['ai_description'])) : '';
            if ($title === '' || $description === '') {
                $result['notice'] = __('Nothing to apply: the title or description is empty.', 'traydigita-core');
                $result['error'] = true;
                return $result;
            }
            update_post_meta($post->ID, SeoOutput::META_TITLE, $title);
            update_post_meta($post->ID, SeoOutput::META_DESCRIPTION, $description);
            $result['notice'] = __('Overrides saved for this post.', 'traydigita-core');
            return $result;
        }
        if ($action === 'ai_remove') {
            if ($postId <= 0) {
                $result['notice'] = __('Enter a post or page ID first.', 'traydigita-core');
                $result['error'] = true;
                return $result;
            }
            delete_post_meta($postId, SeoOutput::META_TITLE);
            delete_post_meta($postId, SeoOutput::META_DESCRIPTION);
            $result['notice'] = __('Overrides removed for this post.', 'traydigita-core');
            return $result;
        }
        $result['notice'] = __('Unknown action.', 'traydigita-core');
        $result['error'] = true;
        return $result;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: the four original boolean
     * toggles (`meta_output`, `json_ld`, `breadcrumbs`,
     * `news_sitemap`), the new boolean toggles (`xml_sitemap`,
     * `indexnow_enabled`, `google_indexing_enabled`,
     * `ai_enabled`), the template strings (`title_post`,
     * `title_page`, `title_home`, `title_archive`,
     * `description_post`, `description_home`,
     * `description_archive`, each cut to 200 characters) and the
     * title separator (`title_sep`, cut to 3 characters). Every
     * value is sanitized for its own type — the dispatcher
     * deep-sanitizes strings, so a checkbox arrives as the string
     * '1' or not at all. Unknown keys are dropped.
     *
     * While an SEO plugin is active, the native-output toggles
     * (news sitemap, XML sitemap, IndexNow, Google Indexing) are
     * disabled in the form and their stored values are preserved
     * instead of being wiped by the missing checkboxes. Enabling
     * IndexNow with no key stored generates a fresh key. When any
     * sitemap or IndexNow setting changes, the rewrite rules are
     * flushed so the URLs start (or stop) resolving immediately.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change SEO settings.', 'traydigita-core'),
            ];
        }
        $existing = self::getSettings();
        $settings = [
            'meta_output' => !empty($data['meta_output']),
            'json_ld' => !empty($data['json_ld']),
            'breadcrumbs' => !empty($data['breadcrumbs']),
            'news_sitemap' => !empty($data['news_sitemap']),
            'xml_sitemap' => !empty($data['xml_sitemap']),
            'indexnow_enabled' => !empty($data['indexnow_enabled']),
            'google_indexing_enabled' => !empty($data['google_indexing_enabled']),
            'ai_enabled' => !empty($data['ai_enabled']),
        ];
        $defaults = self::getDefaults();
        foreach (['title_post', 'title_page', 'title_home', 'title_archive', 'description_post', 'description_home', 'description_archive'] as $field) {
            $value = isset($data[$field]) && is_string($data[$field]) ? $data[$field] : '';
            $value = SeoTemplates::sanitizeTemplate(sanitize_text_field($value));
            $settings[$field] = $value !== '' ? $value : $defaults[$field];
        }
        $sep = isset($data['title_sep']) && is_string($data['title_sep']) ? sanitize_text_field($data['title_sep']) : '';
        $settings['title_sep'] = $sep !== '' ? mb_substr(trim($sep), 0, 3) : $defaults['title_sep'];
        $settings['indexnow_key'] = $existing['indexnow_key'];
        if (SeoDetector::hasSeoPlugin()) {
            $settings['news_sitemap'] = $existing['news_sitemap'];
            $settings['xml_sitemap'] = $existing['xml_sitemap'];
            $settings['indexnow_enabled'] = $existing['indexnow_enabled'];
            $settings['google_indexing_enabled'] = $existing['google_indexing_enabled'];
        }
        if ($settings['indexnow_enabled'] && $settings['indexnow_key'] === '') {
            $settings['indexnow_key'] = SeoIndexing::generateKey();
        }
        $rewriteChanged = $settings['news_sitemap'] !== $existing['news_sitemap']
            || $settings['xml_sitemap'] !== $existing['xml_sitemap']
            || $settings['indexnow_enabled'] !== $existing['indexnow_enabled'];
        update_option(self::OPTION, $settings, false);
        if ($rewriteChanged) {
            flush_rewrite_rules();
        }
        return [
            'success' => true,
            'message' => __('SEO settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The sitemap rewrite rules are registered on `init`
     * (or immediately when `init` already fired); the templated
     * title filter on `document_title_parts`, the meta and JSON-LD
     * output hooks on `wp_head`, and the sitemap handlers on
     * `template_redirect`. The IndexNow key-file rule and the
     * publish pings are registered through SeoIndexing, and the
     * extra AJAX endpoints through SeoAjax. Each output callback
     * re-checks its own toggle and the SEO plugin detection at
     * render time.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        if (did_action('init') > 0) {
            SeoOutput::registerNewsSitemap();
            SeoSitemap::register();
        } else {
            add_action('init', [SeoOutput::class, 'registerNewsSitemap']);
            add_action('init', [SeoSitemap::class, 'register']);
        }
        add_filter('document_title_parts', [SeoOutput::class, 'filterDocumentTitle'], 20);
        add_action('wp_head', [SeoOutput::class, 'renderMeta'], 20);
        add_action('wp_head', [SeoOutput::class, 'renderJsonLd'], 21);
        add_action('template_redirect', [SeoOutput::class, 'maybeServeNewsSitemap']);
        add_action('template_redirect', [SeoSitemap::class, 'maybeServe']);
        SeoIndexing::register();
        SeoAjax::register();
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{meta_output: bool, json_ld: bool, breadcrumbs: bool, news_sitemap: bool, xml_sitemap: bool, indexnow_enabled: bool, indexnow_key: string, google_indexing_enabled: bool, ai_enabled: bool, title_post: string, title_page: string, title_home: string, title_archive: string, title_sep: string, description_post: string, description_home: string, description_archive: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $clean = [
            'meta_output' => (bool) ($settings['meta_output'] ?? false),
            'json_ld' => (bool) ($settings['json_ld'] ?? false),
            'breadcrumbs' => (bool) ($settings['breadcrumbs'] ?? false),
            'news_sitemap' => (bool) ($settings['news_sitemap'] ?? false),
            'xml_sitemap' => (bool) ($settings['xml_sitemap'] ?? false),
            'indexnow_enabled' => (bool) ($settings['indexnow_enabled'] ?? false),
            'google_indexing_enabled' => (bool) ($settings['google_indexing_enabled'] ?? false),
            'ai_enabled' => (bool) ($settings['ai_enabled'] ?? false),
            'indexnow_key' => isset($settings['indexnow_key']) && is_string($settings['indexnow_key']) ? $settings['indexnow_key'] : '',
        ];
        $defaults = self::getDefaults();
        foreach (['title_post', 'title_page', 'title_home', 'title_archive', 'title_sep', 'description_post', 'description_home', 'description_archive'] as $field) {
            $value = isset($settings[$field]) && is_string($settings[$field]) && $settings[$field] !== ''
                ? $settings[$field]
                : $defaults[$field];
            $clean[$field] = $value;
        }
        return $clean;
    }

    /**
     * Default settings.
     *
     * Meta output, JSON-LD, and breadcrumbs are on; the sitemaps,
     * the instant indexing, and the AI suggestions are off until
     * an administrator opts in. Templates default to the classic
     * "%title% | %site%" shapes.
     *
     * @return array{meta_output: bool, json_ld: bool, breadcrumbs: bool, news_sitemap: bool, xml_sitemap: bool, indexnow_enabled: bool, indexnow_key: string, google_indexing_enabled: bool, ai_enabled: bool, title_post: string, title_page: string, title_home: string, title_archive: string, title_sep: string, description_post: string, description_home: string, description_archive: string}
     */
    public static function getDefaults(): array
    {
        return array_merge(
            [
                'meta_output' => true,
                'json_ld' => true,
                'breadcrumbs' => true,
                'news_sitemap' => false,
                'xml_sitemap' => false,
                'indexnow_enabled' => false,
                'indexnow_key' => '',
                'google_indexing_enabled' => false,
                'ai_enabled' => false,
            ],
            SeoTemplates::defaults()
        );
    }
}
