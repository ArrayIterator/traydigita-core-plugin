<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Installer;

use Plugin_Upgrader;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use function activate_plugin;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function defined;
use function delete_transient;
use function esc_html__;
use function file_exists;
use function get_filesystem_method;
use function get_transient;
use function is_array;
use function is_plugin_active;
use function is_string;
use function is_wp_error;
use function parse_url;
use function plugins_api;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function time;
use function WP_Filesystem;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the Plugin Suggestions module.
 *
 * Endpoints (all authenticated admin only, nonce action
 * `traydigita-admin` sent as `_ajax_nonce`, exactly like the
 * generic admin dispatcher):
 * - traydigita_installer_install: install one curated plugin from
 *   wordpress.org (capability `install_plugins`).
 * - traydigita_installer_activate: activate one curated plugin
 *   (capability `activate_plugins`).
 * - traydigita_installer_status: current install status of every
 *   curated plugin (capability `install_plugins`).
 *
 * Trust rules, enforced before any network or filesystem work:
 * - The slug must be in the curated PluginList, and the entry must
 *   be marked installable. Unknown slugs and the traydigita-core
 *   slug (no wordpress.org package) are refused with a JSON error;
 *   nothing is ever downloaded from a caller-supplied URL.
 * - The download link returned by plugins_api() must point at the
 *   host `downloads.wordpress.org`; any other host is refused.
 * - Filesystem: only the `direct` method is used. When the site's
 *   filesystem method is anything else (for example FTP), the
 *   install is refused with a clear error instead of asking for
 *   FTP credentials inside this UI - core's own credentials form
 *   is never triggered from here, and no credentials are ever
 *   collected or stored by this module.
 * - One install at a time: a transient lock rejects a second
 *   concurrent install with a "busy" error instead of letting two
 *   upgrader runs race on the filesystem.
 * - Activation never happens automatically: install only installs.
 *   The user activates with a separate click on the Activate
 *   button, which calls the activate endpoint.
 *
 * Installs run through core's Plugin_Upgrader with the
 * WP_Ajax_Upgrader_Skin, the same upgrader the core plugin
 * installer AJAX flow uses, so output stays captured and errors
 * come back as structured data.
 */
final class InstallerAjax
{
    /**
     * Nonce action for all Installer endpoints (shared with the
     * generic admin dispatcher, AdminAjax::NONCE_ACTION).
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Transient holding the install lock while an install runs.
     */
    public const string LOCK_TRANSIENT = 'traydigita_installer_lock';

    /**
     * How long the install lock lives, in seconds.
     *
     * A plugin download + unzip normally takes seconds; the lock
     * is deleted as soon as the install finishes, so this is only
     * a safety net for a request that dies mid-install.
     */
    public const int LOCK_TTL = 300;

    /**
     * Only host a plugin package may be downloaded from.
     */
    public const string TRUSTED_DOWNLOAD_HOST = 'downloads.wordpress.org';

    /**
     * Register every Installer AJAX action.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_traydigita_installer_install', [self::class, 'install']);
        add_action('wp_ajax_traydigita_installer_activate', [self::class, 'activate']);
        add_action('wp_ajax_traydigita_installer_status', [self::class, 'status']);
    }

    /**
     * Install one curated plugin from wordpress.org.
     *
     * POST: plugin (slug), _ajax_nonce. Requires the
     * `install_plugins` capability. Answers with the plugin's new
     * status (`installed` or `active` when it was already active)
     * so the UI can swap the Install button for Activate.
     *
     * @return void
     */
    public static function install(): void
    {
        check_ajax_referer(self::NONCE_ACTION);
        if (!current_user_can('install_plugins')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to install plugins.', 'traydigita-core')],
                403
            );
        }
        $slug = sanitize_key(self::postString('plugin'));
        $plugin = self::resolveInstallable($slug);
        if ($plugin === null) {
            wp_send_json_error(
                ['message' => self::notInstallableMessage($slug)],
                400
            );
        }
        if (get_transient(self::LOCK_TRANSIENT) !== false) {
            wp_send_json_error(
                ['message' => esc_html__(
                    'Another plugin install is already running. Please wait for it to finish and try again.',
                    'traydigita-core'
                )],
                409
            );
        }
        set_transient(self::LOCK_TRANSIENT, time(), self::LOCK_TTL);
        try {
            $result = self::doInstall($plugin);
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        if (class_exists(ActivityLogModule::class)) {
            ActivityLogModule::record(
                'installer',
                'plugin-installed',
                sprintf("Plugin '%s' installed.", $plugin['name'])
            );
        }
        wp_send_json_success([
            'message' => $result['message'],
            'slug' => $plugin['slug'],
            'status' => $result['status'],
        ]);
    }

