<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Fonts;

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use function __;
use function absint;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function is_array;
use function is_string;
use function move_uploaded_file;
use function sanitize_key;
use function sanitize_text_field;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Module-owned AJAX endpoints for the Font Manager.
 *
 * Registered from `FontsModule::boot()` (only when the module is
 * enabled), so the endpoints do not exist while the module is
 * disabled - the same pattern as the SMTP test-email endpoint.
 *
 * Every endpoint requires the `traydigita-admin` nonce sent as
 * `_ajax_nonce` (see `AdminAjax::NONCE_ACTION`) plus the
 * `manage_options` capability; `check_ajax_referer()` runs first.
 *
 * Endpoints (POST to `admin-ajax.php`):
 * - `traydigita_fonts_download`: `family` (name, e.g. `Open
 *   Sans`), `weights` (comma list, e.g. `400,700`), `italic`
 *   (`1` to include italic variants). Fetches the Google Fonts
 *   stylesheet, downloads the woff2 files, stores them locally,
 *   and registers the family.
 * - `traydigita_fonts_upload`: `family`, `weight`, `style`
 *   (`normal`|`italic`), plus a `font_file` upload. The file is
 *   MIME-verified by FontDownloader before it is stored.
 * - `traydigita_fonts_delete`: `family` (slug); optional
 *   `weight` + `style` to delete a single variant instead of the
 *   whole family.
 *
 * Success returns the updated public font list (`fonts`); failure
 * returns a JSON error with a clear message. No absolute paths
 * are ever returned.
 */
final class FontsAjax
{
    /**
     * AJAX action for downloading a Google Font family.
     */
    public const string DOWNLOAD_ACTION = 'traydigita_fonts_download';

    /**
     * AJAX action for uploading a font file.
     */
    public const string UPLOAD_ACTION = 'traydigita_fonts_upload';

    /**
     * AJAX action for deleting a font family or variant.
     */
    public const string DELETE_ACTION = 'traydigita_fonts_delete';

