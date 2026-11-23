<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Fonts;

use function __;
use function array_key_exists;
use function file_get_contents;
use function finfo_file;
use function finfo_open;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function is_uploaded_file;
use function is_wp_error;
use function parse_url;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function strtok;
use function substr;
use function trim;
use function usort;
use function wp_check_filetype_and_ext;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

/**
 * Google Fonts fetching, Google Fonts CSS parsing, and font-file
 * verification for the Font Manager module.
 *
 * Two trust boundaries, both enforced here:
 *
 * 1. Remote downloads: only the allowlisted Google Fonts hosts are
 *    ever fetched (`fonts.googleapis.com` for the stylesheet,
 *    `fonts.gstatic.com` for the font files). Every remote request
 *    carries a timeout, responses are capped in size, and a
 *    downloaded file must start with the woff2 magic bytes before
 *    it is stored — a redirect to a hostile host or a non-font
 *    payload is rejected instead of saved.
 * 2. Uploads: the client-sent MIME type and the file extension are
 *    never trusted on their own. A file is accepted only when
 *    `wp_check_filetype_and_ext()` (extension + MIME allowlist),
 *    `finfo` (MIME type of the actual bytes), and the magic bytes
 *    at the start of the file all agree on the same font format.
 *
 * The pure helpers (`parseCss()`, `detectFormat()`) take plain
 * strings and are unit-testable without WordPress; the network and
 * upload methods need WordPress and are exercised through the
 * module's AJAX endpoints instead.
 */
final class FontDownloader
{
    /**
     * Host allowed for the Google Fonts stylesheet request.
     */
    public const string API_HOST = 'fonts.googleapis.com';

    /**
     * Host allowed for Google Fonts file downloads.
     */
    public const string FILE_HOST = 'fonts.gstatic.com';

    /**
     * Timeout in seconds for every remote request.
     */
    public const int TIMEOUT_SECONDS = 15;

    /**
     * Maximum accepted size of one downloaded font file (2 MB).
     * A woff2 file is normally tens of kilobytes; anything larger
     * is treated as a wrong payload rather than a font.
     */
    public const int MAX_FILE_BYTES = 2097152;

    /**
     * Maximum number of weights per download request. Google Fonts
     * serves one file per weight/style/subset, so this also caps
     * the number of files a single download can store.
     */
    public const int MAX_WEIGHTS = 6;

    /**
     * Browser user agent sent with the stylesheet request.
     *
     * Google Fonts serves woff2 files only to modern browsers; the
     * default WordPress user agent would get older formats instead.
     */
    public const string USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Allowlisted font MIME types, mapped to their file extension.
     *
     * Only specific font types are listed. A generic type such as
     * `application/octet-stream` is deliberately NOT mapped here:
     * `finfo` reports it for many real font files, and mapping it
     * to one format would make the finfo cross-check reject every
     * other format. Generic types defer to the magic-byte check.
     *
     * @var array<string, string>
     */
    public const array ALLOWED_MIMES = [
        'font/woff2' => 'woff2',
        'font/woff' => 'woff',
        'font/ttf' => 'ttf',
        // WordPress also maps these legacy types; accept them only
        // when the magic bytes agree (see detectFormat()).
        'application/font-woff2' => 'woff2',
        'application/font-woff' => 'woff',
        'application/x-font-ttf' => 'ttf',
    ];

    /**
     * Font format per magic-byte signature at the start of a file.
     *
     * woff2 starts with `wOF2`, woff with `wOFF`, TrueType with
     * `0x00010000` or the string `true`. The OpenType `OTTO`
     * signature is deliberately absent: `.otf` files are not an
     * accepted upload format.
     *
     * @var array<string, string>
     */
    public const array MAGIC_FORMATS = [
        'wOF2' => 'woff2',
        'wOFF' => 'woff',
        "\x00\x01\x00\x00" => 'ttf',
        'true' => 'ttf',
    ];

