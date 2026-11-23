<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ImageOptimizer;

use Throwable;
use WP_Error;
use function class_exists;
use function dirname;
use function filesize;
use function function_exists;
use function get_attached_file;
use function get_post_meta;
use function get_post_mime_type;
use function getimagesize;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function pathinfo;
use function rename;
use function time;
use function uniqid;
use function unlink;
use function update_attached_file;
use function update_post_meta;
use function wp_basename;
use function wp_check_filetype;
use function wp_get_attachment_metadata;
use function wp_get_image_editor;
use function wp_image_editor_supports;
use function wp_update_attachment_metadata;

/**
 * Image conversion engine for the Image Optimizer module.
 *
 * Converts JPEG/PNG (and WebP, when the target is AVIF) uploads to
 * WebP or AVIF with `wp_get_image_editor()`, and compresses them at
 * the configured quality. Every conversion is verified before
 * anything is replaced: the editor writes to a temporary file in
 * the same directory, the temporary file must exist and pass
 * `getimagesize()` with the expected target MIME type, and only
 * then is it renamed over the destination. An original file is
 * deleted only when the `keep_original` setting is explicitly off
 * AND its converted replacement was verified — a failed conversion
 * never deletes or corrupts an original, and the attachment
 * metadata is left untouched on failure.
 *
 * The original file and every generated sub-size are converted, so
 * the attachment keeps working everywhere WordPress serves it: the
 * `_wp_attached_file` value and each size's `file` entry are
 * rewritten to the converted filenames, and the metadata
 * `mime-type` is updated. With `keep_original` on (the default)
 * the old files stay on disk as unreferenced backups.
 *
 * Format support is detected through the WordPress image-editor
 * API (`wp_image_editor_supports()`), so an editor that cannot
 * write WebP or AVIF (an old GD build, for example) is detected
 * up front and the attachment is skipped with a recorded reason
 * instead of failing loudly. No conversion ever runs when Photon
 * is serving the images (see `ImageOptimizerModule::isPhotonEnabled()`).
 *
 * Per-attachment results are recorded in postmeta so a bulk re-run
 * is cheap: `_traydigita_image_optimizer` holds the full record
 * (status, format, quality, time, saved bytes, reason), and
 * `_traydigita_image_optimizer_status` holds the plain status
 * string (`optimized`, `skipped`, `failed`) for cheap counting.
 */
final class Optimizer
{
    /**
     * Postmeta key holding the full per-attachment optimization record.
     */
    public const string META_KEY = '_traydigita_image_optimizer';

    /**
     * Postmeta key holding the plain optimization status string.
     */
    public const string STATUS_KEY = '_traydigita_image_optimizer_status';

    /**
     * Status value for a successfully optimized attachment.
     */
    public const string STATUS_OPTIMIZED = 'optimized';

    /**
     * Status value for an attachment that was examined but not converted.
     */
    public const string STATUS_SKIPPED = 'skipped';

    /**
     * Status value for an attachment whose conversion failed.
     */
    public const string STATUS_FAILED = 'failed';

    /**
     * MIME types that may be converted at all.
     *
     * GIF is excluded on purpose: converting an animated GIF would
     * silently drop the animation. SVG and BMP are not raster
     * images the editor can convert, and ICO files are site icons.
     */
    public const array CONVERTIBLE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * MIME type for the WebP target format.
     */
    public const string MIME_WEBP = 'image/webp';

    /**
     * MIME type for the AVIF target format.
     */
    public const string MIME_AVIF = 'image/avif';

    /**
     * Which target formats the active image editor can actually write.
     *
     * Detection goes through `wp_image_editor_supports()`, the
     * WordPress API that asks every registered editor (Imagick, GD)
     * whether it supports the MIME type. When that function is
     * unavailable (very old WordPress), the check falls back to
     * asking the editor classes directly; when no editor class is
     * loaded at all, both formats report unsupported instead of
     * fataling.
     *
     * @return array{webp: bool, avif: bool}
     */
    public static function supportedFormats(): array
    {
        $supported = [
            'webp' => false,
            'avif' => false,
        ];
        foreach ($supported as $format => $unused) {
            $mime = self::targetMime($format);
            if (function_exists('wp_image_editor_supports')) {
                $supported[$format] = (bool) wp_image_editor_supports([
                    'mime_types' => [$mime],
                ]);
                continue;
            }
            $supported[$format] = self::editorClassSupports($mime);
        }
        return $supported;
    }

    /**
     * Whether the target format is supported by the image editor.
     *
     * @param string $format Target format slug (`webp` or `avif`).
     * @return bool
     */
    public static function isFormatSupported(string $format): bool
    {
        $supported = self::supportedFormats();
        return $supported[$format] ?? false;
    }

