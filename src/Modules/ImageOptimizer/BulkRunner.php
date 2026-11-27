<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ImageOptimizer;

use Throwable;
use function __;
use function absint;
use function add_action;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function delete_transient;
use function get_posts;
use function get_transient;
use function is_array;
use function set_transient;
use function time;
use function uniqid;
use function wp_send_json_error;
use function wp_send_json_success;

/**
 * Chunked bulk optimization runner for the Image Optimizer module.
 *
 * One job at a time, same pattern as the Fast Search indexer: a
 * transient lock (`traydigita_image_optimizer_lock`) is held for
 * the whole run, the queue of attachment ids lives in a transient
 * (`traydigita_image_optimizer_queue`), and progress counts live
 * in a third transient (`traydigita_image_optimizer_progress`)
 * that the settings page polls. Each AJAX chunk processes a
 * handful of attachments (5) and returns immediately, so no
 * single request ever runs long enough to time out; the admin
 * JavaScript simply calls the chunk endpoint again until the
 * queue is empty. A second "start" while a run is in progress is
 * refused, and a stale lock (a browser closed mid-run) expires
 * on its own after 10 minutes.
 *
 * The queue holds every JPEG, PNG, and WebP attachment id. The
 * chunk handler skips attachments that are already optimized for
 * the current format and quality - that check reads one postmeta
 * row, so a re-run over an already-optimized library is cheap.
 * Per-attachment outcomes (optimized / skipped / failed) are
 * recorded by `Optimizer` in postmeta; the progress transient
 * only aggregates the counts.
 *
 * All three AJAX actions require the `traydigita-admin` nonce
 * (sent as `_ajax_nonce`, the field `check_ajax_referer()` reads)
 * and the `manage_options` capability.
 */
final class BulkRunner
{
    /**
     * AJAX action that starts a bulk run.
     */
    public const string ACTION_START = 'traydigita_image_optimizer_start';

    /**
     * AJAX action that processes the next chunk.
     */
    public const string ACTION_CHUNK = 'traydigita_image_optimizer_chunk';

    /**
     * AJAX action that cancels the running bulk job.
     */
    public const string ACTION_CANCEL = 'traydigita_image_optimizer_cancel';

    /**
     * Transient key for the single-run lock (stores a run token).
     */
    public const string LOCK_TRANSIENT = 'traydigita_image_optimizer_lock';

    /**
     * Transient key for the queue of remaining attachment ids.
     */
    public const string QUEUE_TRANSIENT = 'traydigita_image_optimizer_queue';

    /**
     * Transient key for the aggregated progress state.
     */
    public const string PROGRESS_TRANSIENT = 'traydigita_image_optimizer_progress';

    /**
     * Attachments processed per chunk request.
     */
    public const int CHUNK_SIZE = 5;

    /**
     * Lock lifetime in seconds; a stale lock (browser closed
     * mid-run) expires on its own after this long.
     */
    public const int LOCK_TTL = 600;

    /**
     * Register the bulk-run AJAX actions.
     *
     * Called from `ImageOptimizerModule::boot()` (only when the
     * module is enabled), so the endpoints do not exist while the
     * module is disabled.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_START, [self::class, 'handleStart']);
        add_action('wp_ajax_' . self::ACTION_CHUNK, [self::class, 'handleChunk']);
        add_action('wp_ajax_' . self::ACTION_CANCEL, [self::class, 'handleCancel']);
    }

    /**
     * Whether a bulk run is currently in progress.
     *
     * @return bool
     */
    public static function isRunning(): bool
    {
        return get_transient(self::LOCK_TRANSIENT) !== false;
    }

    /**
     * Current progress state, or an empty state when no run has
     * ever started.
     *
     * @return array{status: string, total: int, done: int, optimized: int, skipped: int, failed: int, started: int}
     */
    public static function getProgress(): array
    {
        $progress = get_transient(self::PROGRESS_TRANSIENT);
        if (!is_array($progress)) {
            return [
                'status' => 'idle',
                'total' => 0,
                'done' => 0,
                'optimized' => 0,
                'skipped' => 0,
                'failed' => 0,
                'started' => 0,
            ];
        }
        return [
            'status' => (string) ($progress['status'] ?? 'idle'),
            'total' => (int) ($progress['total'] ?? 0),
            'done' => (int) ($progress['done'] ?? 0),
            'optimized' => (int) ($progress['optimized'] ?? 0),
            'skipped' => (int) ($progress['skipped'] ?? 0),
            'failed' => (int) ($progress['failed'] ?? 0),
            'started' => (int) ($progress['started'] ?? 0),
        ];
    }

