<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Cache;

use function __;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function delete_transient;
use function get_current_user_id;
use function get_transient;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function wp_cache_flush;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the Cache module.
 *
 * Registered from `CacheModule::boot()` (only when the module is
 * enabled), so no endpoint exists while the module is disabled.
 *
 * Every endpoint requires the `traydigita-admin` nonce sent as
 * `_ajax_nonce` (the field `check_ajax_referer()` reads) plus the
 * `manage_options` capability, and refuses to run while the
 * module itself is disabled.
 *
 * Endpoints (all POST to `admin-ajax.php`):
 * - `traydigita_cache_purge_plugin`: purge the page cache
 *   through one detected cache plugin. POST `plugin` (a plugin
 *   id from CacheDetector::detect()).
 * - `traydigita_cache_opcache_reset`: reset the OPcache.
 * - `traydigita_cache_object_flush`: flush the object cache
 *   with wp_cache_flush().
 * - `traydigita_cache_transients_flush`: delete every transient
 *   (and its timeout row). POST `confirm` - the typed
 *   confirmation word, which must match the module's configured
 *   word exactly. Runs under a transient lock like the DB Tools
 *   cleaner.
 * - `traydigita_cache_cloudflare_purge`: purge everything for
 *   the configured Cloudflare zone. Rate-limited to one purge
 *   every 30 seconds per user so a double-click cannot hammer
 *   the Cloudflare API.
 *
 * Responses are JSON with a `message` key. No response ever
 * contains the Cloudflare API token, and no error message is
 * built from a remote response body.
 */
final class CacheAjax
{
    /**
     * AJAX action for purging through a cache plugin.
     */
    public const string ACTION_PURGE_PLUGIN = 'traydigita_cache_purge_plugin';

    /**
     * AJAX action for resetting the OPcache.
     */
    public const string ACTION_OPCACHE_RESET = 'traydigita_cache_opcache_reset';

    /**
     * AJAX action for flushing the object cache.
     */
    public const string ACTION_OBJECT_FLUSH = 'traydigita_cache_object_flush';

    /**
     * AJAX action for flushing all transients.
     */
    public const string ACTION_TRANSIENTS_FLUSH = 'traydigita_cache_transients_flush';

    /**
     * AJAX action for purging the Cloudflare zone cache.
     */
    public const string ACTION_CLOUDFLARE_PURGE = 'traydigita_cache_cloudflare_purge';

    /**
     * Transient key for the single-job lock around transient flush.
     */
    public const string LOCK_TRANSIENT = 'traydigita_cache_lock';

    /**
     * Lock lifetime in seconds (a stuck job frees itself).
     */
    public const int LOCK_TTL_SECONDS = 300;

    /**
     * Minimum seconds between Cloudflare purges from the same user.
     */
    public const int CLOUDFLARE_RATE_LIMIT_SECONDS = 30;

    /**
     * Register the module's AJAX actions.
     *
     * Called from `CacheModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_PURGE_PLUGIN, [self::class, 'purgePlugin']);
        add_action('wp_ajax_' . self::ACTION_OPCACHE_RESET, [self::class, 'resetOpcache']);
        add_action('wp_ajax_' . self::ACTION_OBJECT_FLUSH, [self::class, 'flushObjectCache']);
        add_action('wp_ajax_' . self::ACTION_TRANSIENTS_FLUSH, [self::class, 'flushTransients']);
        add_action('wp_ajax_' . self::ACTION_CLOUDFLARE_PURGE, [self::class, 'purgeCloudflare']);
    }

    /**
     * Authorize a request: nonce, capability, module enabled.
     *
     * Sends a JSON error and returns false when the request is
     * not authorized; `check_ajax_referer()` dies with -1 on a
     * bad nonce before this returns.
     *
     * @return bool True when the request may proceed.
     */
    private static function authorize(): bool
    {
        check_ajax_referer(CacheModule::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!CacheModule::isEnabledForAjax()) {
            wp_send_json_error(['message' => __('The Cache module is disabled.', 'traydigita-core')], 403);
        }
        return true;
    }

    /**
     * Read a POST field as a sanitized string.
     *
     * @param string $key Field name.
     * @return string
     */
    private static function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * Check the typed confirmation word against the setting.
     *
     * The comparison is exact and case-sensitive: the word is a
     * deliberate speed bump before a bulk delete, so a near miss
     * must not pass. This is the same rule the DB Tools module
     * uses for its bulk deletes.
     *
     * @param string $typed Word typed by the admin.
     * @return bool
     */
    private static function confirmMatches(string $typed): bool
    {
        return $typed !== '' && $typed === CacheModule::getSettings()['confirm_word'];
    }

