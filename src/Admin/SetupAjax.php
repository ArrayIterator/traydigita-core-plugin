<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use Plugin_Upgrader;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaModule;
use TrayDigita\WP\Plugin\Core\Modules\Installer\InstallerAjax;
use TrayDigita\WP\Plugin\Core\Modules\Installer\PluginList;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use function add_action;
use function admin_url;
use function check_admin_referer;
use function check_ajax_referer;
use function current_user_can;
use function delete_transient;
use function esc_html__;
use function file_exists;
use function flush_rewrite_rules;
use function get_filesystem_method;
use function get_transient;
use function is_array;
use function is_string;
use function is_wp_error;
use function json_decode;
use function ob_end_clean;
use function ob_get_level;
use function ob_start;
use function parse_url;
use function preg_match;
use function sanitize_file_name;
use function set_transient;
use function sprintf;
use function str_starts_with;
use function time;
use function unlink;
use function update_option;
use function WP_Filesystem;
use function wp_check_filetype;
use function wp_die;
use function wp_handle_upload;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wp_safe_redirect;
use function wp_send_json_error;
use function wp_send_json_success;

/**
 * AJAX and admin-post endpoints for the Setup page.
 *
 * Endpoints (all authenticated admin only, nonce action
 * `traydigita-admin` — InstallerAjax::NONCE_ACTION — sent as
 * `_ajax_nonce` for AJAX and `_wpnonce` for the upload form,
 * exactly like the generic admin dispatcher):
 * - traydigita_setup_install_stack: install TrayDigita Stack from
 *   the project's GitHub release (capability `install_plugins`).
 * - traydigita_setup_activate_stack: activate TrayDigita Stack
 *   (capability `activate_plugins`).
 * - traydigita_setup_flush_permalinks: flush the rewrite rules
 *   and set the Agenda flushed flag (capability `manage_options`).
 * - admin_post_traydigita_setup_upload_stack: install
 *   TrayDigita Stack from an uploaded .zip file (capability
 *   `install_plugins`; redirects back to the Setup page).
 *
 * Trust rules, enforced before any network or filesystem work
 * (same rules as InstallerAjax::doInstall()):
 * - The stack package never comes from a caller-supplied URL.
 *   The install endpoint asks the GitHub API for the latest
 *   release of ArrayIterator/traydigita-stack and takes the
 *   first asset whose name matches the release-asset pattern;
 *   its download URL must parse to host `github.com` with a path
 *   under the project's releases/download directory, or the
 *   install is refused and nothing is downloaded.
 * - The upload endpoint accepts a file upload only — never a
 *   URL — and only a .zip under 20 MB.
 * - Filesystem: only the `direct` method is used. When the
 *   site's filesystem method is anything else, the install is
 *   refused with a clear error instead of asking for FTP
 *   credentials; no credentials are ever collected or stored.
 * - One install at a time: the installer transient lock
 *   (InstallerAjax::LOCK_TRANSIENT) rejects a second concurrent
 *   install with a "busy" error.
 * - Activation never happens automatically: install only
 *   installs. The user activates with a separate click, which
 *   calls the activate endpoint.
 *
 * Installs run through core's Plugin_Upgrader with the
 * WP_Ajax_Upgrader_Skin, the same upgrader the core plugin
 * installer AJAX flow uses, so output stays captured and errors
 * come back as structured data.
 */
final class SetupAjax
{
    /**
     * GitHub API URL of the latest traydigita-stack release.
     */
    public const string GITHUB_API_URL = 'https://api.github.com/repos/ArrayIterator/traydigita-stack/releases/latest';

    /**
     * Only host a stack package may be downloaded from.
     */
    public const string TRUSTED_RELEASE_HOST = 'github.com';

    /**
     * Only path prefix a stack package download URL may have.
     */
    public const string TRUSTED_RELEASE_PATH_PREFIX = '/ArrayIterator/traydigita-stack/releases/download/';

    /**
     * Pattern a release asset name must match to be installable.
     */
    public const string GITHUB_ASSET_PATTERN = '/^traydigita-stack-[0-9][^\/]*\.zip$/';

