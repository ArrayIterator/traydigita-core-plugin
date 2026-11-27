<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Redirects;

use function absint;
use function array_merge;
use function array_slice;
use function array_values;
use function count;
use function esc_url_raw;
use function get_option;
use function in_array;
use function is_array;
use function is_string;
use function rawurldecode;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function time;
use function trim;
use function update_option;
use function usort;
use function wp_parse_url;

/**
 * Storage, validation, matching, and 404 logging for redirects.
 *
 * Everything lives in the single option `traydigita_redirects`
 * (autoload `no`) so the redirect check on the frontend is one
 * option read:
 *
 * [
 *   'rules'    => [ ['from' => '/old', 'to' => '/new', 'status' => 301], ... ],
 *   'log_404s' => true,
 *   'log'      => [ ['path' => '/missing', 'count' => 2, 'last_seen' => 123, 'referrer' => '...'], ... ],
 * ]
 *
 * Matching rules: exact matches win over wildcards, and among
 * wildcards the longest prefix wins. A rule `from` ending in `/*`
 * is a wildcard: `*` captures the remainder of the request path
 * and is substituted into the target (when the target has no `*`
 * the remainder is appended instead).
 *
 * The matching and logging helpers are pure functions over plain
 * arrays, so they are testable without WordPress; only get() and
 * save() touch the options table.
 */
final class RedirectStore
{
    /**
     * Option name holding rules, settings, and the 404 log.
     */
    public const string OPTION = 'traydigita_redirects';

    /**
     * Maximum number of redirect rules (a safety cap against
     * runaway imports or abuse).
     */
    public const int MAX_RULES = 500;

    /**
     * Maximum number of 404 log entries; the oldest entries
     * (by last-seen time) are dropped when the cap is exceeded.
     */
    public const int MAX_LOG_ENTRIES = 200;

    /**
     * Redirect status codes an administrator may choose.
     */
    public const array ALLOWED_STATUSES = [301, 302, 307, 308];

