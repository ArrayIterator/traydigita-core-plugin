<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use function __;
use function function_exists;
use function get_current_user_id;
use function get_post;
use function get_the_excerpt;
use function get_the_title;
use function get_transient;
use function is_string;
use function is_wp_error;
use function mb_strlen;
use function mb_strrpos;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function set_transient;
use function sprintf;
use function trim;
use function wp_ai_client_prompt;
use function wp_strip_all_tags;
use function wp_supports_ai;
use Throwable;
use WP_Post;

/**
 * AI meta suggestions through WordPress core's AI Client API.
 *
 * The WordPress version installed for testing (7.1.5) ships the
 * core AI Client API (wp-includes/ai-client.php, since WordPress
 * 7.0.0): wp_supports_ai() and wp_ai_client_prompt(), whose
 * builder offers is_supported_for_text_generation() and
 * generate_text(). Core ships NO AI provider of its own - a
 * provider is registered only by a provider plugin (for example
 * the AI Services provider plugins, ai-provider-for-anthropic /
 * ai-provider-for-google / ai-provider-for-openai, which core's
 * connector registry lists as the default AI providers). None of
 * those plugins is installed in the test install, so on a stock
 * site this feature reports as unavailable and stays disabled.
 *
 * This class therefore never fakes AI output, never invents an
 * endpoint, and never calls an unlisted external API: it only
 * calls the core builder when is_supported_for_text_generation()
 * says a real provider can generate text, and otherwise returns
 * an honest "unavailable" status naming what is missing (no
 * provider plugin installed). Suggestions are generated on
 * demand for one post at a time, from that post's real title,
 * excerpt, and content; the generated title is capped at 60
 * characters and the description at 160, at a word boundary.
 * Nothing is applied automatically - the admin reviews the
 * suggestions and applies them explicitly as per-post overrides.
 */
final class SeoAi
{
    /**
     * Status reason: the core AI Client API is missing (WordPress older than 7.0).
     */
    public const string REASON_NO_API = 'no-api';

    /**
     * Status reason: AI is disabled on this site (WP_AI_SUPPORT off or filtered off).
     */
    public const string REASON_DISABLED = 'disabled';

    /**
     * Status reason: the API exists but no provider plugin can generate text.
     */
    public const string REASON_NO_PROVIDER = 'no-provider';

    /**
     * Status reason: a provider is available and text generation is supported.
     */
    public const string REASON_READY = 'ready';

    /**
     * Maximum characters of post content sent to the model.
     */
    public const int CONTENT_MAX_LENGTH = 4000;

    /**
     * Maximum suggested title length in characters.
     */
    public const int TITLE_MAX_LENGTH = 60;

    /**
     * Maximum suggested description length in characters.
     */
    public const int DESCRIPTION_MAX_LENGTH = 160;

    /**
     * Minimum seconds between suggestion requests from the same user.
     */
    public const int RATE_LIMIT_SECONDS = 60;

    /**
     * AI availability status for this site.
     *
     * Checks, in order: the core function exists, AI is enabled
     * (wp_supports_ai()), and a registered provider actually
     * supports text generation. Any failure mode is reported
     * honestly with its reason; only REASON_READY means
     * suggestForPost() can run.
     *
     * @return array{available: bool, reason: string}
     */
    public static function status(): array
    {
        if (!function_exists('wp_ai_client_prompt')) {
            return [
                'available' => false,
                'reason' => self::REASON_NO_API,
            ];
        }
        if (function_exists('wp_supports_ai') && !wp_supports_ai()) {
            return [
                'available' => false,
                'reason' => self::REASON_DISABLED,
            ];
        }
        try {
            $builder = wp_ai_client_prompt();
            $supported = $builder->is_supported_for_text_generation();
        } catch (Throwable) {
            return [
                'available' => false,
                'reason' => self::REASON_NO_PROVIDER,
            ];
        }
        if ($supported !== true) {
            return [
                'available' => false,
                'reason' => self::REASON_NO_PROVIDER,
            ];
        }
        return [
            'available' => true,
            'reason' => self::REASON_READY,
        ];
    }

    /**
     * Human-readable explanation for an unavailable status, or an empty string when available.
     *
     * @param string $reason One of the REASON_* constants.
     * @return string
     */
    public static function statusMessage(string $reason): string
    {
        if ($reason === self::REASON_NO_API) {
            return __('The WordPress AI Client API is not available on this site (it ships with WordPress 7.0 and later). AI meta suggestions are disabled.', 'traydigita-core');
        }
        if ($reason === self::REASON_DISABLED) {
            return __('AI features are disabled on this site. AI meta suggestions are disabled.', 'traydigita-core');
        }
        if ($reason === self::REASON_NO_PROVIDER) {
            return __('No WordPress AI provider is available: WordPress ships the AI Client API but no AI provider of its own. Install and connect an AI provider plugin (for example the Anthropic, Google, or OpenAI provider plugin) to enable AI meta suggestions. Nothing is generated until then.', 'traydigita-core');
        }
        return '';
    }

