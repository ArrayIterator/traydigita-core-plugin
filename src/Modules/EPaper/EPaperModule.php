<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\EPaper;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function current_user_can;
use function date_i18n;
use function did_action;
use function esc_html__;
use function file_exists;
use function flush_rewrite_rules;
use function get_option;
use function size_format;
use function strtotime;
use function update_option;
use function wp_enqueue_media;

/**
 * E-Paper module.
 *
 * Publishes the site's newspaper as downloadable PDF issues:
 * the admin uploads PDFs (MIME-verified `application/pdf` only,
 * configurable size limit, default 20 MB) with a title, a date,
 * and an optional cover image picked from the media library, and
 * readers browse the archive at `/epaper/` and read each issue
 * in a native browser PDF embed at `/epaper/<issue-id>/`, with
 * prev/next issue navigation. A `[traydigita_epaper]` shortcode
 * and a "Tray Digita: E-Paper" widget show the latest issue.
 *
 * Issues are NOT a custom post type: records live in the single
 * option `traydigita_epaper_issues` (see EPaperStorage), capped
 * at 500 issues, and PDF files live under
 * `wp-content/traydigita-epaper/` (never in uploads). The first
 * page of a PDF is never rendered as a cover — that is out of
 * scope — so an issue without an uploaded cover shows a
 * generated placeholder (document icon) instead, and the UI
 * says so plainly.
 *
 * The module is optional and DISABLED by default: without any
 * PDFs it has nothing to show. Settings live in the single
 * option `traydigita_epaper` (autoload `no`) and are saved
 * through the phase-2 admin AJAX dispatcher, which verifies the
 * nonce and the `manage_options` capability; saveSettings()
 * still checks the capability itself and clamps every field.
 * Upload, metadata-update, and delete run through the module's
 * own AJAX endpoints (EPaperAjax), each with the
 * `traydigita-admin` nonce and `manage_options`.
 */
final class EPaperModule implements ModuleInterface
{
    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'epaper';
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
        return __('E-Paper', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Publish the newspaper as PDF issues: upload verified PDFs with a cover image, browse them in a dated archive, and read each issue in the browser with prev/next navigation.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'media-document';
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
        return 'traydigita-epaper';
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
     * Configured means at least one issue has been uploaded; an
     * enabled module with no issues shows an empty archive and
     * the card carries a "needs setup" badge until the first
     * upload.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return EPaperStorage::getIssues() !== [];
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable, and disabled
     * by default (the coordinator lists it in neither
     * DEFAULT_ENABLED nor the core set).
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
            ['id' => 'settings', 'label' => __('Settings', 'traydigita-core'), 'icon' => 'admin-settings'],
            ['id' => 'upload', 'label' => __('Upload a new issue', 'traydigita-core'), 'icon' => 'upload'],
            ['id' => 'issues', 'label' => __('Issues', 'traydigita-core'), 'icon' => 'media-document'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Loads `templates/admin/modules/epaper.php` with the settings,
     * the issue list (each issue enriched with its public URLs, a
     * cover thumbnail URL, and human-readable size/date labels),
     * the archive URL, and whether the storage directory could be
     * created. The media library scripts are enqueued so the
     * cover picker in the upload form can open `wp.media`.
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
        wp_enqueue_media();
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $epaperSettings = EPaperStorage::getSettings();
        $epaperIssues = self::getAdminIssueList();
        $epaperArchiveUrl = EPaperStorage::archiveUrl();
        $epaperDirOk = EPaperStorage::ensureDir();
        $epaperMaxIssues = EPaperStorage::MAX_ISSUES;
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/epaper.php';
        if (!file_exists($file)) {
            return;
        }
        require $file;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `max_size_mb` (1–100, default
     * 20) and `issues_per_page` (1–50, default 12). Unknown keys
     * are dropped. The rewrite rules are flushed on every save so
     * the `/epaper/` URLs start (or keep) resolving right after
     * the module is enabled and saved once.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change E-Paper settings.', 'traydigita-core'),
            ];
        }
        $settings = [
            'max_size_mb' => isset($data['max_size_mb']) ? absint($data['max_size_mb']) : EPaperStorage::DEFAULT_MAX_SIZE_MB,
            'issues_per_page' => isset($data['issues_per_page']) ? absint($data['issues_per_page']) : EPaperStorage::DEFAULT_PER_PAGE,
        ];
        if ($settings['max_size_mb'] < 1) {
            $settings['max_size_mb'] = 1;
        }
        if ($settings['max_size_mb'] > EPaperStorage::MAX_SIZE_MB_CAP) {
            $settings['max_size_mb'] = EPaperStorage::MAX_SIZE_MB_CAP;
        }
        if ($settings['issues_per_page'] < 1) {
            $settings['issues_per_page'] = 1;
        }
        if ($settings['issues_per_page'] > 50) {
            $settings['issues_per_page'] = 50;
        }
        update_option(EPaperStorage::OPTION_SETTINGS, $settings, false);
        flush_rewrite_rules();
        return [
            'success' => true,
            'message' => __('E-Paper settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module's hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The AJAX endpoints, the rewrite rules, the
     * template selector, the document-title filter, and the
     * shortcode are all registered here; a disabled module
     * registers none of them. The rewrite rules are registered on
     * `init` (or immediately when `init` already fired, mirroring
     * SeoModule).
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        EPaperAjax::register();
        if (did_action('init') > 0) {
            EPaperFrontend::registerRewrites();
        } else {
            add_action('init', [EPaperFrontend::class, 'registerRewrites']);
        }
        add_filter('template_include', [EPaperFrontend::class, 'filterTemplate']);
        add_filter('document_title_parts', [EPaperFrontend::class, 'filterDocumentTitle']);
        EPaperFrontend::registerShortcode();
    }

    /**
     * Issue list for the admin page, newest first.
     *
     * Each record is the stored issue plus its public issue/PDF
     * URLs, a cover thumbnail URL (or null when the issue has no
     * cover), and human-readable size/date labels. No absolute
     * server path is ever included.
     *
     * @return array<int, array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int, url: string, pdf_url: string, cover_url: string|null, size_label: string, date_label: string}>
     */
    private static function getAdminIssueList(): array
    {
        $list = [];
        foreach (EPaperStorage::getIssues() as $issue) {
            $list[] = array_merge($issue, [
                'url' => EPaperStorage::issueUrl($issue),
                'pdf_url' => EPaperStorage::pdfUrl($issue),
                'cover_url' => EPaperStorage::coverUrl($issue, 'thumbnail'),
                'size_label' => (string) size_format($issue['size']),
                'date_label' => date_i18n(get_option('date_format', 'F j, Y'), strtotime($issue['date'])),
            ]);
        }
        return $list;
    }
}
