<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Ads;

use Throwable;
use WP_Post;
use WP_Term;
use function __;
use function absint;
use function add_action;
use function apply_filters;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function error_log;
use function get_post;
use function get_the_terms;
use function is_array;
use function is_string;
use function remove_filter;
use function setup_postdata;
use function wp_reset_postdata;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Live-preview AJAX endpoint for the Ads Inserter module.
 *
 * Registered from `AdsModule::boot()` (only when the module is
 * enabled), so the endpoint does not exist while the module is
 * disabled — the same pattern as the SMTP test-email endpoint.
 *
 * Request (POST to `admin-ajax.php`):
 * - `action` = `traydigita_ads_preview`
 * - `_ajax_nonce` = nonce for action `traydigita-admin` (the
 *   `check_ajax_referer()` default field; never a field named
 *   `nonce`)
 * - `post_id` (required, validated with absint() + get_post())
 * - `rules` (the draft rules from the settings form, as nested
 *   `rules[N][field]` fields; the ad code travels raw here, NOT
 *   base64-encoded, because this endpoint is module-owned and
 *   does not pass through the shell dispatcher's
 *   sanitize_text_field() — the values are unslashed and then
 *   normalized by AdsModule::sanitizeRules())
 *
 * The endpoint renders `the_content` of the chosen post with the
 * draft rules applied and returns the HTML for the preview pane.
 * It never reads the stored option: the returned HTML only ever
 * contains ad code the requesting administrator just submitted,
 * so stored ad code can never leak through this endpoint. Only
 * administrators (`manage_options`) may call it.
 *
 * Preview rendering: the module's own `the_content` filter is
 * removed first (it would apply the *stored* rules), then the
 * remaining content filters run, then the draft rules are applied
 * directly through PlacementEngine with a singular-post context
 * for the chosen post.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Ads
 */
final class AdsAjax
{
    /**
     * AJAX action name for the live-preview endpoint.
     */
    public const string AJAX_ACTION = 'traydigita_ads_preview';

    /**
     * Register the live-preview AJAX action.
     *
     * Called from `AdsModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'renderPreview']);
    }

    /**
     * Render a preview of the chosen post with the draft rules applied.
     *
     * POST: `post_id` (required), `rules` (draft rules from the
     * settings form). Success returns `['html' => string,
     * 'inserted' => int]`; failure returns a JSON error with a
     * generic message. No option values and no ad code beyond what
     * the request itself supplied are ever returned.
     *
     * @return void
     */
    public static function renderPreview(): void
    {
        check_ajax_referer(AdsModule::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $postId = absint($_POST['post_id'] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;
        if (!$post instanceof WP_Post) {
            wp_send_json_error(
                ['message' => __('Please choose a post to preview.', 'traydigita-core')],
                400
            );
        }
        $rawRules = isset($_POST['rules']) && is_array($_POST['rules'])
            ? wp_unslash($_POST['rules'])
            : [];
        if (!is_array($rawRules)) {
            $rawRules = [];
        }
        $rules = AdsModule::sanitizeRules($rawRules, false)['rules'];
        $settings = AdsModule::getSettings();
        $context = [
            'post_type' => $post->post_type,
            'category_ids' => self::postTermIds($postId, 'category'),
            'tag_ids' => self::postTermIds($postId, 'post_tag'),
            'is_singular' => true,
            'is_home' => false,
            'is_archive' => false,
            'is_search' => false,
            'is_mobile' => false,
            'ads_remaining' => $settings['max_ads_per_page'],
            'already_inserted' => [],
        ];
        try {
            // The module's own filter would apply the stored rules;
            // remove it so only the draft rules are applied below.
            remove_filter('the_content', [AdsModule::class, 'filterContent'], 12);
            $previousPost = $GLOBALS['post'] ?? null;
            $GLOBALS['post'] = $post;
            setup_postdata($post);
            $rendered = apply_filters('the_content', $post->post_content);
            wp_reset_postdata();
            if ($previousPost instanceof WP_Post) {
                $GLOBALS['post'] = $previousPost;
            } else {
                unset($GLOBALS['post']);
            }
            if (!is_string($rendered)) {
                $rendered = '';
            }
            $result = PlacementEngine::insertAds($rendered, $rules, $postId, $context);
        } catch (Throwable $exception) {
            error_log('TrayDigita Ads: preview failed for post ' . $postId . ': ' . $exception->getMessage());
            wp_send_json_error(
                ['message' => __('Could not render the preview. Please try again.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success([
            'html' => $result['content'],
            'inserted' => count($result['inserted']),
        ]);
    }

    /**
     * Term ids of a post for one taxonomy.
     *
     * @param int $postId Post id.
     * @param string $taxonomy Taxonomy slug.
     * @return array<int, int>
     */
    private static function postTermIds(int $postId, string $taxonomy): array
    {
        $terms = get_the_terms($postId, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }
        $ids = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $ids[] = $term->term_id;
            }
        }
        return $ids;
    }
}