    /**
     * MIME type for a target format slug.
     *
     * @param string $format `webp` or `avif`; anything else falls back to WebP.
     * @return string
     */
    public static function targetMime(string $format): string
    {
        return $format === 'avif' ? self::MIME_AVIF : self::MIME_WEBP;
    }

    /**
     * Whether a source MIME type may be converted at all.
     *
     * Non-image attachments are never touched: this only returns
     * true for JPEG, PNG, and WebP (the last one only matters when
     * the target is AVIF).
     *
     * @param string $mime Source MIME type.
     * @return bool
     */
    public static function isOptimizableMime(string $mime): bool
    {
        return in_array($mime, self::CONVERTIBLE_MIME_TYPES, true);
    }

    /**
     * Read the stored optimization record for an attachment.
     *
     * @param int $attachmentId Attachment post id.
     * @return array<string, mixed> Empty array when nothing was recorded yet.
     */
    public static function getRecord(int $attachmentId): array
    {
        $record = get_post_meta($attachmentId, self::META_KEY, true);
        return is_array($record) ? $record : [];
    }

    /**
     * Whether an attachment is already optimized for these settings.
     *
     * A re-run is cheap because of this: the bulk runner skips
     * attachments whose recorded format and quality already match
     * the current settings without opening the image editor.
     *
     * @param int $attachmentId Attachment post id.
     * @param string $format Target format slug.
     * @param int $quality Quality setting (1-100).
     * @return bool
     */
    public static function isAlreadyOptimized(int $attachmentId, string $format, int $quality): bool
    {
        $record = self::getRecord($attachmentId);
        return ($record['status'] ?? '') === self::STATUS_OPTIMIZED
            && ($record['format'] ?? '') === $format
            && (int) ($record['quality'] ?? 0) === $quality;
    }

    /**
     * Optimize one attachment: convert its original file and every
     * generated sub-size to the target format.
     *
     * All-or-nothing per attachment: every file is converted to a
     * temporary file and verified first; only when every conversion
     * passed are the metadata rewritten and (when `keep_original`
     * is off) the old files deleted. A failure anywhere deletes the
     * temporary converted files, leaves the originals and the
     * metadata untouched, and records `failed` with the reason.
     * Files that are missing on disk (a sub-size deleted by hand,
     * for example) are skipped without failing the attachment.
     *
     * @param int $attachmentId Attachment post id.
     * @param array{format: string, quality: int, keep_original: bool} $settings Module settings.
     * @param bool $photonEnabled Whether Photon is serving images (skip when true).
     * @return array{status: string, reason: string, saved_bytes: int} Outcome; `status` is one of the STATUS_* constants.
     */
    public static function optimizeAttachment(int $attachmentId, array $settings, bool $photonEnabled): array
    {
        $format = $settings['format'] ?? 'webp';
        $quality = (int) ($settings['quality'] ?? ImageOptimizerModule::DEFAULT_QUALITY);
        $keepOriginal = (bool) ($settings['keep_original'] ?? true);
        $targetMime = self::targetMime($format);

        if ($photonEnabled) {
            return self::record($attachmentId, self::STATUS_SKIPPED, 'photon', $format, $quality, 0);
        }

        $file = get_attached_file($attachmentId);
        if (!is_string($file) || $file === '' || !is_file($file)) {
            return self::record($attachmentId, self::STATUS_SKIPPED, 'missing_file', $format, $quality, 0);
        }

        $mime = get_post_mime_type($attachmentId);
        if (!is_string($mime) || $mime === '') {
            $checked = wp_check_filetype($file);
            $mime = is_string($checked['type'] ?? null) ? $checked['type'] : '';
        }
        if (!self::isOptimizableMime($mime)) {
            return self::record($attachmentId, self::STATUS_SKIPPED, 'not_image', $format, $quality, 0);
        }
        if (self::isAlreadyOptimized($attachmentId, $format, $quality)) {
            return self::record($attachmentId, self::STATUS_SKIPPED, 'already_optimized', $format, $quality, 0);
        }
        if (!self::isFormatSupported($format)) {
            return self::record($attachmentId, self::STATUS_SKIPPED, 'unsupported_format', $format, $quality, 0);
        }

        $metadata = wp_get_attachment_metadata($attachmentId);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        // Collect every file to convert: the original plus each
        // generated sub-size, deduplicated. Missing files are
        // skipped without failing the attachment.
        $directory = dirname($file);
        $files = [$file];
        $sizes = isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : [];
        foreach ($sizes as $size) {
            if (!is_array($size) || !isset($size['file']) || !is_string($size['file'])) {
                continue;
            }
            $sizeFile = $directory . '/' . $size['file'];
            if (is_file($sizeFile) && !in_array($sizeFile, $files, true)) {
                $files[] = $sizeFile;
            }
        }

        // Convert every file to a temporary file and verify it
        // before touching anything live.
        $converted = [];
        $savedBytes = 0;
        foreach ($files as $source) {
            $result = self::convertFile($source, $targetMime, $quality);
            if ($result === null) {
                self::cleanupConverted($converted);
                return self::record($attachmentId, self::STATUS_FAILED, 'conversion_failed', $format, $quality, 0);
            }
            $converted[$source] = $result;
            $savedBytes += $result['saved_bytes'];
        }

        // Every conversion verified: rename the temporary files
        // over their destinations, then rewrite the metadata.
        $renamed = [];
        foreach ($converted as $source => $info) {
            $destination = $info['destination'];
            if ($destination === $source) {
                // Same path (recompress in place): the temporary
                // file replaces the original atomically.
                if (!rename($info['temp'], $destination)) {
                    self::cleanupConverted($converted);
                    self::restoreRenamed($renamed);
                    return self::record($attachmentId, self::STATUS_FAILED, 'conversion_failed', $format, $quality, 0);
                }
            } elseif (!rename($info['temp'], $destination)) {
                self::cleanupConverted($converted);
                self::restoreRenamed($renamed);
                return self::record($attachmentId, self::STATUS_FAILED, 'conversion_failed', $format, $quality, 0);
            }
            $renamed[$source] = $destination;
        }

        $metadata = self::rewriteMetadata($metadata, $renamed, $targetMime);
        wp_update_attachment_metadata($attachmentId, $metadata);
        $originalPath = $renamed[$file] ?? $file;
        if ($originalPath !== $file) {
            update_attached_file($attachmentId, $originalPath);
        }

        // Only now, with the converted files verified and live, may
        // the originals be deleted — and only when the setting
        // explicitly says so.
        if (!$keepOriginal) {
            foreach ($renamed as $source => $destination) {
                if ($destination !== $source && is_file($source)) {
                    unlink($source);
                }
            }
        }

        return self::record($attachmentId, self::STATUS_OPTIMIZED, '', $format, $quality, $savedBytes);
    }

