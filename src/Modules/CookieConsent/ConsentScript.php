<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\CookieConsent;

use function __;
use function array_merge;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_url;
use function in_array;
use function is_string;
use function ob_get_clean;
use function ob_start;
use function sanitize_hex_color;
use function wp_kses_post;

/**
 * Frontend banner markup and behaviour for the Cookie Consent module.
 *
 * The banner is a small, self-contained fragment rendered in
 * `wp_footer` by CookieConsentModule::renderBanner(): the banner
 * markup (styled by resources/css/components/cookie-consent.css,
 * which reads the colours below as CSS variables) and one inline
 * <script>. Nothing is enqueued and no network request is made.
 *
 * Accessibility: the banner is a `role="dialog"` with an accessible
 * name, real <button> controls, an `aria-expanded` customize toggle
 * that reveals an explanation panel, and a `prefers-reduced-motion`
 * media query that disables the entrance transition. The banner is
 * `position: fixed` and never covers content permanently — it is
 * dismissible, and once a choice is made it is hidden and never
 * rendered again until the consent cookie expires or is cleared.
 *
 * Behaviour (inline script): if the `traydigita_consent` cookie is
 * absent, the banner stays visible; Accept sets the cookie to
 * `accepted`, Decline sets it to `declined`, both with
 * `SameSite=Lax`, path `/`, `Max-Age` from the duration setting,
 * and the `Secure` flag on HTTPS. A `traydigita:cookie-consent`
 * CustomEvent with `{ choice: 'accepted' | 'declined' }` is
 * dispatched on `document` so other scripts can react (for
 * example to load optional analytics only after acceptance).
 *
 * renderPreview() renders the same banner markup for the live
 * preview on the module settings page, without the inline script:
 * the preview can never set a consent cookie. The preview buttons
 * are inert except for the Customize toggle, which the settings
 * page JavaScript wires to the explanation panel only.
 */