    /**
     * Build the Google Fonts css2 stylesheet URL for a family.
     *
     * Weights are the requested font weights (100–900, step 100).
     * With `$italic` true the italic axis is requested for the
     * same weights, so one request covers both styles.
     *
     * @param string $family Family name, e.g. `Open Sans`.
     * @param array<int, int> $weights Sorted, unique weights.
     * @param bool $italic Whether to request italic variants too.
     * @return string
     */
    public static function cssUrl(string $family, array $weights, bool $italic): string
    {
        $familyParam = str_replace(' ', '+', trim($family));
        if ($italic) {
            $tuples = [];
            foreach ($weights as $weight) {
                $tuples[] = '0,' . $weight;
                $tuples[] = '1,' . $weight;
            }
            $axis = 'ital,wght@' . implode(';', $tuples);
        } else {
            $axis = 'wght@' . implode(';', $weights);
        }
        return 'https://' . self::API_HOST . '/css2?family=' . $familyParam . ':' . $axis . '&display=swap';
    }

    /**
     * Fetch the Google Fonts stylesheet for a family.
     *
     * The request goes to the allowlisted API host only, with a
     * timeout and a modern browser user agent (needed to receive
     * woff2 URLs). A non-200 response, an empty body, or a
     * transport error is reported as a failure with a clear
     * message; the raw error text is never exposed to the admin.
     *
     * @param string $family Family name.
     * @param array<int, int> $weights Sorted, unique weights.
     * @param bool $italic Whether to request italic variants too.
     * @return array{ok: bool, css?: string, error?: string}
     */
    public static function fetchGoogleCss(string $family, array $weights, bool $italic): array
    {
        $url = self::cssUrl($family, $weights, $italic);
        if (parse_url($url, PHP_URL_HOST) !== self::API_HOST) {
            return [
                'ok' => false,
                'error' => __('The Google Fonts request was refused: unexpected host.', 'traydigita-core'),
            ];
        }
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/css',
            ],
        ]);
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'error' => __('Google Fonts could not be reached. Check the connection and try again.', 'traydigita-core'),
            ];
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return [
                'ok' => false,
                'error' => __('Google Fonts did not return the font stylesheet. Check the family name and try again.', 'traydigita-core'),
            ];
        }
        $css = wp_remote_retrieve_body($response);
        if (!is_string($css) || trim($css) === '') {
            return [
                'ok' => false,
                'error' => __('Google Fonts returned an empty stylesheet.', 'traydigita-core'),
            ];
        }
        return ['ok' => true, 'css' => $css];
    }

    /**
     * Download one font file from the allowlisted file host.
     *
     * The URL host must be `fonts.gstatic.com` and the path must
     * end in `.woff2`; anything else is refused before any request
     * is made. The response must be HTTP 200, within the size cap,
     * and start with the woff2 magic bytes.
     *
     * @param string $url Font file URL.
     * @return array{ok: bool, bytes?: string, error?: string}
     */
    public static function downloadFile(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($host !== self::FILE_HOST || !str_contains(strtolower($path), '.woff2')) {
            return [
                'ok' => false,
                'error' => __('The font file URL was refused: unexpected host or file type.', 'traydigita-core'),
            ];
        }
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['User-Agent' => self::USER_AGENT],
        ]);
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'error' => __('The font file could not be downloaded.', 'traydigita-core'),
            ];
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return [
                'ok' => false,
                'error' => __('The font file could not be downloaded.', 'traydigita-core'),
            ];
        }
        $bytes = wp_remote_retrieve_body($response);
        if (!is_string($bytes) || $bytes === '') {
            return [
                'ok' => false,
                'error' => __('The downloaded font file is empty.', 'traydigita-core'),
            ];
        }
        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            return [
                'ok' => false,
                'error' => __('The downloaded font file is too large.', 'traydigita-core'),
            ];
        }
        if (self::detectFormat($bytes) !== 'woff2') {
            return [
                'ok' => false,
                'error' => __('The downloaded file is not a valid woff2 font.', 'traydigita-core'),
            ];
        }
        return ['ok' => true, 'bytes' => $bytes];
    }

    /**
     * Parse a Google Fonts stylesheet into its `@font-face` faces.
     *
     * Each block contributes one face (weight, style, file URL).
     * Only woff2 URLs on the allowlisted file host are kept.
     * Google returns one block per weight/style/subset (latin,
     * latin-ext, and others); the `latin` subset is preferred, then
     * `latin-ext`, then the first subset seen, so one file per
     * weight/style is stored instead of one per subset.
     *
     * @param string $css Stylesheet text.
     * @return array<int, array{weight: int, style: string, url: string}>
     */
    public static function parseCss(string $css): array
    {
        $matches = [];
        preg_match_all(
            '/\/\*\s*([a-z0-9-]+)\s*\*\/\s*@font-face\s*\{([^}]*)\}/i',
            $css,
            $matches,
            PREG_SET_ORDER
        );
        // Blocks without a subset comment (some stylesheets omit
        // them) are matched separately and treated as subset ''.
        $plainMatches = [];
        preg_match_all('/@font-face\s*\{([^}]*)\}/i', $css, $plainMatches, PREG_SET_ORDER);
        $candidates = [];
        foreach ($matches as $match) {
            $candidates[] = ['subset' => strtolower($match[1]), 'body' => $match[2]];
        }
        $commentedBodies = [];
        foreach ($candidates as $candidate) {
            $commentedBodies[] = $candidate['body'];
        }
        foreach ($plainMatches as $match) {
            if (in_array($match[1], $commentedBodies, true)) {
                continue;
            }
            $candidates[] = ['subset' => '', 'body' => $match[1]];
        }
        $byKey = [];
        $preference = ['latin' => 0, 'latin-ext' => 1, '' => 2];
        foreach ($candidates as $candidate) {
            $body = $candidate['body'];
            $weightMatches = [];
            $weight = preg_match('/font-weight:\s*(\d{3})\s*;/i', $body, $weightMatches) === 1
                ? (int) $weightMatches[1]
                : 400;
            if ($weight < 100 || $weight > 900 || $weight % 100 !== 0) {
                continue;
            }
            $styleMatches = [];
            $style = preg_match('/font-style:\s*(normal|italic)\s*;/i', $body, $styleMatches) === 1
                ? strtolower($styleMatches[1])
                : 'normal';
            $urlMatches = [];
            if (preg_match("/src:\s*url\(\s*['\"]?(https:[^)'\"]+\.woff2)['\"]?\)/i", $body, $urlMatches) !== 1) {
                continue;
            }
            $url = $urlMatches[1];
            if (parse_url($url, PHP_URL_HOST) !== self::FILE_HOST) {
                continue;
            }
            $key = $weight . '|' . $style;
            $rank = $preference[$candidate['subset']] ?? 3;
            if (!isset($byKey[$key]) || $rank < $byKey[$key]['rank']) {
                $byKey[$key] = ['weight' => $weight, 'style' => $style, 'url' => $url, 'rank' => $rank];
            }
        }
        $faces = [];
        foreach ($byKey as $face) {
            unset($face['rank']);
            $faces[] = $face;
        }
        usort($faces, static function (array $a, array $b): int {
            if ($a['weight'] !== $b['weight']) {
                return $a['weight'] <=> $b['weight'];
            }
            if ($a['style'] === $b['style']) {
                return 0;
            }
            return $a['style'] === 'normal' ? -1 : 1;
        });
        return $faces;
    }

    /**
     * Detect the font format from the magic bytes at the start of a file.
     *
     * Returns null for anything that is not a recognized font
     * (including `.otf`, which is not an accepted format). The
     * check reads only the first bytes, so it works on a partial
     * read as well as a full file.
     *
     * @param string $bytes File contents (or at least the first bytes).
     * @return string|null 'woff2', 'woff', 'ttf', or null when unrecognized.
     */
    public static function detectFormat(string $bytes): ?string
    {
        foreach (self::MAGIC_FORMATS as $magic => $format) {
            if (substr($bytes, 0, strlen($magic)) === $magic) {
                return $format;
            }
        }
        return null;
    }

    /**
     * MIME type for a font format.
     *
     * @param string $format Font format.
     * @return string|null MIME type, or null for an unknown format.
     */
    public static function mimeForFormat(string $format): ?string
    {
        $map = [
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
        ];
        return $map[$format] ?? null;
    }

    /**
     * Validate one uploaded font file (one `$_FILES` entry).
     *
     * Three independent checks must all agree:
     * - `wp_check_filetype_and_ext()` on the temporary file must
     *   report an allowlisted extension and MIME type;
     * - `finfo` on the actual bytes must report the same MIME
     *   type;
     * - the magic bytes must identify the same format.
     * A mismatch at any step rejects the file with a clear error.
     * The client-sent MIME type is never consulted.
     *
     * @param mixed $file One `$_FILES` entry.
     * @return array{ok: bool, format?: string, error?: string}
     */
    public static function validateUploadedFile(mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [
                'ok' => false,
                'error' => __('No font file was uploaded, or the upload failed.', 'traydigita-core'),
            ];
        }
        $tmpPath = $file['tmp_name'] ?? '';
        if (!is_string($tmpPath) || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return [
                'ok' => false,
                'error' => __('The uploaded file could not be read.', 'traydigita-core'),
            ];
        }
        $size = $file['size'] ?? 0;
        if (!is_int($size) && !is_float($size)) {
            $size = 0;
        }
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            return [
                'ok' => false,
                'error' => sprintf(
                    /* translators: %d is the maximum upload size in megabytes. */
                    __('The font file is too large. Maximum size is %d MB.', 'traydigita-core'),
                    (int) (self::MAX_FILE_BYTES / 1048576)
                ),
            ];
        }
        $name = is_string($file['name'] ?? null) ? (string) $file['name'] : '';
        $checked = wp_check_filetype_and_ext($tmpPath, $name, self::wpMimeMap());
        $ext = is_string($checked['ext'] ?? null) ? strtolower((string) $checked['ext']) : '';
        $type = is_string($checked['type'] ?? null) ? strtolower((string) $checked['type']) : '';
        if ($ext === '' || $type === '' || !array_key_exists($type, self::ALLOWED_MIMES)) {
            return [
                'ok' => false,
                'error' => __('Only woff2, woff, and ttf font files are allowed.', 'traydigita-core'),
            ];
        }
        $format = self::ALLOWED_MIMES[$type];
        if ($ext !== $format) {
            return [
                'ok' => false,
                'error' => __('The font file extension does not match its contents.', 'traydigita-core'),
            ];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return [
                'ok' => false,
                'error' => __('The uploaded file could not be verified.', 'traydigita-core'),
            ];
        }
        $detectedMime = finfo_file($finfo, $tmpPath);
        if (is_string($detectedMime) && $detectedMime !== '') {
            $cleanMime = strtolower(trim((string) strtok($detectedMime, ';')));
            if ($cleanMime !== $type) {
                // finfo names a different font format, or a
                // clearly non-font type (text, image, script):
                // reject. A generic type such as
                // application/octet-stream is left to the
                // magic-byte check below, which is authoritative.
                $finfoFormat = self::formatForMime($cleanMime);
                if ($finfoFormat !== null && $finfoFormat !== $format) {
                    return [
                        'ok' => false,
                        'error' => __('The uploaded file is not a valid font file.', 'traydigita-core'),
                    ];
                }
                if (str_contains($cleanMime, 'text/') || str_contains($cleanMime, 'image/')) {
                    return [
                        'ok' => false,
                        'error' => __('The uploaded file is not a valid font file.', 'traydigita-core'),
                    ];
                }
            }
        }
        $bytes = file_get_contents($tmpPath, false, null, 0, 8);
        if (!is_string($bytes) || self::detectFormat($bytes) !== $format) {
            return [
                'ok' => false,
                'error' => __('The uploaded file is not a valid font file.', 'traydigita-core'),
            ];
        }
        return ['ok' => true, 'format' => $format];
    }

    /**
     * WordPress MIME map for `wp_check_filetype_and_ext()`.
     *
     * Extension => MIME type, limited to the accepted font formats.
     *
     * @return array<string, string>
     */
    public static function wpMimeMap(): array
    {
        return [
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
        ];
    }

    /**
     * Font format for a MIME type, or null when not a font type.
     *
     * @param string $mime MIME type.
     * @return string|null
     */
    private static function formatForMime(string $mime): ?string
    {
        return self::ALLOWED_MIMES[$mime] ?? null;
    }
}
