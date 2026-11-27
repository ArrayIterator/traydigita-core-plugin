<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FastSearch;

use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function get_current_user_id;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Chunked index-build AJAX endpoints for the Fast Search module.
 *
 * Registered from `FastSearchModule::boot()` (only when the module
 * is enabled), so the endpoints do not exist while the module is
 * disabled.
 *
 * Endpoints (all POST to `admin-ajax.php`, nonce action
 * `traydigita-admin` sent as `_ajax_nonce`, capability
 * `manage_options`):
 * - `traydigita_fast_search_start`: acquire the build lock and
 *   create a job for the missing indexes. Returns 409 with the
 *   running job's progress when a build is already running, and
 *   a success payload with `already_installed: true` when nothing
 *   is missing.
 * - `traydigita_fast_search_chunk`: build ONE index (the next in
 *   the job queue). POST `job_token`. Returns the progress shape
 *   from `IndexManager::runNextChunk()`; the UI calls it again
 *   until `done` is true. Returns 404 when the token does not
 *   match a running job.
 * - `traydigita_fast_search_status`: read-only progress of the
 *   running job (or the current index statuses when idle), so a
 *   reloaded settings page can show a build started in another
 *   tab instead of offering a second one.
 *
 * Index builds never run on a frontend request: these are admin
 * AJAX actions only, and the module registers no build trigger
 * anywhere else.
 */
final class FastSearchAjax
{
    /**
     * AJAX action starting a build job.
     */
    public const string AJAX_START = 'traydigita_fast_search_start';

    /**
     * AJAX action building the next index in the job queue.
     */
    public const string AJAX_CHUNK = 'traydigita_fast_search_chunk';

    /**
     * AJAX action reading build progress / index statuses.
     */
    public const string AJAX_STATUS = 'traydigita_fast_search_status';

    /**
     * Shared nonce action for the admin AJAX endpoints.
     *
     * The nonce itself must be sent as `_ajax_nonce` (see
     * `check_ajax_referer()`), never as a field named `nonce`.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Register the three AJAX actions.
     *
     * Called from `FastSearchModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_START, [self::class, 'start']);
        add_action('wp_ajax_' . self::AJAX_CHUNK, [self::class, 'chunk']);
        add_action('wp_ajax_' . self::AJAX_STATUS, [self::class, 'status']);
    }

    /**
     * Start a build job for the missing indexes.
     *
     * When a build is already running, answers 409 with its
     * progress so the UI can poll the status endpoint instead of
     * starting a second build. When nothing is missing, answers
     * success with `already_installed: true`.
     *
     * @return void
     */
    public static function start(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $running = IndexManager::jobProgress();
        if ($running !== null) {
            wp_send_json_error(
                [
                    'message' => esc_html__('An index build is already running.', 'traydigita-core'),
                    'progress' => $running,
                ],
                409
            );
        }
        $job = IndexManager::startJob(get_current_user_id());
        if ($job === null) {
            // startJob() returns null in two cases: nothing is
            // missing, or a build is already running. The running
            // case was answered with 409 above, so re-check what
            // is actually missing instead of assuming the
            // already-installed case - a stale lock used to land
            // here too and report "already installed" while all
            // four indexes were still missing.
            $missing = IndexManager::missingKeys(IndexManager::checkStatus());
            if ($missing === []) {
                wp_send_json_success(
                    [
                        'already_installed' => true,
                        'message' => esc_html__('All search indexes are already installed.', 'traydigita-core'),
                        'statuses' => IndexManager::checkStatus(),
                        'ready' => IndexManager::isReady(),
                    ]
                );
            }
            wp_send_json_error(
                ['message' => esc_html__('The index build could not run. Please try again.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success(
            [
                'already_installed' => false,
                'job_token' => $job['token'],
                'total' => $job['total'],
                'queue' => $job['queue'],
                'message' => esc_html__('Index build started.', 'traydigita-core'),
            ]
        );
    }

    /**
     * Build the next index in the running job (one index per call).
     *
     * POST: `job_token` (the token returned by the start endpoint).
     * A token that does not match the running job is a 404: the
     * job finished, expired, or belongs to another build.
     *
     * @return void
     */
    public static function chunk(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $token = sanitize_key(self::postString('job_token'));
        if ($token === '') {
            wp_send_json_error(['message' => esc_html__('Missing build job.', 'traydigita-core')], 400);
        }
        $progress = IndexManager::runNextChunk($token);
        if ($progress === null) {
            wp_send_json_error(['message' => esc_html__('Build job not found or expired.', 'traydigita-core')], 404);
        }
        $progress['ready'] = IndexManager::isReady();
        if (!empty($progress['done'])) {
            $progress['statuses'] = IndexManager::checkStatus();
            $progress['message'] = !empty($progress['failed'])
                ? esc_html__('Index build finished with errors.', 'traydigita-core')
                : esc_html__('All search indexes are installed.', 'traydigita-core');
        }
        wp_send_json_success($progress);
    }

    /**
     * Read the running build's progress, or the index statuses.
     *
     * Never starts or advances a build: safe to poll while another
     * tab owns the job.
     *
     * @return void
     */
    public static function status(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $progress = IndexManager::jobProgress();
        if ($progress !== null) {
            wp_send_json_success(
                [
                    'running' => true,
                    'progress' => $progress,
                    'ready' => IndexManager::isReady(),
                ]
            );
        }
        wp_send_json_success(
            [
                'running' => false,
                'statuses' => IndexManager::checkStatus(),
                'ready' => IndexManager::isReady(),
            ]
        );
    }

    /**
     * Verify the nonce, then the capability.
     *
     * check_ajax_referer() dies with -1 on a bad nonce; a missing
     * capability is answered with a JSON 403 instead.
     *
     * @return bool True when the request is authorized.
     */
    private static function authorize(): bool
    {
        check_ajax_referer(self::NONCE_ACTION);
        return current_user_can('manage_options');
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
}
