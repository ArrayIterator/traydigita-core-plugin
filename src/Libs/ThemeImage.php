<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Libs;

use Throwable;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Customizer\Settings;
use TrayDigita\WP\Plugin\Core\Dependencies\ThemeSupport;
use TrayDigita\WP\Plugin\Core\Stack\Libs\Data\FeatureImage;
use TrayDigita\WP\Plugin\Core\Stack\Stack;
use function class_exists;
use function esc_attr;
use function esc_url;
use function explode;
use function function_exists;
use function get_the_title;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sanitize_key;
use function trim;
use function wp_get_attachment_image_srcset;
use function wp_get_registered_image_subsizes;

/**
 * The theme's single image helper.
 *
 * Every featured image / thumbnail in the theme is rendered through
 * this class — never through get_the_post_thumbnail(),
 * the_post_thumbnail(), get_the_post_thumbnail_url() or
 * wp_get_attachment_image_url() directly.
 *
 * Image data comes only from the traydigita-stack helper
 * FeatureImage (new FeatureImage($post) + getThumbnail($size)),
 * srcset from core wp_get_attachment_image_srcset(), and Photon
 * serving only from the stack JetpackPhoton::replace() (see
 * applyPhoton()). The theme never builds an i0.wp.com URL itself.
 *
 * Size names are validated against the sizes WordPress knows
 * (wp_get_registered_image_subsizes() plus the theme sizes from
 * ThemeSupport::IMAGE_SIZES and 'full'). FeatureImage falls back
 * silently to 'thumbnail' for unknown sizes, so an unvalidated
 * typo would render the wrong size with no error — validation here
 * is what prevents that.
 */
final class ThemeImage
{
    /**
     * Default size used when no size is given.
     */
    public const string DEFAULT_SIZE = 'traydigita-card';

    /**
     * Default size per layout slot, used when the matching
     * Customizer setting (thumbnail_size_<slot>) is missing or
     * holds an unknown size name.
     *
     * @var array<string, string>
     */
    public const array SLOT_DEFAULTS = [
        'hero' => 'traydigita-hero',
        'card' => 'traydigita-card',
        'list' => 'traydigita-thumb',
    ];

    /**
     * Attribute names allowed in the $attrs argument of thumbnail().
     *
     * @var array<int, string>
     */
    private const array ALLOWED_ATTRS = ['class', 'id', 'loading', 'decoding', 'fetchpriority', 'sizes'];

    private function __construct()
    {
    }

    /**
     * Render a complete escaped <img> tag for a post's image.
     *
     * The image is resolved through FeatureImage, so a post without
     * a featured image falls back to the first image in its content
     * (the stack helper's behaviour); a post with no image at all
     * returns an empty string. Output is fully escaped (esc_url /
     * esc_attr) and includes width/height, alt text, loading="lazy"
     * and a srcset when WordPress can build one.
     *
     * @param mixed $post Post id, WP_Post or FeatureImage (see FeatureImage::__construct).
     * @param string $size Registered image size name.
     * @param array<string, mixed> $attrs Extra img attributes (class, id, loading,
     *                                     decoding, fetchpriority, sizes).
     * @return string Complete <img> tag, or '' when the post has no image.
     */
    public static function thumbnail(mixed $post, string $size = self::DEFAULT_SIZE, array $attrs = []): string
    {
        $size = self::resolveSize($size);
        $thumb = self::resolveThumbnail($post, $size);
        if ($thumb === null) {
            return '';
        }
        return self::buildImg($thumb, $size, $attrs);
    }

    /**
     * Image URL only, for contexts that need a URL (not markup).
     *
     * Photon is applied here too, so a URL resolved through this
     * method matches the URL rendered by thumbnail().
     *
     * @param mixed $post Post id, WP_Post or FeatureImage (see FeatureImage::__construct).
     * @param string $size Registered image size name.
     * @return string Image URL, or '' when the post has no image.
     */
    public static function url(mixed $post, string $size = self::DEFAULT_SIZE): string
    {
        $size = self::resolveSize($size);
        $thumb = self::resolveThumbnail($post, $size);
        if ($thumb === null) {
            return '';
        }
        return self::applyPhoton((string) $thumb['url']);
    }