    /**
     * Start a bulk run: build the queue and take the lock.
     *
     * Refuses with a 409 when a run is already in progress, and
     * with a 400 when Photon is serving the images (local
     * optimization is unnecessary while it is on) or when the
     * selected format is unsupported by the image editor.
     *
     * @return void
     */
    public static function handleStart(): void
    {
        check_ajax_referer(ImageOptimizerModule::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (ImageOptimizerModule::isPhotonEnabled()) {
            wp_send_json_error(
                ['message' => __('Photon is serving your images — local optimization is unnecessary while it is on.', 'traydigita-core')],
                400
            );
        }
        $settings = ImageOptimizerModule::getSettings();
        if (!Optimizer::isFormatSupported($settings['format'])) {
            wp_send_json_error(
                ['message' => __('The selected image format is not supported by this server’s image editor.', 'traydigita-core')],
                400
            );
        }
        if (self::isRunning()) {
            wp_send_json_error(
                ['message' => __('A bulk optimization is already running. Wait for it to finish or cancel it first.', 'traydigita-core')],
                409
            );
        }
        $queue = self::buildQueue();
        $token = uniqid('td-img-opt-', true);
        set_transient(self::LOCK_TRANSIENT, $token, self::LOCK_TTL);
        set_transient(self::QUEUE_TRANSIENT, $queue, self::LOCK_TTL);
        set_transient(
            self::PROGRESS_TRANSIENT,
            [
                'status' => 'running',
                'total' => count($queue),
                'done' => 0,
                'optimized' => 0,
                'skipped' => 0,
                'failed' => 0,
                'started' => time(),
            ],
            self::LOCK_TTL
        );
        wp_send_json_success([
            'message' => __('Bulk optimization started.', 'traydigita-core'),
            'progress' => self::getProgress(),
        ]);
    }

    /**
     * Process the next chunk of the queue.
     *
     * Pops up to CHUNK_SIZE attachment ids, optimizes each one,
     * and updates the progress transient. When the queue empties,
     * the lock and queue are deleted and the progress status
     * becomes `done`. A chunk with no active lock answers 409 so
     * the UI can stop polling instead of looping forever.
     *
     * @return void
     */
    public static function handleChunk(): void
    {
        check_ajax_referer(ImageOptimizerModule::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!self::isRunning()) {
            wp_send_json_error(
                ['message' => __('No bulk optimization is running.', 'traydigita-core')],
                409
            );
        }
        $settings = ImageOptimizerModule::getSettings();
        $queue = get_transient(self::QUEUE_TRANSIENT);
        if (!is_array($queue)) {
            $queue = [];
        }
        $progress = self::getProgress();
        $batch = [];
        for ($i = 0; $i < self::CHUNK_SIZE && $queue !== []; $i++) {
            $batch[] = array_shift($queue);
        }
        foreach ($batch as $attachmentId) {
            $id = absint($attachmentId);
            if ($id <= 0) {
                continue;
            }
            try {
                $result = Optimizer::optimizeAttachment($id, $settings, ImageOptimizerModule::isPhotonEnabled());
            } catch (Throwable) {
                // One bad attachment must never kill the whole
                // run: record the failure and move on.
                $result = Optimizer::markFailed($id, $settings);
            }
            $progress['done']++;
            if ($result['status'] === Optimizer::STATUS_OPTIMIZED) {
                $progress['optimized']++;
            } elseif ($result['status'] === Optimizer::STATUS_SKIPPED) {
                $progress['skipped']++;
            } else {
                $progress['failed']++;
            }
        }
        if ($queue === []) {
            $progress['status'] = 'done';
            delete_transient(self::LOCK_TRANSIENT);
            delete_transient(self::QUEUE_TRANSIENT);
            set_transient(self::PROGRESS_TRANSIENT, $progress, self::LOCK_TTL);
            wp_send_json_success([
                'message' => __('Bulk optimization finished.', 'traydigita-core'),
                'progress' => $progress,
                'finished' => true,
            ]);
        }
        // Refresh the lock so a long run does not expire mid-queue.
        set_transient(self::LOCK_TRANSIENT, get_transient(self::LOCK_TRANSIENT), self::LOCK_TTL);
        set_transient(self::QUEUE_TRANSIENT, $queue, self::LOCK_TTL);
        set_transient(self::PROGRESS_TRANSIENT, $progress, self::LOCK_TTL);
        wp_send_json_success([
            'message' => __('Bulk optimization in progress.', 'traydigita-core'),
            'progress' => $progress,
            'finished' => false,
        ]);
    }

    /**
     * Cancel the running bulk job.
     *
     * Deletes the lock and the queue; the progress transient is
     * kept with status `cancelled` so the settings page can show
     * how far the run got. Cancelling when nothing is running is
     * a no-op success.
     *
     * @return void
     */
    public static function handleCancel(): void
    {
        check_ajax_referer(ImageOptimizerModule::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        delete_transient(self::LOCK_TRANSIENT);
        delete_transient(self::QUEUE_TRANSIENT);
        $progress = self::getProgress();
        if ($progress['status'] === 'running') {
            $progress['status'] = 'cancelled';
            set_transient(self::PROGRESS_TRANSIENT, $progress, self::LOCK_TTL);
        }
        wp_send_json_success([
            'message' => __('Bulk optimization cancelled.', 'traydigita-core'),
            'progress' => self::getProgress(),
        ]);
    }

    /**
     * Build the queue: ids of every JPEG, PNG, and WebP attachment.
     *
     * Non-image attachments are never queued. Attachments already
     * optimized for the current settings stay in the queue and are
     * skipped cheaply (one postmeta read) by the chunk handler, so
     * the progress total always matches the library size.
     *
     * @return array<int, int>
     */
    private static function buildQueue(): array
    {
        $posts = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        $ids = [];
        foreach ($posts as $postId) {
            $ids[] = absint($postId);
        }
        return $ids;
    }
}
