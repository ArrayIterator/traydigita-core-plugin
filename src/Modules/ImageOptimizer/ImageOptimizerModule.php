<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ImageOptimizer;

use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use wpdb;
use function __;
use function add_filter;
use function class_exists;
use function current_user_can;
use function esc_html__;
use function file_exists;
use function function_exists;
use function get_option;
use function get_theme_mod;
use function is_array;
use function is_string;
use function max;
use function min;
use function update_option;

/**
 * Image Optimizer module.
 *
 * Converts JPEG/PNG uploads to WebP or AVIF and compresses them at
 * the configured quality (default 82), and bulk-optimizes the
 * existing media library in chunked AJAX runs. Conversion runs
 * through `wp_get_image_editor()` (see `Optimizer`), only when the
 * editor actually supports the target format — an unsupported
 * format is skipped with a recorded reason, never a fatal error.
 *
 * Safety rules:
 * - Only image attachments are ever touched (JPEG, PNG, WebP).
 * - An original file is deleted only when `keep_original` is
 *   explicitly off AND its converted replacement was verified
 *   (exists + `getimagesize()` passes); by default the original
 *   is kept on disk as an unreferenced backup.
 * - When the stack Photon helper is serving images
 *   (`photon_enabled` Customizer setting), local optimization is
 *   skipped entirely: the settings page says so, the upload hook
 *   does nothing, and the bulk runner refuses to start. The
 *   module must not fight Photon.
 *
 * Settings live in the single option `traydigita_image_optimizer`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; `saveSettings()` still checks the capability itself
 * and re-sanitizes every field for its own type.
 *
 * The module is optional and disabled by default.
 */
final class ImageOptimizerModule implements ModuleInterface
{
    /**
     * Option name for all Image Optimizer settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_image_optimizer';

    /**
     * Nonce action shared by every admin endpoint (see AdminAjax).
     */
    public const string NONCE_ACTION = AdminAjax::NONCE_ACTION;

    /**
     * Default conversion quality (1-100).
     */
    public const int DEFAULT_QUALITY = 82;

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
        return 'image-optimizer';
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
        return __('Image Optimizer', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Convert uploads to WebP or AVIF and compress them on upload, and bulk-optimize the existing media library in small chunks. Originals are kept unless you switch that off, and nothing runs while Photon is serving your images.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'format-image';
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
            __('Convert uploads to WebP or AVIF and compress on upload.', 'traydigita-core'),
            __('Bulk-optimize the existing media library in small chunks.', 'traydigita-core'),
            __('Originals are kept unless switched off.', 'traydigita-core'),
            __('Pauses automatically while Photon serves images.', 'traydigita-core'),
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
        return 'traydigita-image-optimizer';
    }