    /**
     * Resolve a post's thumbnail array (FeatureImage shape).
     *
     * Exposed so callers that need both the URL and the markup
     * (WidgetBase::normalizePost()) resolve the image once instead
     * of calling url() and thumbnail() separately.
     *
     * @param mixed $post Post id, WP_Post or FeatureImage (see FeatureImage::__construct).
     * @param string $size Registered image size name.
     * @return array{id: int, url: string, width: int, height: int, alt: ?string}|null
     */
    public static function thumbnailArray(mixed $post, string $size = self::DEFAULT_SIZE): ?array
    {
        return self::resolveThumbnail($post, self::resolveSize($size));
    }

    /**
     * Render a complete escaped <img> tag from a FeatureImage-shaped
     * thumbnail array (the shape returned by
     * FeatureImage::getThumbnail() and
     * AnalyticRecord::getThumbnail()).
     *
     * Used by the Popular Posts widget, which already holds the
     * record's thumbnail array and must not re-resolve the post.
     *
     * @param array{id: int, url: string, width: int, height: int, alt: ?string,
     *     thumbnail_size?: string}|null $thumb Thumbnail array, or null.
     * @param string $size Registered image size name (used for the srcset).
     * @param array<string, mixed> $attrs Extra img attributes (see thumbnail()).
     * @return string Complete <img> tag, or '' when there is no thumbnail.
     */
    public static function thumbnailFromArray(?array $thumb, string $size = self::DEFAULT_SIZE, array $attrs = []): string
    {
        if ($thumb === null || !isset($thumb['url']) || !is_string($thumb['url']) || $thumb['url'] === '') {
            return '';
        }
        $size = self::resolveSize($size);
        $srcsetSize = isset($thumb['thumbnail_size']) && is_string($thumb['thumbnail_size'])
            ? self::resolveSize($thumb['thumbnail_size'], $size)
            : $size;
        return self::buildImg($thumb, $srcsetSize, $attrs);
    }

    /**
     * Photon-aware URL from a FeatureImage-shaped thumbnail array.
     *
     * @param array{id: int, url: string, width: int, height: int, alt: ?string}|null $thumb
     * @param string $size Reserved for symmetry with thumbnailFromArray(); the URL
     *                     itself is already size-resolved by getThumbnail().
     * @return string Image URL, or '' when there is no thumbnail.
     */
    public static function urlFromArray(?array $thumb, string $size = self::DEFAULT_SIZE): string
    {
        if ($thumb === null || !isset($thumb['url']) || !is_string($thumb['url']) || $thumb['url'] === '') {
            return '';
        }
        return self::applyPhoton($thumb['url']);
    }

    /**
     * Size name for a layout slot, from the Customizer with safe fallbacks.
     *
     * Reads Settings::get('thumbnail_size_' . $slot). The
     * settings are added by the Customizer track; until they exist,
     * get() returns null and the slot default below is used, so this
     * works before the settings are registered. A stored value that
     * is not a registered size is ignored silently and the slot
     * default is used instead — FeatureImage would otherwise fall
     * back to 'thumbnail' without any error.
     *
     * @param string $slot Layout slot: 'hero', 'card' or 'list'.
     * @return string Registered image size name.
     */
    public static function slotSize(string $slot): string
    {
        $slot = sanitize_key($slot);
        $default = self::SLOT_DEFAULTS[$slot] ?? self::DEFAULT_SIZE;
        $stored = Settings::get('thumbnail_size_' . $slot);
        if (is_string($stored) && $stored !== '') {
            return self::resolveSize(sanitize_key($stored), $default);
        }
        return self::resolveSize($default);
    }