    /**
     * Register the three AJAX actions.
     *
     * Called from `FontsModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::DOWNLOAD_ACTION, [self::class, 'download']);
        add_action('wp_ajax_' . self::UPLOAD_ACTION, [self::class, 'upload']);
        add_action('wp_ajax_' . self::DELETE_ACTION, [self::class, 'delete']);
    }

    /**
     * Download a Google Fonts family to local files.
     *
     * Validates the family name and weight list, fetches the
     * stylesheet from the allowlisted Google Fonts host, downloads
     * each woff2 file (host + magic bytes checked), and registers
     * the family. Files already stored for a previous download of
     * the same family are replaced; a failed download stores
     * nothing and removes any files it just wrote.
     *
     * @return void
     */
    public static function download(): void
    {
        if (!self::authorize()) {
            return;
        }
        $family = sanitize_text_field(wp_unslash($_POST['family'] ?? ''));
        if (!FontsModule::isValidFamilyName($family)) {
            wp_send_json_error(
                ['message' => __('Enter a valid font family name, using letters, digits, spaces, apostrophes, and hyphens only.', 'traydigita-core')],
                400
            );
        }
        $weightsRaw = $_POST['weights'] ?? '';
        $weights = FontsModule::parseWeights(is_string($weightsRaw) ? wp_unslash($weightsRaw) : '');
        if ($weights === []) {
            wp_send_json_error(
                ['message' => __('Enter at least one valid weight, for example 400, 700.', 'traydigita-core')],
                400
            );
        }
        $italic = isset($_POST['italic']) && wp_unslash($_POST['italic']) === '1';
        $fetched = FontDownloader::fetchGoogleCss($family, $weights, $italic);
        if (!$fetched['ok'] || !isset($fetched['css'])) {
            wp_send_json_error(
                ['message' => $fetched['error'] ?? __('Google Fonts could not be reached.', 'traydigita-core')],
                502
            );
        }
        $faces = FontDownloader::parseCss($fetched['css']);
        if ($faces === []) {
            wp_send_json_error(
                ['message' => __('Google Fonts returned no usable font files for that family and those weights.', 'traydigita-core')],
                502
            );
        }
        $slug = FontsModule::slugForFamily($family);
        $storedFiles = [];
        $written = [];
        foreach ($faces as $face) {
            $downloaded = FontDownloader::downloadFile($face['url']);
            if (!$downloaded['ok'] || !isset($downloaded['bytes'])) {
                foreach ($written as $fileName) {
                    FontsModule::deleteStoredFile($fileName);
                }
                wp_send_json_error(
                    ['message' => $downloaded['error'] ?? __('A font file could not be downloaded.', 'traydigita-core')],
                    502
                );
            }
            $fileName = FontsModule::fileNameFor($slug, $face['weight'], $face['style'], 'woff2');
            if (!FontsModule::storeFile($fileName, $downloaded['bytes'])) {
                foreach ($written as $writtenFile) {
                    FontsModule::deleteStoredFile($writtenFile);
                }
                wp_send_json_error(
                    ['message' => __('The font files could not be saved on this site.', 'traydigita-core')],
                    500
                );
            }
            $written[] = $fileName;
            $storedFiles[] = [
                'weight' => $face['weight'],
                'style' => $face['style'],
                'file' => $fileName,
                'format' => 'woff2',
                'source' => 'google',
            ];
        }
        if (!FontsModule::replaceFamily(['family' => $family, 'slug' => $slug, 'files' => $storedFiles])) {
            foreach ($written as $fileName) {
                FontsModule::deleteStoredFile($fileName);
            }
            wp_send_json_error(
                ['message' => __('The font could not be registered.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success([
            'message' => __('Font downloaded and stored locally.', 'traydigita-core'),
            'fonts' => FontsModule::publicFonts(),
        ]);
    }

    /**
     * Upload one local font file (woff2, woff, or ttf).
     *
     * The file is MIME-verified by FontDownloader (extension,
     * finfo, and magic bytes must agree) before it is moved into
     * the font directory. Uploading a variant that already exists
     * for the family replaces it; uploading the first file for a
     * new family creates the family.
     *
     * @return void
     */
    public static function upload(): void
    {
        if (!self::authorize()) {
            return;
        }
        $family = sanitize_text_field(wp_unslash($_POST['family'] ?? ''));
        if (!FontsModule::isValidFamilyName($family)) {
            wp_send_json_error(
                ['message' => __('Enter a valid font family name, using letters, digits, spaces, apostrophes, and hyphens only.', 'traydigita-core')],
                400
            );
        }
        $weight = absint($_POST['weight'] ?? 0);
        if ($weight < 100 || $weight > 900 || $weight % 100 !== 0) {
            wp_send_json_error(
                ['message' => __('Choose a valid weight between 100 and 900.', 'traydigita-core')],
                400
            );
        }
        $style = sanitize_key(wp_unslash($_POST['style'] ?? ''));
        if ($style !== 'normal' && $style !== 'italic') {
            wp_send_json_error(
                ['message' => __('Choose a valid font style.', 'traydigita-core')],
                400
            );
        }
        $file = $_FILES['font_file'] ?? null;
        $validated = FontDownloader::validateUploadedFile($file);
        if (!$validated['ok'] || !isset($validated['format'])) {
            wp_send_json_error(
                ['message' => $validated['error'] ?? __('The uploaded file is not a valid font file.', 'traydigita-core')],
                400
            );
        }
        $format = $validated['format'];
        $slug = FontsModule::slugForFamily($family);
        $fileName = FontsModule::fileNameFor($slug, $weight, $style, $format);
        if (!FontsModule::ensureDir()) {
            wp_send_json_error(
                ['message' => __('The font directory could not be created.', 'traydigita-core')],
                500
            );
        }
        $tmpPath = is_array($file) && is_string($file['tmp_name'] ?? null) ? (string) $file['tmp_name'] : '';
        if ($tmpPath === '' || !move_uploaded_file($tmpPath, FontsModule::fontsDir() . '/' . $fileName)) {
            wp_send_json_error(
                ['message' => __('The font file could not be saved on this site.', 'traydigita-core')],
                500
            );
        }
        $existing = null;
        foreach (FontsModule::getFonts() as $font) {
            if ($font['slug'] === $slug) {
                $existing = $font;
                break;
            }
        }
        $files = $existing !== null ? $existing['files'] : [];
        $replaced = false;
        foreach ($files as $index => $entry) {
            if ($entry['weight'] === $weight && $entry['style'] === $style) {
                // A replaced variant may change format (woff2 over
                // ttf): remove the old file first so no orphan
                // stays on disk.
                if ($entry['file'] !== $fileName) {
                    FontsModule::deleteStoredFile($entry['file']);
                }
                $files[$index] = [
                    'weight' => $weight,
                    'style' => $style,
                    'file' => $fileName,
                    'format' => $format,
                    'source' => 'upload',
                ];
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $files[] = [
                'weight' => $weight,
                'style' => $style,
                'file' => $fileName,
                'format' => $format,
                'source' => 'upload',
            ];
        }
        if (!FontsModule::replaceFamily(['family' => $family, 'slug' => $slug, 'files' => $files])) {
            FontsModule::deleteStoredFile($fileName);
            wp_send_json_error(
                ['message' => __('The font could not be registered.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success([
            'message' => __('Font file uploaded.', 'traydigita-core'),
            'fonts' => FontsModule::publicFonts(),
        ]);
    }

    /**
     * Delete a font family, or one variant of it.
     *
     * With `weight` and `style` posted, only that variant is
     * removed (and the whole family goes with it when it was the
     * last variant); otherwise the whole family and all its files
     * are removed. The stylesheet is regenerated either way.
     *
     * @return void
     */
    public static function delete(): void
    {
        if (!self::authorize()) {
            return;
        }
        $slug = sanitize_key(wp_unslash($_POST['family'] ?? ''));
        if ($slug === '') {
            wp_send_json_error(
                ['message' => __('Choose a font to delete.', 'traydigita-core')],
                400
            );
        }
        $weight = absint($_POST['weight'] ?? 0);
        $style = sanitize_key(wp_unslash($_POST['style'] ?? ''));
        if ($weight !== 0 && ($style === 'normal' || $style === 'italic')) {
            $removed = FontsModule::removeVariant($slug, $weight, $style);
        } else {
            $removed = FontsModule::removeFamily($slug);
        }
        if (!$removed) {
            wp_send_json_error(
                ['message' => __('That font was not found.', 'traydigita-core')],
                404
            );
        }
        wp_send_json_success([
            'message' => __('Font deleted.', 'traydigita-core'),
            'fonts' => FontsModule::publicFonts(),
        ]);
    }

    /**
     * Verify the nonce, then the capability.
     *
     * `check_ajax_referer()` dies with -1 on a bad nonce; a
     * missing capability is answered with a JSON 403 instead.
     *
     * @return bool True when the request is authorized.
     */
    private static function authorize(): bool
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        return true;
    }
}
