<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Pwa;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use function __;
use function add_action;
use function add_filter;
use function add_shortcode;
use function admin_url;
use function current_user_can;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_textarea;
use function esc_url;
use function esc_url_raw;
use function function_exists;
use function get_site_icon_url;
use function home_url;
use function is_plugin_active;
use function is_string;
use function json_decode;
use function sanitize_text_field;
use function status_header;
use function strtok;
use function trim;
use function update_option;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_json_encode;
use function wp_localize_script;
use function wp_register_script;
use function wp_unslash;

/**
 * PWA & Push Notifications module.
 *
 * ONE module for both features (merged 2026-11-22; the old
 * standalone Push card is gone — see ModuleRegistry, which no
 * longer lists a 'push' module at all). The module id stays
 * 'pwa', so the stored enabled-modules option needs no
 * migration: sites that had 'pwa' enabled keep this module
 * enabled, and the 'push' id simply stops resolving (the
 * registry drops unknown ids on read).
 *
 * PWA half: the phase-1 behaviour behind the enabled flag —
 * manifest link + theme-color meta (gated in functions.php),
 * install sheet + "Install App" buttons (gated in the header /
 * footer / mobile sidebar templates), `initPwa()` in app.ts.
 *
 * Push half: manual Firebase Cloud Messaging notifications,
 * adapted from the standalone koperasi-kita-push plugin (which
 * stays untouched as the standalone alternative). The web
 * config + VAPID key live in `traydigita_push`, the Service
 * Account JSON in its own option, subscriber tokens in the
 * module's own table (see PushSender). Sending is manual only
 * — nothing here auto-sends on publish.
 *
 * ONE service worker serves both halves: the module serves
 * /firebase-messaging-sw.js itself at the site root (same
 * redirect_canonical technique as the plugin, so the URL is
 * never canonical-redirected to a trailing-slash version),
 * with root scope. The worker runs the Firebase messaging
 * background handler AND a network-first fetch passthrough, so
 * it is also the PWA worker: installing the app and receiving
 * push in the background share one registration and cannot
 * conflict. The PWA install sheet never registers a worker
 * itself (resources/js/modules/pwa.ts), so there is exactly
 * one registration path: the subscribe button registers this
 * worker, and the PWA install flow only captures
 * `beforeinstallprompt`.
 *
 * Plugin deferral: when the koperasi-kita-push plugin is
 * active, it owns the root service worker, the token REST
 * endpoint and the subscribe button, so the module defers all
 * push runtime to it (boot() registers no SW / REST /
 * shortcode hooks, and the test + send endpoints refuse).
 * Saving push config stays allowed — it only stores options —
 * so a config can be ready for the day the plugin is
 * deactivated. The PWA half is unaffected by the plugin.
 *
 * Settings surface: ONE page (slug 'traydigita-pwa') with two
 * tabs — Settings (PWA status + push config, with two-way
 * JS + PHP validation, lock-on-valid with Unlock / Delete,
 * and a Test-config button that fetches an FCM access token
 * without sending anything) and Message (manual send form,
 * locked until a valid config is saved).
 */
final class PwaModule implements ModuleInterface
{
    /**
     * Plugin file as used by is_plugin_active().
     */
    public const string PLUGIN_FILE = 'koperasi-kita-push/koperasi-kita-push.php';

    /**
     * Admin page slug of the push plugin.
     */
    public const string PLUGIN_PAGE = 'kk-push';

    /**
     * Shortcode printing the push subscribe button.
     */
    public const string SUBSCRIBE_SHORTCODE = 'traydigita_push_subscribe';

    /**
     * Whether boot() already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'pwa';
    }

    /**
     * Default category slug for the module list.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'performance';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('PWA & Push Notifications', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Let visitors install the site as an app (web manifest, theme colour, store-style install sheet) and send manual push notifications to subscribers with Firebase Cloud Messaging. One service worker serves both. Disable to remove every trace of it.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * Kept as 'smartphone': the grid maps Dashicons slugs to
     * Material ligatures (see admin-ui.ts), and 'smartphone'
     * maps cleanly; the name carries the push half.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'smartphone';
    }

    /**
     * Module version.
     *
     * 2.0.0: the push half was folded in (was PWA-only 1.0.0).
     *
     * @return string
     */
    public static function version(): string
    {
        return '2.0.0';
    }