final class ConsentScript
{
    /**
     * Render the complete banner fragment (style + markup + script).
     *
     * @param array<string, mixed> $settings Sanitized module settings.
     * @return string
     */
    public static function render(array $settings): string
    {
        $settings = array_merge(CookieConsentModule::defaults(), $settings);
        $markup = self::renderBannerMarkup($settings, false);

        ob_start();
        echo $markup;
        echo "\n";
        ?>
        <script>
        (function () {
            'use strict';
            var banner = document.querySelector('[data-traydigita-cookie-consent]');
            if (!banner) {
                return;
            }
            var cookieName = banner.getAttribute('data-cookie-name') || 'traydigita_consent';
            var maxAge = parseInt(banner.getAttribute('data-cookie-max-age') || '15552000', 10);
            if (!maxAge || maxAge < 0) {
                maxAge = 15552000;
            }
            function hasConsent() {
                var cookies = document.cookie ? document.cookie.split(';') : [];
                for (var i = 0; i < cookies.length; i++) {
                    if (cookies[i].trim().indexOf(cookieName + '=') === 0) {
                        return true;
                    }
                }
                return false;
            }
            function setConsent(value) {
                var cookie = cookieName + '=' + encodeURIComponent(value)
                    + '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax';
                if (window.location.protocol === 'https:') {
                    cookie += '; Secure';
                }
                document.cookie = cookie;
                banner.setAttribute('hidden', '');
                document.dispatchEvent(new CustomEvent('traydigita:cookie-consent', {
                    detail: { choice: value }
                }));
            }
            if (hasConsent()) {
                banner.setAttribute('hidden', '');
                return;
            }
            var acceptButton = banner.querySelector('[data-traydigita-cookie-accept]');
            var declineButton = banner.querySelector('[data-traydigita-cookie-decline]');
            var customizeButton = banner.querySelector('[data-traydigita-cookie-customize]');
            var details = banner.querySelector('[data-traydigita-cookie-details]');
            if (acceptButton) {
                acceptButton.addEventListener('click', function () { setConsent('accepted'); });
            }
            if (declineButton) {
                declineButton.addEventListener('click', function () { setConsent('declined'); });
            }
            if (customizeButton && details) {
                customizeButton.addEventListener('click', function () {
                    var expanded = customizeButton.getAttribute('aria-expanded') === 'true';
                    customizeButton.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    if (expanded) {
                        details.setAttribute('hidden', '');
                    } else {
                        details.removeAttribute('hidden');
                    }
                });
            }
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render the banner markup for the settings-page live preview.
     *
     * The same markup as the frontend banner, but without the
     * inline script, so the preview can never set a consent
     * cookie. The settings page JavaScript updates this markup
     * live from the form fields and wires only the Customize
     * toggle (which opens the explanation panel, nothing more).
     *
     * @param array<string, mixed> $settings Sanitized module settings.
     * @return string
     */
    public static function renderPreview(array $settings): string
    {
        $settings = array_merge(CookieConsentModule::defaults(), $settings);
        return self::renderBannerMarkup($settings, true);
    }

    /**
     * Render the banner <div> shared by the frontend and the preview.
     *
     * The returned markup is trimmed so render() can append the
     * inline script on the next line, exactly as before.
     *
     * @param array<string, mixed> $settings Sanitized module settings.
     * @param bool $preview True for the settings-page preview.
     * @return string
     */
    private static function renderBannerMarkup(array $settings, bool $preview): string
    {
        $policyUrl = CookieConsentModule::policyUrl($settings);
        $position = isset($settings['position']) && is_string($settings['position'])
            && in_array($settings['position'], ['bottom', 'top'], true)
            ? $settings['position']
            : 'bottom';
        $message = isset($settings['message']) && is_string($settings['message'])
            ? $settings['message']
            : '';
        $acceptText = isset($settings['accept_text']) && is_string($settings['accept_text'])
            ? $settings['accept_text']
            : __('Accept', 'traydigita-core');
        $declineText = isset($settings['decline_text']) && is_string($settings['decline_text'])
            ? $settings['decline_text']
            : __('Decline', 'traydigita-core');
        $customizeText = isset($settings['customize_text']) && is_string($settings['customize_text'])
            ? $settings['customize_text']
            : __('Customize', 'traydigita-core');
        $backgroundColor = self::color($settings, 'background_color', '#ffffff');
        $textColor = self::color($settings, 'text_color', '#1f2937');
        // Blue by default, matching the --traydigita-color-accent
        // token of the admin UI library; never green.
        $acceptColor = self::color($settings, 'accept_color', '#0369a1');
        $detailsId = $preview
            ? 'traydigita-cookie-consent-preview-details'
            : 'traydigita-cookie-consent-details';
        $previewAttr = $preview ? ' data-traydigita-cookie-preview-banner' : '';
        $maxAge = (int) $settings['duration_days'] * 86400;

        ob_start();
        ?>
        <div class="traydigita-cookie-consent traydigita-cookie-consent--<?php echo esc_attr($position); ?>"
            role="dialog"
            aria-label="<?php echo esc_attr__('Cookie consent', 'traydigita-core'); ?>"
            aria-live="polite"
            style="--traydigita-cookie-background: <?php echo esc_attr($backgroundColor); ?>;
                --traydigita-cookie-text: <?php echo esc_attr($textColor); ?>;
                --traydigita-cookie-accept: <?php echo esc_attr($acceptColor); ?>;"
            data-traydigita-cookie-consent<?php echo $previewAttr . "\n"; ?>
            data-cookie-name="<?php echo esc_attr(CookieConsentModule::COOKIE_NAME); ?>"
            data-cookie-max-age="<?php echo esc_attr((string) $maxAge); ?>">
            <p class="traydigita-cookie-consent-message"><?php echo wp_kses_post($message); ?></p>
            <div class="traydigita-cookie-consent-details"
                id="<?php echo esc_attr($detailsId); ?>"
                data-traydigita-cookie-details
                hidden>
                <p><?php esc_html_e('This website only uses cookies that are strictly necessary for it to work, plus one cookie to remember the choice you make here. No optional cookies are set until you accept.', 'traydigita-core'); ?></p>
                <?php if ($policyUrl !== '') : ?>
                    <p>
                        <a class="traydigita-cookie-consent-policy-link"
                            href="<?php echo esc_url($policyUrl); ?>">
                            <?php esc_html_e('Read the Privacy Policy', 'traydigita-core'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
            <div class="traydigita-cookie-consent-actions">
                <button type="button"
                    class="traydigita-cookie-consent-accept"
                    data-traydigita-cookie-accept>
                    <?php echo esc_html($acceptText); ?>
                </button>
                <button type="button"
                    class="traydigita-cookie-consent-decline"
                    data-traydigita-cookie-decline>
                    <?php echo esc_html($declineText); ?>
                </button>
                <button type="button"
                    class="traydigita-cookie-consent-customize"
                    data-traydigita-cookie-customize
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr($detailsId); ?>">
                    <?php echo esc_html($customizeText); ?>
                </button>
            </div>
        </div>
        <?php
        return rtrim((string) ob_get_clean());
    }

    /**
     * One banner colour from the settings, validated as hex.
     *
     * The value comes from the module settings (already sanitized);
     * anything that is not a valid hex colour falls back to the
     * built-in default, so the inline style attribute can never
     * carry an injected value.
     *
     * @param array<string, mixed> $settings Sanitized module settings.
     * @param string $key Setting key.
     * @param string $fallback Built-in default colour.
     * @return string
     */
    private static function color(array $settings, string $key, string $fallback): string
    {
        $value = isset($settings[$key]) && is_string($settings[$key]) ? $settings[$key] : '';
        if ($value === '') {
            return $fallback;
        }
        $color = sanitize_hex_color($value);
        return $color === null ? $fallback : $color;
    }
}