    /**
     * Activate one curated plugin.
     *
     * POST: plugin (slug), _ajax_nonce. Requires the
     * `activate_plugins` capability. Installing and activating are
     * separate steps on purpose: nothing is ever auto-activated.
     * Activating an already-active plugin is a no-op success.
     * Activation is for the current site only (not network-wide
     * on multisite).
     *
     * @return void
     */
    public static function activate(): void
    {
        check_ajax_referer(self::NONCE_ACTION);
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to activate plugins.', 'traydigita-core')],
                403
            );
        }
        $slug = sanitize_key(self::postString('plugin'));
        $plugin = PluginList::get($slug);
        if ($plugin === null) {
            wp_send_json_error(
                ['message' => esc_html__('Unknown plugin.', 'traydigita-core')],
                400
            );
        }
        $status = PluginList::status($slug);
        if ($status === PluginList::STATUS_NOT_INSTALLED) {
            wp_send_json_error(
                ['message' => esc_html__('Install the plugin before activating it.', 'traydigita-core')],
                400
            );
        }
        if ($status === PluginList::STATUS_ACTIVE) {
            wp_send_json_success([
                'message' => esc_html__('The plugin is already active.', 'traydigita-core'),
                'slug' => $slug,
                'status' => PluginList::STATUS_ACTIVE,
            ]);
        }
        self::ensurePluginApi();
        $activated = activate_plugin($plugin['file']);
        if (is_wp_error($activated)) {
            wp_send_json_error(
                ['message' => self::errorMessage($activated, esc_html__(
                    'The plugin could not be activated.',
                    'traydigita-core'
                ))],
                400
            );
        }
        if (!is_plugin_active($plugin['file'])) {
            wp_send_json_error(
                ['message' => esc_html__('The plugin could not be activated.', 'traydigita-core')],
                400
            );
        }
        if (class_exists(ActivityLogModule::class)) {
            ActivityLogModule::record(
                'installer',
                'plugin-activated',
                sprintf("Plugin '%s' activated.", $plugin['name'])
            );
        }
        wp_send_json_success([
            'message' => sprintf(
                /* translators: %s is the plugin name, e.g. "Google Site Kit". */
                esc_html__('%s is now active.', 'traydigita-core'),
                $plugin['name']
            ),
            'slug' => $slug,
            'status' => PluginList::STATUS_ACTIVE,
        ]);
    }

    /**
     * Current install status of every curated plugin.
     *
     * Requires the `install_plugins` capability. Lets the UI
     * refresh its badges without a page reload (for example after
     * a plugin is installed or activated from the Plugins screen
     * in another tab).
     *
     * @return void
     */
    public static function status(): void
    {
        check_ajax_referer(self::NONCE_ACTION);
        if (!current_user_can('install_plugins')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to install plugins.', 'traydigita-core')],
                403
            );
        }
        wp_send_json_success(['statuses' => PluginList::statuses()]);
    }

    /**
     * Resolve a slug to a curated entry that may be installed.
     *
     * Returns the entry only when the slug is curated AND marked
     * installable (a wordpress.org package exists). Returns null
     * for unknown slugs and for traydigita-core, so callers can
     * refuse before any network or filesystem work happens. Pure
     * allowlist check - no WordPress state is read or changed.
     *
     * @param string $slug Plugin slug, already sanitized with sanitize_key().
     * @return array{slug: string, file: string, name: string, reason: string, category: string, recommended: bool, required: bool, installable: bool, duplicates: string|null}|null
     */
    public static function resolveInstallable(string $slug): ?array
    {
        if (!PluginList::isInstallable($slug)) {
            return null;
        }
        return PluginList::get($slug);
    }

    /**
     * Whether a package URL comes from the trusted download host.
     *
     * Only `https://downloads.wordpress.org/...` passes. Any other
     * host, a relative URL, or an unparseable URL fails closed.
     * Pure check - no network call.
     *
     * @param string $url Package URL from plugins_api().
     * @return bool
     */
    public static function isTrustedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        return ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === self::TRUSTED_DOWNLOAD_HOST;
    }

    /**
     * Error message for a slug that cannot be installed from here.
     *
     * Unknown slugs get a generic refusal. The core slug gets the
     * specific explanation: it has no wordpress.org package, so
     * this installer cannot fetch it - it must come from the
     * project's own release, and the theme cannot boot without it.
     *
     * @param string $slug Plugin slug, already sanitized with sanitize_key().
     * @return string
     */
    private static function notInstallableMessage(string $slug): string
    {
        if ($slug === PluginList::CORE_SLUG) {
            return esc_html__(
                'TrayDigita Core is not available from the wordpress.org plugin directory, so it cannot be installed from here. Install it from the project’s own release instead — the theme cannot boot without it.',
                'traydigita-core'
            );
        }
        return esc_html__('That plugin cannot be installed from here.', 'traydigita-core');
    }

    /**
     * Run the install for one already-validated curated plugin.
     *
     * Loads the admin upgrader APIs on demand, checks that the
     * filesystem method is `direct` (anything else is refused
     * with a clear error instead of prompting for FTP
     * credentials), fetches the plugin information from
     * wordpress.org, validates the download host, and installs
     * with core's Plugin_Upgrader. The caller owns the lock and
     * the JSON response; this method only returns a result array
     * and never dies, so the lock is always released.
     *
     * @param array{slug: string, file: string, name: string, reason: string, category: string, recommended: bool, required: bool, installable: bool, duplicates: string|null} $plugin Curated plugin entry.
     * @return array{success: bool, message: string, status: string}
     */
    private static function doInstall(array $plugin): array
    {
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
        $info = plugins_api('plugin_information', ['slug' => $plugin['slug']]);
        if (is_wp_error($info)) {
            return [
                'success' => false,
                'message' => self::errorMessage($info, esc_html__(
                    'The plugin could not be found on wordpress.org.',
                    'traydigita-core'
                )),
                'status' => PluginList::STATUS_NOT_INSTALLED,
            ];
        }
        $downloadLink = isset($info->download_link) && is_string($info->download_link)
            ? $info->download_link
            : '';
        if ($downloadLink === '' || !self::isTrustedDownloadUrl($downloadLink)) {
            return [
                'success' => false,
                'message' => esc_html__(
                    'The download link for this plugin did not come from wordpress.org, so the install was refused.',
                    'traydigita-core'
                ),
                'status' => PluginList::STATUS_NOT_INSTALLED,
            ];
        }
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $installed = $upgrader->install($downloadLink);
        if (is_wp_error($installed)) {
            return [
                'success' => false,
                'message' => self::errorMessage($installed, esc_html__(
                    'The plugin could not be installed.',
                    'traydigita-core'
                )),
                'status' => PluginList::status($plugin['slug']),
            ];
        }
        if ($installed === false || $installed === null) {
            // Plugin_Upgrader::install() returns null - not false
            // or a WP_Error - when the package download fails:
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
                'status' => PluginList::status($plugin['slug']),
            ];
        }
        $status = PluginList::status($plugin['slug']);
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
            'message' => sprintf(
                /* translators: %s is the plugin name, e.g. "Google Site Kit". */
                esc_html__('%s was installed. Activate it when you are ready — nothing is activated automatically.', 'traydigita-core'),
                $plugin['name']
            ),
            'status' => $status,
        ];
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
     * WP_Filesystem() lives in wp-admin/includes/file.php,
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

    /**
     * Load the admin plugin API on demand (for activate_plugin()).
     *
     * @return void
     */
    private static function ensurePluginApi(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }
        if (function_exists('activate_plugin') && function_exists('is_plugin_active')) {
            return;
        }
        $file = ABSPATH . 'wp-admin/includes/plugin.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
}