    /**
     * Resolve a post's thumbnail through the stack FeatureImage helper.
     *
     * @param mixed $post Post id, WP_Post or FeatureImage.
     * @param string $size Registered image size name.
     * @return array{id: int, url: string, width: int, height: int, alt: ?string}|null
     */
    private static function resolveThumbnail(mixed $post, string $size): ?array
    {
        if (!class_exists(FeatureImage::class)) {
            return null;
        }
        try {
            $image = new FeatureImage($post);
            $thumb = $image->getThumbnail($size);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($thumb) || !isset($thumb['url']) || !is_string($thumb['url']) || $thumb['url'] === '') {
            return null;
        }
        if (empty($thumb['alt'])) {
            $wpPost = $image->getPost();
            if ($wpPost !== null) {
                $title = get_the_title($wpPost);
                if (is_string($title) && $title !== '') {
                    $thumb['alt'] = $title;
                }
            }
        }
        return $thumb;
    }

    /**
     * Validate a size name; fall back instead of letting FeatureImage
     * silently substitute 'thumbnail' for a typo.
     *
     * @param string $size Requested size name.
     * @param string $fallback Size used when $size is unknown.
     * @return string A registered size name.
     */
    private static function resolveSize(string $size, string $fallback = self::DEFAULT_SIZE): string
    {
        if (self::isRegisteredSize($size)) {
            return $size;
        }
        if (self::isRegisteredSize($fallback)) {
            return $fallback;
        }
        return 'thumbnail';
    }

    /**
     * Whether a size name is known to WordPress or to this theme.
     *
     * 'full' is not a registered sub-size, so it is accepted
     * explicitly. Theme sizes are accepted from
     * ThemeSupport::IMAGE_SIZES as well as from the registered
     * sub-sizes, so validation works even before after_setup_theme
     * has run add_image_size().
     *
     * @param string $size Size name.
     * @return bool
     */
    private static function isRegisteredSize(string $size): bool
    {
        if ($size === '' || $size === 'full') {
            return $size === 'full';
        }
        if (isset(ThemeSupport::IMAGE_SIZES[$size])) {
            return true;
        }
        if (!function_exists('wp_get_registered_image_subsizes')) {
            return false;
        }
        $sizes = wp_get_registered_image_subsizes();
        return isset($sizes[$size]);
    }

    /**
     * Build the <img> tag for a resolved thumbnail array.
     *
     * @param array{id: int, url: string, width: int, height: int, alt: ?string} $thumb
     * @param string $size Size name used for the srcset.
     * @param array<string, mixed> $attrs Extra img attributes (see thumbnail()).
     * @return string Complete escaped <img> tag.
     */
    private static function buildImg(array $thumb, string $size, array $attrs): string
    {
        $attrs = self::normalizeAttrs($attrs);
        $id = isset($thumb['id']) && is_int($thumb['id']) ? $thumb['id'] : 0;
        $width = isset($thumb['width']) && is_int($thumb['width']) ? $thumb['width'] : 0;
        $height = isset($thumb['height']) && is_int($thumb['height']) ? $thumb['height'] : 0;
        $alt = isset($thumb['alt']) && is_string($thumb['alt']) ? $thumb['alt'] : '';
        $html = '<img src="' . esc_url(self::applyPhoton((string) $thumb['url'])) . '"';
        $srcset = $id > 0 ? self::buildSrcset($id, $size) : '';
        if ($srcset !== '') {
            $html .= ' srcset="' . esc_attr($srcset) . '"';
        }
        if (isset($attrs['sizes'])) {
            $html .= ' sizes="' . esc_attr($attrs['sizes']) . '"';
        }
        if ($width > 0) {
            $html .= ' width="' . esc_attr((string) $width) . '"';
        }
        if ($height > 0) {
            $html .= ' height="' . esc_attr((string) $height) . '"';
        }
        $html .= ' alt="' . esc_attr($alt) . '"';
        if (isset($attrs['class'])) {
            $html .= ' class="' . esc_attr($attrs['class']) . '"';
        }
        if (isset($attrs['id'])) {
            $html .= ' id="' . esc_attr($attrs['id']) . '"';
        }
        $html .= ' loading="' . esc_attr($attrs['loading']) . '"';
        $html .= ' decoding="' . esc_attr($attrs['decoding']) . '"';
        if (isset($attrs['fetchpriority'])) {
            $html .= ' fetchpriority="' . esc_attr($attrs['fetchpriority']) . '"';
        }
        $html .= '>';
        return $html;
    }

