<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core;

use function base64_encode;
use function file_get_contents;
use function is_readable;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * TrayDigita Core brand assets (logo v4).
 *
 * The logo keeps the ORIGINAL TrayDigita mark - the white T+D
 * glyph from the theme (`resources/images/traydigita-mark.svg`);
 * no new mark was created. The mark is THREE separate white
 * pieces (its three potrace subpaths: top swoosh, stem, bowl).
 * The theme is "Connecting To The Core": ABSTRACT first - the
 * three pieces fly in as abstract shapes from different
 * directions (swoosh from the top, stem from bottom-left, bowl
 * from bottom-right), each spinning and fading in, so no whole
 * T or D shows at the start; they lock together into the T+D
 * logo, a gold flash fires at the seam, then circuit traces
 * draw out from behind the logo to six CHIP nodes - abstract
 * microchips with pins, NOT round circles, and NO ring - which
 * pop in one by one. The animation is a 5 s video (MP4, 512x512,
 * h264, 24 fps) plus the GIF; the SVG keeps the same story as
 * a SMIL animation. The assets live in `assets/brand/` (see
 * `generate.py`, re-runnable - it bakes the frames in Python
 * from chrome-headless-shell screenshots and assembles them
 * with ffmpeg):
 *
 * - `traydigita-core-logo.mp4` - the 5 s animation. Kept for API
 *   compatibility (Brand::logoVideo()); the hub/about page no
 *   longer uses it because MP4 (h264, yuv420p) cannot carry an
 *   alpha channel, so its corners are white.
 * - `traydigita-core-logo.svg` - animated (SMIL, as above).
 *   Used as the admin menu icon (base64 data URI; browsers
 *   animate SMIL in data-URI SVGs, and the first frame is the
 *   correct static logo where they do not).
 * - `traydigita-core-logo-static.svg` - the same mark without
 *   animation, for contexts that cannot animate.
 * - `traydigita-core-logo.gif` - animated raster version with a
 *   transparent background, used on the hub/about page (and the
 *   readme).
 * - `icon-128x128.png`, `icon-256x256.png`, `icon-512x512.png`
 *   - plugin icons (WP.org sizes).
 * - `banner-772x250.png`, `banner-1544x500.png` - readme banners.
 *
 * Earlier versions are NOT used: v1 (a core + nodes mark that
 * looked like an eye), v2 (T and D halves sliding together)
 * and v3 (six round nodes on a ring) were all rejected by the
 * user.
 *
 * Nothing here redesigns the admin UI: the admin design stays
 * the theme's Elegant Blue; only the logo mark changes.
 *
 * @package TrayDigita\WP\Plugin\Core
 */
final class Brand
{
    /**
     * Relative path of the animated logo inside the plugin.
     */
    public const string LOGO_ANIMATED = 'assets/brand/traydigita-core-logo.svg';

    /**
     * Relative path of the static logo inside the plugin.
     */
    public const string LOGO_STATIC = 'assets/brand/traydigita-core-logo-static.svg';

    /**
     * Relative path of the animated GIF logo inside the plugin.
     */
    public const string LOGO_GIF = 'assets/brand/traydigita-core-logo.gif';

    /**
     * Relative path of the logo video inside the plugin.
     */
    public const string LOGO_VIDEO = 'assets/brand/traydigita-core-logo.mp4';

    /**
     * The animated logo markup, for inline use in a template.
     *
     * The file is a static asset shipped with the plugin, not
     * user input; the contents are returned as-is after a
     * sanity check that the file really is an SVG document.
     *
     * @return string SVG markup, or '' when the file is missing or invalid.
     */
    public static function logoSvg(): string
    {
        $file = Core::pluginDir() . '/' . self::LOGO_ANIMATED;
        if (!is_readable($file)) {
            return '';
        }
        $svg = file_get_contents($file);
        if (!is_string($svg) || $svg === '') {
            return '';
        }
        $svg = trim($svg);
        if (!str_starts_with($svg, '<svg')) {
            return '';
        }
        return $svg;
    }

