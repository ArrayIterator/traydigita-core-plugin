<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Customizer;

/**
 * Customizer section ids the theme owns.
 *
 * Core only needs the two sections modules link to from their
 * settings pages (weather and images). The sections themselves
 * live in the theme's Customizer; when the theme is not active
 * the links simply land on the Customizer index, which is the
 * same behaviour as linking to a missing section.
 */
final class Definitions
{
    /**
     * Images section id.
     */
    public const string SECTION_IMAGES = 'traydigita_images';

    /**
     * Weather section id.
     */
    public const string SECTION_WEATHER = 'traydigita_weather';
}