    /**
     * Convert one file to the target MIME type via the image editor.
     *
     * The editor writes to a temporary file in the same directory
     * (same filesystem, so the later rename is atomic). The
     * temporary file is verified — it must exist and
     * `getimagesize()` must report the target MIME type — before
     * it is accepted. Returns null on any failure, in which case
     * the temporary file is removed.
     *
     * @param string $source Absolute path of the source file.
     * @param string $targetMime Target MIME type (`image/webp` or `image/avif`).
     * @param int $quality Quality setting (1-100).
     * @return array{temp: string, destination: string, saved_bytes: int}|null Conversion result, or null on failure.
     */
    private static function convertFile(string $source, string $targetMime, int $quality): ?array
    {
        $extension = $targetMime === self::MIME_AVIF ? 'avif' : 'webp';
        $info = pathinfo($source);
        $destination = ($info['dirname'] ?? dirname($source)) . '/' . ($info['filename'] ?? wp_basename($source)) . '.' . $extension;
        $temp = ($info['dirname'] ?? dirname($source)) . '/.traydigita-' . uniqid('', true) . '.' . $extension;
        try {
            $editor = wp_get_image_editor($source);
            if ($editor instanceof WP_Error) {
                return null;
            }
            $editor->set_quality($quality);
            $saved = $editor->save($temp, $targetMime);
            if ($saved instanceof WP_Error || !is_array($saved) || !isset($saved['path'])) {
                if (is_file($temp)) {
                    unlink($temp);
                }
                return null;
            }
            if (!self::isVerifiedImage($temp, $targetMime)) {
                if (is_file($temp)) {
                    unlink($temp);
                }
                return null;
            }
        } catch (Throwable) {
            if (is_file($temp)) {
                unlink($temp);
            }
            return null;
        }
        $originalSize = filesize($source);
        $newSize = filesize($temp);
        $savedBytes = 0;
        if ($originalSize !== false && $newSize !== false && $originalSize > $newSize) {
            $savedBytes = $originalSize - $newSize;
        }
        return [
            'temp' => $temp,
            'destination' => $destination,
            'saved_bytes' => $savedBytes,
        ];
    }

