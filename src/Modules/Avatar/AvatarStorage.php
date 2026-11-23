<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Avatar;

use function basename;
use function content_url;
use function delete_user_meta;
use function dns_get_record;
use function esc_url_raw;
use function file_exists;
use function file_put_contents;
use function filter_var;
use function function_exists;
use function get_user_meta;
use function gethostbynamel;
use function getimagesize;
use function glob;
use function in_array;
use function inet_ntop;
use function inet_pton;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function is_uploaded_file;
use function is_wp_error;
use function ord;
use function parse_url;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unlink;
use function update_user_meta;
use function wp_get_image_editor;
use function wp_mkdir_p;
use function wp_remote_head;
use function wp_remote_retrieve_header;
use function wp_remote_retrieve_response_code;
use function wp_safe_remote_get;
use function wp_tempnam;
use function __;

/**
 * Filesystem + user-meta storage for custom avatars.
 *
 * One avatar per user, stored in user meta `traydigita_avatar`:
 * `['type' => 'file'|'url', 'url' => string, 'file' => string|null]`.
 * Uploaded files live under `wp-content/traydigita-avatar/<user-id>.<ext>`
 * (never in the uploads directory); remote avatars store only the
 * verified URL, no file is downloaded or kept.
 *
 * Verification rules (never trust a URL string or a client-sent MIME
 * type alone):
 * - Remote URL: scheme must be http/https, the host must be a
 *   public address (see isPublicHost(): no localhost, private,
 *   or link-local host may ever be fetched from the server),
 *   a `wp_remote_head()` pre-check must return HTTP 200 with an
 *   allowlisted image Content-Type, then the file is downloaded
 *   to a temporary file and checked with `getimagesize()` — the
 *   MIME type reported by the image itself is what counts. The
 *   temporary file is deleted afterwards. A non-image URL is
 *   rejected with a clear error.
 * - Upload: the file must be a real HTTP upload, `getimagesize()`
 *   must report an allowlisted MIME type, and the image is opened
 *   with `wp_get_image_editor()` and cropped/resized to the
 *   recommended 256x256 square before it is saved.
 *
 * Square policy: uploads are auto-cropped to the centre square and
 * then resized to 256x256 (documented in the UI). Non-square images
 * are accepted and centre-cropped, never rejected for their shape;
 * non-images are always rejected.
 */
final class AvatarStorage
{
    /**
     * User meta key holding the avatar record.
     */
    public const string META_KEY = 'traydigita_avatar';

    /**
     * Directory name under wp-content holding the avatar files.
     */
    public const string DIR_NAME = 'traydigita-avatar';

    /**
     * Recommended avatar size in pixels (square). Uploads are
     * cropped and resized to this size.
     */
    public const int RECOMMENDED_SIZE = 256;

    /**
     * Maximum accepted upload size in bytes (2 MB).
     */
    public const int MAX_FILE_SIZE = 2097152;

    /**
     * Allowlisted image MIME types, mapped to their file extension.
     *
     * @var array<string, string>
     */
    public const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Absolute path of the avatar directory.
     *
     * @return string
     */
    public static function dir(): string
    {
        return WP_CONTENT_DIR . '/' . self::DIR_NAME;
    }

    /**
     * Public base URL of the avatar directory.
     *
     * @return string
     */
    public static function urlBase(): string
    {
        return content_url('/' . self::DIR_NAME);
    }

    /**
     * Create the avatar directory (with an index.php silence file)
     * when it does not exist yet.
     *
     * @return bool True when the directory exists and is usable.
     */
    public static function ensureDir(): bool
    {
        $dir = self::dir();
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        $silence = $dir . '/index.php';
        if (!file_exists($silence)) {
            file_put_contents($silence, "<?php\n// Silence is golden.\n");
        }
        return true;
    }