    /**
     * Largest accepted stack .zip upload, in bytes (20 MB).
     */
    public const int MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    /**
     * Register every Setup endpoint.
     *
     * Called by the wiring builder (AdminMenu / theme boot).
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_traydigita_setup_install_stack', [self::class, 'installStack']);
        add_action('wp_ajax_traydigita_setup_activate_stack', [self::class, 'activateStack']);
        add_action('wp_ajax_traydigita_setup_flush_permalinks', [self::class, 'flushPermalinks']);
        add_action('wp_ajax_traydigita_setup_create_activity_log', [self::class, 'createActivityLogTable']);
        add_action('admin_post_traydigita_setup_upload_stack', [self::class, 'uploadStack']);
    }

    /**
     * Install TrayDigita Stack from its GitHub release.
     *
     * POST: _ajax_nonce. Requires the `install_plugins`
     * capability. Looks up the latest release through the GitHub
     * API, validates the asset download URL, and installs with
     * core's Plugin_Upgrader. Never auto-activates: activation is
     * the separate activate endpoint. Answers with the plugin's
     * new status so the UI can swap the Install button for
     * Activate.
     *
     * @return void
     */
    public static function installStack(): void
    {
        check_ajax_referer(InstallerAjax::NONCE_ACTION);
        if (!current_user_can('install_plugins')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to install plugins.', 'traydigita-core')],
                403
            );
        }
        $status = PluginList::status(PluginList::STACK_SLUG);
        if ($status !== PluginList::STATUS_NOT_INSTALLED) {
            wp_send_json_success([
                'message' => esc_html__('TrayDigita Stack is already installed.', 'traydigita-core'),
                'slug' => PluginList::STACK_SLUG,
                'status' => $status,
            ]);
        }
        if (get_transient(InstallerAjax::LOCK_TRANSIENT) !== false) {
            wp_send_json_error(
                ['message' => esc_html__(
                    'Another plugin install is already running. Please wait for it to finish and try again.',
                    'traydigita-core'
                )],
                409
            );
        }
        set_transient(InstallerAjax::LOCK_TRANSIENT, time(), InstallerAjax::LOCK_TTL);
        try {
            $result = self::doInstallStack();
        } finally {
            delete_transient(InstallerAjax::LOCK_TRANSIENT);
        }
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'slug' => PluginList::STACK_SLUG,
            'status' => $result['status'],
        ]);
    }

    /**
     * Activate TrayDigita Stack.
     *
     * POST: _ajax_nonce. Requires the `activate_plugins`
     * capability. Delegates to InstallerAjax::activate() as the
     * final statement: that method verifies the nonce and the
     * capability itself, reads the slug from $_POST['plugin'],
     * and ends with wp_send_json_* (dies). The slug is forced to
     * the stack constant here — never caller input — so this
     * endpoint can only ever activate the stack plugin.
     *
     * @return void
     */
    public static function activateStack(): void
    {
        check_ajax_referer(InstallerAjax::NONCE_ACTION);
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to activate plugins.', 'traydigita-core')],
                403
            );
        }
        $_POST['plugin'] = PluginList::STACK_SLUG;
        InstallerAjax::activate();
    }

    /**
     * Flush the rewrite rules and mark them flushed.
     *
     * POST: _ajax_nonce. Requires the `manage_options`
     * capability. Runs flush_rewrite_rules() and sets the Agenda
     * flushed flag exactly the way AgendaModule::boot() does, so
     * the flag and the stored rules agree. Answers with the
     * refreshed permalinks item (status + detail) so the UI can
     * update its badge without a reload.
     *
     * @return void
     */
    public static function flushPermalinks(): void
    {
        check_ajax_referer(InstallerAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to change permalink settings.', 'traydigita-core')],
                403
            );
        }
        flush_rewrite_rules();
        update_option(AgendaModule::REWRITES_FLUSHED_OPTION, 1, false);
        $item = SetupPage::permalinksItem();
        wp_send_json_success([
            'message' => $item['status'] === 'done'
                ? esc_html__('Permalink rules are in place.', 'traydigita-core')
                : esc_html__(
                    'Rewrite rules were flushed, but the Agenda or E-Paper rules are still missing. Check that both modules are enabled.',
                    'traydigita-core'
                ),
            'status' => $item['status'],
            'detail' => $item['detail'],
        ]);
    }

    /**
     * Create the Activity Log table.
     *
     * POST: _ajax_nonce. Requires the `manage_options`
     * capability. Runs the version-gated dbDelta upgrade path
     * immediately (bypassing the 1-hour check transient) through
     * `ActivityLogModule::ensureSchema(true)`, which acquires
     * the dbDelta lock transient itself and releases it in a
     * `finally` block — so this endpoint never holds the lock
     * while calling it (a pre-acquired lock would make the
     * ensure call skip every time). A request arriving while
     * the lock is held gets a 409 instead of a second
     * concurrent dbDelta. Answers with the refreshed
     * activity-log item (status + detail) so the UI can update
     * its badge without a reload. The same endpoint is called
     * by the create-table button on the Activity Log settings
     * page.
     *
     * @return void
     */
    public static function createActivityLogTable(): void
    {
        check_ajax_referer(InstallerAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to change Activity Log settings.', 'traydigita-core')],
                403
            );
        }
        if (get_transient(ActivityLogModule::DB_DELTA_LOCK_TRANSIENT) !== false) {
            wp_send_json_error(
                ['message' => esc_html__(
                    'The log table is already being created. Please wait a moment and try again.',
                    'traydigita-core'
                )],
                409
            );
        }
        $created = ActivityLogModule::ensureSchema(true);
        if (!$created) {
            wp_send_json_error(
                ['message' => esc_html__('The log table could not be created. Please try again.', 'traydigita-core')],
                400
            );
        }
        $item = SetupPage::activityLogItem();
        wp_send_json_success([
            // Neutral wording on purpose: the module boot already
            // heals a missing table on admin requests (version-gated
            // + throttled), so by the time this endpoint runs the
            // table may exist already — "created" vs "already exists"
            // cannot be told apart reliably here. The detail line
            // carries the live state either way.
            'message' => esc_html__('The log table is ready.', 'traydigita-core'),
            'status' => $item['status'],
            'detail' => $item['detail'],
        ]);
    }

    /**
     * Install TrayDigita Stack from an uploaded .zip file.
     *
     * Classic admin-post handler (the upload form posts here with
     * enctype multipart/form-data). Nonce action
     * `traydigita-admin` sent as `_wpnonce`; requires the
     * `install_plugins` capability. Validates the upload, installs
     * it with core's Plugin_Upgrader, deletes the temporary file,
     * and redirects back to the Setup page with
     * ?setup-result=installed|error&setup-message=<code>. The
     * message is always a short code from the allowlist in
     * SetupPage (rendered as human text there) — raw input is
     * never reflected in the redirect.
     *
     * @return void
     */
    public static function uploadStack(): void
    {
        check_admin_referer(InstallerAjax::NONCE_ACTION);
        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('You are not allowed to install plugins.', 'traydigita-core'));
        }
        $code = self::handleUpload();
        $result = $code === 'installed' || $code === 'already-installed' ? 'installed' : 'error';
        wp_safe_redirect(
            admin_url(
                'admin.php?page=' . SetupPage::SLUG
                . '&setup-result=' . $result
                . '&setup-message=' . $code
            )
        );
        exit;
    }

    /**
     * Validate the uploaded file and install it.
     *
     * Returns a short result code only (see SetupPage for the
     * human text): 'installed', 'already-installed', 'no-file',
     * 'upload-error', 'not-zip', 'too-large', 'filesystem' or
     * 'install-failed'. The temporary upload is deleted after the
     * install attempt, whether it succeeded or not. Upgrader
     * output is discarded with output buffering: this request
     * ends in a redirect, so nothing may be echoed.
     *
     * @return string Result code.
     */
    private static function handleUpload(): string
    {
        $file = $_FILES['stack_zip'] ?? null;
        if (!is_array($file)) {
            return 'no-file';
        }
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return 'no-file';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'upload-error';
        }
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) {
            return 'no-file';
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            return 'too-large';
        }
        $name = isset($file['name']) && is_string($file['name'])
            ? sanitize_file_name($file['name'])
            : '';
        $type = wp_check_filetype($name, ['zip' => 'application/zip']);
        if (($type['ext'] ?? '') !== 'zip') {
            return 'not-zip';
        }
        if (PluginList::status(PluginList::STACK_SLUG) !== PluginList::STATUS_NOT_INSTALLED) {
            return 'already-installed';
        }
        self::ensureUpgraderApi();
        if (get_filesystem_method() !== 'direct' || !WP_Filesystem()) {
            return 'filesystem';
        }
        $moved = wp_handle_upload($file, ['test_form' => false, 'mimes' => ['zip' => 'application/zip']]);
        if (!is_array($moved) || isset($moved['error']) || !isset($moved['file']) || !is_string($moved['file'])) {
            return 'upload-error';
        }
        $tempFile = $moved['file'];
        $installed = false;
        ob_start();
        try {
            $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
            $installed = $upgrader->install($tempFile);
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
        if (is_wp_error($installed) || $installed === false) {
            return 'install-failed';
        }
        if (PluginList::status(PluginList::STACK_SLUG) === PluginList::STATUS_NOT_INSTALLED) {
            return 'install-failed';
        }
        return 'installed';
    }

    /**
     * Run the GitHub-release install for TrayDigita Stack.
     *
     * Mirrors InstallerAjax::doInstall(): direct filesystem
     * method only, core Plugin_Upgrader, and a post-install
     * status check. The only difference is the package source:
     * the latest GitHub release asset (validated by
     * latestStackDownloadUrl()) instead of wordpress.org. The
     * caller owns the lock and the JSON response; this method
     * only returns a result array and never dies, so the lock is
     * always released.
     *
     * @return array{success: bool, message: string, status: string}
     */
    private static function doInstallStack(): array
    {
        $lookup = self::latestStackDownloadUrl();
        if ($lookup['url'] === null) {
            return [
                'success' => false,
                'message' => $lookup['message'],
                'status' => PluginList::STATUS_NOT_INSTALLED,
            ];
        }
        self::ensureUpgraderApi();
        if (get_filesystem_method() !== 'direct') {
            return [
                'success' => false,
                'message' => esc_html__(
                    'WordPress cannot write plugin files directly on this site (direct filesystem access is unavailable). Check the file ownership and permissions, or install the plugin from the Plugins screen, and try again.',
                    'traydigita-core'
                ),
                'status' => PluginList::STATUS_NOT_INSTALLED,
            ];
        }
        if (!WP_Filesystem()) {
            return [
                'success' => false,
                'message' => esc_html__(
                    'WordPress could not access the filesystem to install the plugin. Check the file ownership and permissions and try again.',
                    'traydigita-core'
                ),
                'status' => PluginList::STATUS_NOT_INSTALLED,
            ];
        }
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $installed = $upgrader->install($lookup['url']);
        if (is_wp_error($installed)) {
            return [
                'success' => false,
                'message' => self::errorMessage($installed, esc_html__(
                    'The plugin could not be installed.',
                    'traydigita-core'
                )),
                'status' => PluginList::status(PluginList::STACK_SLUG),
            ];
        }
        if ($installed === false || $installed === null) {
            // Plugin_Upgrader::install() returns null — not false
            // or a WP_Error — when the package download fails:
            // $this->result is only set by install_package(),
            // which never runs. Treat null as a failed install
            // and surface the skin's error, or the user gets the
            // misleading "reported success" message below.
            $skinError = $upgrader->skin instanceof WP_Ajax_Upgrader_Skin ? $upgrader->skin->get_errors() : null;
            $message = $skinError instanceof WP_Error
                ? self::errorMessage($skinError, '')
                : '';
            return [
                'success' => false,
                'message' => $message !== '' ? $message : esc_html__(
                    'The plugin could not be installed.',
                    'traydigita-core'
                ),
                'status' => PluginList::status(PluginList::STACK_SLUG),
            ];
        }
        $status = PluginList::status(PluginList::STACK_SLUG);
        if ($status === PluginList::STATUS_NOT_INSTALLED) {
            return [
                'success' => false,
                'message' => esc_html__(
                    'The installer reported success, but the plugin is still not installed. Please check the Plugins screen.',
                    'traydigita-core'
                ),
                'status' => $status,
            ];
        }
        return [
            'success' => true,
            'message' => esc_html__(
                'TrayDigita Stack was installed. Activate it when you are ready — nothing is activated automatically.',
                'traydigita-core'
            ),
            'status' => $status,
        ];
    }

    /**
     * Find the download URL of the latest stack release asset.
     *
     * Asks the GitHub API for the latest release of
     * ArrayIterator/traydigita-stack and takes the first asset
     * whose name matches the release-asset pattern. The
     * download URL must parse to host `github.com` with a path
     * under the project's releases/download directory; any other
     * URL is refused. No download is attempted here.
     *
     * @return array{url: string|null, message: string} URL on success (message empty), or null URL with a human-readable error message.
     */
    private static function latestStackDownloadUrl(): array
    {
        $response = wp_remote_get(self::GITHUB_API_URL, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'TrayDigita-Theme-Setup',
            ],
        ]);
        if (is_wp_error($response)) {
            return [
                'url' => null,
                'message' => self::errorMessage($response, esc_html__(
                    'Could not reach GitHub to look up the latest TrayDigita Stack release.',
                    'traydigita-core'
                )),
            ];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [
                'url' => null,
                'message' => sprintf(
                    /* translators: %d is the HTTP status code returned by the GitHub API. */
                    esc_html__('GitHub did not return the latest TrayDigita Stack release (HTTP %d).', 'traydigita-core'),
                    (int) $code
                ),
            ];
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return [
                'url' => null,
                'message' => esc_html__('GitHub returned a release response that could not be read.', 'traydigita-core'),
            ];
        }
        $assets = $data['assets'] ?? null;
        if (!is_array($assets)) {
            return [
                'url' => null,
                'message' => esc_html__('The latest TrayDigita Stack release has no downloadable files.', 'traydigita-core'),
            ];
        }
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = $asset['name'] ?? '';
            if (!is_string($name) || preg_match(self::GITHUB_ASSET_PATTERN, $name) !== 1) {
                continue;
            }
            $url = $asset['browser_download_url'] ?? '';
            if (!is_string($url) || $url === '') {
                continue;
            }
            if (!self::isTrustedReleaseUrl($url)) {
                return [
                    'url' => null,
                    'message' => esc_html__(
                        'The release download link did not come from the expected GitHub release location, so the install was refused.',
                        'traydigita-core'
                    ),
                ];
            }
            return ['url' => $url, 'message' => ''];
        }
        return [
            'url' => null,
            'message' => esc_html__('The latest TrayDigita Stack release has no .zip file to install.', 'traydigita-core'),
        ];
    }

    /**
     * Whether a release download URL comes from the trusted location.
     *
     * Only `https://github.com/ArrayIterator/traydigita-stack/
     * releases/download/...` passes. Any other host, scheme or
     * path, a relative URL, or an unparseable URL fails closed.
     * Pure check — no network call.
     *
     * @param string $url Download URL from the GitHub API response.
     * @return bool
     */
    private static function isTrustedReleaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $path = $parts['path'] ?? '';
        return ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === self::TRUSTED_RELEASE_HOST
            && is_string($path)
            && str_starts_with($path, self::TRUSTED_RELEASE_PATH_PREFIX);
    }

    /**
     * First error message from a WP_Error, or the fallback.
     *
     * Only the first message is surfaced so a long upgrader error
     * list does not flood the UI. The fallback is used when the
     * error carries no message at all.
     *
     * @param WP_Error $error Error to read.
     * @param string $fallback Message when the error has none.
     * @return string
     */
    private static function errorMessage(WP_Error $error, string $fallback): string
    {
        $messages = $error->get_error_messages();
        foreach ($messages as $message) {
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }
        return $fallback;
    }

    /**
     * Load the admin APIs the install path needs, on demand.
     *
     * Same file list as InstallerAjax::ensureUpgraderApi():
     * WP_Filesystem() lives in wp-admin/includes/file.php (also
     * home of wp_handle_upload(), used by the upload endpoint),
     * plugins_api() in plugin-install.php, Plugin_Upgrader and
     * WP_Ajax_Upgrader_Skin in class-wp-upgrader.php, and
     * activate_plugin() in plugin.php. All are admin-only files,
     * so they are required here instead of assuming the caller
     * loaded them. Outside WordPress (no ABSPATH) there is
     * nothing to load.
     *
     * @return void
     */
    private static function ensureUpgraderApi(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }
        $files = [
            'wp-admin/includes/file.php',
            'wp-admin/includes/plugin-install.php',
            'wp-admin/includes/class-wp-upgrader.php',
            'wp-admin/includes/plugin.php',
        ];
        foreach ($files as $file) {
            $path = ABSPATH . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }
}
