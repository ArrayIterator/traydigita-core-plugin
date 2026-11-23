<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Redirects;

use function absint;
use function add_action;
use function array_values;
use function base64_decode;
use function check_ajax_referer;
use function count;
use function ctype_digit;
use function current_user_can;
use function esc_html__;
use function gmdate;
use function is_array;
use function is_string;
use function json_decode;
use function sanitize_text_field;
use function time;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the Redirect Manager module.
 *
 * Every endpoint requires the `traydigita-admin` nonce (sent as
 * `_ajax_nonce`, the field check_ajax_referer() reads) and the
 * `manage_options` capability:
 *
 * - traydigita_redirects_add_rule: add a rule, or replace the
 *   rule at `edit_index` when given. POST: from, to, status,
 *   edit_index (optional).
 * - traydigita_redirects_delete_rule: delete the rule at
 *   `index`. POST: index.
 * - traydigita_redirects_clear_log: empty the 404 log.
 * - traydigita_redirects_export: return the rules as a JSON
 *   payload the admin JavaScript downloads as a file.
 * - traydigita_redirects_import: import rules from a
 *   base64-encoded JSON payload (base64 transport, like the
 *   Login/Ads modules, so the JSON survives POST intact).
 *   POST: payload (base64 JSON), overwrite ('1' to replace all
 *   rules, otherwise merge, skipping duplicates by `from`).
 *
 * The module settings form itself (the 404-logging toggle) goes
 * through the generic `traydigita_admin_save` dispatcher; only
 * the rule list, the log, and import/export need these endpoints.
 * Every handler re-validates through RedirectStore, so a
 * malformed request can never store an invalid rule.
 */
final class RedirectsAjax
{
    /**
     * Nonce action shared by every admin endpoint.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Export format version written into exported files.
     */
    public const int EXPORT_VERSION = 1;

    /**
     * Register the module AJAX actions (authenticated admin only).
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_traydigita_redirects_add_rule', [self::class, 'addRule']);
        add_action('wp_ajax_traydigita_redirects_delete_rule', [self::class, 'deleteRule']);
        add_action('wp_ajax_traydigita_redirects_clear_log', [self::class, 'clearLog']);
        add_action('wp_ajax_traydigita_redirects_export', [self::class, 'exportRules']);
        add_action('wp_ajax_traydigita_redirects_import', [self::class, 'importRules']);
    }

    /**
     * Add a rule, or replace the rule at `edit_index`.
     *
     * POST: from (site path, optionally ending in `/*`), to
     * (site path or absolute URL), status (301/302/307/308),
     * edit_index (optional rule index to replace). Duplicate
     * `from` values are rejected, except when editing the rule
     * that already owns that `from`. Returns the full rule list
     * so the admin UI can re-render without a reload.
     *
     * @return void
     */
    public static function addRule(): void
    {
        self::authorizeAdmin();
        $store = RedirectStore::get();
        $rule = RedirectStore::normalizeRule([
            'from' => self::postString('from'),
            'to' => self::postString('to'),
            'status' => self::postString('status'),
        ]);
        if ($rule === null) {
            wp_send_json_error(
                ['message' => esc_html__('That redirect is not valid. The source must be a site path starting with /, and the target a site path or a full http(s) URL.', 'traydigita-core')],
                400
            );
        }
        $editIndex = self::postIndex('edit_index');
        if ($editIndex !== null) {
            if (!isset($store['rules'][$editIndex])) {
                wp_send_json_error(
                    ['message' => esc_html__('The rule you tried to edit no longer exists.', 'traydigita-core')],
                    400
                );
            }
            foreach ($store['rules'] as $index => $existing) {
                if ($index !== $editIndex && $existing['from'] === $rule['from']) {
                    wp_send_json_error(
                        ['message' => esc_html__('A redirect for that source path already exists.', 'traydigita-core')],
                        400
                    );
                }
            }
            $store['rules'][$editIndex] = $rule;
        } else {
            foreach ($store['rules'] as $existing) {
                if ($existing['from'] === $rule['from']) {
                    wp_send_json_error(
                        ['message' => esc_html__('A redirect for that source path already exists.', 'traydigita-core')],
                        400
                    );
                }
            }
            if (count($store['rules']) >= RedirectStore::MAX_RULES) {
                wp_send_json_error(
                    ['message' => esc_html__('The redirect list is full. Delete a rule before adding another.', 'traydigita-core')],
                    400
                );
            }
            $store['rules'][] = $rule;
        }
        $store['rules'] = array_values($store['rules']);
        RedirectStore::save($store);
        wp_send_json_success([
            'message' => esc_html__('Redirect saved.', 'traydigita-core'),
            'rules' => $store['rules'],
        ]);
    }

