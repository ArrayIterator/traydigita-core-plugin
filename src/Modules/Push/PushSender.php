<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Push;

use TrayDigita\WP\Plugin\Core\Libs\GoogleServiceAccount;
use TrayDigita\WP\Plugin\Core\Libs\SchemaTable;
use function __;
use function array_slice;
use function current_time;
use function delete_option;
use function delete_transient;
use function esc_url_raw;
use function get_option;
use function get_transient;
use function hash;
use function is_array;
use function is_string;
use function is_wp_error;
use function json_decode;
use function preg_match;
use function rawurlencode;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function stripos;
use function strlen;
use function substr;
use function trim;
use function update_option;
use function usleep;
use function wp_json_encode;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use WP_Error;

/**
 * Firebase Cloud Messaging engine for the Push module.
 *
 * Adapted from the standalone koperasi-kita-push plugin (which
 * stays untouched as the standalone alternative): an OAuth
 * access token from the shared GoogleServiceAccount client,
 * then FCM HTTP v1 sends — no Composer, no SDK on the server.
 * Sending is manual only; nothing here ever auto-sends on
 * publish.
 *
 * Storage: the web config + VAPID key live in the single option
 * `traydigita_push` (autoload `no`); the Service Account JSON
 * lives in its own option `traydigita_push_service_account` so
 * the settings form can show a "saved" state without ever
 * printing the private key back to the browser. Subscriber
 * tokens live in the module's own table
 * `{$wpdb->prefix}traydigita_push_tokens` (created lazily with
 * CREATE TABLE IF NOT EXISTS on the first token save, upgraded
 * with dbDelta only when the schema version changes); the
 * plugin's `wp_kk_push_tokens` table is never touched. The
 * access token is cached in a transient for ~50 minutes and is
 * never logged or returned to the browser.
 *
 * These option and table names are unchanged since the push
 * half lived in the PWA module, so a site that had push
 * configured keeps its config, its subscribers, and its send
 * log with no migration step: the Push module simply takes
 * ownership of the same names.
 */
final class PushSender
{
    /**
     * Option name for the web config + VAPID key (autoload `no`).
     */
    public const string OPTION = 'traydigita_push';

    /**
     * Option name for the Service Account JSON (autoload `no`).
     */
    public const string SERVICE_ACCOUNT_OPTION = 'traydigita_push_service_account';

    /**
     * Option name for the manual-send log (autoload `no`).
     */
    public const string LOG_OPTION = 'traydigita_push_send_log';

    /**
     * Unprefixed name of the subscriber-token table.
     */
    public const string TABLE = 'traydigita_push_tokens';

    /**
     * Option flag recording the tokens-table schema version.
     */
    public const string TABLE_VERSION_OPTION = 'traydigita_push_table_version';

    /**
     * Current tokens-table schema version.
     */
    public const int TABLE_VERSION = 1;

    /**
     * Transient throttling the schema-upgrade check.
     *
     * When present, ensureTokensTable() returns without touching
     * the database at all. Set for one hour after every check,
     * whatever the outcome.
     */
    public const string VERSION_CHECK_TRANSIENT = 'traydigita_push_table_version_check';

    /**
     * How long the version-check throttle lives, in seconds.
     */
    public const int VERSION_CHECK_TTL = 3600;

    /**
     * Transient serialising concurrent schema upgrades.
     *
     * Held while dbDelta runs; a second request that finds it
     * skips its own upgrade instead of racing the first.
     */
    public const string DELTA_LOCK_TRANSIENT = 'traydigita_db_delta_lock_push_tokens';

    /**
     * How long the upgrade lock lives, in seconds.
     */
    public const int DELTA_LOCK_TTL = 60;

    /**
     * Longest device token accepted (FCM tokens are ~150 chars).
     */
    public const int MAX_TOKEN_LENGTH = 512;

    /**
     * Transient holding the cached FCM access token.
     */
    public const string TOKEN_TRANSIENT = 'traydigita_push_access_token';

    /**
     * REST namespace for the device-registration endpoint.
     */
    public const string REST_NAMESPACE = 'traydigita-push/v1';

    /**
     * Firebase JS SDK version loaded from gstatic (frontend + SW).
     */
    public const string FIREBASE_JS_VERSION = '10.12.2';

