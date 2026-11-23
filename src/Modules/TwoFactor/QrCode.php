<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

use function count;
use function is_array;
use function is_readable;
use function sprintf;
use function strlen;
use function substr_count;

/**
 * QR code rendering for the TOTP enrolment screen.
 *
 * The matrix comes from the vendored phpqrcode library
 * (`vendor/phpqrcode/`, LGPL 3 — see its README.md): the encoder
 * itself is a large, stable algorithm, and vendoring it keeps the
 * module free of runtime Composer dependencies. The library's own
 * PNG/SVG entry points are NOT used — they send HTTP headers,
 * which would break the AJAX JSON responses the profile page
 * relies on. Instead this class takes the encoder frame (an array
 * of '0'/'1' strings) and renders it as inline SVG, which needs
 * no GD, no temp files, and no headers.
 *
 * The frame has no quiet zone, so a four-module white margin is
 * added around it (the QR specification's minimum). The SVG is
 * built from computed numbers only — no user input ever reaches
 * the markup — and the caller wraps it in a figure with a text
 * alternative (the otpauth:// URI and the secret itself, for
 * manual entry).
 *
 * Round-trip verified 2026-11-21: the same encoder frame rendered
 * to PNG decodes back with zbarimg to the exact otpauth:// URI,
 * and the frame shows the correct finder patterns.
 */
final class QrCode
{
    /**
     * Error-correction level: M (15% recovery).
     *
     * A good balance for a code shown on screen and scanned from
     * a phone: it survives glare and small screens without
     * inflating the version the way level H would.
     */
    private const int ERROR_CORRECTION = 1;

    /**
     * Quiet-zone width in modules (QR specification minimum).
     */
    private const int QUIET_ZONE = 4;

    /**
     * Whether the vendored library has been loaded.
     */
    private static bool $loaded = false;

    /**
     * Render text as an inline SVG QR code.
     *
     * Returns an empty string when the text is empty or the
     * encoder cannot be loaded — the caller must fall back to
     * showing the secret and the otpauth:// URI for manual entry.
     *
     * @param string $text Text to encode (an otpauth:// URI).
     * @param int $moduleSize Pixel size of one module in the SVG.
     * @return string SVG markup, or '' when rendering is impossible.
     */
    public static function renderSvg(string $text, int $moduleSize = 8): string
    {
        if ($text === '' || !self::loadLibrary()) {
            return '';
        }
        if ($moduleSize < 1) {
            $moduleSize = 1;
        }
        if ($moduleSize > 32) {
            $moduleSize = 32;
        }
        $encoder = \QRencode::factory(self::ERROR_CORRECTION, 4, 0);
        $frame = $encoder->encode($text);
        if (!is_array($frame) || $frame === []) {
            return '';
        }
        $rows = count($frame);
        $columns = strlen((string) $frame[0]);
        if ($columns === 0) {
            return '';
        }
        $margin = self::QUIET_ZONE;
        $width = $columns + 2 * $margin;
        $height = $rows + 2 * $margin;
        $size = $width * $moduleSize;
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-hidden="true" focusable="false">',
            $width * $moduleSize,
            $height * $moduleSize,
            $size,
            $size
        );
        $svg .= sprintf(
            '<rect width="%d" height="%d" fill="#ffffff"/>',
            $width * $moduleSize,
            $height * $moduleSize
        );
        $svg .= '<g fill="#000000">';
        for ($y = 0; $y < $rows; $y++) {
            $row = (string) $frame[$y];
            for ($x = 0; $x < $columns; $x++) {
                if (($row[$x] ?? '0') !== '1') {
                    continue;
                }
                $svg .= sprintf(
                    '<rect x="%d" y="%d" width="%d" height="%d"/>',
                    ($x + $margin) * $moduleSize,
                    ($y + $margin) * $moduleSize,
                    $moduleSize,
                    $moduleSize
                );
            }
        }
        $svg .= '</g></svg>';
        return $svg;
    }

    /**
     * Count the dark modules in an encoder frame.
     *
     * Test support: the SVG renderer must emit exactly one rect
     * per dark module, so the harness compares this count against
     * the rect count in the rendered SVG.
     *
     * @param array<int, string> $frame Encoder frame.
     * @return int Dark modules.
     */
    public static function countDarkModules(array $frame): int
    {
        $count = 0;
        foreach ($frame as $row) {
            $count += substr_count((string) $row, '1');
        }
        return $count;
    }

    /**
     * Encode text and return the raw frame.
     *
     * Test support: exposes the encoder frame so the harness can
     * check its structure (size, finder patterns) without going
     * through the SVG renderer.
     *
     * @param string $text Text to encode.
     * @return array<int, string>|null Frame rows, or null when the library cannot load.
     */
    public static function encodeFrame(string $text): ?array
    {
        if ($text === '' || !self::loadLibrary()) {
            return null;
        }
        $encoder = \QRencode::factory(self::ERROR_CORRECTION, 4, 0);
        $frame = $encoder->encode($text);
        if (!is_array($frame) || $frame === []) {
            return null;
        }
        return $frame;
    }

    /**
     * Load the vendored phpqrcode library once.
     *
     * The require is guarded by class_exists() so a site where
     * another plugin already loaded phpqrcode never fatals on a
     * redeclaration — the already-loaded copy is used instead.
     * The library files live in the global namespace, which is
     * why the leading backslash is used at the call sites.
     *
     * @return bool True when the QRencode class is available.
     */
    private static function loadLibrary(): bool
    {
        if (self::$loaded) {
            return true;
        }
        if (!class_exists('QRencode', false)) {
            $library = __DIR__ . '/vendor/phpqrcode/qrlib.php';
            if (!is_readable($library)) {
                return false;
            }
            require_once $library;
        }
        self::$loaded = class_exists('QRencode', false);
        return self::$loaded;
    }
}