    /**
     * Delete the rule at `index`.
     *
     * POST: index (rule position in the stored list).
     *
     * @return void
     */
    public static function deleteRule(): void
    {
        self::authorizeAdmin();
        $store = RedirectStore::get();
        $index = self::postIndex('index');
        if ($index === null || !isset($store['rules'][$index])) {
            wp_send_json_error(
                ['message' => esc_html__('That redirect no longer exists.', 'traydigita-core')],
                400
            );
        }
        unset($store['rules'][$index]);
        $store['rules'] = array_values($store['rules']);
        RedirectStore::save($store);
        wp_send_json_success([
            'message' => esc_html__('Redirect deleted.', 'traydigita-core'),
            'rules' => $store['rules'],
        ]);
    }

    /**
     * Empty the 404 log (rules are untouched).
     *
     * @return void
     */
    public static function clearLog(): void
    {
        self::authorizeAdmin();
        $store = RedirectStore::get();
        $store['log'] = [];
        RedirectStore::save($store);
        wp_send_json_success([
            'message' => esc_html__('404 log cleared.', 'traydigita-core'),
        ]);
    }

    /**
     * Return the rules as an export payload.
     *
     * The admin JavaScript downloads the payload as a JSON file;
     * no file is written on the server. Only the rules are
     * exported — the 404 log is diagnostic data, not config.
     *
     * @return void
     */
    public static function exportRules(): void
    {
        self::authorizeAdmin();
        $store = RedirectStore::get();
        wp_send_json_success([
            'export' => [
                'version' => self::EXPORT_VERSION,
                'exported_at' => gmdate('c', time()),
                'rules' => $store['rules'],
            ],
            'filename' => 'traydigita-redirects-' . gmdate('Y-m-d', time()) . '.json',
        ]);
    }

    /**
     * Import rules from a base64-encoded JSON payload.
     *
     * POST: payload (base64-encoded JSON: either an object with
     * a `rules` array, or a bare array of rules), overwrite
     * ('1' to replace all existing rules, otherwise merge and
     * skip any rule whose `from` already exists). Strict-decoded
     * (base64_decode(..., true)) and strictly JSON-validated:
     * an undecodable payload or a wrong shape is rejected, and
     * each rule is re-validated through RedirectStore — invalid
     * entries are skipped and counted, never stored. Returns the
     * imported/skipped/duplicate counts and the full rule list.
     *
     * @return void
     */
    public static function importRules(): void
    {
        self::authorizeAdmin();
        $encoded = self::postString('payload');
        $json = base64_decode($encoded, true);
        if (!is_string($json) || $json === '') {
            wp_send_json_error(
                ['message' => esc_html__('The import file could not be read. Export a fresh file and try again.', 'traydigita-core')],
                400
            );
        }
        $decoded = json_decode($json, true);
        $rawRules = null;
        if (is_array($decoded) && isset($decoded['rules']) && is_array($decoded['rules'])) {
            $rawRules = $decoded['rules'];
        } elseif (is_array($decoded) && self::isList($decoded)) {
            $rawRules = $decoded;
        }
        if ($rawRules === null) {
            wp_send_json_error(
                ['message' => esc_html__('That file is not a TrayDigita redirect export. It must contain a list of redirect rules.', 'traydigita-core')],
                400
            );
        }
        $store = RedirectStore::get();
        $overwrite = self::postString('overwrite') === '1';
        $base = $overwrite ? [] : $store['rules'];
        $seen = [];
        foreach ($base as $existing) {
            $seen[$existing['from']] = true;
        }
        $imported = 0;
        $skipped = 0;
        $duplicates = 0;
        foreach ($rawRules as $rawRule) {
            if (!is_array($rawRule)) {
                $skipped++;
                continue;
            }
            $rule = RedirectStore::normalizeRule($rawRule);
            if ($rule === null) {
                $skipped++;
                continue;
            }
            if (isset($seen[$rule['from']])) {
                $duplicates++;
                continue;
            }
            if (count($base) >= RedirectStore::MAX_RULES) {
                $skipped++;
                continue;
            }
            $base[] = $rule;
            $seen[$rule['from']] = true;
            $imported++;
        }
        $store['rules'] = array_values($base);
        RedirectStore::save($store);
        wp_send_json_success([
            'message' => esc_html__('Import finished.', 'traydigita-core'),
            'imported' => $imported,
            'skipped' => $skipped,
            'duplicates' => $duplicates,
            'rules' => $store['rules'],
        ]);
    }

    /**
     * Verify the admin nonce and the manage_options capability.
     *
     * check_ajax_referer() dies with -1 on a bad nonce; a missing
     * capability is answered with a JSON 403 instead.
     *
     * @return void
     */
    private static function authorizeAdmin(): void
    {
        check_ajax_referer(self::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')], 403);
        }
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
     * Read a POST field as a non-negative integer index, or null
     * when the field is missing or not a plain integer.
     *
     * @param string $key Field name.
     * @return int|null
     */
    private static function postIndex(string $key): ?int
    {
        $raw = isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        return absint($raw);
    }

    /**
     * Whether an array is a plain list (sequential integer keys
     * from zero), i.e. a bare rules array rather than an object.
     *
     * @param array<mixed> $value Array to test.
     * @return bool
     */
    private static function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $item) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }
        return true;
    }
}
