<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\CookieConsent;

use WP_Post;
use function __;
use function absint;
use function add_action;
use function array_key_exists;
use function array_merge;
use function current_user_can;
use function esc_html__;
use function esc_url_raw;
use function file_exists;
use function get_option;
use function get_permalink;
use function get_post;
use function get_theme_mod;
use function in_array;
use function is_admin;
use function is_array;
use function is_customize_preview;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sanitize_hex_color;
use function sanitize_key;
use function sanitize_text_field;
use function trim;
use function update_option;
use function wp_doing_ajax;
use function wp_kses_post;
use function wp_unslash;

/**
 * Cookie Consent module.
 *
 * Shows a dismissible cookie consent banner on the frontend and
 * remembers the visitor's choice in a cookie.
 *
 * Storage: one option, `traydigita_cookie_consent` (autoload `no`),
 * holding every banner setting. The visitor's choice is stored in
 * the `traydigita_consent` cookie with the value `accepted` or
 * `declined`, `SameSite=Lax`, path `/`, and a max-age derived from
 * the `duration_days` setting. No consent state is ever stored
 * server-side and no tracking cookie is set by this module.
 *
 * The "Customize" button only expands an explanation panel (with a
 * link to the privacy policy page when one is configured); the
 * visitor's choice is still Accept or Decline, so the cookie only
 * ever holds those two values.
 *
 * Appearance (banner message, button labels, colours, position) can
 * also be controlled from the Customizer (section
 * `traydigita_cookie_consent`). The Customizer writes the SAME
 * option and the SAME keys as this page — one source of truth, so
 * a value changed in the Customizer shows up here and vice versa.
 * Consent behaviour — duration, privacy policy page and URL —
 * lives only here in the module and has no Customizer equivalent.
 *
 * Legacy theme_mods written by the Customizer before the settings
 * were unified (`cookie_consent_message` and friends) are imported
 * once: a legacy value is used only for a key the option does not
 * hold yet, and the next save persists it into the option, after
 * which the theme_mod is never read again.
 *
 * isConfigured() returns true once the settings option exists and
 * holds a non-empty message and accept-button text. Both fields have
 * defaults, so saving the settings once — even without changing
 * anything — counts as configured. Before the first save the module
 * reports "needs setup" on its card.
 *
 * The banner is rendered only when the module is enabled (boot()
 * is called only for enabled modules) and only when the consent
 * cookie is absent, so returning visitors never see it again until
 * the cookie expires or is cleared.
 */
final class CookieConsentModule implements \TrayDigita\WP\Plugin\Core\Admin\ModuleInterface
{
    /**
     * Option name holding all cookie consent settings.
     */
    public const string OPTION = 'traydigita_cookie_consent';

    /**
     * Name of the frontend consent cookie.
     */
    public const string COOKIE_NAME = 'traydigita_consent';

    /**
     * Minimum/maximum/default consent duration in days.
     */
    public const int MIN_DURATION_DAYS = 30;

    public const int MAX_DURATION_DAYS = 365;

    public const int DEFAULT_DURATION_DAYS = 180;

