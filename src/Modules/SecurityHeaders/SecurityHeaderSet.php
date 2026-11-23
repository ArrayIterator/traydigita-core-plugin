<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\SecurityHeaders;

use function __;
use function in_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strip_tags;
use function trim;

/**
 * Canonical security-header values and builders.
 *
 * Single home for everything the Security Headers module and the
 * Custom Headers module used to duplicate: the default
 * report-only CSP, the fixed Permissions-Policy value, the
 * Referrer-Policy allowlist, the CSP length cap, the defensive
 * CSP cleaner, the save-path CSP validation, and the builder
 * that turns a settings array into the name => value headers.
 *
 * The Security Headers module owns sending
 * (SecurityHeadersModule::sendHeaders() is the only
 * `send_headers` callback); the Custom Headers module builds
 * its own set from the same builder and adds only the header
 * it alone owns (Strict-Transport-Security) plus its advanced
 * rules. Neither module keeps its own copy of the constants
 * or of the CSP logic anymore.
 */
final class SecurityHeaderSet
{
    /**
     * Maximum Content-Security-Policy length in characters.
     */
    public const int MAX_POLICY_LENGTH = 2000;

    /**
     * Fixed Permissions-Policy value: no camera, microphone, or
     * geolocation for the page or any embedded frame.
     */
    public const string PERMISSIONS_POLICY_VALUE = 'camera=(), microphone=(), geolocation=()';

    /**
     * Default report-only policy.
     *
     * Conservative: everything must come from the site itself,
     * except Google Fonts (stylesheets + font files) and the
     * Open-Meteo hosts the theme's weather widget talks to.
     * Market prices are fetched server-side only, so no market
     * host is allowlisted here. Frames may only embed the site itself.
     */
    public const string DEFAULT_CSP_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://api.open-meteo.com https://geocoding-api.open-meteo.com; connect-src 'self' https://api.open-meteo.com https://geocoding-api.open-meteo.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

    /**
     * Allowlisted Referrer-Policy values. An empty string means
     * the header is disabled.
     *
     * @var array<int, string>
     */
    public const array REFERRER_POLICIES = [
        'no-referrer',
        'same-origin',
        'strict-origin',
        'strict-origin-when-cross-origin',
    ];

    /**
     * Build the shared security headers from a settings array.
     *
     * Only enabled headers are included, and the report-only CSP
     * is included only when its toggle is on and the policy is
     * non-empty. An enforcing `Content-Security-Policy` header
     * is never returned. Strict-Transport-Security is not part
     * of this set: only the Custom Headers module sends it.
     *
     * @param array<string, mixed> $settings Sanitized settings.
     * @return array<string, string>
     */
    public static function build(array $settings): array
    {
        $headers = [];
        if (!empty($settings['x_content_type_options'])) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }
        if (!empty($settings['x_frame_options'])) {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }
        if (isset($settings['referrer_policy']) && is_string($settings['referrer_policy'])
            && $settings['referrer_policy'] !== ''
        ) {
            $headers['Referrer-Policy'] = $settings['referrer_policy'];
        }
        if (!empty($settings['permissions_policy'])) {
            $headers['Permissions-Policy'] = self::PERMISSIONS_POLICY_VALUE;
        }
        if (!empty($settings['csp_report_only'])
            && isset($settings['csp_policy']) && is_string($settings['csp_policy'])
            && $settings['csp_policy'] !== ''
        ) {
            $headers['Content-Security-Policy-Report-Only'] = $settings['csp_policy'];
        }
        return $headers;
    }

    /**
     * Clean a CSP string defensively for storage.
     *
     * Tags are stripped, control characters are replaced with a
     * space, runs of whitespace are collapsed, and the result is
     * capped at 2000 characters. This is the read-path safety net;
     * the save path rejects invalid policies outright instead.
     *
     * @param string $policy Raw policy string.
     * @return string
     */
    public static function cleanCspPolicy(string $policy): string
    {
        $policy = strip_tags($policy);
        $policy = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $policy);
        $policy = (string) preg_replace('/\s+/', ' ', $policy);
        $policy = trim($policy);
        if (mb_strlen($policy) > self::MAX_POLICY_LENGTH) {
            $policy = mb_substr($policy, 0, self::MAX_POLICY_LENGTH);
        }
        return $policy;
    }

    /**
     * Keep a Referrer-Policy value only when it is allowlisted.
     *
     * Anything else — including a non-string value — disables
     * the header (empty string).
     *
     * @param mixed $value Raw Referrer-Policy value.
     * @return string
     */
    public static function sanitizeReferrerPolicy(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        return in_array($value, self::REFERRER_POLICIES, true) ? $value : '';
    }

    /**
     * Validate a report-only CSP on the save path.
     *
     * The policy is rejected when it is empty while report-only
     * mode is on, longer than 2000 characters, or contains line
     * breaks or control characters. Returns the (translated)
     * rejection message, or null when the incoming data passes.
     *
     * @param array<string, mixed> $incoming Unslashed form data.
     * @return string|null
     */
    public static function validateCspForSave(array $incoming): ?string
    {
        if (empty($incoming['csp_report_only'])) {
            return null;
        }
        $rawPolicy = isset($incoming['csp_policy']) && is_string($incoming['csp_policy'])
            ? trim($incoming['csp_policy'])
            : '';
        if ($rawPolicy === '') {
            return __('Enter a Content-Security-Policy before enabling report-only mode.', 'traydigita-core');
        }
        if (mb_strlen($rawPolicy) > self::MAX_POLICY_LENGTH) {
            return sprintf(
                /* translators: %d is the maximum policy length in characters. */
                __('The policy must be at most %d characters.', 'traydigita-core'),
                self::MAX_POLICY_LENGTH
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $rawPolicy) === 1) {
            return __('The policy must not contain line breaks or control characters.', 'traydigita-core');
        }
        return null;
    }
}