    /**
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-pwa';
    }

    /**
     * Settings tabs: Settings (PWA + push config) and Message
     * (manual send). The Message tab is disabled until a valid
     * push config is saved; the shell renders the disabled
     * state from the 'disabled' key (see AdminMenu::moduleTabs()
     * and the tab button in templates/admin/shell.php).
     *
     * @return array<int, array{id: string, label: string, disabled?: bool}>
     */
    public static function tabs(): array
    {
        return [
            ['id' => 'settings', 'label' => __('Settings', 'traydigita-core')],
            [
                'id' => 'message',
                'label' => __('Message', 'traydigita-core'),
                'disabled' => !PushSender::isConfigured(),
            ],
        ];
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * The PWA needs no setup: there are no keys, no
     * credentials, and no settings to fill in. Push config is
     * optional inside the module (the Message tab stays locked
     * until it is saved), so an unconfigured push half is not
     * "needs setup" for the module as a whole.
     *
     * @return bool Always true.
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether the PWA module is currently enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return ModuleRegistry::isEnabled(self::id());
    }

    /**
     * Whether the standalone push plugin is installed and active.
     *
     * is_plugin_active() lives in wp-admin/includes/plugin.php,
     * which is loaded in the admin but not always on the
     * frontend, so it is included on demand when the function
     * does not exist yet.
     *
     * @return bool
     */
    public static function isPluginActive(): bool
    {
        if (!defined('ABSPATH')) {
            return false;
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active(self::PLUGIN_FILE);
    }

    /**
     * Whether a valid push config is saved (delegates to PushSender).
     *
     * @return bool
     */
    public static function isPushConfigured(): bool
    {
        return PushSender::isConfigured();
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Two panels matching tabs(): Settings (PWA status, push
     * config form with lock / unlock / delete / test) and
     * Message (manual send form + send log, disabled until a
     * valid config is saved). The forms are NOT submitted by
     * the generic admin dispatcher: the config form is posted
     * by resources/js/modules/admin-push.ts (which validates
     * client-side first), and the send form posts to the
     * module-owned send endpoint. The Service Account JSON is
     * never printed back to the page — only a "saved" summary
     * (project id + client email) is shown.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $enabled = self::isEnabled();
        $settings = PushSender::getSettings();
        $configured = PushSender::isConfigured();
        $locked = $settings['locked'] && $configured;
        $pluginActive = self::isPluginActive();
        $summary = PushSender::serviceAccountSummary();
        $subscribers = PushSender::subscriberCount();
        $log = PushSender::getLog();
        $nonce = wp_create_nonce(PushAjax::NONCE_ACTION);
        $homeUrl = home_url('/');
        ?>
        <div class="traydigita-module-settings pwa-settings" data-push-admin
            data-push-configured="<?php echo $configured ? '1' : '0'; ?>"
            data-push-plugin-active="<?php echo $pluginActive ? '1' : '0'; ?>"
            data-msg-config-required="<?php echo esc_attr(__('Firebase web config is required.', 'traydigita-core')); ?>"
            data-msg-invalid-json="<?php echo esc_attr(__('Firebase web config is not valid JSON.', 'traydigita-core')); ?>"
            data-msg-missing-key="<?php echo esc_attr(__('Firebase web config is missing the required key: %s.', 'traydigita-core')); ?>"
            data-msg-vapid-required="<?php echo esc_attr(__('VAPID key is required.', 'traydigita-core')); ?>"
            data-msg-invalid-vapid="<?php echo esc_attr(__('VAPID key does not look like a Firebase Web Push key. Copy it from Firebase Console > Project Settings > Cloud Messaging > Web Push certificates.', 'traydigita-core')); ?>"
            data-msg-sa-required="<?php echo esc_attr(__('Service Account JSON is required.', 'traydigita-core')); ?>"
            data-msg-invalid-sa="<?php echo esc_attr(__('Service Account JSON is not valid JSON.', 'traydigita-core')); ?>"
            data-msg-invalid-sa-type="<?php echo esc_attr(__('Service Account JSON must have "type": "service_account".', 'traydigita-core')); ?>"
            data-msg-invalid-sa-email="<?php echo esc_attr(__('Service Account JSON is missing a valid client_email.', 'traydigita-core')); ?>"
            data-msg-invalid-sa-key="<?php echo esc_attr(__('Service Account JSON is missing a valid private_key.', 'traydigita-core')); ?>"
            data-msg-confirm-delete="<?php echo esc_attr(__('Delete the saved push config? Subscriber tokens are kept.', 'traydigita-core')); ?>"
            data-msg-saving="<?php echo esc_attr(__('Saving…', 'traydigita-core')); ?>"
            data-msg-testing="<?php echo esc_attr(__('Testing config…', 'traydigita-core')); ?>"
            data-msg-sending="<?php echo esc_attr(__('Sending…', 'traydigita-core')); ?>">
            <div data-traydigita-panel="settings" role="tabpanel" aria-labelledby="traydigita-tab-settings">
                <?php if (!$enabled) : ?>
                    <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                        <p>
                            <?php esc_html_e('PWA & Push is disabled. The site is not installable and no push service worker or subscribe button is served. Enable the module in the module grid first.', 'traydigita-core'); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if ($pluginActive) : ?>
                    <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                        <p>
                            <?php esc_html_e('The koperasi-kita-push plugin is active and owns push notifications right now: it serves the service worker and the token endpoint. The theme module defers to it — test and send are disabled here until the plugin is deactivated. The PWA half below is unaffected.', 'traydigita-core'); ?>
                        </p>
                        <p>
                            <a class="traydigita-admin-button traydigita-admin-button-secondary"
                                href="<?php echo esc_url(admin_url('admin.php?page=' . self::PLUGIN_PAGE)); ?>">
                                <?php esc_html_e('Open Push Notifications (plugin)', 'traydigita-core'); ?>
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <?php
                // Settings Redesign: only the one-line summary
                // shows; the four things the PWA does — and what
                // disabling removes — sit behind Help.
                SettingsControls::about(
                    __('What the PWA does', 'traydigita-core'),
                    __('Visitors can install the site as an app, and push notifications share one service worker.', 'traydigita-core'),
                    '<ul class="pwa-status-list">'
                        . '<li>' . esc_html__('Links the web manifest (manifest.webmanifest) from every page head, so browsers know the app name, theme colour, icons, start URL, and scope.', 'traydigita-core') . '</li>'
                        . '<li>' . esc_html__('Prints a theme-color meta tag in the page head, using the brand primary colour from the Customizer.', 'traydigita-core') . '</li>'
                        . '<li>' . esc_html__('Shows a store-style install sheet (Play Store variant, App Store variant on macOS and iOS) with an Install button and install instructions.', 'traydigita-core') . '</li>'
                        . '<li>' . esc_html__('Serves one root-scope service worker (/firebase-messaging-sw.js) that receives Firebase push messages in the background and also acts as the PWA worker, so installing the app and receiving push never conflict.', 'traydigita-core') . '</li>'
                        . '</ul>'
                        . '<p>' . esc_html__('Disabling this module in the module grid removes the manifest link, the theme-color meta tag, the install sheet, the "Install App" buttons, the service worker, and the subscribe button entirely.', 'traydigita-core') . '</p>'
                );
                ?>

                <div class="push-settings">
                    <h3><?php esc_html_e('Push notifications (Firebase Cloud Messaging)', 'traydigita-core'); ?></h3>
                    <p class="push-settings-intro">
                        <?php
                        SettingsControls::helpText(
                            __('Notifications are sent manually from the Message tab only — never automatically when a post is published.', 'traydigita-core'),
                            __('Paste the Firebase config once, save it, and the settings lock. Subscribers register from the [traydigita_push_subscribe] button on the site.', 'traydigita-core')
                        );
                        ?>
                        <?php
                        printf(
                            /* translators: %d is the number of registered subscriber devices. */
                            esc_html__('Registered devices: %d.', 'traydigita-core'),
                            $subscribers
                        );
                        ?>
                    </p>

                    <form class="push-config-form" data-push-config-form method="post" action="">
                        <input type="hidden" name="action" value="traydigita_admin_save">
                        <input type="hidden" name="module" value="pwa">
                        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($nonce); ?>">

                        <p class="push-field">
                            <label for="push-firebase-config"><?php esc_html_e('Firebase Web Config (JSON)', 'traydigita-core'); ?></label>
                            <span class="td-settings-code-wrap"><textarea id="push-firebase-config" name="firebase_config" rows="6" cols="80" class="traydigita-ui-textarea traydigita-ui-textarea-code" data-push-field="firebase_config" spellcheck="false" <?php echo $locked ? 'readonly' : ''; ?> placeholder='{"apiKey":"...","authDomain":"...","projectId":"...","messagingSenderId":"...","appId":"..."}'><?php echo esc_textarea($settings['firebase_config']); ?></textarea><?php echo SettingsControls::copyButton('push-firebase-config'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by SettingsControls from an escaped id. ?></span>
                            <span class="push-field-help">
                                <?php esc_html_e('Firebase Console > Project Settings > Your apps (Web) > firebaseConfig. Paste the whole JSON object.', 'traydigita-core'); ?>
                            </span>
                            <span class="push-field-error" data-field-error="firebase_config" hidden></span>
                        </p>

                        <p class="push-field">
                            <label for="push-vapid-key"><?php esc_html_e('VAPID Key (Web Push)', 'traydigita-core'); ?></label>
                            <input id="push-vapid-key" name="vapid_key" type="text" class="traydigita-ui-input traydigita-ui-input-mono" data-push-field="vapid_key" spellcheck="false" autocomplete="off" value="<?php echo esc_attr($settings['vapid_key']); ?>" <?php echo $locked ? 'readonly' : ''; ?>>
                            <span class="push-field-help">
                                <?php esc_html_e('Firebase Console > Project Settings > Cloud Messaging > Web Push certificates.', 'traydigita-core'); ?>
                            </span>
                            <span class="push-field-error" data-field-error="vapid_key" hidden></span>
                        </p>

                        <p class="push-field">
                            <label for="push-service-account"><?php esc_html_e('Service Account JSON', 'traydigita-core'); ?></label>
                            <?php if ($summary !== null) : ?>
                                <span class="push-saved-state">
                                    <?php
                                    printf(
                                        /* translators: %1$s is the Firebase project id, %2$s is the service account email. */
                                        esc_html__('Saved — project: %1$s, email: %2$s', 'traydigita-core'),
                                        esc_html($summary['project_id']),
                                        esc_html($summary['client_email'])
                                    );
                                    ?>
                                </span>
                                <span class="push-field-help">
                                    <?php
                                    SettingsControls::helpText(
                                        __('Leave empty to keep the saved key.', 'traydigita-core'),
                                        __('Paste a new JSON only when rotating the key. The private key is never shown here.', 'traydigita-core')
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                            <span class="td-settings-code-wrap"><textarea id="push-service-account" name="service_account" rows="6" cols="80" class="traydigita-ui-textarea traydigita-ui-textarea-code" data-push-field="service_account" spellcheck="false" <?php echo $locked ? 'disabled' : ''; ?> placeholder='{"type":"service_account","project_id":"...","client_email":"..."}'></textarea><?php echo SettingsControls::copyButton('push-service-account'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by SettingsControls from an escaped id. ?></span>
                            <span class="push-field-help">
                                <?php
                                SettingsControls::helpText(
                                    __('Firebase Console > Project Settings > Service accounts > Generate new private key. Required for sending (FCM HTTP v1).', 'traydigita-core'),
                                    __('Never share this with anyone.', 'traydigita-core')
                                );
                                ?>
                            </span>
                            <span class="push-field-error" data-field-error="service_account" hidden></span>
                        </p>

                        <p class="push-form-status" data-push-status role="status"></p>

                        <p class="push-form-actions">
                            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary" data-push-save <?php echo $locked ? 'hidden' : ''; ?>>
                                <?php esc_html_e('Save config', 'traydigita-core'); ?>
                            </button>
                            <button type="button" class="traydigita-admin-button traydigita-admin-button-secondary" data-push-test <?php echo $configured && !$pluginActive ? '' : 'disabled'; ?>>
                                <?php esc_html_e('Test config', 'traydigita-core'); ?>
                            </button>
                            <button type="button" class="traydigita-admin-button traydigita-admin-button-secondary" data-push-unlock <?php echo $locked ? '' : 'hidden'; ?>>
                                <?php esc_html_e('Unlock to edit', 'traydigita-core'); ?>
                            </button>
                            <button type="button" class="traydigita-admin-button traydigita-admin-button-danger" data-push-delete <?php echo $configured ? '' : 'hidden'; ?>>
                                <?php esc_html_e('Delete config', 'traydigita-core'); ?>
                            </button>
                        </p>
                        <p class="push-field-help">
                            <?php
                            SettingsControls::helpText(
                                __('Test config checks the saved credentials without sending anything.', 'traydigita-core'),
                                __('Test config fetches an FCM access token from Google with the saved Service Account — proof the config works. It sends no notification.', 'traydigita-core')
                            );
                            ?>
                        </p>
                    </form>
                </div>
            </div>

            <div data-traydigita-panel="message" role="tabpanel" aria-labelledby="traydigita-tab-message" hidden>
                <?php if (!$configured) : ?>
                    <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                        <p>
                            <?php esc_html_e('Push is not configured yet. Save a valid Firebase config on the Settings tab first — this form unlocks once the config is saved.', 'traydigita-core'); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <form class="push-send-form" data-push-send-form method="post" action="" <?php echo $configured && !$pluginActive ? '' : 'aria-disabled="true"'; ?>>
                    <input type="hidden" name="action" value="<?php echo esc_attr(PushAjax::ACTION_SEND); ?>">
                    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <fieldset <?php echo $configured && !$pluginActive ? '' : 'disabled'; ?>>
                        <p class="push-field">
                            <label for="push-title"><?php esc_html_e('Title', 'traydigita-core'); ?></label>
                            <input id="push-title" name="title" type="text" class="traydigita-ui-input" maxlength="100">
                            <span class="push-field-error" data-field-error="title" hidden></span>
                        </p>
                        <p class="push-field">
                            <label for="push-body"><?php esc_html_e('Message', 'traydigita-core'); ?></label>
                            <textarea id="push-body" name="body" rows="3" cols="80" class="traydigita-ui-textarea" maxlength="250"></textarea>
                            <span class="push-field-error" data-field-error="body" hidden></span>
                        </p>
                        <p class="push-field">
                            <label for="push-link"><?php esc_html_e('Destination link', 'traydigita-core'); ?></label>
                            <input id="push-link" name="link" type="url" class="traydigita-ui-input" value="<?php echo esc_attr($homeUrl); ?>">
                        </p>
                        <p class="push-field">
                            <label for="push-image"><?php esc_html_e('Image (optional)', 'traydigita-core'); ?></label>
                            <input id="push-image" name="image" type="url" class="traydigita-ui-input">
                        </p>
                        <p class="push-field">
                            <span class="push-field-label"><?php esc_html_e('Target', 'traydigita-core'); ?></span>
                            <span class="push-radio-group">
                                <label><input class="traydigita-ui-radio" type="radio" name="target" value="all" checked> <?php esc_html_e('All subscribers', 'traydigita-core'); ?></label>
                                <label><input class="traydigita-ui-radio" type="radio" name="target" value="test"> <?php esc_html_e('One device (test)', 'traydigita-core'); ?></label>
                            </span>
                            <input name="test_token" type="text" class="traydigita-ui-input traydigita-ui-input-mono" placeholder="<?php esc_attr_e('FCM token of the test device', 'traydigita-core'); ?>">
                            <span class="push-field-error" data-field-error="test_token" hidden></span>
                        </p>
                        <p class="push-form-status" data-push-send-status role="status"></p>
                        <p class="push-form-actions">
                            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                                <?php esc_html_e('Send notification', 'traydigita-core'); ?>
                            </button>
                        </p>
                    </fieldset>
                </form>

                <div class="push-send-log">
                    <h3><?php esc_html_e('Recent sends', 'traydigita-core'); ?></h3>
                    <?php if ($log === []) : ?>
                        <p><?php esc_html_e('No notifications sent yet.', 'traydigita-core'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr><th><?php esc_html_e('Time', 'traydigita-core'); ?></th><th><?php esc_html_e('Title', 'traydigita-core'); ?></th><th><?php esc_html_e('Sent', 'traydigita-core'); ?></th><th><?php esc_html_e('Failed', 'traydigita-core'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($log, 0, 20) as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['time'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['title'] ?? '')); ?></td>
                                    <td><?php echo (int) ($row['sent'] ?? 0); ?></td>
                                    <td><?php echo (int) ($row['failed'] ?? 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <p class="description"><?php SettingsControls::helpText(__('Put the subscribe button on the site with the shortcode [traydigita_push_subscribe].', 'traydigita-core'), __('Tokens that stop working (for example the app was removed) are removed from the list automatically.', 'traydigita-core')); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Validate and save the push config from a submitted form.
     *
     * Expected flat keys: `firebase_config` (JSON with apiKey,
     * authDomain, projectId, messagingSenderId, appId),
     * `vapid_key`, and `service_account` (JSON with
     * type=service_account, client_email, private_key). The
     * Service Account field is only overwritten when a new JSON
     * is pasted; an empty field keeps the stored key. Unknown
     * keys are dropped. On a valid save the config locks
     * (locked=true); on any error nothing is stored and the
     * per-field errors are returned. A locked config refuses
     * a new save unless `push_unlock` is '1' (sent only after
     * the Unlock button is clicked). The private key is never
     * part of the returned data.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change PWA settings.', 'traydigita-core'),
            ];
        }
        $stored = PushSender::getSettings();
        // Locked config guard: once a valid config is saved the
        // form locks, and the lock must be enforced here, not
        // only by the readonly fields in the browser — a direct
        // POST could otherwise overwrite the Service Account
        // without ever unlocking. An explicit `push_unlock` flag
        // (sent only after the Unlock button is clicked) is the
        // one way through; deleting the config stays available
        // through the separate delete endpoint.
        if (PushSender::isConfigured()
            && (!isset($data['push_unlock']) || $data['push_unlock'] !== '1')
        ) {
            return [
                'success' => false,
                'message' => __('Push config is locked. Unlock it first to change the config.', 'traydigita-core'),
            ];
        }
        $configRaw = isset($data['firebase_config']) && is_string($data['firebase_config']) && trim($data['firebase_config']) !== ''
            ? trim($data['firebase_config'])
            : $stored['firebase_config'];
        $vapid = isset($data['vapid_key']) && is_string($data['vapid_key']) && trim($data['vapid_key']) !== ''
            ? trim($data['vapid_key'])
            : $stored['vapid_key'];
        $saRaw = isset($data['service_account']) && is_string($data['service_account'])
            ? trim($data['service_account'])
            : '';
        $errors = [];
        $config = PushSender::validateFirebaseConfig($configRaw);
        if ($config['config'] === null) {
            $errors['firebase_config'] = $config['error'];
        }
        $vapidError = PushSender::validateVapidKey($vapid);
        if ($vapidError !== '') {
            $errors['vapid_key'] = $vapidError;
        }
        $saReplaced = false;
        if ($saRaw !== '') {
            $sa = PushSender::validateServiceAccount($saRaw);
            if ($sa['account'] === null) {
                $errors['service_account'] = $sa['error'];
            } else {
                $saReplaced = true;
            }
        } elseif (PushSender::getServiceAccount() === null) {
            $errors['service_account'] = __('Service Account JSON is required.', 'traydigita-core');
        }
        if ($errors !== []) {
            return [
                'success' => false,
                'message' => __('Push config could not be saved. Check the fields below.', 'traydigita-core'),
                'errors' => $errors,
            ];
        }
        /** @var array<string, string> $configArray */
        $configArray = $config['config'];
        update_option(PushSender::OPTION, [
            'firebase_config' => (string) wp_json_encode($configArray),
            'vapid_key' => $vapid,
            'project_id' => $configArray['projectId'],
            'locked' => true,
        ], false);
        if ($saReplaced) {
            update_option(PushSender::SERVICE_ACCOUNT_OPTION, $saRaw, false);
            delete_transient(PushSender::TOKEN_TRANSIENT);
        }
        return [
            'success' => true,
            'message' => __('Push config saved and locked. Test it with the Test config button, then send from the Message tab.', 'traydigita-core'),
            'data' => [
                'configured' => true,
                'project_id' => $configArray['projectId'],
                'service_account_saved' => $saReplaced,
                'subscribers' => PushSender::subscriberCount(),
            ],
        ];
    }

    /**
     * Register the module's hooks. Called only when the module is enabled.
     *
     * PushAjax endpoints + the tokens table are always registered
     * when enabled. The push runtime (root service worker, token
     * REST routes, subscribe shortcode) is skipped while the
     * standalone push plugin is active — the plugin owns those
     * URLs then (see the class docblock).
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        self::$booted = true;
        PushSender::ensureTokensTable();
        PushAjax::register();
        if (self::isPluginActive()) {
            return;
        }
        add_action('rest_api_init', [self::class, 'registerRestRoutes']);
        add_action('init', [self::class, 'serveServiceWorker'], 1);
        add_filter('redirect_canonical', [self::class, 'noCanonicalForServiceWorker'], 10, 2);
        add_shortcode(self::SUBSCRIBE_SHORTCODE, [self::class, 'subscribeShortcode']);
    }

    /**
     * Register the public device-registration REST routes.
     *
     * Public endpoints: a device token alone cannot send
     * anything, and the token value itself is never shown in
     * the admin UI. Tokens are stored in full (FCM needs the
     * raw token to send) with a sha256 hash as the lookup key;
     * anyone who can read the database can therefore send to
     * those devices, so the table must be treated like any
     * other credential store.
     *
     * @return void
     */
    public static function registerRestRoutes(): void
    {
        register_rest_route(PushSender::REST_NAMESPACE, '/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'restSaveToken'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => ['required' => true, 'type' => 'string'],
            ],
        ]);
        register_rest_route(PushSender::REST_NAMESPACE, '/token', [
            'methods' => 'DELETE',
            'callback' => [self::class, 'restDeleteToken'],
            'permission_callback' => '__return_true',
            'args' => [
                'token' => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    /**
     * REST callback: store one device token.
     *
     * @param \WP_REST_Request $request Request with a `token` param.
     * @return array{ok: bool}|\WP_Error
     */
    public static function restSaveToken(\WP_REST_Request $request): array|\WP_Error
    {
        $token = sanitize_text_field((string) $request->get_param('token'));
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if (!PushSender::saveToken($token, $userAgent)) {
            return new \WP_Error('push_bad_token', __('Token is not valid.', 'traydigita-core'), ['status' => 400]);
        }
        return ['ok' => true];
    }

    /**
     * REST callback: remove one device token.
     *
     * @param \WP_REST_Request $request Request with a `token` param.
     * @return array{ok: bool}
     */
    public static function restDeleteToken(\WP_REST_Request $request): array
    {
        PushSender::deleteToken(sanitize_text_field((string) $request->get_param('token')));
        return ['ok' => true];
    }

    /**
     * Serve /firebase-messaging-sw.js at the site root.
     *
     * Runs on `init` at priority 1, before WordPress decides
     * the request is a 404: a service worker must be served
     * with HTTP 200 and no redirect at all. When no valid
     * config is saved yet the worker answers 503 with a
     * comment, so the browser reports a clear failure instead
     * of registering a worker that can never initialise
     * Firebase. The worker is the single PWA + FCM worker: it
     * runs the Firebase background-message handler and a
     * network-first fetch passthrough (no caching, so it can
     * never serve stale content).
     *
     * @return void
     */
    public static function serveServiceWorker(): void
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = strtok($uri, '?');
        if ($path !== '/firebase-messaging-sw.js') {
            return;
        }
        $settings = PushSender::getSettings();
        $config = json_decode($settings['firebase_config'], true);
        if (!is_array($config) || empty($config['apiKey']) || !PushSender::isConfigured()) {
            status_header(503);
            header('Content-Type: application/javascript; charset=utf-8');
            echo "/* TrayDigita Push: Firebase config has not been saved yet. */\n";
            exit;
        }
        $version = PushSender::FIREBASE_JS_VERSION;
        $icon = get_site_icon_url(192, home_url('/'));
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        echo "importScripts('https://www.gstatic.com/firebasejs/{$version}/firebase-app-compat.js');\n";
        echo "importScripts('https://www.gstatic.com/firebasejs/{$version}/firebase-messaging-compat.js');\n";
        echo 'firebase.initializeApp(' . (string) wp_json_encode($config) . ");\n";
        echo "const traydigitaMessaging = firebase.messaging();\n";
        echo "traydigitaMessaging.onBackgroundMessage(function (payload) {\n";
        echo "  const n = payload.notification || {};\n";
        echo "  const data = payload.data || {};\n";
        echo "  self.registration.showNotification(n.title || document.title || 'TrayDigita', {\n";
        echo "    body: n.body || '',\n";
        echo "    icon: " . (string) wp_json_encode($icon) . ",\n";
        echo "    image: n.image || undefined,\n";
        echo "    data: { url: (payload.fcmOptions && payload.fcmOptions.link) || data.url || '/' }\n";
        echo "  });\n";
        echo "});\n";
        echo "self.addEventListener('notificationclick', function (event) {\n";
        echo "  event.notification.close();\n";
        echo "  const url = (event.notification.data && event.notification.data.url) || '/';\n";
        echo "  event.waitUntil(clients.openWindow(url));\n";
        echo "});\n";
        // PWA half of the same worker: a network-first fetch
        // passthrough. No caching is done here — the worker
        // must never serve stale content — but the fetch
        // handler is what makes this a real PWA worker for
        // installability, alongside the push handler above.
        echo "self.addEventListener('fetch', function (event) {\n";
        echo "  event.respondWith(fetch(event.request));\n";
        echo "});\n";
        exit;
    }

    /**
     * Never let WordPress canonical-redirect the service worker URL.
     *
     * @param string|false $redirectUrl Redirect target, or false for none.
     * @param string $requestedUrl Requested URL.
     * @return string|false
     */
    public static function noCanonicalForServiceWorker(string|false $redirectUrl, string $requestedUrl): string|false
    {
        if (str_contains((string) $requestedUrl, 'firebase-messaging-sw.js')) {
            return false;
        }
        return $redirectUrl;
    }

    /**
     * Shortcode [traydigita_push_subscribe]: the opt-in button.
     *
     * The Firebase SDK scripts are enqueued here (not on every
     * page): they only load on pages that actually show the
     * button. Returns '' when push is not configured.
     *
     * @return string Button markup, or '' when not configured.
     */
    public static function subscribeShortcode(): string
    {
        $settings = PushSender::getSettings();
        $config = json_decode($settings['firebase_config'], true);
        if (!is_array($config) || empty($config['apiKey']) || empty($settings['vapid_key'])) {
            return '';
        }
        $version = PushSender::FIREBASE_JS_VERSION;
        wp_register_script(
            'firebase-app-compat',
            'https://www.gstatic.com/firebasejs/' . $version . '/firebase-app-compat.js',
            [],
            null,
            true
        );
        wp_register_script(
            'firebase-messaging-compat',
            'https://www.gstatic.com/firebasejs/' . $version . '/firebase-messaging-compat.js',
            ['firebase-app-compat'],
            null,
            true
        );
        wp_enqueue_script('firebase-app-compat');
        wp_enqueue_script('firebase-messaging-compat');
        // The subscribe behaviour itself lives in the `app` bundle
        // (resources/js/modules/push-subscribe.ts, init on DOM
        // ready), so the config is localized onto the SDK handle:
        // it prints with the SDK scripts in the footer, before
        // the DOM-ready init reads it.
        wp_localize_script('firebase-messaging-compat', 'TRAYDIGITA_PUSH', [
            'firebaseConfig' => $config,
            'vapidKey' => $settings['vapid_key'],
            'restUrl' => esc_url_raw(rest_url(PushSender::REST_NAMESPACE . '/token')),
            'swUrl' => home_url('/firebase-messaging-sw.js'),
        ]);
        return '<button type="button" class="push-subscribe" data-push-subscribe'
            . ' data-msg-unsupported="' . esc_attr__('This browser does not support notifications.', 'traydigita-core') . '"'
            . ' data-msg-already-active="' . esc_attr__('Notifications are already on.', 'traydigita-core') . '"'
            . ' data-msg-requesting="' . esc_attr__('Asking for notification permission…', 'traydigita-core') . '"'
            . ' data-msg-denied="' . esc_attr__('Notification permission was denied. Enable it from the lock icon in the address bar if you change your mind.', 'traydigita-core') . '"'
            . ' data-msg-active="' . esc_attr__('Notifications are on. You will receive the latest updates.', 'traydigita-core') . '"'
            . ' data-msg-active-label="' . esc_attr__('Notifications On', 'traydigita-core') . '"'
            . ' data-msg-failed="' . esc_attr__('Could not enable notifications. Make sure the site uses HTTPS and the service worker loads without a redirect.', 'traydigita-core') . '"'
            . ' data-msg-no-firebase="' . esc_attr__('Firebase has not loaded yet.', 'traydigita-core') . '">'
            . esc_html__('Enable notifications', 'traydigita-core')
            . '</button><p class="push-subscribe-status" data-push-status role="status"></p>';
    }
}