    /**
     * Maximum banner message length in characters.
     */
    public const int MAX_MESSAGE_LENGTH = 2000;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'cookie-consent';
    }

    /**
     * Default category slug for the module list.
     *
     * Optional: ModuleRegistry::categoryOf() falls back to the
     * 'administration' category when a module does not declare
     * one, so a card is never uncategorised.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'security';
    }

    /**
     * Module name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Cookie Consent', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Shows a dismissible cookie consent banner and remembers the visitor’s choice.', 'traydigita-core');
    }

    /**
     * Dashicons slug for the module card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'shield';
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
     * Admin menu slug of the settings page.
     *
     * @return string
     */
    /**
     * Author shown on the module card in the admin module list.
     *
     * Modules may override this to name their own author; the
     * registry falls back to "TrayDigita" when a module does not
     * declare one, so every card always shows an author.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    public static function settingsPageSlug(): string
    {
        return 'traydigita-cookie-consent';
    }

    /**
     * The module has no external dependency, so it is always available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * True once the settings option exists with a message and an
     * accept-button text. Both have defaults, so the first save
     * counts as configured (see the class docblock).
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $stored = get_option(self::OPTION, false);
        if (!is_array($stored)) {
            return false;
        }
        $message = isset($stored['message']) && is_string($stored['message'])
            ? trim($stored['message'])
            : '';
        $accept = isset($stored['accept_text']) && is_string($stored['accept_text'])
            ? trim($stored['accept_text'])
            : '';
        return $message !== '' && $accept !== '';
    }

    /**
     * Whether this is a core module.
     *
     * Always false for the modules shipped today: every module is
     * optional and can be disabled from the grid. Phase 3 marks
     * modules as core by flipping this to true; the registry, the
     * grid toggle and the toggle endpoint all read this flag.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'text', 'label' => __('Banner text', 'traydigita-core'), 'icon' => 'admin-comments'],
            ['id' => 'buttons', 'label' => __('Buttons', 'traydigita-core'), 'icon' => 'button'],
            ['id' => 'appearance', 'label' => __('Appearance', 'traydigita-core'), 'icon' => 'admin-appearance'],
            ['id' => 'behaviour', 'label' => __('Behaviour', 'traydigita-core'), 'icon' => 'admin-settings'],
        ];
    }

    /**
     * Echo the settings UI inside the admin shell content area.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $settings = self::moduleSettings();
        $defaults = self::defaults();
        $previewHtml = ConsentScript::renderPreview($settings);
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/cookie-consent.php';
        if (!file_exists($partial)) {
            echo '<p>' . esc_html__('The Cookie Consent settings template is missing.', 'traydigita-core') . '</p>';
            return;
        }
        require $partial;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `message`, `accept_text`,
     * `decline_text`, `customize_text`, `policy_page_id`,
     * `policy_url`, `position` (`bottom`|`top`), `duration_days`,
     * `background_color`, `text_color`, `accept_color` (hex colours,
     * empty means the built-in default). Unknown keys are dropped. Missing keys keep the stored value,
     * so a partial submission never wipes a setting.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to do that.', 'traydigita-core'),
            ];
        }
        $incoming = self::unslashArray($data);
        $stored = self::moduleSettings();
        $clean = self::sanitizeSettings(array_merge($stored, $incoming));
        update_option(self::OPTION, $clean, false);
        return [
            'success' => true,
            'message' => __('Cookie consent settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend banner hook. Called only when enabled.
     *
     * The banner never renders in wp-admin, during AJAX requests,
     * or in the Customizer preview.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (is_admin() || wp_doing_ajax() || is_customize_preview()) {
            return;
        }
        add_action('wp_footer', [self::class, 'renderBanner'], 20);
    }

    /**
     * Render the banner markup in wp_footer.
     *
     * Skipped entirely when the visitor already has a consent
     * cookie, so returning visitors get no banner markup at all.
     *
     * @return void
     */
    public static function renderBanner(): void
    {
        if (self::hasConsentCookie()) {
            return;
        }
        echo ConsentScript::render(self::getSettings());
    }

    /**
     * Default settings (also the fallback for every missing key).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'message' => __(
                'We use cookies to keep this site working and to remember your preferences. You can accept or decline optional cookies below.',
                'traydigita-core'
            ),
            'accept_text' => __('Accept', 'traydigita-core'),
            'decline_text' => __('Decline', 'traydigita-core'),
            'customize_text' => __('Customize', 'traydigita-core'),
            'policy_page_id' => 0,
            'policy_url' => '',
            'position' => 'bottom',
            'duration_days' => self::DEFAULT_DURATION_DAYS,
            'background_color' => '',
            'text_color' => '',
            'accept_color' => '',
        ];
    }

    /**
     * Current settings: stored option merged over the defaults,
     * sanitized the same way a save would sanitize them.
     *
     * The option is the single source of truth: the Customizer
     * appearance controls write these same keys into this same
     * option, so there is nothing to overlay at render time.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        return self::moduleSettings();
    }

    /**
     * Module settings from the option only. Used by the admin form,
     * by saveSettings() and by getSettings(), so all three always
     * agree on the same values.
     *
     * Before sanitizing, any legacy Customizer theme_mod value
     * (written before the Customizer and this page were unified
     * onto this option) is imported for a key the option does not
     * hold yet — see importLegacyThemeMods(). The next save then
     * persists the imported value into the option.
     *
     * @return array<string, mixed>
     */
    private static function moduleSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::defaults(), self::sanitizeSettings(self::importLegacyThemeMods($stored)));
    }

    /**
     * Import legacy Customizer theme_mods into a stored option array.
     *
     * Before unification the Customizer wrote its own theme_mods
     * (`cookie_consent_message` and friends) that only overrode
     * the module settings at render time. A legacy value is copied
     * into the returned array only when the option does not already
     * hold that key, so a value the owner set on this page always
     * wins. An empty legacy value and the old `module` position
     * choice (meaning "use the module setting") are ignored.
     *
     * @param array<string, mixed> $stored Raw stored option values.
     * @return array<string, mixed>
     */
    private static function importLegacyThemeMods(array $stored): array
    {
        $legacyKeys = [
            'message' => 'cookie_consent_message',
            'accept_text' => 'cookie_consent_accept_text',
            'decline_text' => 'cookie_consent_decline_text',
            'customize_text' => 'cookie_consent_customize_text',
            'background_color' => 'cookie_consent_background_color',
            'text_color' => 'cookie_consent_text_color',
            'accept_color' => 'cookie_consent_accept_color',
            'position' => 'cookie_consent_position',
        ];
        foreach ($legacyKeys as $settingKey => $themeMod) {
            if (array_key_exists($settingKey, $stored)) {
                continue;
            }
            $value = get_theme_mod($themeMod, '');
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            if ($settingKey === 'position' && !in_array($value, ['bottom', 'top'], true)) {
                continue;
            }
            $stored[$settingKey] = $value;
        }
        return $stored;
    }

    /**
     * Privacy policy URL for the banner link.
     *
     * The selected policy page (when set and published) wins over
     * the manually entered URL. Returns an empty string when
     * neither is configured, in which case the banner shows no link.
     *
     * @param array<string, mixed> $settings Sanitized settings.
     * @return string
     */
    public static function policyUrl(array $settings): string
    {
        $pageId = isset($settings['policy_page_id']) ? absint($settings['policy_page_id']) : 0;
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return isset($settings['policy_url']) && is_string($settings['policy_url'])
            ? $settings['policy_url']
            : '';
    }

    /**
     * Sanitize a settings array, dropping unknown keys.
     *
     * Empty button texts fall back to their defaults so the banner
     * can never render with an unlabeled button. An unknown or
     * missing position falls back to `bottom`; the duration is
     * clamped to 30–365 days.
     *
     * @param array<string, mixed> $data Raw settings (already unslashed).
     * @return array<string, mixed>
     */
    private static function sanitizeSettings(array $data): array
    {
        $defaults = self::defaults();
        $clean = [];

        $message = isset($data['message']) && is_string($data['message'])
            ? trim($data['message'])
            : '';
        if ($message !== '') {
            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
            }
            $message = wp_kses_post($message);
        }
        $clean['message'] = $message !== '' ? $message : $defaults['message'];

        foreach (['accept_text', 'decline_text', 'customize_text'] as $key) {
            $value = isset($data[$key]) && is_string($data[$key])
                ? sanitize_text_field($data[$key])
                : '';
            $clean[$key] = $value !== '' ? $value : $defaults[$key];
        }

        $pageId = isset($data['policy_page_id']) ? absint($data['policy_page_id']) : 0;
        $post = $pageId > 0 ? get_post($pageId) : null;
        $clean['policy_page_id'] = ($post instanceof WP_Post
            && $post->post_type === 'page'
            && $post->post_status === 'publish')
            ? $pageId
            : 0;

        $clean['policy_url'] = isset($data['policy_url']) && is_string($data['policy_url'])
            ? esc_url_raw(trim($data['policy_url']))
            : '';

        $position = isset($data['position']) && is_string($data['position'])
            ? sanitize_key($data['position'])
            : '';
        $clean['position'] = in_array($position, ['bottom', 'top'], true)
            ? $position
            : $defaults['position'];

        $duration = isset($data['duration_days']) ? absint($data['duration_days']) : self::DEFAULT_DURATION_DAYS;
        if ($duration < self::MIN_DURATION_DAYS) {
            $duration = self::MIN_DURATION_DAYS;
        }
        if ($duration > self::MAX_DURATION_DAYS) {
            $duration = self::MAX_DURATION_DAYS;
        }
        $clean['duration_days'] = $duration;

        foreach (['background_color', 'text_color', 'accept_color'] as $key) {
            $color = isset($data[$key]) && is_string($data[$key]) && $data[$key] !== ''
                ? sanitize_hex_color($data[$key])
                : null;
            $clean[$key] = $color ?? '';
        }

        return $clean;
    }

    /**
     * Recursively unslash submitted data (string keys only).
     *
     * @param array<string, mixed> $data Raw submitted data.
     * @return array<string, mixed>
     */
    private static function unslashArray(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($value)) {
                $clean[$key] = wp_unslash($value);
            } elseif (is_array($value)) {
                $clean[$key] = self::unslashArray($value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    /**
     * Whether the visitor already has a valid consent cookie.
     *
     * @return bool
     */
    private static function hasConsentCookie(): bool
    {
        if (!isset($_COOKIE[self::COOKIE_NAME]) || !is_string($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }
        $value = sanitize_key(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        return in_array($value, ['accepted', 'declined'], true);
    }
}