    /**
     * Admin menu icon: the animated logo as a data URI.
     *
     * WordPress accepts a data URI as the `add_menu_page()`
     * icon; the SMIL animation plays in browsers that animate
     * SVG images, and the first frame is the logo everywhere
     * else. Falls back to the static logo URL when the animated
     * file cannot be read.
     *
     * @return string Data URI or plugin URL.
     */
    public static function menuIconUrl(): string
    {
        $file = Core::pluginDir() . '/' . self::LOGO_ANIMATED;
        if (is_readable($file)) {
            $svg = file_get_contents($file);
            if (is_string($svg) && $svg !== '') {
                return 'data:image/svg+xml;base64,' . base64_encode($svg);
            }
        }
        return Core::pluginUrl() . '/' . self::LOGO_STATIC;
    }

    /**
     * URL of the animated logo file.
     *
     * @return string
     */
    public static function logoUrl(): string
    {
        return Core::pluginUrl() . '/' . self::LOGO_ANIMATED;
    }

    /**
     * URL of the static logo file.
     *
     * @return string
     */
    public static function staticLogoUrl(): string
    {
        return Core::pluginUrl() . '/' . self::LOGO_STATIC;
    }

    /**
     * URL of the animated GIF logo (readme use).
     *
     * @return string
     */
    public static function gifUrl(): string
    {
        return Core::pluginUrl() . '/' . self::LOGO_GIF;
    }

    /**
     * URL of the logo video (5 s MP4).
     *
     * @return string
     */
    public static function videoUrl(): string
    {
        return Core::pluginUrl() . '/' . self::LOGO_VIDEO;
    }

    /**
     * The animated GIF logo markup for the hub/about page.
     *
     * The GIF has a transparent background (see `generate.py`),
     * so it sits cleanly on any admin background - the MP4 it
     * replaces cannot carry alpha. Returns '' when the GIF file
     * is missing, so the caller can fall back to logoSvg().
     *
     * @param int $size Display size in pixels (square).
     * @return string Img markup, or ''.
     */
    public static function logoGif(int $size = 72): string
    {
        $file = Core::pluginDir() . '/' . self::LOGO_GIF;
        if (!is_readable($file)) {
            return '';
        }
        return sprintf(
            '<img src="%2$s" width="%1$d" height="%1$d" alt="" aria-hidden="true">',
            $size,
            self::gifUrl()
        );
    }

    /**
     * The logo video markup.
     *
     * Autoplays muted on a loop (the 5 s animation); the
     * animated SVG is the fallback content for browsers that
     * cannot play the video. Returns '' when the video file is
     * missing, so the caller can fall back to logoSvg().
     *
     * @param int $size Display size in pixels (square).
     * @return string Video markup, or ''.
     */
    public static function logoVideo(int $size = 72): string
    {
        $file = Core::pluginDir() . '/' . self::LOGO_VIDEO;
        if (!is_readable($file)) {
            return '';
        }
        $fallback = self::logoSvg();
        return sprintf(
            '<video width="%1$d" height="%1$d" autoplay muted loop playsinline aria-hidden="true"><source src="%2$s" type="video/mp4">%3$s</video>',
            $size,
            self::videoUrl(),
            $fallback
        );
    }

    /**
     * URL of a plugin icon PNG (128, 256, or 512 px).
     *
     * @param int $size Icon size: 128, 256, or 512.
     * @return string
     */
    public static function iconUrl(int $size = 256): string
    {
        $size = match ($size) {
            128 => 128,
            512 => 512,
            default => 256,
        };
        return sprintf('%s/assets/brand/icon-%2$dx%2$d.png', Core::pluginUrl(), $size);
    }

    /**
     * URL of a readme banner PNG.
     *
     * @param bool $large True for 1544x500, false for 772x250.
     * @return string
     */
    public static function bannerUrl(bool $large = true): string
    {
        return Core::pluginUrl() . ($large ? '/assets/brand/banner-1544x500.png' : '/assets/brand/banner-772x250.png');
    }
}