    /**
     * File extensions whose 404s are never logged: missing
     * static assets (theme files, plugin assets, favicons) are
     * noise, not broken content links worth fixing.
     */
    public const array STATIC_EXTENSIONS = [
        'css', 'js', 'mjs', 'map',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    /**
     * Maximum length of a stored referrer URL.
     */
    public const int MAX_REFERRER_LENGTH = 500;

    /**
     * Per-request cache of the loaded store, so a redirect check
     * followed by a 404 log on the same request reads the option
     * only once.
     *
     * @var array<string, mixed>|null
     */
    /** @var array{rules: array<int, array{from: string, to: string, status: int}>, log_404s: bool, log: array<int, array{path: string, count: int, last_seen: int, referrer: string}>}|null */
    private static ?array $cache = null;

    /**
     * Drop the per-request cache (used by tests and after save()).
     *
     * @return void
     */
    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /**
     * Default store shape.
     *
     * @return array{rules: array<int, array{from: string, to: string, status: int}>, log_404s: bool, log: array<int, array{path: string, count: int, last_seen: int, referrer: string}>}
     */
    public static function defaults(): array
    {
        return [
            'rules' => [],
            'log_404s' => true,
            'log' => [],
        ];
    }

    /**
     * Read the store merged over the defaults, with every rule
     * and log entry re-validated (a hand-edited option can never
     * smuggle an invalid rule into the redirect check).
     *
     * @return array{rules: array<int, array{from: string, to: string, status: int}>, log_404s: bool, log: array<int, array{path: string, count: int, last_seen: int, referrer: string}>}
     */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $store = array_merge(self::defaults(), $stored);
        $rules = [];
        if (is_array($store['rules'])) {
            foreach ($store['rules'] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $clean = self::normalizeRule($rule);
                if ($clean !== null) {
                    $rules[] = $clean;
                }
            }
        }
        $log = [];
        if (is_array($store['log'])) {
            foreach ($store['log'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $clean = self::normalizeLogEntry($entry);
                if ($clean !== null) {
                    $log[] = $clean;
                }
            }
        }
        $store = [
            'rules' => $rules,
            'log_404s' => (bool) ($store['log_404s'] ?? true),
            'log' => $log,
        ];
        self::$cache = $store;
        return $store;
    }

    /**
     * Persist the whole store (autoload `no`).
     *
     * @param array{rules: array<int, array{from: string, to: string, status: int}>, log_404s: bool, log: array<int, array{path: string, count: int, last_seen: int, referrer: string}>} $store Store shape from get().
     * @return void
     */
    public static function save(array $store): void
    {
        self::$cache = $store;
        update_option(self::OPTION, $store, false);
    }

    /**
     * Normalize a request or rule path for comparison.
     *
     * Leading slash is enforced, any query string or fragment is
     * stripped, percent-encoding is decoded, and a trailing slash
     * is removed (except for the site root). Paths are compared
     * case-sensitively: on a Linux host `/News` and `/news` are
     * different URLs.
     *
     * @param string $path Raw path.
     * @return string Normalized path, at least '/'.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        $parsed = wp_parse_url($path, PHP_URL_PATH);
        if (is_string($parsed) && $parsed !== '') {
            $path = $parsed;
        }
        $path = rawurldecode($path);
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    /**
     * Validate and normalize one redirect rule.
     *
     * `from` must be a site path starting with `/` (optionally
     * ending in a single trailing `/*` wildcard); the site root
     * `/` and `/*` are rejected - redirecting the home page (or
     * everything) is never what an administrator means. `to`
     * must be a site path starting with `/` or an absolute
     * `http(s)` URL; protocol-relative and non-http(s) targets
     * are rejected. `status` must be one of the allowlisted
     * codes and falls back to 301.
     *
     * @param array<string, mixed> $rule Raw rule.
     * @return array{from: string, to: string, status: int}|null Clean rule, or null when invalid.
     */
    public static function normalizeRule(array $rule): ?array
    {
        $from = isset($rule['from']) && is_string($rule['from']) ? trim($rule['from']) : '';
        $to = isset($rule['to']) && is_string($rule['to']) ? trim($rule['to']) : '';
        if ($from === '' || $to === '') {
            return null;
        }
        if (!str_starts_with($from, '/')) {
            return null;
        }
        if (str_starts_with($from, 'http://') || str_starts_with($from, 'https://') || str_starts_with($from, '//')) {
            return null;
        }
        $isWildcard = str_ends_with($from, '/*');
        $fromPath = $isWildcard ? substr($from, 0, -2) : $from;
        if ($fromPath === '' || $fromPath === '/') {
            return null;
        }
        if (str_contains($fromPath, '*')) {
            return null;
        }
        $fromPath = self::normalizePath($fromPath);
        if ($fromPath === '/') {
            return null;
        }
        $from = $isWildcard ? $fromPath . '/*' : $fromPath;
        if (str_starts_with($to, '//')) {
            return null;
        }
        if (str_starts_with($to, 'http://') || str_starts_with($to, 'https://')) {
            $cleanTo = esc_url_raw($to);
            $host = wp_parse_url($cleanTo, PHP_URL_HOST);
            if ($cleanTo === '' || !is_string($host) || $host === '') {
                return null;
            }
            $to = $cleanTo;
        } else {
            if (!str_starts_with($to, '/')) {
                return null;
            }
            $targetPath = wp_parse_url($to, PHP_URL_PATH);
            $targetQuery = wp_parse_url($to, PHP_URL_QUERY);
            if (!is_string($targetPath) || $targetPath === '') {
                return null;
            }
            $targetPath = self::normalizePath($targetPath);
            $to = $targetPath;
            if (is_string($targetQuery) && $targetQuery !== '') {
                $to .= '?' . $targetQuery;
            }
        }
        $status = isset($rule['status']) ? absint($rule['status']) : 301;
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 301;
        }
        return [
            'from' => $from,
            'to' => $to,
            'status' => $status,
        ];
    }

    /**
     * Validate and normalize one 404 log entry.
     *
     * @param array<string, mixed> $entry Raw entry.
     * @return array{path: string, count: int, last_seen: int, referrer: string}|null Clean entry, or null when invalid.
     */
    public static function normalizeLogEntry(array $entry): ?array
    {
        $path = isset($entry['path']) && is_string($entry['path']) ? self::normalizePath($entry['path']) : '';
        if ($path === '' || $path === '/') {
            return null;
        }
        $count = isset($entry['count']) ? absint($entry['count']) : 0;
        $lastSeen = isset($entry['last_seen']) ? absint($entry['last_seen']) : 0;
        $referrer = isset($entry['referrer']) && is_string($entry['referrer']) ? $entry['referrer'] : '';
        return [
            'path' => $path,
            'count' => $count < 1 ? 1 : $count,
            'last_seen' => $lastSeen > 0 ? $lastSeen : time(),
            'referrer' => $referrer,
        ];
    }

