<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Recaptcha;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Modules\ContactForm\ContactFormModule;
use function __;
use function array_key_exists;
use function array_merge;
use function current_user_can;
use function esc_attr;
use function esc_html__;
use function esc_html_e;
use function esc_js;
use function get_option;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function rawurlencode;
use function sanitize_text_field;
use function trim;
use function update_option;
use function wp_add_inline_script;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_unslash;

/**
 * Google reCAPTCHA module (v2 checkbox and v3 score).
 *
 * DECISION — standalone module, not part of the Contact Form:
 * reCAPTCHA is bot protection with its own key pair, its own
 * Google console, and its own verification endpoint. The
 * Contact Form only consumes it (widget + verification), the
 * same way it consumes the Turnstile module. Keeping the keys,
 * the verifier, and the widget in their own independently
 * toggleable module means a site can switch bot protection on
 * or off without touching its contact forms, and another module
 * can reuse reCAPTCHA later without copying the verifier.
 *
 * What it does:
 * - Holds the site key and secret key in the single option
 *   `traydigita_recaptcha` (autoload `no`) and the v3 score
 *   threshold (0.0–1.0, default 0.5).
 * - Verifies tokens against Google's siteverify endpoint
 *   (see Verifier) and renders the v2 checkbox widget or the
 *   v3 invisible execute script (see enqueueScript() /
 *   widgetHtml()), for the Contact Form module to call.
 *
 * There is deliberately NO "Test keys" button (unlike
 * Turnstile): Google's siteverify cannot validate a secret
 * key with a dummy token — it answers `invalid-input-response`
 * for every dummy or empty token, whatever secret is sent
 * (verified live 2026-11-23: wrong, empty, and short-garbage
 * secrets all return only `invalid-input-response`; only the
 * documented test keys return success). A test button would
 * report any secret as valid, so there is none.
 *
 * Migration: the keys used to live in the Contact Form
 * settings (`traydigita_contact_form_settings`, keys
 * `recaptcha_site_key`, `recaptcha_secret_key`,
 * `recaptcha_v3_threshold`). On first read, getSettings()
 * copies those values into this module's option ONCE when
 * this module has no keys yet, and records
 * `migrated_from_contact_form` so the copy never runs again
 * (clearing the keys here afterwards stays cleared). The old
 * values are copied, never deleted, so nothing is silently
 * lost if the module is disabled again.
 *
 * Keys: the site key is public and is printed (escaped) into
 * the widget markup; the secret key is a secret. It is stored
 * in the single option `traydigita_recaptcha` (autoload `no`),
 * rendered as an always-empty password field, only overwritten
 * when a non-empty value is submitted, and never printed, never
 * logged, and never returned by any endpoint — the same rule
 * the SMTP and Turnstile modules apply to their secrets.
 * `saveSettings()` returns no `data` at all, so the generic
 * dispatcher has nothing secret to echo back.
 *
 * Settings live in the single option `traydigita_recaptcha`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; `saveSettings()` still checks the capability
 * itself and re-sanitizes every field for its own type.
 *
 * The settings form is rendered inline here (no template
 * partial), following the SeoModule / TurnstileModule pattern,
 * so the module stays self-contained in its own directory.
 */
final class RecaptchaModule implements ModuleInterface
{
    /**
     * Option name for all reCAPTCHA settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_recaptcha';

    /**
     * Shared nonce action for the admin save form.
     *
     * The nonce itself must be sent as `_ajax_nonce` (see
     * `check_ajax_referer()`), never as a field named `nonce`.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Google reCAPTCHA JavaScript API URL.
     *
     * The v3 render mode appends `?render=<site key>`; the v2
     * checkbox mode loads the plain URL. The host is a class
     * constant, never caller input.
     */
    public const string SCRIPT_URL = 'https://www.google.com/recaptcha/api.js';

    /**
     * Script handle for the enqueued reCAPTCHA API script.
     */
    public const string SCRIPT_HANDLE = 'traydigita-recaptcha';

    /**
     * Name of the token field reCAPTCHA posts back with the form.
     */
    public const string TOKEN_FIELD = 'g-recaptcha-response';

    /**
     * Default v3 score threshold (Google's recommended default).
     */
    public const float V3_DEFAULT_THRESHOLD = 0.5;

