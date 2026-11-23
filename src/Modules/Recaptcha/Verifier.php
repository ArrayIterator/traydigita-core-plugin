<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Recaptcha;

use function __;
use function is_array;
use function is_numeric;
use function is_string;
use function is_wp_error;
use function json_decode;
use function sprintf;
use function wp_remote_post;
use function wp_remote_retrieve_body;

/**
 * Google reCAPTCHA verification (v2 checkbox and v3 score).
 *
 * Both versions verify the token directly against the
 * siteverify endpoint with wp_remote_post — no external
 * library. The secret key never leaves the server. The
 * siteverify host is a class constant, never caller input.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Recaptcha
 */
final class Verifier
{
    /**
     * Google reCAPTCHA siteverify endpoint.
     */
    public const string SITEVERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Verify a reCAPTCHA token.
     *
     * @param string $token Token posted by the frontend widget.
     * @param string $secret Secret key from the module settings.
     * @param string $version 'v2' or 'v3'.
     * @param float $threshold Minimum v3 score (0.0–1.0); ignored for v2.
     * @param string $action Expected v3 action ('' skips the action check).
     * @return array{success: bool, score: float|null, error: string}
     */
    public static function verify(string $token, string $secret, string $version = 'v2', float $threshold = 0.5, string $action = ''): array
    {
        if ($token === '' || $secret === '') {
            return [
                'success' => false,
                'score' => null,
                'error' => __('Missing reCAPTCHA token or secret key.', 'traydigita-core'),
            ];
        }
        $response = wp_remote_post(
            self::SITEVERIFY_URL,
            [
                'timeout' => 10,
                'body' => [
                    'secret' => $secret,
                    'response' => $token,
                ],
            ]
        );
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'score' => null,
                'error' => __('Could not reach the reCAPTCHA verification service.', 'traydigita-core'),
            ];
        }
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'score' => null,
                'error' => __('Invalid response from the reCAPTCHA verification service.', 'traydigita-core'),
            ];
        }
        if (empty($decoded['success'])) {
            $codes = isset($decoded['error-codes']) && is_array($decoded['error-codes']) ? $decoded['error-codes'] : [];
            $detail = '';
            foreach ($codes as $code) {
                if (is_string($code) && $code !== '') {
                    $detail = $code;
                    break;
                }
            }
            return [
                'success' => false,
                'score' => null,
                'error' => $detail !== ''
                    ? sprintf(
                        /* translators: %s: reCAPTCHA error code. */
                        __('reCAPTCHA verification failed (%s).', 'traydigita-core'),
                        $detail
                    )
                    : __('reCAPTCHA verification failed.', 'traydigita-core'),
            ];
        }
        $score = null;
        if ($version === 'v3') {
            $rawScore = $decoded['score'] ?? null;
            $score = is_numeric($rawScore) ? (float) $rawScore : null;
            if ($score === null || $score < $threshold) {
                return [
                    'success' => false,
                    'score' => $score,
                    'error' => __('reCAPTCHA score is too low.', 'traydigita-core'),
                ];
            }
            if ($action !== '') {
                $returnedAction = isset($decoded['action']) && is_string($decoded['action']) ? $decoded['action'] : '';
                if ($returnedAction !== $action) {
                    return [
                        'success' => false,
                        'score' => $score,
                        'error' => __('reCAPTCHA action mismatch.', 'traydigita-core'),
                    ];
                }
            }
        }
        return ['success' => true, 'score' => $score, 'error' => ''];
    }
}