    /**
     * Required keys of the Firebase web config object.
     *
     * @var array<int, string>
     */
    public const array FIREBASE_CONFIG_KEYS = [
        'apiKey',
        'authDomain',
        'projectId',
        'messagingSenderId',
        'appId',
    ];

    /**
     * Minimum plausible VAPID public-key length.
     *
     * Real VAPID keys are 87 base64url characters; the floor
     * only rejects obvious junk (an empty string, a word, a
     * truncated paste) instead of pretending to know the exact
     * length Google will accept.
     */
    public const int MIN_VAPID_LENGTH = 20;

    /**
     * OAuth scope the FCM access token is requested for.
     */
    public const string FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * FCM HTTP v1 host: the only host contacted for sends,
     * besides oauth2.googleapis.com for the access token.
     */
    public const string FCM_HOST = 'fcm.googleapis.com';

    /**
     * Validate a pasted Firebase web config JSON string.
     *
     * @param string $raw Raw pasted JSON.
     * @return array{config: array<string, mixed>|null, error: string} Config array on success, else an error message.
     */
    public static function validateFirebaseConfig(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['config' => null, 'error' => __('Firebase web config is required.', 'traydigita-core')];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['config' => null, 'error' => __('Firebase web config is not valid JSON.', 'traydigita-core')];
        }
        foreach (self::FIREBASE_CONFIG_KEYS as $key) {
            if (!isset($decoded[$key]) || !is_string($decoded[$key]) || trim($decoded[$key]) === '') {
                return [
                    'config' => null,
                    /* translators: %s is the missing Firebase web config key. */
                    'error' => sprintf(__('Firebase web config is missing the required key: %s.', 'traydigita-core'), $key),
                ];
            }
        }
        $config = [];
        foreach (self::FIREBASE_CONFIG_KEYS as $key) {
            $config[$key] = trim($decoded[$key]);
        }
        return ['config' => $config, 'error' => ''];
    }

    /**
     * Validate a pasted VAPID public key.
     *
     * @param string $key Raw pasted key.
     * @return string Empty on success, else an error message.
     */
    public static function validateVapidKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return __('VAPID key is required.', 'traydigita-core');
        }
        if (strlen($key) < self::MIN_VAPID_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            return __('VAPID key does not look like a Firebase Web Push key. Copy it from Firebase Console > Project Settings > Cloud Messaging > Web Push certificates.', 'traydigita-core');
        }
        return '';
    }

    /**
     * Validate a pasted Service Account JSON string.
     *
     * Delegates to the shared GoogleServiceAccount client; a
     * push Service Account must also carry
     * "type": "service_account".
     *
     * @param string $raw Raw pasted JSON.
     * @return array{account: array<string, mixed>|null, error: string} Account array on success, else an error message.
     */
    public static function validateServiceAccount(string $raw): array
    {
        return GoogleServiceAccount::validate($raw, true);
    }

    /**
     * Read the stored push settings merged over the defaults.
     *
     * The Service Account JSON is never part of the return
     * value: callers that need to show a "saved" state use
     * serviceAccountSummary() instead.
     *
     * @return array{firebase_config: string, vapid_key: string, project_id: string, locked: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return [
            'firebase_config' => isset($stored['firebase_config']) && is_string($stored['firebase_config'])
                ? $stored['firebase_config']
                : '',
            'vapid_key' => isset($stored['vapid_key']) && is_string($stored['vapid_key'])
                ? trim($stored['vapid_key'])
                : '',
            'project_id' => isset($stored['project_id']) && is_string($stored['project_id'])
                ? trim($stored['project_id'])
                : '',
            'locked' => !empty($stored['locked']),
        ];
    }

    /**
     * Whether a valid push config is saved and locked.
     *
     * All three pieces must validate against what is stored:
     * a locked flag alone is never trusted, so a config edited
     * outside the settings form cannot pass as valid.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        if (!$settings['locked']) {
            return false;
        }
        $config = self::validateFirebaseConfig($settings['firebase_config']);
        if ($config['config'] === null) {
            return false;
        }
        if (self::validateVapidKey($settings['vapid_key']) !== '') {
            return false;
        }
        return self::getServiceAccount() !== null;
    }

    /**
     * Read and parse the stored Service Account JSON.
     *
     * @return array<string, mixed>|null Parsed account, or null when missing or invalid.
     */
    public static function getServiceAccount(): ?array
    {
        $raw = get_option(self::SERVICE_ACCOUNT_OPTION, '');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $account = GoogleServiceAccount::parse($raw);
        if ($account === null) {
            return null;
        }
        if ($account['project_id'] === '') {
            $account['project_id'] = self::getSettings()['project_id'];
        }
        return $account;
    }

    /**
     * Non-secret summary of the stored Service Account.
     *
     * Only the project id and the client email are ever shown;
     * the private key is never printed, masked or otherwise.
     *
     * @return array{project_id: string, client_email: string}|null
     */
    public static function serviceAccountSummary(): ?array
    {
        $account = self::getServiceAccount();
        if ($account === null) {
            return null;
        }
        return [
            'project_id' => isset($account['project_id']) && is_string($account['project_id'])
                ? $account['project_id']
                : '',
            'client_email' => isset($account['client_email']) && is_string($account['client_email'])
                ? $account['client_email']
                : '',
        ];
    }

    /**
     * Delete the stored push config (options + cached token).
     *
     * Subscriber tokens and the send log are kept: clearing the
     * config must not wipe the subscriber list, so re-entering
     * a config later resumes sending to the same devices.
     *
     * @return void
     */
    public static function deleteConfig(): void
    {
        delete_option(self::OPTION);
        delete_option(self::SERVICE_ACCOUNT_OPTION);
        delete_transient(self::TOKEN_TRANSIENT);
    }

    /**
     * Whether the tokens table was verified present in this request.
     *
     * Shared table kernel for this class's table (see
     * `Libs\SchemaTable`).
     */
    private static ?SchemaTable $schema = null;

    /**
     * Shared table kernel, built once from this class's constants.
     *
     * The constants above stay the single definition of the
     * table's stored state (version option, check/lock transient
     * names and TTLs); SchemaTable only executes the pattern.
     *
     * @return SchemaTable
     */
    private static function schema(): SchemaTable
    {
        if (self::$schema === null) {
            self::$schema = new SchemaTable(
                self::TABLE,
                self::TABLE_VERSION,
                self::TABLE_VERSION_OPTION,
                self::VERSION_CHECK_TRANSIENT,
                self::VERSION_CHECK_TTL,
                self::DELTA_LOCK_TRANSIENT,
                self::DELTA_LOCK_TTL,
                self::schemaSql(...)
            );
        }
        return self::$schema;
    }

    /**
     * Table SQL for the shared kernel.
     *
     * @param bool $ifNotExists Add IF NOT EXISTS (lazy insert path).
     * @return string
     */
    private static function schemaSql(bool $ifNotExists): string
    {
        global $wpdb;
        return self::createTableSql($wpdb->prefix . self::TABLE, $wpdb->get_charset_collate(), $ifNotExists);
    }

    /**
     * DDL for the subscriber-token table.
     *
     * @param string $table Full table name.
     * @param string $charset Charset/collate clause.
     * @param bool $ifNotExists Add IF NOT EXISTS (lazy insert path).
     * @return string
     */
    private static function createTableSql(string $table, string $charset, bool $ifNotExists = false): string
    {
        $guard = $ifNotExists ? 'IF NOT EXISTS ' : '';
        return "CREATE TABLE {$guard}{$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash varchar(64) NOT NULL,
            token text NOT NULL,
            user_agent varchar(255) DEFAULT '',
            created_at datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash)
        ) {$charset};";
    }

    /**
     * Make sure the subscriber-token table exists, lazily.
     *
     * Called from the insert path (saveToken()) only — never from
     * a page load. When the table is missing it is created with
     * one idempotent CREATE TABLE IF NOT EXISTS and the insert
     * then proceeds, so no token is ever lost to a deleted table.
     * No dbDelta, no error_log, no throw.
     *
     * @return bool True when the table exists after this call.
     */
    public static function ensureTableExists(): bool
    {
        return self::schema()->ensureForInsert();
    }

    /**
     * Upgrade the subscriber-token table schema when the version changed.
     *
     * See `SchemaTable::ensureSchema()` for the gating: dbDelta
     * runs at most once an hour behind the version-check
     * transient, a separate transient lock (60s) serialises
     * concurrent upgrades and is released in a `finally` block,
     * and the path is admin-only. Pass $force to bypass the
     * hourly check (the Setup create-table button); the lock is
     * still respected. Table creation itself never happens here:
     * the lazy insert path (ensureTableExists(), called from
     * saveToken()) creates a missing table with CREATE TABLE IF
     * NOT EXISTS.
     *
     * @param bool $force Bypass the hourly version-check throttle.
     * @return void
     */
    public static function ensureTokensTable(bool $force = false): void
    {
        self::schema()->ensureSchema($force);
    }

    /**
     * Number of registered subscriber devices.
     *
     * @return int
     */
    public static function subscriberCount(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        return $count === null ? 0 : (int) $count;
    }

    /**
     * Store one device token (insert or refresh on re-register).
     *
     * @param string $token FCM device token.
     * @param string $userAgent Request user agent.
     * @return bool False when the token is too short to be real.
     */
    public static function saveToken(string $token, string $userAgent): bool
    {
        $token = sanitize_text_field($token);
        if (strlen($token) < 20 || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }
        if (!self::ensureTableExists()) {
            return false;
        }
        global $wpdb;
        $now = current_time('mysql');
        $replaced = $wpdb->replace(
            $wpdb->prefix . self::TABLE,
            [
                'token_hash' => hash('sha256', $token),
                'token' => $token,
                'user_agent' => substr(sanitize_text_field($userAgent), 0, 255),
                'created_at' => $now,
                'last_seen' => $now,
            ]
        );
        return $replaced !== false;
    }

    /**
     * Remove one device token.
     *
     * @param string $token FCM device token.
     * @return void
     */
    public static function deleteToken(string $token): void
    {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            ['token_hash' => hash('sha256', sanitize_text_field($token))]
        );
    }

    /**
     * All stored device tokens.
     *
     * @return array<int, string>
     */
    public static function allTokens(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $tokens = $wpdb->get_col("SELECT token FROM {$table}");
        if (!is_array($tokens)) {
            return [];
        }
        return array_values(array_filter($tokens, static fn ($token): bool => is_string($token) && $token !== ''));
    }

    /**
     * Recent manual-send log entries (newest first).
     *
     * @return array<int, array{time: string, title: string, sent: int, failed: int}>
     */
    public static function getLog(): array
    {
        $log = get_option(self::LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }

    /**
     * Fetch an FCM access token (OAuth2, via the shared Google service-account client).
     *
     * The token is cached in a transient for ~50 minutes. The
     * token value itself is never logged or returned to the
     * browser — callers only learn whether one was obtained.
     *
     * @param bool $force Skip the transient cache.
     * @return string|WP_Error Access token, or an error describing why none could be obtained.
     */
    public static function getAccessToken(bool $force = false): string|WP_Error
    {
        if (!$force) {
            $cached = get_transient(self::TOKEN_TRANSIENT);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }
        $account = self::getServiceAccount();
        if ($account === null || empty($account['project_id'])) {
            return new WP_Error('push_no_service_account', __('Service Account has not been saved yet.', 'traydigita-core'));
        }
        $token = GoogleServiceAccount::getAccessToken($account, self::FCM_SCOPE);
        if (is_wp_error($token)) {
            $code = $token->get_error_code();
            if ($code === 'google_sa_sign_failed') {
                return new WP_Error('push_sign_failed', __('Could not sign the Service Account JWT. Check the private_key.', 'traydigita-core'));
            }
            if ($code === 'google_sa_token_denied') {
                $detail = $token->get_error_data();
                $detail = is_array($detail) && isset($detail['detail']) && is_string($detail['detail'])
                    ? $detail['detail']
                    : '';
                return new WP_Error(
                    'push_token_denied',
                    $detail !== ''
                        ? sprintf(
                            /* translators: %s is Google's error description. */
                            __('Google did not return an access token: %s', 'traydigita-core'),
                            $detail
                        )
                        : __('Google did not return an access token. Check the Service Account JSON.', 'traydigita-core')
                );
            }
            return new WP_Error('push_token_request_failed', $token->get_error_message());
        }
        set_transient(self::TOKEN_TRANSIENT, $token, 50 * MINUTE_IN_SECONDS);
        return $token;
    }

    /**
     * Send one notification to one FCM token via HTTP v1.
     *
     * @param string $accessToken OAuth access token.
     * @param string $projectId Firebase project id.
     * @param string $token Device token.
     * @param string $title Notification title.
     * @param string $body Notification body.
     * @param string $link Destination URL.
     * @param string $image Optional image URL.
     * @return true|string True on success, else an error string.
     */
    public static function sendToToken(
        string $accessToken,
        string $projectId,
        string $token,
        string $title,
        string $body,
        string $link,
        string $image
    ): bool|string {
        $message = [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'webpush' => [
                'fcm_options' => ['link' => $link],
            ],
            'data' => [
                'url' => $link,
                'title' => $title,
            ],
        ];
        if ($image !== '') {
            $message['notification']['image'] = $image;
        }
        $response = wp_remote_post(
            'https://' . self::FCM_HOST . '/v1/projects/' . rawurlencode($projectId) . '/messages:send',
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => (string) wp_json_encode(['message' => $message]),
            ]
        );
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return true;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $detail = isset($decoded['error']['message']) && is_string($decoded['error']['message'])
            ? $decoded['error']['message']
            : (string) wp_remote_retrieve_body($response);
        return $code . ': ' . $detail;
    }

    /**
     * Manual send: loop over tokens, prune dead ones, log the result.
     *
     * @param string $title Notification title.
     * @param string $body Notification body.
     * @param string $link Destination URL.
     * @param string $image Optional image URL.
     * @param string $mode 'all' for every subscriber, 'test' for one token.
     * @param string $testToken Single token when $mode is 'test'.
     * @return array{success: bool, message: string, sent: int, failed: int, pruned: int}
     */
    public static function doSend(
        string $title,
        string $body,
        string $link,
        string $image,
        string $mode,
        string $testToken
    ): array {
        $account = self::getServiceAccount();
        if ($account === null || empty($account['project_id'])) {
            return [
                'success' => false,
                'message' => __('Could not send: Service Account has not been saved yet. Save it on the Settings tab first.', 'traydigita-core'),
                'sent' => 0,
                'failed' => 0,
                'pruned' => 0,
            ];
        }
        $accessToken = self::getAccessToken();
        if (is_wp_error($accessToken)) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s is the token error. */
                    __('Could not get an access token: %s', 'traydigita-core'),
                    $accessToken->get_error_message()
                ),
                'sent' => 0,
                'failed' => 0,
                'pruned' => 0,
            ];
        }
        $tokens = $mode === 'test' ? [$testToken] : self::allTokens();
        $tokens = array_values(array_filter($tokens, static fn ($token): bool => is_string($token) && $token !== ''));
        if ($tokens === []) {
            return [
                'success' => false,
                'message' => __('There are no subscribers to send to.', 'traydigita-core'),
                'sent' => 0,
                'failed' => 0,
                'pruned' => 0,
            ];
        }
        $sent = 0;
        $failed = 0;
        $dead = [];
        foreach ($tokens as $token) {
            $result = self::sendToToken(
                $accessToken,
                (string) $account['project_id'],
                $token,
                $title,
                $body,
                esc_url_raw($link),
                $image
            );
            if ($result === true) {
                $sent++;
            } else {
                $failed++;
                // Dead tokens (app uninstalled / token revoked) are pruned.
                if (stripos((string) $result, 'UNREGISTERED') !== false || stripos((string) $result, 'INVALID_ARGUMENT') !== false) {
                    $dead[] = hash('sha256', $token);
                }
            }
            if (($sent + $failed) % 100 === 0) {
                usleep(100000);
            }
        }
        if ($dead !== []) {
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            foreach ($dead as $hash) {
                $wpdb->delete($table, ['token_hash' => $hash]);
            }
        }
        $log = self::getLog();
        array_unshift($log, [
            'time' => current_time('mysql'),
            'title' => $title,
            'sent' => $sent,
            'failed' => $failed,
        ]);
        update_option(self::LOG_OPTION, array_slice($log, 0, 50), false);
        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %1$d sent, %2$d failed, %3$d pruned. */
                __('Sent to %1$d devices, failed %2$d, dead tokens removed: %3$d.', 'traydigita-core'),
                $sent,
                $failed,
                count($dead)
            ),
            'sent' => $sent,
            'failed' => $failed,
            'pruned' => count($dead),
        ];
    }
}
