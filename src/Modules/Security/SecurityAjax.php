<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Security;

use Throwable;
use function __;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function date_i18n;
use function get_option;
use function sprintf;
use function time;
use function wp_send_json_error;
use function wp_send_json_success;
use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;

/**
 * "Re-check" AJAX endpoint for the Security Monitor module.
 *
 * Registered from `SecurityModule::boot()` (only when the module
 * is enabled), so the endpoint does not exist while the module is
 * disabled.
 *
 * Request (POST to `admin-ajax.php`):
 * - `action` = `traydigita_security_monitor_recheck`
 * - `_ajax_nonce` = nonce for action `traydigita-admin` (the
 *   `check_ajax_referer()` default field; never a field named
 *   `nonce`)
 *
 * Response is JSON via `wp_send_json_success()` with `checks`
 * (the full check list, same shape as `Checks::run()` returns),
 * `summary` (`pass` / `warning` / `fail` counts), `checked_at`
 * (human-readable time), and a `message`. Only administrators
 * (`manage_options`) may call it. The endpoint only reads state
 * and returns it; it writes nothing.
 */
final class SecurityAjax
{
    /**
     * AJAX action name for the re-check endpoint.
     */
    public const string AJAX_ACTION = 'traydigita_security_monitor_recheck';

    /**
     * Register the re-check AJAX action.
     *
     * Called from `SecurityModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'recheck']);
    }

    /**
     * Re-run all checks and return the fresh results.
     *
     * @return void
     */
    public static function recheck(): void
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!ModuleRegistry::isEnabled(SecurityModule::id())) {
            wp_send_json_error(
                ['message' => __('The Security Monitor module is disabled.', 'traydigita-core')],
                400
            );
        }
        try {
            $checks = SecurityModule::runChecks();
        } catch (Throwable) {
            wp_send_json_error(
                ['message' => __('The security checks could not run. Please try again.', 'traydigita-core')],
                500
            );
        }
        $summary = Checks::summarize($checks);
        wp_send_json_success([
            'message' => __('Security checks updated.', 'traydigita-core'),
            'checks' => $checks,
            'summary' => $summary,
            'summary_text' => sprintf(
                /* translators: %1$d passed checks, %2$d warnings, %3$d failed checks. */
                __('%1$d passed · %2$d warnings · %3$d failed', 'traydigita-core'),
                $summary['pass'],
                $summary['warning'],
                $summary['fail']
            ),
            'checked_at' => date_i18n(
                (string) get_option('date_format') . ' ' . (string) get_option('time_format'),
                time()
            ),
        ]);
    }
}