    /**
     * Take the single-job lock, or refuse when a job is running.
     *
     * @return bool True when the lock was taken.
     */
    private static function acquireLock(): bool
    {
        if (false !== get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }
        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL_SECONDS);
        return true;
    }

    /**
     * Release the single-job lock.
     *
     * @return void
     */
    private static function releaseLock(): void
    {
        delete_transient(self::LOCK_TRANSIENT);
    }

    /**
     * Purge the page cache through one detected cache plugin.
     *
     * POST `plugin` (a plugin id from CacheDetector::detect()).
     * The detector allowlists the id and refuses unknown or
     * inactive plugins; plugins without a verified purge API are
     * refused with a message pointing at their own screen.
     *
     * @return void
     */
    public static function purgePlugin(): void
    {
        if (!self::authorize()) {
            return;
        }
        $plugin = sanitize_key(self::postString('plugin'));
        if ($plugin === '' || !CacheDetector::isKnownPlugin($plugin)) {
            wp_send_json_error(
                ['message' => __('Unknown cache plugin.', 'traydigita-core')],
                400
            );
        }
        $result = CacheDetector::purge($plugin);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * Reset the OPcache.
     *
     * Refused when the OPcache is not available on this server.
     * The settings page warns that resetting on a busy site
     * causes a reload spike; the endpoint itself does the reset
     * once and reports the outcome.
     *
     * @return void
     */
    public static function resetOpcache(): void
    {
        if (!self::authorize()) {
            return;
        }
        if (!CacheModule::isOpcacheAvailable()) {
            wp_send_json_error(
                ['message' => __('OPcache is not available on this server.', 'traydigita-core')],
                400
            );
        }
        if (!CacheModule::resetOpcache()) {
            wp_send_json_error(
                ['message' => __('The OPcache could not be reset. Please try again.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success(
            ['message' => __('OPcache reset. PHP files will be recompiled on their next request.', 'traydigita-core')]
        );
    }

    /**
     * Flush the object cache with wp_cache_flush().
     *
     * @return void
     */
    public static function flushObjectCache(): void
    {
        if (!self::authorize()) {
            return;
        }
        if (!wp_cache_flush()) {
            wp_send_json_error(
                ['message' => __('The object cache could not be flushed. Please try again.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success(
            ['message' => __('Object cache flushed.', 'traydigita-core')]
        );
    }

    /**
     * Delete every transient (and its timeout row).
     *
     * POST `confirm` (the typed confirmation word). Nothing is
     * deleted when the word does not match, or when another job
     * holds the lock. The response reports exactly how many
     * transient rows were deleted.
     *
     * @return void
     */
    public static function flushTransients(): void
    {
        if (!self::authorize()) {
            return;
        }
        if (!self::confirmMatches(self::postString('confirm'))) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: %s is the confirmation word the admin must type. */
                        __('Type %s to confirm flushing all transients.', 'traydigita-core'),
                        CacheModule::getSettings()['confirm_word']
                    ),
                ],
                400
            );
        }
        if (!self::acquireLock()) {
            wp_send_json_error(
                ['message' => __('Another cache job is already running. Please wait for it to finish and try again.', 'traydigita-core')],
                409
            );
        }
        try {
            $deleted = CacheModule::flushTransients();
        } finally {
            self::releaseLock();
        }
        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d is the number of transient rows deleted. */
                __('Deleted %d transient rows.', 'traydigita-core'),
                $deleted
            ),
            'deleted' => $deleted,
            'remaining' => CacheModule::countTransients(),
        ]);
    }

    /**
     * Purge everything for the configured Cloudflare zone.
     *
     * Refused while Cloudflare is unconfigured, and rate-limited
     * to one purge every 30 seconds per user. The API token never
     * appears in any response.
     *
     * @return void
     */
    public static function purgeCloudflare(): void
    {
        if (!self::authorize()) {
            return;
        }
        if (!CacheModule::isCloudflareConfigured()) {
            wp_send_json_error(
                ['message' => __('Cloudflare is not configured. Save an API token and a zone ID first.', 'traydigita-core')],
                400
            );
        }
        $rateLimitKey = 'traydigita_cache_cf_purge_' . get_current_user_id();
        if (false !== get_transient($rateLimitKey)) {
            wp_send_json_error(
                [
                    /* translators: %d is the number of seconds to wait before the next purge. */
                    'message' => sprintf(
                        __('Please wait %d seconds before purging the Cloudflare cache again.', 'traydigita-core'),
                        self::CLOUDFLARE_RATE_LIMIT_SECONDS
                    ),
                ],
                429
            );
        }
        set_transient($rateLimitKey, 1, self::CLOUDFLARE_RATE_LIMIT_SECONDS);
        $settings = CacheModule::getSettings();
        $result = CloudflareClient::purgeEverything($settings['api_token'], $settings['zone_id']);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['code'] >= 400 ? $result['code'] : 500);
        }
        wp_send_json_success(['message' => $result['message']]);
    }
}