    /**
     * Verify a converted file: it exists and `getimagesize()` reads
     * it as a real image of the expected MIME type.
     *
     * @param string $path Absolute path to check.
     * @param string $expectedMime Expected MIME type.
     * @return bool
     */
    private static function isVerifiedImage(string $path, string $expectedMime): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $info = getimagesize($path);
        if ($info === false) {
            return false;
        }
        return $info['mime'] === $expectedMime;
    }

    /**
     * Rewrite attachment metadata after a successful conversion.
     *
     * The `file` entry of the original and of every converted
     * sub-size is pointed at the new filename, and the top-level
     * `mime-type` is updated so WordPress serves the converted
     * files. Sizes whose source file was missing on disk keep
     * their old entry.
     *
     * @param array<string, mixed> $metadata Attachment metadata.
     * @param array<string, string> $renamed Map of old absolute path to new absolute path.
     * @param string $targetMime Target MIME type.
     * @return array<string, mixed> Updated metadata.
     */
    private static function rewriteMetadata(array $metadata, array $renamed, string $targetMime): array
    {
        $metadata['mime-type'] = $targetMime;
        if (isset($metadata['file']) && is_string($metadata['file'])) {
            $directory = dirname($metadata['file']);
            $basename = wp_basename($metadata['file']);
            foreach ($renamed as $old => $new) {
                if (wp_basename($old) === $basename) {
                    $metadata['file'] = $directory === '.' ? wp_basename($new) : $directory . '/' . wp_basename($new);
                    break;
                }
            }
        }
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $name => $size) {
                if (!is_array($size) || !isset($size['file']) || !is_string($size['file'])) {
                    continue;
                }
                foreach ($renamed as $old => $new) {
                    if (wp_basename($old) === $size['file']) {
                        $metadata['sizes'][$name]['file'] = wp_basename($new);
                        $metadata['sizes'][$name]['mime-type'] = $targetMime;
                        break;
                    }
                }
            }
        }
        return $metadata;
    }

    /**
     * Delete temporary converted files after a failed run.
     *
     * @param array<string, array{temp: string, destination: string, saved_bytes: int}> $converted Conversions to clean up.
     * @return void
     */
    private static function cleanupConverted(array $converted): void
    {
        foreach ($converted as $info) {
            if (isset($info['temp']) && is_file($info['temp'])) {
                unlink($info['temp']);
            }
        }
    }

    /**
     * Best-effort restore of already-renamed files after a later
     * rename in the same run failed.
     *
     * A rename only fails on a filesystem error, so this path is
     * defensive: the metadata has not been rewritten yet at that
     * point, so restoring the original filenames keeps the
     * attachment consistent.
     *
     * @param array<string, string> $renamed Map of old absolute path to new absolute path.
     * @return void
     */
    private static function restoreRenamed(array $renamed): void
    {
        foreach ($renamed as $source => $destination) {
            if ($destination !== $source && is_file($destination) && !is_file($source)) {
                rename($destination, $source);
            }
        }
    }

    /**
     * Record a failed outcome for an attachment that threw while
     * optimizing (used by the bulk runner so one bad attachment
     * never kills the whole run).
     *
     * @param int $attachmentId Attachment post id.
     * @param array{format: string, quality: int, keep_original: bool} $settings Module settings.
     * @return array{status: string, reason: string, saved_bytes: int}
     */
    public static function markFailed(int $attachmentId, array $settings): array
    {
        return self::record(
            $attachmentId,
            self::STATUS_FAILED,
            'conversion_failed',
            $settings['format'] ?? 'webp',
            (int) ($settings['quality'] ?? ImageOptimizerModule::DEFAULT_QUALITY),
            0
        );
    }

    /**
     * Record an optimization outcome in postmeta and return it.
     *
     * @param int $attachmentId Attachment post id.
     * @param string $status One of the STATUS_* constants.
     * @param string $reason Machine-readable reason for skipped/failed outcomes (empty when optimized).
     * @param string $format Target format slug.
     * @param int $quality Quality setting.
     * @param int $savedBytes Bytes saved by the conversion.
     * @return array{status: string, reason: string, saved_bytes: int}
     */
    private static function record(int $attachmentId, string $status, string $reason, string $format, int $quality, int $savedBytes): array
    {
        $record = [
            'status' => $status,
            'reason' => $reason,
            'format' => $format,
            'quality' => $quality,
            'saved_bytes' => $savedBytes,
            'time' => time(),
        ];
        update_post_meta($attachmentId, self::META_KEY, $record);
        update_post_meta($attachmentId, self::STATUS_KEY, $status);
        return [
            'status' => $status,
            'reason' => $reason,
            'saved_bytes' => $savedBytes,
        ];
    }

    /**
     * Fallback format-support check against the editor classes.
     *
     * Used only when `wp_image_editor_supports()` is unavailable.
     *
     * @param string $mime MIME type to check.
     * @return bool
     */
    private static function editorClassSupports(string $mime): bool
    {
        foreach (['WP_Image_Editor_Imagick', 'WP_Image_Editor_GD'] as $class) {
            if (!class_exists($class)) {
                continue;
            }
            if (method_exists($class, 'supports_mime_type') && $class::supports_mime_type($mime)) {
                return true;
            }
        }
        return false;
    }
}