    /**
     * File extension for an allowlisted MIME type.
     *
     * @param string $mime MIME type to look up.
     * @return string|null Extension without the dot, or null when not allowlisted.
     */
    public static function extensionForMime(string $mime): ?string
    {
        return self::ALLOWED_MIMES[strtolower($mime)] ?? null;
    }

    /**
     * Read a user's avatar record.
     *
     * A `file` record whose file no longer exists on disk is treated
     * as no avatar (stale meta never produces a broken image).
     *
     * @param int $userId User id.
     * @return array{type: string, url: string, file: string|null}|null
     */
    public static function getAvatar(int $userId): ?array
    {
        $stored = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($stored) || !isset($stored['type'], $stored['url'])) {
            return null;
        }
        $type = $stored['type'];
        $url = $stored['url'];
        if (!is_string($type) || !is_string($url) || $url === '') {
            return null;
        }
        if ($type === 'url') {
            return ['type' => 'url', 'url' => $url, 'file' => null];
        }
        if ($type === 'file') {
            $file = is_string($stored['file'] ?? null) ? $stored['file'] : null;
            if ($file === null || !file_exists(self::dir() . '/' . $file)) {
                return null;
            }
            return ['type' => 'file', 'url' => $url, 'file' => $file];
        }
        return null;
    }

    /**
     * Absolute path of a user's stored avatar file, if one exists.
     *
     * The file is found by globbing `<user-id>.*` and matching the
     * extension against the allowlist, so a replaced avatar that
     * changed extension is still found.
     *
     * @param int $userId User id.
     * @return string|null Absolute path, or null when there is no file.
     */
    public static function filePathForUser(int $userId): ?string
    {
        $matches = glob(self::dir() . '/' . $userId . '.*');
        if (!is_array($matches)) {
            return null;
        }
        foreach ($matches as $path) {
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, self::ALLOWED_MIMES, true)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Delete a user's stored avatar file, if one exists.
     *
     * @param int $userId User id.
     * @return void
     */
    public static function deleteFileForUser(int $userId): void
    {
        $path = self::filePathForUser($userId);
        if ($path !== null) {
            unlink($path);
        }
    }

    /**
     * Delete a user's avatar entirely (file + user meta).
     *
     * @param int $userId User id.
     * @return void
     */
    public static function deleteAvatar(int $userId): void
    {
        self::deleteFileForUser($userId);
        delete_user_meta($userId, self::META_KEY);
    }

    /**
     * Whether one IP address is public.
     *
     * `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`
     * rejects private and reserved ranges, but it is not
     * trusted on its own for IPv6 literals: loopback (::1),
     * link-local (fe80::/10), unique-local (fc00::/7) and
     * IPv4-mapped private addresses (::ffff:10.0.0.0/8 and
     * friends) are checked explicitly with inet_pton(), so a
     * literal that slips past the filter can never pass here.
     *
     * @param string $ip IP address (v4 or v6).
     * @return bool True when the address is public.
     */
    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (!str_contains($ip, ':')) {
            return true;
        }
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        // ::1 (loopback) and :: (unspecified).
        if ($packed === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01"
            || $packed === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
        ) {
            return false;
        }
        // fe80::/10 (link-local).
        if ($first === 0xfe && ($second & 0xc0) === 0x80) {
            return false;
        }
        // fc00::/7 (unique-local).
        if (($first & 0xfe) === 0xfc) {
            return false;
        }
        // ::ffff:0:0/96 (IPv4-mapped): the embedded IPv4
        // address must itself be public.
        if (substr($packed, 0, 10) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            && ord($packed[10]) === 0xff
            && ord($packed[11]) === 0xff
        ) {
            $embedded = inet_ntop(substr($packed, 12, 4));
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            $embeddedPublic = is_string($embedded)
                && filter_var($embedded, FILTER_VALIDATE_IP, $flags) !== false;
            if (!$embeddedPublic) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a URL host may be fetched from this server.
     *
     * SSRF guard for the remote-avatar fetch: the URL comes from a
     * logged-in user (self-service avatars are on by default), so a
     * loopback, private, or link-local host must never be requested
     * — that would let a low-privilege user probe the server itself
     * or cloud metadata (169.254.169.254). Hostnames are resolved
     * once and every resolved address is checked the same way as a
     * literal IP; an unresolvable hostname fails closed. Both
     * address families are checked: gethostbynamel() resolves
     * IPv4 only, so AAAA records are resolved separately with
     * dns_get_record() — a hostname may resolve to a mix of
     * public and private addresses, and one private address
     * anywhere in the answer rejects the host. DNS rebinding
     * between this check and the fetch (TOCTOU) remains a
     * residual risk: the fetch never returns body content to the
     * caller, only whether the URL serves a real image.
     *
     * @param string $host Host from parse_url().
     * @return bool True when the host is a public address.
     */
    private static function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }
        // gethostbynamel() (not gethostbypname(), which is missing from
        // some PHP builds): resolve every IPv4 address and require
        // ALL of them to be public.
        $resolved = gethostbynamel($host);
        if ($resolved === false || $resolved === []) {
            return false;
        }
        foreach ($resolved as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }
        // AAAA records are never returned by gethostbynamel(), so
        // resolve them separately: a hostname whose IPv6 answer
        // points at ::1, a unique-local, or a link-local address
        // must be rejected too. A DNS error fails closed.
        $aaaa = dns_get_record($host, DNS_AAAA);
        if ($aaaa === false) {
            return false;
        }
        foreach ($aaaa as $record) {
            $ipv6 = is_array($record) && isset($record['ipv6']) && is_string($record['ipv6']) ? $record['ipv6'] : '';
            if ($ipv6 === '' || !self::isPublicIp($ipv6)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Verify that a remote URL points to a real image.
     *
     * A `wp_remote_head()` pre-check (10 second timeout) must return
     * HTTP 200 with an allowlisted image Content-Type; hosts that
     * refuse HEAD requests fall through to the download instead.
     * The file is then downloaded to a temporary file and checked
     * with `getimagesize()` — the MIME type of the actual image
     * data is authoritative, never the header or the URL. The
     * temporary file is always deleted again.
     *
     * Redirects are never followed: both requests pass
     * `'redirection' => 0` and any non-200 response is rejected,
     * so a public URL that 301/302-redirects to a loopback or
     * private address cannot bypass isPublicHost(), which only
     * validates the initial URL. (`download_url()` cannot be used
     * here: it takes no request arguments and always follows up
     * to 5 redirects.)
     *
     * @param string $url URL to verify.
     * @return array{success: bool, message: string, mime?: string}
     */
    public static function verifyRemoteImage(string $url): array
    {
        $url = esc_url_raw(trim($url));
        if ($url === '') {
            return [
                'success' => false,
                'message' => __('Enter an image URL.', 'traydigita-core'),
            ];
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [
                'success' => false,
                'message' => __('Only http:// and https:// image URLs are allowed.', 'traydigita-core'),
            ];
        }
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null;
        if ((is_array($parts) && (isset($parts['user']) || isset($parts['pass'])))
            || ($port !== null && $port !== 80 && $port !== 443)
            || !self::isPublicHost($host)
        ) {
            return [
                'success' => false,
                'message' => __('That image URL is not allowed.', 'traydigita-core'),
            ];
        }
        $head = wp_remote_head($url, ['timeout' => 10, 'redirection' => 0]);
        if (!is_wp_error($head)) {
            $code = wp_remote_retrieve_response_code($head);
            if ($code !== 200) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %d is the HTTP status code returned by the remote server. */
                        __('The image URL could not be reached (HTTP status %d).', 'traydigita-core'),
                        $code
                    ),
                ];
            }
            $contentType = wp_remote_retrieve_header($head, 'content-type');
            if (is_string($contentType) && $contentType !== '') {
                $mime = strtolower(trim((string) strtok($contentType, ';')));
                if (self::extensionForMime($mime) === null) {
                    return [
                        'success' => false,
                        'message' => sprintf(
                            /* translators: %s is the Content-Type returned by the remote server. */
                            __('The URL does not point to an image (server reports %s). Only JPEG, PNG, GIF, and WebP images are allowed.', 'traydigita-core'),
                            $mime
                        ),
                    ];
                }
            }
        }
        // Stream the download to a temporary file with redirects
        // disabled (see the docblock above): a 3xx here means the
        // URL redirects, and a redirect target is never fetched.
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = wp_tempnam(basename((string) parse_url($url, PHP_URL_PATH)));
        if (!is_string($tmp) || $tmp === '') {
            return [
                'success' => false,
                'message' => __('The image URL could not be downloaded, so it cannot be verified as a real image.', 'traydigita-core'),
            ];
        }
        $response = wp_safe_remote_get($url, [
            'timeout' => 15,
            'redirection' => 0,
            'stream' => true,
            'filename' => $tmp,
        ]);
        if (is_wp_error($response)) {
            unlink($tmp);
            return [
                'success' => false,
                'message' => __('The image URL could not be downloaded, so it cannot be verified as a real image.', 'traydigita-core'),
            ];
        }
        $downloadCode = wp_remote_retrieve_response_code($response);
        if ($downloadCode !== 200) {
            unlink($tmp);
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %d is the HTTP status code returned by the remote server. */
                    __('The image URL could not be reached (HTTP status %d).', 'traydigita-core'),
                    $downloadCode
                ),
            ];
        }
        $info = getimagesize($tmp);
        unlink($tmp);
        if ($info === false) {
            return [
                'success' => false,
                'message' => __('The URL does not point to a real image file.', 'traydigita-core'),
            ];
        }
        if (self::extensionForMime($info['mime']) === null) {
            return [
                'success' => false,
                'message' => __('The URL does not point to a supported image. Only JPEG, PNG, GIF, and WebP images are allowed.', 'traydigita-core'),
            ];
        }
        return [
            'success' => true,
            'message' => __('The image URL was verified.', 'traydigita-core'),
            'mime' => $info['mime'],
        ];
    }

    /**
     * Set a user's avatar from a remote URL, after verifying it.
     *
     * Any previously stored avatar file for the user is deleted.
     * Only the URL is stored; no file is downloaded or kept.
     *
     * @param int $userId User id (must exist; the caller checks this).
     * @param string $url Remote image URL.
     * @return array{success: bool, message: string, url?: string}
     */
    public static function setUrlAvatar(int $userId, string $url): array
    {
        $verified = self::verifyRemoteImage($url);
        if (!$verified['success']) {
            return $verified;
        }
        $cleanUrl = esc_url_raw(trim($url));
        self::deleteFileForUser($userId);
        update_user_meta($userId, self::META_KEY, [
            'type' => 'url',
            'url' => $cleanUrl,
            'file' => null,
        ]);
        return [
            'success' => true,
            'message' => __('Avatar set from URL.', 'traydigita-core'),
            'url' => $cleanUrl,
        ];
    }

    /**
     * Check that a local file is a real image with an allowlisted MIME type.
     *
     * Used by the upload path before the image editor touches the
     * file; public so it can be unit-tested without an HTTP upload.
     *
     * @param string $path Absolute path of the file to check.
     * @return array{success: bool, message: string, mime?: string}
     */
    public static function verifyLocalImage(string $path): array
    {
        $info = getimagesize($path);
        if ($info === false) {
            return [
                'success' => false,
                'message' => __('The uploaded file is not a real image.', 'traydigita-core'),
            ];
        }
        if (self::extensionForMime($info['mime']) === null) {
            return [
                'success' => false,
                'message' => __('Only JPEG, PNG, GIF, and WebP images are allowed.', 'traydigita-core'),
            ];
        }
        return [
            'success' => true,
            'message' => __('The uploaded file is a valid image.', 'traydigita-core'),
            'mime' => $info['mime'],
        ];
    }

    /**
     * Crop a verified local image to the recommended square size and
     * store it as the user's avatar file.
     *
     * Non-square images are centre-cropped to a square and then
     * resized to 256x256. The old avatar file (if any) is deleted
     * only after the new file has been saved successfully.
     *
     * @param string $tmpPath Absolute path of the verified image file.
     * @param string $mime Verified MIME type of the image.
     * @param int $userId User id (must exist; the caller checks this).
     * @return array{success: bool, message: string, url?: string}
     */
    public static function processLocalImage(string $tmpPath, string $mime, int $userId): array
    {
        $ext = self::extensionForMime($mime);
        if ($ext === null) {
            return [
                'success' => false,
                'message' => __('Only JPEG, PNG, GIF, and WebP images are allowed.', 'traydigita-core'),
            ];
        }
        if (!self::ensureDir()) {
            return [
                'success' => false,
                'message' => __('The avatar directory could not be created.', 'traydigita-core'),
            ];
        }
        $editor = wp_get_image_editor($tmpPath);
        if (is_wp_error($editor)) {
            return [
                'success' => false,
                'message' => __('The uploaded image could not be opened for resizing.', 'traydigita-core'),
            ];
        }
        $size = self::RECOMMENDED_SIZE;
        $resized = $editor->resize($size, $size, true);
        if (is_wp_error($resized)) {
            return [
                'success' => false,
                'message' => __('The uploaded image could not be resized to a square.', 'traydigita-core'),
            ];
        }
        $dest = self::dir() . '/' . $userId . '.' . $ext;
        // Capture the old file BEFORE saving: after the save, the
        // glob in filePathForUser() could return the new file.
        $oldPath = self::filePathForUser($userId);
        $saved = $editor->save($dest, $mime);
        if (is_wp_error($saved)) {
            return [
                'success' => false,
                'message' => __('The avatar image could not be saved.', 'traydigita-core'),
            ];
        }
        if ($oldPath !== null && $oldPath !== $dest) {
            unlink($oldPath);
        }
        $url = self::urlBase() . '/' . $userId . '.' . $ext;
        update_user_meta($userId, self::META_KEY, [
            'type' => 'file',
            'url' => $url,
            'file' => $userId . '.' . $ext,
        ]);
        return [
            'success' => true,
            'message' => __('Avatar uploaded.', 'traydigita-core'),
            'url' => $url,
        ];
    }

    /**
     * Store an uploaded avatar file for a user.
     *
     * Expects one entry of the `$_FILES` array. The upload must be a
     * real HTTP upload within the size limit; the file contents are
     * verified with `getimagesize()` (never the client-sent MIME
     * type or extension) and then centre-cropped and resized to the
     * recommended 256x256 square.
     *
     * @param int $userId User id (must exist; the caller checks this).
     * @param mixed $file One `$_FILES` entry.
     * @return array{success: bool, message: string, url?: string}
     */
    public static function storeUploadedFile(int $userId, mixed $file): array
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => __('No avatar file was uploaded, or the upload failed.', 'traydigita-core'),
            ];
        }
        $tmpPath = $file['tmp_name'] ?? '';
        if (!is_string($tmpPath) || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return [
                'success' => false,
                'message' => __('The uploaded file could not be read.', 'traydigita-core'),
            ];
        }
        $size = $file['size'] ?? 0;
        if (!is_int($size) && !is_float($size)) {
            $size = 0;
        }
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %d is the maximum upload size in megabytes. */
                    __('The avatar file is too large. Maximum size is %d MB.', 'traydigita-core'),
                    (int) (self::MAX_FILE_SIZE / 1048576)
                ),
            ];
        }
        $verified = self::verifyLocalImage($tmpPath);
        if (!$verified['success'] || !isset($verified['mime'])) {
            return [
                'success' => false,
                'message' => $verified['message'],
            ];
        }
        return self::processLocalImage($tmpPath, $verified['mime'], $userId);
    }
}