    /**
     * Build a srcset for an attachment id and size, Photon-aware.
     *
     * @param int $attachmentId Attachment id.
     * @param string $size Registered image size name.
     * @return string Srcset value, or '' when WordPress builds none.
     */
    private static function buildSrcset(int $attachmentId, string $size): string
    {
        if (!function_exists('wp_get_attachment_image_srcset')) {
            return '';
        }
        $srcset = wp_get_attachment_image_srcset($attachmentId, $size);
        if (!is_string($srcset) || $srcset === '') {
            return '';
        }
        if (!self::isPhotonEnabled()) {
            return $srcset;
        }
        $entries = [];
        foreach (explode(',', $srcset) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $parts = explode(' ', $entry, 2);
            $parts[0] = self::applyPhoton($parts[0]);
            $entries[] = implode(' ', $parts);
        }
        return implode(', ', $entries);
    }

    /**
     * Keep only allowlisted attributes, with validated values.
     *
     * loading defaults to 'lazy' and decoding to 'async'; both only
     * accept their allowlisted values. fetchpriority and sizes are
     * included only when given a valid value.
     *
     * @param array<string, mixed> $attrs Raw attributes.
     * @return array<string, string> Clean attributes.
     */
    private static function normalizeAttrs(array $attrs): array
    {
        $clean = [];
        foreach ($attrs as $name => $value) {
            if (!is_string($name) || !in_array($name, self::ALLOWED_ATTRS, true) || !is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if ($name === 'loading' && !in_array($value, ['lazy', 'eager', 'auto'], true)) {
                continue;
            }
            if ($name === 'decoding' && !in_array($value, ['async', 'sync', 'auto'], true)) {
                continue;
            }
            if ($name === 'fetchpriority' && !in_array($value, ['high', 'low', 'auto'], true)) {
                continue;
            }
            $clean[$name] = $value;
        }
        $clean['loading'] = $clean['loading'] ?? 'lazy';
        $clean['decoding'] = $clean['decoding'] ?? 'async';
        return $clean;
    }

    /**
     * Whether Photon serving is enabled in the Customizer.
     *
     * The photon_enabled checkbox is added by the Customizer track;
     * until it exists, get() returns null and Photon stays off.
     *
     * @return bool
     */
    private static function isPhotonEnabled(): bool
    {
        return Settings::sanitizeCheckbox(Settings::get('photon_enabled'));
    }

    /**
     * Pass a URL through the stack Photon helper when enabled.
     *
     * Implemented ONLY via Core::stack()->getJetpackPhoton()->replace();
     * the theme never builds an i0.wp.com URL itself. When the stack
     * helper is unavailable (stack plugin inactive), the original URL
     * is returned unchanged. No resize query parameters (?w=, ?h=,
     * ?fit=) are appended by default: Photon rewrites the host only,
     * and the requested image size is already served by WordPress
     * (the URL points at the registered sub-size file), so
     * server-side resizing parameters are intentionally not added.
     *
     * @param string $url Image URL.
     * @return string Photon URL, or the original URL unchanged.
     */
    private static function applyPhoton(string $url): string
    {
        if ($url === '' || !self::isPhotonEnabled()) {
            return $url;
        }
        if (!class_exists(Stack::class)) {
            return $url;
        }
        try {
            $photon = Core::stack()->getJetpackPhoton();
            $replaced = $photon->replace($url);
            return $replaced !== '' ? $replaced : $url;
        } catch (Throwable) {
            return $url;
        }
    }
}