    /**
     * Whether a rule `from` value is a wildcard rule.
     *
     * @param string $from Normalized `from` value.
     * @return bool
     */
    public static function isWildcard(string $from): bool
    {
        return str_ends_with($from, '/*');
    }

    /**
     * Find the rule matching a request path.
     *
     * Exact matches win over wildcards; among wildcards the
     * longest prefix wins (ties keep the earliest-defined rule).
     * Returns the matched rule together with its resolved target
     * (the `*` capture substituted in), or null when nothing
     * matches.
     *
     * @param array<int, array{from: string, to: string, status: int}> $rules Normalized rules.
     * @param string $requestPath Normalized request path.
     * @return array{rule: array{from: string, to: string, status: int}, target: string}|null
     */
    public static function match(array $rules, string $requestPath): ?array
    {
        foreach ($rules as $rule) {
            if (!self::isWildcard($rule['from']) && $rule['from'] === $requestPath) {
                return ['rule' => $rule, 'target' => $rule['to']];
            }
        }
        $best = null;
        $bestPrefixLength = -1;
        $bestTarget = '';
        foreach ($rules as $rule) {
            if (!self::isWildcard($rule['from'])) {
                continue;
            }
            $prefix = substr($rule['from'], 0, -1);
            if (!str_starts_with($requestPath, $prefix)) {
                continue;
            }
            $prefixLength = strlen($prefix);
            if ($prefixLength <= $bestPrefixLength) {
                continue;
            }
            $best = $rule;
            $bestPrefixLength = $prefixLength;
            $bestTarget = self::resolveTarget($rule, $requestPath);
        }
        if ($best === null) {
            return null;
        }
        return ['rule' => $best, 'target' => $bestTarget];
    }

    /**
     * Resolve a wildcard rule's target for a request path.
     *
     * The remainder after the matched prefix replaces `*` in the
     * target; when the target has no `*` the remainder is
     * appended to it instead. Exact rules return their target
     * unchanged.
     *
     * @param array{from: string, to: string, status: int} $rule Normalized rule.
     * @param string $requestPath Normalized request path.
     * @return string Resolved target (path or absolute URL).
     */
    public static function resolveTarget(array $rule, string $requestPath): string
    {
        if (!self::isWildcard($rule['from'])) {
            return $rule['to'];
        }
        $prefix = substr($rule['from'], 0, -1);
        $remainder = substr($requestPath, strlen($prefix));
        $remainder = trim($remainder, '/');
        if (str_contains($rule['to'], '*')) {
            return str_replace('*', $remainder, $rule['to']);
        }
        if ($remainder === '') {
            return $rule['to'];
        }
        return rtrim($rule['to'], '/') . '/' . $remainder;
    }