    /**
     * Whether the one-time migration from the Contact Form
     * settings has been attempted in this request.
     */
    private static bool $migrationChecked = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'recaptcha';
    }

    /**
     * Default category slug for the module list.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'security';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Google reCAPTCHA', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Google reCAPTCHA bot protection (v2 checkbox and v3 score) for the Contact Form. Disabled until the site key and secret key are saved.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'shield-alt';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Google reCAPTCHA bot protection (v2 checkbox and v3 score).', 'traydigita-core'),
            __('Protects the Contact Form module.', 'traydigita-core'),
            __('Disabled until the site key and secret key are entered.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
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
        return 'traydigita-recaptcha';
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
     * Configured means both keys are saved.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        return $settings['site_key'] !== '' && $settings['secret_key'] !== '';
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable, and disabled
     * by default because it needs keys before it can do anything.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * This module has no hooks of its own: it registers nothing
     * on the frontend and no AJAX endpoint (there is no test-keys
     * endpoint — see the class docblock). The Contact Form module
     * calls enqueueScript() / widgetHtml() and the verifier
     * directly. The method exists only to satisfy the interface.
     *
     * @return void
     */
    public static function boot(): void
    {
    }

    /**
     * Default settings.
     *
     * @return array{site_key: string, secret_key: string, v3_threshold: float, migrated_from_contact_form: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'site_key' => '',
            'secret_key' => '',
            'v3_threshold' => self::V3_DEFAULT_THRESHOLD,
            'migrated_from_contact_form' => false,
        ];
    }

    /**
     * Read the stored settings, merged over the defaults and normalized.
     *
     * The returned array includes the secret key; callers must never
     * print, log, or return it. `renderSettingsPage()` strips it
     * before the settings reach any markup.
     *
     * On the first read in a request, a one-time migration runs:
     * when this module has no keys yet, keys still stored in the
     * Contact Form settings are copied over (see
     * maybeMigrateFromContactForm()).
     *
     * @return array{site_key: string, secret_key: string, v3_threshold: float, migrated_from_contact_form: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $settings = [
            'site_key' => sanitize_text_field(self::asString($settings['site_key'] ?? '')),
            'secret_key' => self::asString($settings['secret_key'] ?? ''),
            'v3_threshold' => max(0.0, min(1.0, (float) ($settings['v3_threshold'] ?? self::V3_DEFAULT_THRESHOLD))),
            'migrated_from_contact_form' => (bool) ($settings['migrated_from_contact_form'] ?? false),
        ];
        if (!self::$migrationChecked) {
            self::$migrationChecked = true;
            $settings = self::maybeMigrateFromContactForm($settings);
        }
        return $settings;
    }

    /**
     * One-time migration of the reCAPTCHA keys from the Contact
     * Form settings.
     *
     * Before this module existed, the keys lived in the Contact
     * Form option (`traydigita_contact_form_settings`). When this
     * module has no keys yet and that option still holds any
     * reCAPTCHA value, the values are copied here and the
     * `migrated_from_contact_form` flag is stored, so the copy
     * never runs again — clearing the keys here afterwards stays
     * cleared. The old values are copied, never deleted.
     *
     * @param array{site_key: string, secret_key: string, v3_threshold: float, migrated_from_contact_form: bool} $settings Normalized settings.
     * @return array{site_key: string, secret_key: string, v3_threshold: float, migrated_from_contact_form: bool} Settings with the migrated values, if any.
     */
    private static function maybeMigrateFromContactForm(array $settings): array
    {
        if ($settings['migrated_from_contact_form']) {
            return $settings;
        }
        $settings['migrated_from_contact_form'] = true;
        if ($settings['site_key'] !== '' || $settings['secret_key'] !== '') {
            update_option(self::OPTION, $settings, false);
            return $settings;
        }
        $legacy = get_option(ContactFormModule::OPTION, []);
        if (!is_array($legacy)) {
            update_option(self::OPTION, $settings, false);
            return $settings;
        }
        $legacySiteKey = isset($legacy['recaptcha_site_key']) && is_string($legacy['recaptcha_site_key'])
            ? sanitize_text_field($legacy['recaptcha_site_key'])
            : '';
        $legacySecretKey = isset($legacy['recaptcha_secret_key']) && is_string($legacy['recaptcha_secret_key'])
            ? $legacy['recaptcha_secret_key']
            : '';
        $legacyThreshold = $legacy['recaptcha_v3_threshold'] ?? null;
        if ($legacySiteKey === '' && $legacySecretKey === '' && !is_numeric($legacyThreshold)) {
            update_option(self::OPTION, $settings, false);
            return $settings;
        }
        if ($legacySiteKey !== '') {
            $settings['site_key'] = $legacySiteKey;
        }
        if ($legacySecretKey !== '') {
            $settings['secret_key'] = $legacySecretKey;
        }
        if (is_numeric($legacyThreshold)) {
            $settings['v3_threshold'] = max(0.0, min(1.0, (float) $legacyThreshold));
        }
        update_option(self::OPTION, $settings, false);
        return $settings;
    }

    /**
     * Enqueue the reCAPTCHA API script for one mode.
     *
     * v2 loads the plain API script; v3 loads it in render mode
     * (`?render=<site key>`) and adds the inline submit handler
     * that executes reCAPTCHA with the given action and fills the
     * hidden token field before the form submits. Does nothing
     * while the module has no site key saved, or for an unknown
     * mode.
     *
     * @param string $mode `v2` or `v3`.
     * @param string $action v3 action name ('' skips the action check on verify).
     * @return void
     */
    public static function enqueueScript(string $mode, string $action = ''): void
    {
        $settings = self::getSettings();
        if ($settings['site_key'] === '') {
            return;
        }
        if ($mode === 'v2') {
            wp_enqueue_script(
                self::SCRIPT_HANDLE,
                self::SCRIPT_URL,
                [],
                null,
                true
            );
            return;
        }
        if ($mode !== 'v3') {
            return;
        }
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            self::SCRIPT_URL . '?render=' . rawurlencode($settings['site_key']),
            [],
            null,
            true
        );
        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'document.addEventListener("submit",function(e){var f=e.target;if(!f.classList.contains("traydigita-form"))return;e.preventDefault();grecaptcha.ready(function(){grecaptcha.execute("'
            . esc_js($settings['site_key'])
            . '",{action:"'
            . esc_js($action)
            . '"}).then(function(t){var i=f.querySelector("[name='
            . self::TOKEN_FIELD
            . ']");if(i)i.value=t;f.submit();});});});'
        );
    }

    /**
     * Widget markup for one mode.
     *
     * v2 returns the checkbox container; v3 returns the hidden
     * token field (the token is filled by the inline script added
     * by enqueueScript()). The site key is public by design (it
     * is embedded in every page that shows a challenge) and is
     * escaped as an attribute. An empty string is returned while
     * the module has no site key saved, or for an unknown mode,
     * so callers can use the result unconditionally.
     *
     * @param string $mode `v2` or `v3`.
     * @return string
     */
    public static function widgetHtml(string $mode): string
    {
        $settings = self::getSettings();
        if ($settings['site_key'] === '') {
            return '';
        }
        if ($mode === 'v2') {
            return '<div class="g-recaptcha" data-sitekey="' . esc_attr($settings['site_key']) . '"></div>';
        }
        if ($mode === 'v3') {
            return '<input type="hidden" name="' . esc_attr(self::TOKEN_FIELD) . '" value="">';
        }
        return '';
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Follows the SeoModule pattern: the form is rendered inline
     * here, no template partial, and the fields are framework
     * fields (SettingsControls) inside a framework section, so
     * the page has the same two-column layout, dividers and
     * sticky save bar as every other module settings page. The
     * secret key is stripped before the settings reach the
     * markup — the password field is always rendered empty, and
     * an empty submission keeps the stored key.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-error"><p>'
                . esc_html__('You are not allowed to do that.', 'traydigita-core')
                . '</p></div>';
            return;
        }
        $settings = self::getSettings();
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the inline markup below, in the same scope.
        $recaptchaConfigured = self::isConfigured();
        $recaptchaHasSecret = $settings['secret_key'] !== '';
        $recaptchaNonceAction = self::NONCE_ACTION;
        $recaptchaSaveAction = 'traydigita_admin_save';
        $recaptchaSiteKey = $settings['site_key'];
        $recaptchaThreshold = $settings['v3_threshold'];
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        ?>
        <div class="traydigita-module-settings recaptcha-settings">
            <div class="recaptcha-status" role="status">
                <?php if ($recaptchaConfigured) : ?>
                    <p class="recaptcha-status-badge recaptcha-status-configured"><?php esc_html_e('Configured', 'traydigita-core'); ?></p>
                    <p class="recaptcha-status-text"><?php esc_html_e('The keys are saved. Contact forms using reCAPTCHA protection will show the challenge.', 'traydigita-core'); ?></p>
                <?php else : ?>
                    <p class="recaptcha-status-badge recaptcha-status-unconfigured"><?php esc_html_e('Needs setup', 'traydigita-core'); ?></p>
                    <p class="recaptcha-status-text">
                        <?php
                        SettingsControls::helpText(
                            __('Create a reCAPTCHA site in the Google reCAPTCHA admin console and '
                                . 'paste its site key and secret key below.', 'traydigita-core'),
                            __('Until both keys are saved, contact forms fall back to no spam protection for their reCAPTCHA modes.', 'traydigita-core')
                        );
                        ?>
                        <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener"><?php esc_html_e('Open the Google reCAPTCHA admin console', 'traydigita-core'); ?></a>
                    </p>
                <?php endif; ?>
            </div>

            <form class="recaptcha-settings-form" data-traydigita-module-form data-traydigita-module="recaptcha" data-module="recaptcha" method="post" action="">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($recaptchaNonceAction)); ?>">
                <input type="hidden" name="module" value="recaptcha">

                <?php SettingsControls::sectionOpen('keys', __('Keys', 'traydigita-core')); ?>
                <?php
                SettingsControls::input([
                    'type' => 'text',
                    'id' => 'recaptcha-site-key',
                    'name' => 'site_key',
                    'label' => __('Site key', 'traydigita-core'),
                    'value' => $recaptchaSiteKey,
                    'help' => __('The public key from your Google reCAPTCHA site (v2 checkbox and v3 share one key pair).', 'traydigita-core'),
                    'help_long' => __('It is shown in the page markup by design — only the secret key must stay private.', 'traydigita-core'),
                    'mono' => true,
                    'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                ]);
                SettingsControls::input([
                    'type' => 'password',
                    'id' => 'recaptcha-secret-key',
                    'name' => 'secret_key',
                    'label' => __('Secret key', 'traydigita-core'),
                    'value' => '',
                    'help' => __('Leave empty to keep the saved secret key.', 'traydigita-core'),
                    'help_long' => __('The secret key is never shown, never logged, and never returned by any endpoint.', 'traydigita-core'),
                    'attrs' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => $recaptchaHasSecret ? __('•••••••• (saved)', 'traydigita-core') : __('Enter secret key', 'traydigita-core'),
                    ],
                ]);
                SettingsControls::number([
                    'id' => 'recaptcha-v3-threshold',
                    'name' => 'v3_threshold',
                    'label' => __('reCAPTCHA v3 score threshold', 'traydigita-core'),
                    'value' => $recaptchaThreshold,
                    'help' => __('Submissions scoring below this (0.0–1.0) are rejected.', 'traydigita-core'),
                    'help_long' => __('0.5 is Google’s recommended default. Only applies to the v3 (score) mode; the v2 checkbox has no score.', 'traydigita-core'),
                    'attrs' => ['min' => '0', 'max' => '1', 'step' => '0.1'],
                ]);
                ?>

                <p class="recaptcha-field-help">
                    <?php
                    SettingsControls::helpText(
                        __('Content-Security-Policy note: reCAPTCHA loads its script and challenge iframe '
                            . 'from https://www.google.com/recaptcha/.', 'traydigita-core'),
                        __('A site that enforces a Content-Security-Policy must allow that host in script-src and frame-src, or the widget will not load.', 'traydigita-core')
                    );
                    ?>
                </p>
                <?php SettingsControls::sectionClose(); ?>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Only keys present in `$data` are changed; missing keys keep
     * their stored values. The secret key is only overwritten when
     * a non-empty value is submitted; an empty or missing secret
     * field keeps the stored key. The v3 threshold is clamped to
     * 0.0–1.0; a non-numeric threshold keeps the stored value.
     * Values in `$data` are raw POST values (possibly slashed);
     * they are unslashed here. No secret is ever included in the
     * returned array.
     *
     * @param array<string, mixed> $data Submitted settings.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change reCAPTCHA settings.', 'traydigita-core'),
            ];
        }
        $current = self::getSettings();
        $new = $current;

        if (array_key_exists('site_key', $data)) {
            $new['site_key'] = trim(sanitize_text_field(wp_unslash(self::inputString($data, 'site_key'))));
        }

        if (array_key_exists('secret_key', $data)) {
            // Stored as-is (no sanitize_text_field: a secret key is
            // opaque). Only a non-empty submission overwrites the
            // stored value.
            $secretKey = trim(wp_unslash(self::inputString($data, 'secret_key')));
            if ($secretKey !== '') {
                $new['secret_key'] = $secretKey;
            }
        }

        if (array_key_exists('v3_threshold', $data)) {
            $threshold = $data['v3_threshold'];
            if (is_numeric($threshold)) {
                $new['v3_threshold'] = max(0.0, min(1.0, (float) $threshold));
            }
        }

        update_option(self::OPTION, $new, false);
        return [
            'success' => true,
            'message' => __('reCAPTCHA settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Read a submitted value as a string (empty string for anything else).
     *
     * @param array<string, mixed> $data Submitted data.
     * @param string $key Field name.
     * @return string
     */
    private static function inputString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Cast a stored option value to a string (empty string for
     * anything that is not a string, int, float, or bool).
     *
     * Guards against a hand-edited option holding an array where a
     * string is expected, which a plain `(string)` cast would turn
     * into a warning and the word "Array".
     *
     * @param mixed $value Stored value.
     * @return string
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }
}
