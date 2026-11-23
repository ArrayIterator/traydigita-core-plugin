<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Dependencies;

use function add_image_size;

/**
 * Image sizes the modules rely on.
 *
 * The theme registers these sizes from its ThemeSupport
 * dependency; Core registers the same five sizes itself so
 * thumbnails keep working when Core runs without the theme.
 * ThemeImage resolves a size name through this map first and
 * falls back to WordPress core sizes for anything else.
 */
final class ThemeSupport
{
    /**
     * Image size name => [width, height, crop].
     *
     * @var array<string, array{0: int, 1: int, 2: bool}>
     */
    public const array IMAGE_SIZES = [
        'traydigita-hero' => [1200, 675, true],
        'traydigita-featured' => [800, 450, true],
        'traydigita-card' => [600, 400, true],
        'traydigita-thumb' => [300, 200, true],
        'traydigita-square' => [400, 400, true],
    ];

    /**
     * Register the image sizes with WordPress.
     *
     * Hooked on `after_setup_theme` by Core::init().
     *
     * @return void
     */
    public static function register(): void
    {
        foreach (self::IMAGE_SIZES as $name => $dimensions) {
            add_image_size($name, $dimensions[0], $dimensions[1], $dimensions[2]);
        }
    }
}