    /**
     * Whether redirecting to a target would loop back to the
     * current request.
     *
     * A target is a loop when its normalized path equals the
     * request path and it points at this site (relative targets
     * always do; absolute targets only when their host matches
     * the home host). External targets can never loop.
     *
     * @param string $requestPath Normalized request path.
     * @param string $target Resolved target (path or absolute URL).
     * @param string $homeHost Host of the site home URL, e.g. 'padangkita.com'.
     * @return bool
     */
    public static function isLoop(string $requestPath, string $target, string $homeHost): bool
    {
        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            $targetHost = wp_parse_url($target, PHP_URL_HOST);
            if (!is_string($targetHost) || strtolower($targetHost) !== strtolower($homeHost)) {
                return false;
            }
            $targetPath = wp_parse_url($target, PHP_URL_PATH);
            $targetPath = is_string($targetPath) ? self::normalizePath($targetPath) : '/';
            return $targetPath === $requestPath;
        }
        $targetPath = wp_parse_url($target, PHP_URL_PATH);
        $targetPath = is_string($targetPath) ? self::normalizePath($targetPath) : '/';
        return $targetPath === $requestPath;
    }

    /**
     * Whether a path must never be redirected (admin, AJAX,
     * REST, login, and cron entry points).
     *
     * @param string $path Normalized request path.
     * @return bool
     */
    public static function shouldSkipPath(string $path): bool
    {
        $protected = [
            '/wp-admin',
            '/wp-login.php',
            '/wp-json',
            '/xmlrpc.php',
            '/wp-cron.php',
        ];
        foreach ($protected as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a path is a static asset whose 404 is not worth
     * logging (missing theme/plugin files, images, fonts).
     *
     * @param string $path Normalized request path.
     * @return bool
     */
    public static function isStaticAsset(string $path): bool
    {
        $dot = strrpos($path, '.');
        if ($dot === false) {
            return false;
        }
        $extension = strtolower(substr($path, $dot + 1));
        if (str_contains($extension, '/')) {
            return false;
        }
        return in_array($extension, self::STATIC_EXTENSIONS, true);
    }

    /**
     * Record one 404 hit in the store's log.
     *
     * Repeats of the same path bump the count and refresh the
     * last-seen time and referrer instead of adding a row. The
     * log is capped at MAX_LOG_ENTRIES entries; when the cap is
     * exceeded the entries with the oldest last-seen time are
     * dropped. Static-asset paths are never recorded (checked
     * here as well as by the caller, so the rule cannot be
     * bypassed by calling this method directly).
     *
     * @param array{rules: array<int, array{from: string, to: string, status: int}>, log_404s: bool, log: array<int, array{path: string, count: int, last_seen: int, referrer: string}>} $store Store shape from get().
     * @param string $path Normalized 404 path.
     * @param string $referrer Referrer URL (may be empty).
     * @param int $now Current Unix timestamp.
     * @return array{rules: array<int, array{from: string, to: string, status: int}>, log_404s: bool, log: array<int, array{path: string, count: int, last_seen: int, referrer: string}>} Updated store.
     */
    public static function record404(array $store, string $path, string $referrer, int $now): array
    {
        if (self::isStaticAsset($path) || $path === '/') {
            return $store;
        }
        $log = $store['log'];
        $referrer = trim($referrer);
        if (strlen($referrer) > self::MAX_REFERRER_LENGTH) {
            $referrer = substr($referrer, 0, self::MAX_REFERRER_LENGTH);
        }
        $found = false;
        foreach ($log as $index => $entry) {
            if ($entry['path'] !== $path) {
                continue;
            }
            $log[$index]['count'] = absint($entry['count']) + 1;
            $log[$index]['last_seen'] = $now;
            if ($referrer !== '') {
                $log[$index]['referrer'] = $referrer;
            }
            $found = true;
            break;
        }
        if (!$found) {
            $log[] = [
                'path' => $path,
                'count' => 1,
                'last_seen' => $now,
                'referrer' => $referrer,
            ];
        }
        if (count($log) > self::MAX_LOG_ENTRIES) {
            usort($log, static function (array $a, array $b): int {
                return absint($a['last_seen'] ?? 0) <=> absint($b['last_seen'] ?? 0);
            });
            $log = array_values(array_slice($log, count($log) - self::MAX_LOG_ENTRIES));
        }
        $store['log'] = array_values($log);
        return $store;
    }

    /**
     * The most-hit 404s, most frequent first.
     *
     * @param array{rules: array<int, array{from: string, to: string, status: int}>, log_404s: bool, log: array<int, array{path: string, count: int, last_seen: int, referrer: string}>} $store Store shape from get().
     * @param int $limit Maximum entries to return.
     * @return array<int, array{path: string, count: int, last_seen: int, referrer: string}>
     */
    public static function top404s(array $store, int $limit = 20): array
    {
        $log = $store['log'];
        $entries = [];
        foreach ($log as $entry) {
            $clean = self::normalizeLogEntry($entry);
            if ($clean !== null) {
                $entries[] = $clean;
            }
        }
        usort($entries, static function (array $a, array $b): int {
            $byCount = absint($b['count']) <=> absint($a['count']);
            if ($byCount !== 0) {
                return $byCount;
            }
            return absint($b['last_seen']) <=> absint($a['last_seen']);
        });
        return array_values(array_slice($entries, 0, $limit < 1 ? 20 : $limit));
    }
}