    /**
     * Whether the module can run at all right now.
     *
     * False only when no WordPress image editor exists (neither
     * Imagick nor GD is available), in which case no conversion
     * could ever succeed.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return function_exists('wp_get_image_editor')
            && (class_exists('WP_Image_Editor_Imagick') || class_exists('WP_Image_Editor_GD'));
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * Configured means the selected target format is actually
     * supported by the image editor. With an unsupported format
     * selected, the module would only ever skip attachments, so
     * the card shows the "needs setup" state until the format is
     * changed (or the server gains WebP/AVIF support).
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return Optimizer::isFormatSupported(self::getSettings()['format']);
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and disabled by default.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether Photon is serving the site's images.
     *
     * Reads the `photon_enabled` Customizer setting (the same key
     * phase 3 registered in `Definitions.php`, default off). When
     * this is on, the stack Photon helper serves images through
     * i0.wp.com and local optimization is unnecessary.
     *
     * @return bool
     */
    public static function isPhotonEnabled(): bool
    {
        return (bool) get_theme_mod('photon_enabled', false);
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'support', 'label' => __('Format support', 'traydigita-core'), 'icon' => 'format-image'],
            ['id' => 'library', 'label' => __('Library', 'traydigita-core'), 'icon' => 'images-alt2'],
            ['id' => 'settings', 'label' => __('Settings', 'traydigita-core'), 'icon' => 'admin-settings'],
            ['id' => 'bulk', 'label' => __('Bulk optimize', 'traydigita-core'), 'icon' => 'update'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Loads `templates/admin/modules/image-optimizer.php` with the
     * current settings, format-support map, Photon state, library
     * statistics, and the last bulk-run progress.
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
        $settings = self::getSettings();
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $imageOptimizerSettings = $settings;
        $imageOptimizerSupported = Optimizer::supportedFormats();
        $imageOptimizerPhoton = self::isPhotonEnabled();
        $imageOptimizerStats = self::getStats();
        $imageOptimizerProgress = BulkRunner::getProgress();
        $imageOptimizerRunning = BulkRunner::isRunning();
        $imageOptimizerConfigured = self::isConfigured();
        $imageOptimizerNonceAction = self::NONCE_ACTION;
        $imageOptimizerStartAction = BulkRunner::ACTION_START;
        $imageOptimizerChunkAction = BulkRunner::ACTION_CHUNK;
        $imageOptimizerCancelAction = BulkRunner::ACTION_CANCEL;
        $imageOptimizerSaveAction = 'traydigita_admin_save';
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/image-optimizer.php';
        if (!file_exists($file)) {
            return;
        }
        require $file;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `format` (`webp` or `avif`),
     * `quality` (1-100), `keep_original` (`1` when checked),
     * `optimize_on_upload` (`1` when checked). An absent checkbox
     * key means the checkbox was unchecked. Every value is
     * re-sanitized for its own type; unknown keys are dropped.
     * A format the image editor cannot write is rejected with a
     * field error instead of being stored.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, errors?: array<string, string>}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Image Optimizer settings.', 'traydigita-core'),
            ];
        }
        $format = isset($data['format']) && is_string($data['format']) && $data['format'] === 'avif' ? 'avif' : 'webp';
        $quality = isset($data['quality']) ? (int) $data['quality'] : self::DEFAULT_QUALITY;
        $quality = max(1, min(100, $quality));
        $settings = [
            'format' => $format,
            'quality' => $quality,
            'keep_original' => !empty($data['keep_original']),
            'optimize_on_upload' => !empty($data['optimize_on_upload']),
        ];
        if (!Optimizer::isFormatSupported($format)) {
            return [
                'success' => false,
                'message' => __('The selected image format is not supported by this server’s image editor.', 'traydigita-core'),
                'errors' => [
                    'format' => __('This format is not supported by the image editor on this server. Choose the other format.', 'traydigita-core'),
                ],
            ];
        }
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Image Optimizer settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the upload hook and the bulk-run AJAX endpoints.
     *
     * Called only when the module is enabled (and available) by
     * `ModuleRegistry::bootEnabled()`. A disabled module registers
     * no hooks and enqueues nothing.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_filter('wp_generate_attachment_metadata', [self::class, 'filterAttachmentMetadata'], 10, 2);
        BulkRunner::register();
    }

    /**
     * Optimize an image right after WordPress generates its sizes.
     *
     * Runs on `wp_generate_attachment_metadata`, which fires for
     * every image upload after the sub-sizes exist — so the
     * original and every size are converted together and the
     * returned metadata already points at the converted files.
     * (A `wp_handle_upload` hook would fire before the sizes are
     * generated, so it cannot cover them.)
     *
     * The callback never breaks an upload: Photon, a disabled
     * on-upload toggle, an unsupported format, or any conversion
     * error all return the metadata unchanged, and unexpected
     * throwables are swallowed so the upload still succeeds.
     *
     * @param array<string, mixed> $metadata Attachment metadata generated by WordPress.
     * @param int $attachmentId Attachment post id.
     * @return array<string, mixed> Metadata, possibly rewritten to the converted files.
     */
    public static function filterAttachmentMetadata(array $metadata, int $attachmentId): array
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return $metadata;
        }
        $settings = self::getSettings();
        if (!$settings['optimize_on_upload'] || self::isPhotonEnabled()) {
            return $metadata;
        }
        try {
            Optimizer::optimizeAttachment($attachmentId, $settings, false);
        } catch (Throwable) {
            // Never break an upload because optimization failed.
        }
        return $metadata;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{format: string, quality: int, keep_original: bool, optimize_on_upload: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $format = isset($settings['format']) && is_string($settings['format']) && $settings['format'] === 'avif' ? 'avif' : 'webp';
        $quality = isset($settings['quality']) ? (int) $settings['quality'] : self::DEFAULT_QUALITY;
        return [
            'format' => $format,
            'quality' => max(1, min(100, $quality)),
            'keep_original' => (bool) ($settings['keep_original'] ?? true),
            'optimize_on_upload' => (bool) ($settings['optimize_on_upload'] ?? true),
        ];
    }

    /**
     * Default settings.
     *
     * WebP at quality 82, originals kept, optimization on upload on.
     *
     * @return array{format: string, quality: int, keep_original: bool, optimize_on_upload: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'format' => 'webp',
            'quality' => self::DEFAULT_QUALITY,
            'keep_original' => true,
            'optimize_on_upload' => true,
        ];
    }

    /**
     * Library statistics for the settings page.
     *
     * Counts image attachments by optimization status (from the
     * plain status postmeta `Optimizer::STATUS_KEY`, so no
     * serialized values are ever matched). Attachments with no
     * status row have never been processed.
     *
     * @return array{total: int, optimized: int, skipped: int, failed: int, unprocessed: int}
     */
    public static function getStats(): array
    {
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return [
                'total' => 0,
                'optimized' => 0,
                'skipped' => 0,
                'failed' => 0,
                'unprocessed' => 0,
            ];
        }
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_mime_type IN (%s, %s, %s)",
                'attachment',
                'inherit',
                'image/jpeg',
                'image/png',
                'image/webp'
            )
        );
        $counts = [
            'optimized' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        foreach ($counts as $status => $unused) {
            $counts[$status] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                    Optimizer::STATUS_KEY,
                    $status
                )
            );
        }
        $processed = $counts['optimized'] + $counts['skipped'] + $counts['failed'];
        return [
            'total' => $total,
            'optimized' => $counts['optimized'],
            'skipped' => $counts['skipped'],
            'failed' => $counts['failed'],
            'unprocessed' => max(0, $total - $processed),
        ];
    }
}
