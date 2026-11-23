<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Turnstile;

use function __;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function get_current_user_id;
use function get_transient;
use function set_transient;
use function sprintf;
use function wp_send_json_error;
use function wp_send_json_success;

/**
 * "Test keys" AJAX endpoint for the Turnstile module.
 *
 * Registered from `TurnstileModule::boot()` (only when the module is
 * enabled), so the endpoint does not exist while the module is
 * disabled.
 *
 * Request (POST to `admin-ajax.php`):
 * - `action` = `traydigita_turnstile_test`
 * - `_ajax_nonce` = nonce for action `traydigita-admin` (the
 *   `check_ajax_referer()` default field; never a field named
 *   `nonce`)
 *
 * The test verifies the stored keys against Cloudflare by sending
 * the documented dummy token `XXXX.DUMMY.TOKEN.XXXX` to siteverify.
 * Cloudflare always rejects a dummy token, so the outcome is read
 * from *which* error comes back:
 * - `success: true` — the test keys are in use (Cloudflare accepts
 *   any token with them), so the keys work.
 * - `invalid-input-response` — Cloudflare accepted the secret key
 *   and rejected only the dummy token, which is expected: the keys
 *   work.
 * - `invalid-input-secret` — the secret key itself was rejected:
 *   the keys do not work.
 * - Cloudflare unreachable — the keys could not be checked.
 *
 * Only administrators (`manage_options`) may call it, only while
 * the module is configured, and at most once every 30 seconds per
 * user (HTTP 429 while limited). Responses never contain the secret
 * key or the site key, and the stored test result is only a
 * timestamp plus a boolean — no key material, no error text.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Turnstile
 */
final class TurnstileAjax
{
    /**
     * AJAX action name for the test-keys endpoint.
     */
    public const string AJAX_ACTION = 'traydigita_turnstile_test';

    /**
     * Minimum seconds between key tests from the same user.
     */
    public const int RATE_LIMIT_SECONDS = 30;

    /**
     * Dummy token Cloudflare documents for key testing.
     *
     * With real keys Cloudflare rejects it with
     * `invalid-input-response`; with the documented test keys it is
     * accepted. Either outcome proves the secret key was accepted.
     */
    public const string DUMMY_TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

    /**
     * Register the test-keys AJAX action.
     *
     * Called from `TurnstileModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'testKeys']);
    }

    /**
     * Verify the stored keys against Cloudflare with a dummy token.
     *
     * Interprets the verifier result as described in the class
     * docblock and answers with a JSON success or error carrying a
     * `message` key only. The secret key never appears in any
     * response, even redacted — messages are built from the
     * allowlisted internal error codes, never from Cloudflare's raw
     * answer.
     *
     * @return void
     */
    public static function testKeys(): void
    {
        check_ajax_referer(TurnstileModule::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!TurnstileModule::isConfigured()) {
            wp_send_json_error(
                ['message' => __('Turnstile is not configured yet. Save the site key and secret key first.', 'traydigita-core')],
                400
            );
        }
        $userId = get_current_user_id();
        $rateLimitKey = 'traydigita_turnstile_test_' . $userId;
        if (false !== get_transient($rateLimitKey)) {
            wp_send_json_error(
                [
                    /* translators: %d is the number of seconds to wait before the next key test. */
                    'message' => sprintf(
                        __('Please wait %d seconds before testing the keys again.', 'traydigita-core'),
                        self::RATE_LIMIT_SECONDS
                    ),
                ],
                429
            );
        }
        set_transient($rateLimitKey, 1, self::RATE_LIMIT_SECONDS);

        $result = Verifier::verify(self::DUMMY_TOKEN, TurnstileModule::clientIp());
        if ($result['success']) {
            TurnstileModule::recordLastTest(true);
            wp_send_json_success(
                ['message' => __('The keys are valid. Cloudflare accepted the test verification.', 'traydigita-core')]
            );
        }
        if ($result['error_code'] === 'invalid-token') {
            // Cloudflare accepted the secret key and rejected only
            // the dummy token — that is the expected outcome for
            // real keys, so the keys work.
            TurnstileModule::recordLastTest(true);
            wp_send_json_success(
                ['message' => __('The keys look valid. Cloudflare accepted the secret key and rejected only the dummy test token, as expected.', 'traydigita-core')]
            );
        }
        TurnstileModule::recordLastTest(false);
        if ($result['error_code'] === 'invalid-secret') {
            wp_send_json_error(
                ['message' => __('Cloudflare rejected the secret key. Please check the secret key and try again.', 'traydigita-core')],
                400
            );
        }
        if ($result['error_code'] === 'invalid-host') {
            wp_send_json_error(
                ['message' => __('Cloudflare accepted the keys, but the answer was for a different site. Please check that the site key belongs to this site.', 'traydigita-core')],
                400
            );
        }
        if ($result['unreachable']) {
            wp_send_json_error(
                ['message' => __('Cloudflare could not be reached. Please check the server connection and try again.', 'traydigita-core')],
                503
            );
        }
        wp_send_json_error(
            ['message' => esc_html__('The keys could not be verified. Please check the keys and try again.', 'traydigita-core')],
            500
        );
    }
}