    /**
     * Generate a suggested meta title and description for one post or page.
     *
     * The prompt is built from the post's real title, excerpt, and
     * content only. The model is asked to answer in a strict
     * "TITLE: ... / DESCRIPTION: ..." format, which is parsed
     * defensively: an unparsable answer, a provider error, or a
     * missing post all return an error instead of invented text.
     * At most one request per user per minute (transient rate
     * limit); the generated text is never stored here - the caller
     * decides whether to apply it.
     *
     * @param int $postId Post or page ID.
     * @return array{title: string, description: string}|array{error: string}
     */
    public static function suggestForPost(int $postId): array
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return ['error' => __('That post could not be found.', 'traydigita-core')];
        }
        if ($post->post_type !== 'post' && $post->post_type !== 'page') {
            return ['error' => __('AI suggestions are available for posts and pages only.', 'traydigita-core')];
        }
        $status = self::status();
        if (!$status['available']) {
            return ['error' => self::statusMessage($status['reason'])];
        }
        $userId = get_current_user_id();
        $rateLimitKey = 'traydigita_seo_ai_' . $userId;
        if (false !== get_transient($rateLimitKey)) {
            return [
                'error' => sprintf(
                    /* translators: %d is the number of seconds to wait before the next AI suggestion. */
                    __('Please wait %d seconds before requesting another AI suggestion.', 'traydigita-core'),
                    self::RATE_LIMIT_SECONDS
                ),
            ];
        }
        set_transient($rateLimitKey, 1, self::RATE_LIMIT_SECONDS);
        $prompt = self::buildPrompt($post);
        try {
            $builder = wp_ai_client_prompt($prompt);
            $result = $builder
                ->using_system_instruction('You are an SEO assistant for a news website. Write concise, factual meta titles and descriptions. Never invent facts that are not in the article. Answer in exactly this format, with nothing else: TITLE: <title>\nDESCRIPTION: <description>')
                ->using_max_tokens(300)
                ->generate_text();
        } catch (Throwable) {
            return ['error' => __('The AI provider could not generate a suggestion. Please try again later.', 'traydigita-core')];
        }
        if (is_wp_error($result)) {
            return ['error' => __('The AI provider could not generate a suggestion. Please try again later.', 'traydigita-core')];
        }
        if (!is_string($result) || trim($result) === '') {
            return ['error' => __('The AI provider returned an empty suggestion.', 'traydigita-core')];
        }
        return self::parseSuggestion($result);
    }

    /**
     * Build the prompt text for one post from its real content.
     *
     * Title, excerpt, and the first 4000 characters of stripped
     * content; nothing else is sent to the provider.
     *
     * @param WP_Post $post Post or page.
     * @return string
     */
    private static function buildPrompt(WP_Post $post): string
    {
        $content = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($post->post_content)));
        if (mb_strlen($content) > self::CONTENT_MAX_LENGTH) {
            $content = mb_substr($content, 0, self::CONTENT_MAX_LENGTH);
        }
        $prompt = 'Article title: ' . get_the_title($post) . "\n";
        $excerpt = get_the_excerpt($post);
        if ($excerpt !== '') {
            $prompt .= 'Excerpt: ' . $excerpt . "\n";
        }
        if ($content !== '') {
            $prompt .= 'Article text: ' . $content . "\n";
        }
        $prompt .= 'Write one SEO meta title of at most 60 characters and one meta description of at most 160 characters for this article.';
        return $prompt;
    }

    /**
     * Parse a "TITLE: ... / DESCRIPTION: ..." answer into its two parts.
     *
     * Both lines must be present and non-empty; the title is cut
     * to 60 characters and the description to 160, at a word
     * boundary. Anything else is an error, never a guess.
     *
     * @param string $text Raw model answer.
     * @return array{title: string, description: string}|array{error: string}
     */
    private static function parseSuggestion(string $text): array
    {
        $title = '';
        $description = '';
        if (preg_match('/^TITLE:\s*(.+)$/m', $text, $matches) === 1) {
            $title = trim($matches[1]);
        }
        if (preg_match('/^DESCRIPTION:\s*(.+)$/m', $text, $matches) === 1) {
            $description = trim($matches[1]);
        }
        if ($title === '' || $description === '') {
            return ['error' => __('The AI provider returned a suggestion in an unexpected format. Please try again.', 'traydigita-core')];
        }
        return [
            'title' => self::cutAtWord($title, self::TITLE_MAX_LENGTH),
            'description' => self::cutAtWord($description, self::DESCRIPTION_MAX_LENGTH),
        ];
    }

    /**
     * Cut text to a maximum length at a word boundary.
     *
     * @param string $text Text to cut.
     * @param int $maxLength Maximum length in characters.
     * @return string
     */
    private static function cutAtWord(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        $cut = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return trim($cut);
    }
}
