<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Fonts;

use function count;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function rawurlencode;
use function rtrim;
use function sprintf;
use function str_replace;
use function usort;

/**
 * Builds the `@font-face` stylesheet for locally hosted fonts.
 *
 * Pure function, no WordPress calls, so it can be unit-tested in
 * isolation: given the font registry (families with their stored
 * files) and the public base URL of the font directory, it emits
 * one `@font-face` block per stored file, each pointing at the
 * local file only. Google Fonts URLs never appear in the output -
 * the module downloads the files first and serves them from the
 * site itself.
 *
 * Font record shape (see FontsModule::getFonts()):
 * `['family' => string, 'slug' => string, 'source' => string,
 *   'files' => [['weight' => int, 'style' => 'normal'|'italic',
 *   'file' => string, 'format' => 'woff2'|'woff'|'ttf'], ...]]`
 */
final class FontCss
{
    /**
     * CSS `format()` hint per stored font format.
     *
     * @var array<string, string>
     */
    public const array FORMAT_HINTS = [
        'woff2' => 'woff2',
        'woff' => 'woff',
        'ttf' => 'truetype',
    ];

    /**
     * Build the full `@font-face` stylesheet for the given fonts.
     *
     * Families are emitted in registry order; files inside a family
     * are sorted by weight (ascending), normal before italic, so the
     * output is deterministic. Records with an unknown format, a
     * missing file name, or an out-of-range weight are skipped, so
     * a hand-edited option can never produce broken CSS. An empty
     * registry (or a registry with no usable files) produces an
     * empty string, and the caller then removes the CSS file
     * instead of serving an empty stylesheet.
     *
     * @param array<int, array<string, mixed>> $fonts Font registry records.
     * @param string $baseUrl Public base URL of the font directory, without a trailing slash.
     * @return string The stylesheet, or an empty string when there is nothing to emit.
     */
    public static function build(array $fonts, string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $blocks = [];
        foreach ($fonts as $font) {
            $family = $font['family'] ?? '';
            if (!is_string($family) || $family === '') {
                continue;
            }
            $files = $font['files'] ?? [];
            if (!is_array($files)) {
                continue;
            }
            $usable = [];
            foreach ($files as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $fileName = $file['file'] ?? '';
                $format = $file['format'] ?? '';
                $weight = $file['weight'] ?? 0;
                $style = $file['style'] ?? '';
                if (!is_string($fileName) || $fileName === '') {
                    continue;
                }
                if (!is_string($format) || !isset(self::FORMAT_HINTS[$format])) {
                    continue;
                }
                if (!is_int($weight) || $weight < 100 || $weight > 900 || $weight % 100 !== 0) {
                    continue;
                }
                if ($style !== 'normal' && $style !== 'italic') {
                    continue;
                }
                $usable[] = [
                    'weight' => $weight,
                    'style' => $style,
                    'file' => $fileName,
                    'format' => $format,
                ];
            }
            usort($usable, static function (array $a, array $b): int {
                if ($a['weight'] !== $b['weight']) {
                    return $a['weight'] <=> $b['weight'];
                }
                if ($a['style'] === $b['style']) {
                    return 0;
                }
                return $a['style'] === 'normal' ? -1 : 1;
            });
            foreach ($usable as $file) {
                $blocks[] = sprintf(
                    "@font-face {\n    font-family: '%s';\n    font-style: %s;\n    font-weight: %d;\n    font-display: swap;\n    src: url('%s/%s') format('%s');\n}",
                    self::escapeFamily($family),
                    $file['style'],
                    $file['weight'],
                    $baseUrl,
                    rawurlencode($file['file']),
                    self::FORMAT_HINTS[$file['format']]
                );
            }
        }
        if ($blocks === []) {
            return '';
        }
        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * Escape a family name for use inside a single-quoted CSS string.
     *
     * Backslashes and single quotes are escaped; anything else is
     * left alone because family names are validated on storage.
     *
     * @param string $family Family name.
     * @return string
     */
    public static function escapeFamily(string $family): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $family);
    }

    /**
     * Count the `@font-face` blocks in a generated stylesheet.
     *
     * Convenience for the admin status display; counts the literal
     * `@font-face` marker so the number always matches the output.
     *
     * @param string $css Generated stylesheet.
     * @return int
     */
    public static function countFaces(string $css): int
    {
        return count(explode('@font-face', $css)) - 1;
    }
}
