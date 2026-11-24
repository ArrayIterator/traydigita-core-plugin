<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Ads;

use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\AdminMenu;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use WP_Post;
use WP_Term;
use function __;
use function absint;
use function add_filter;
use function admin_url;
use function array_key_exists;
use function array_merge;
use function base64_decode;
use function bin2hex;
use function count;
use function current_user_can;
use function error_log;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function function_exists;
use function get_option;
use function get_post;
use function get_post_types;
use function get_posts;
use function get_term;
use function get_terms;
use function get_the_ID;
use function get_the_terms;
use function implode;
use function in_array;
use function is_archive;
use function is_array;
use function is_bool;
use function is_feed;
use function is_home;
use function is_int;
use function is_search;
use function is_singular;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function random_bytes;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function substr;
use function time;
use function trim;
use function update_option;
use function wp_create_nonce;
use function wp_is_mobile;

/**
 * Ads Inserter module — smart in-article ad placement.
 *
 * Multiple rules, each with ad code + placement + conditions, all
 * stored in the single option `traydigita_ads` (autoload `no`):
 * `['max_ads_per_page' => int, 'rules' => array<int, rule>]`.
 * The module ships DISABLED by default (no rules configured means
 * nothing renders) and is optional, never core.
 *
 * Relation to the phase-1 AdSense widget: the widget is for
 * sidebars; this inserter is for in-article ads inside post
 * content. That distinction is stated in the module copy below.
 *
 * Ad-code trust model (read before touching saveSettings() or the
 * output path):
 * - Ad code may contain <script> and <ins> (AdSense). It is stored
 *   exactly as submitted — never through sanitize_text_field() or
 *   wp_kses(), which would strip the scripts — and it is output
 *   exactly as stored through the single dedicated function
 *   PlacementEngine::renderAdCode(), never through any other path.
 * - Only users with `manage_options` can save it: saving goes
 *   through the phase-2 dispatcher (AdminAjax::saveModule), which
 *   requires the `traydigita-admin` nonce plus `manage_options`,
 *   and saveSettings() checks the capability again. Lower roles
 *   can never reach saveSettings(). This is the same trust level
 *   WordPress grants the Custom HTML widget: administrators may
 *   store arbitrary markup, nobody else may.
 * - Transport detail: the dispatcher deep-sanitizes every posted
 *   string with sanitize_text_field(), which would strip the
 *   <script> tags out of ad code before saveSettings() ever sees
 *   it. The admin JavaScript therefore base64-encodes each rule's
 *   ad code before submitting (the same workaround the Login
 *   module uses for its design JSON), and saveSettings() decodes
 *   it back to the raw code. A rule whose code fails strict
 *   base64 decoding is dropped and the save is rejected, so
 *   corrupted transport can never store mangled code.
 * - The dispatcher never returns option values, and the preview
 *   endpoint only renders rules supplied in its own request, so
 *   stored ad code never leaks in an AJAX response.
 *
 * The settings page is rendered inline by renderSettingsPage()
 * (no template file): this track owns src/Admin/Modules/Ads/
 * only, so the markup lives in this class. The form deliberately
 * carries no `data-traydigita-module-form` / `data-action`
 * attributes — the shell's generic handler would submit the raw
 * ad code through the dispatcher and mangle it; the module's own
 * JavaScript (resources/js/modules/ads-preview.ts) submits the
 * form instead, base64-encoding the ad code first.
 */
final class AdsModule implements \TrayDigita\WP\Plugin\Core\Admin\ModuleInterface
{
    /**
     * Option name holding all ads settings (autoload `no`).
     *
     * Shape: `['max_ads_per_page' => int, 'rules' => array<int, rule>]`.
     */
    public const string OPTION = 'traydigita_ads';

    /**
     * Shared nonce action for the admin AJAX endpoints.
     *
     * The nonce itself must be sent as `_ajax_nonce` (see
     * `check_ajax_referer()`), never as a field named `nonce`.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Default maximum ads per page.
     */
    public const int DEFAULT_MAX_ADS_PER_PAGE = 3;

    /**
     * Hard cap for the maximum ads per page setting.
     */
    public const int MAX_ADS_PER_PAGE = 10;

    /**
     * Hard cap for the number of rules (keeps the settings form
     * and the option size sane).
     */
    public const int MAX_RULES = 20;

    /**
     * Default paragraph for the after-paragraph placement.
     */
    public const int DEFAULT_PARAGRAPH = 3;

    /**
     * Default percentage for the after-percent placement.
     */
    public const int DEFAULT_PERCENT = 50;

    /**
     * Maximum rule label length in characters.
     */
    public const int LABEL_MAX_LENGTH = 100;

    /**
     * Maximum terms offered in the category/tag pickers.
     */
    public const int TERM_PICKER_LIMIT = 100;

    /**
     * Number of recent posts offered in the preview picker.
     */
    public const int PREVIEW_POST_LIMIT = 20;

    /**
     * Whether `boot()` has already run.
     */
    private static bool $booted = false;

    /**
     * Re-entrancy guard for the `the_content` filter.
     *
     * Set while PlacementEngine runs, so a nested `the_content`
     * application (for example a shortcode rendering another post)
     * returns its content untouched instead of inserting ads into
     * ads.
     */
    private static bool $filtering = false;

    /**
     * Ads inserted so far on this request, across all posts.
     *
     * Enforces the per-page budget across rules and across
     * `the_content` calls.
     */
    private static int $adsInsertedCount = 0;

    /**
     * Rule ids already inserted per post id on this request.
     *
     * @var array<int, array<int, string>>
     */
    private static array $insertedRuleIdsByPost = [];

    /**
     * Module id used by the module registry and the shell save endpoint.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'ads';
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
        return 'monetization';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Ads Inserter', 'traydigita-core');
    }

    /**
     * Module description shown on the module grid card.
     *
     * States the split with the phase-1 AdSense widget: the widget
     * is for sidebars, this inserter is for in-article ads.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Insert ads inside article content — after a paragraph, at a percentage, or at a seeded-random spot. The AdSense widget is for sidebars; this inserter is for in-article ads.', 'traydigita-core');
    }

    /**
     * Dashicon shown on the module grid card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'megaphone';
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
            __('Insert ads after a paragraph, at a percentage of the article, '
                . 'or at a seeded-random spot.', 'traydigita-core'),
            __('The AdSense widget covers sidebars; this inserter covers in-article ads.', 'traydigita-core'),
            __('Seeded placement keeps the ad spot stable per article.', 'traydigita-core'),
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
     * Admin page slug for the module settings page.
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
        return 'traydigita-ads';
    }

    /**
     * Whether the module can run at all.
     *
     * The inserter has no hard dependencies, so this is always true.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether at least one rule has ad code to show.
     *
     * True when a rule exists with non-empty ad code. A module with
     * no usable rule reports as unconfigured, so the grid can show
     * the "needs setup" state.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        foreach (self::getSettings()['rules'] as $rule) {
            if (is_string($rule['ad_code'] ?? null) && $rule['ad_code'] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this is a core module.
     *
     * Always false: the inserter is optional and can be disabled
     * from the grid. It also ships disabled by default (see
     * ModuleRegistry::DEFAULT_ENABLED — 'ads' must NOT be listed
     * there), because no rules configured means nothing renders.
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
     * Registers the `the_content` filter (priority 12: after
     * wpautop at 10 and do_shortcode at 11, so paragraphs exist
     * when the engine counts them) and the live-preview AJAX
     * endpoint (see AdsAjax::register()). A disabled module
     * registers nothing. The registry already boots enabled
     * modules only; the isEnabled() guard here is belt and braces.
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
        add_filter('the_content', [self::class, 'filterContent'], 12);
        AdsAjax::register();
    }

    /**
     * Default settings, used when the option has no stored value yet.
     *
     * @return array{max_ads_per_page: int, rules: array<int, array<string, mixed>>}
     */
    public static function getDefaults(): array
    {
        return [
            'max_ads_per_page' => self::DEFAULT_MAX_ADS_PER_PAGE,
            'rules' => [],
        ];
    }

    /**
     * Read the stored settings, merged over the defaults and normalized.
     *
     * Stored rules pass through sanitizeRules() with
     * $adCodeIsBase64 false: the stored code is already raw, and
     * unslashing or re-decoding it here would corrupt legitimate
     * backslashes in ad scripts.
     *
     * @return array{max_ads_per_page: int, rules: array<int, array<string, mixed>>}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $maxAds = absint($settings['max_ads_per_page'] ?? self::DEFAULT_MAX_ADS_PER_PAGE);
        if ($maxAds > self::MAX_ADS_PER_PAGE) {
            $maxAds = self::MAX_ADS_PER_PAGE;
        }
        $rawRules = is_array($settings['rules'] ?? null) ? $settings['rules'] : [];
        $rules = self::sanitizeRules($rawRules, false)['rules'];
        return [
            'max_ads_per_page' => $maxAds,
            'rules' => $rules,
        ];
    }

    /**
     * Validate and save the settings submitted by the shell save endpoint.
     *
     * Only `manage_options` users may save: the dispatcher already
     * gates on the `traydigita-admin` nonce plus `manage_options`,
     * and this method checks the capability again so the trust
     * model does not depend on the caller. Lower roles can never
     * reach this method, so they can never store ad code.
     *
     * Ad code arrives base64-encoded (see the class docblock for
     * why) when `$data['ad_code_encoding']` is 'base64'; it is
     * decoded back to the raw code and stored as-is — never through
     * sanitize_text_field() or wp_kses(), which would strip the
     * <script>/<ins> tags. When the encoding flag is absent the
     * code is stored as-is from the already-unslashed dispatcher
     * data. A rule whose code fails strict base64 decoding is
     * dropped and the whole save is rejected, so corrupted
     * transport can never store mangled code.
     *
     * @param array<string, mixed> $data Sanitized form data from the dispatcher.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change ads settings.', 'traydigita-core'),
            ];
        }
        $settings = self::getSettings();
        if (array_key_exists('max_ads_per_page', $data)) {
            $rawMax = $data['max_ads_per_page'];
            $maxAds = absint(is_string($rawMax) || is_int($rawMax) ? $rawMax : self::DEFAULT_MAX_ADS_PER_PAGE);
            if ($maxAds > self::MAX_ADS_PER_PAGE) {
                $maxAds = self::MAX_ADS_PER_PAGE;
            }
            $settings['max_ads_per_page'] = $maxAds;
        }
        if (array_key_exists('rules', $data) && is_array($data['rules'])) {
            $encoded = isset($data['ad_code_encoding']) && $data['ad_code_encoding'] === 'base64';
            $sanitized = self::sanitizeRules($data['rules'], $encoded);
            if ($sanitized['dropped'] > 0) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %d is the number of rules dropped because their ad code could not be decoded. */
                        __('%d rule(s) could not be saved because their ad code was corrupted in '
                            . 'transit. Please try saving again.', 'traydigita-core'),
                        $sanitized['dropped']
                    ),
                ];
            }
            $settings['rules'] = $sanitized['rules'];
        }
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Ads settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Normalize submitted or stored rules into the engine's shape.
     *
     * The input is expected to be already unslashed (the dispatcher
     * unslashes before saveSettings(); AdsAjax unslashes the raw
     * POST array before calling this for previews; stored values
     * were never slashed), so this method never calls wp_unslash()
     * itself — doing so here would strip legitimate backslashes
     * from stored ad code.
     *
     * Every field is validated for its own type: placements and
     * devices against allowlists, paragraph/percent clamped to
     * their ranges, term ids through absint(), post types against
     * the public post types (unknown types are dropped; an empty
     * result falls back to ['post']). Labels are sanitized text.
     * Rule ids are kept when they are valid and unique; a missing,
     * invalid or duplicate id gets a fresh random one. Rules beyond
     * MAX_RULES are dropped.
     *
     * @param array<int, mixed> $rawRules Raw rule arrays.
     * @param bool $adCodeIsBase64 True when each ad_code value is base64-encoded (dispatcher save path).
     * @return array{rules: array<int, array<string, mixed>>, dropped: int} Normalized rules and the count dropped for undecodable ad code.
     */
    public static function sanitizeRules(array $rawRules, bool $adCodeIsBase64): array
    {
        $rules = [];
        $dropped = 0;
        $usedIds = [];
        $publicPostTypes = get_post_types(['public' => true], 'names');
        if (!is_array($publicPostTypes)) {
            $publicPostTypes = [];
        }
        foreach ($rawRules as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $id = isset($raw['id']) && is_string($raw['id']) ? sanitize_key($raw['id']) : '';
            if ($id === '' || in_array($id, $usedIds, true)) {
                $id = self::generateRuleId();
            }
            $usedIds[] = $id;
            $code = isset($raw['ad_code']) && is_string($raw['ad_code']) ? $raw['ad_code'] : '';
            if ($adCodeIsBase64 && $code !== '') {
                $decoded = base64_decode($code, true);
                if ($decoded === false) {
                    $dropped++;
                    continue;
                }
                $code = $decoded;
            }
            $placement = isset($raw['placement']) && is_string($raw['placement'])
                ? sanitize_key($raw['placement'])
                : '';
            if (!in_array($placement, PlacementEngine::PLACEMENTS, true)) {
                $placement = PlacementEngine::PLACEMENT_AFTER_PARAGRAPH;
            }
            $paragraph = absint($raw['paragraph'] ?? self::DEFAULT_PARAGRAPH);
            if ($paragraph < 1) {
                $paragraph = self::DEFAULT_PARAGRAPH;
            } elseif ($paragraph > 100) {
                $paragraph = 100;
            }
            $percent = absint($raw['percent'] ?? self::DEFAULT_PERCENT);
            if ($percent < 1) {
                $percent = self::DEFAULT_PERCENT;
            } elseif ($percent > 99) {
                $percent = 99;
            }
            $postTypes = self::sanitizePostTypes($raw['post_types'] ?? [], $publicPostTypes);
            $device = isset($raw['device']) && is_string($raw['device']) ? sanitize_key($raw['device']) : '';
            if (!in_array($device, PlacementEngine::DEVICES, true)) {
                $device = 'all';
            }
            $label = isset($raw['label']) && is_string($raw['label'])
                ? sanitize_text_field($raw['label'])
                : '';
            if (mb_strlen($label) > self::LABEL_MAX_LENGTH) {
                $label = mb_substr($label, 0, self::LABEL_MAX_LENGTH);
            }
            $rules[] = [
                'id' => $id,
                'enabled' => self::asBool($raw['enabled'] ?? true),
                'label' => $label,
                'ad_code' => $code,
                'placement' => $placement,
                'paragraph' => $paragraph,
                'percent' => $percent,
                'post_types' => $postTypes,
                'include_categories' => self::sanitizeTermIds($raw['include_categories'] ?? []),
                'exclude_categories' => self::sanitizeTermIds($raw['exclude_categories'] ?? []),
                'include_tags' => self::sanitizeTermIds($raw['include_tags'] ?? []),
                'exclude_tags' => self::sanitizeTermIds($raw['exclude_tags'] ?? []),
                'exclude_home_archive' => self::asBool($raw['exclude_home_archive'] ?? true),
                'device' => $device,
            ];
            if (count($rules) >= self::MAX_RULES) {
                break;
            }
        }
        return ['rules' => $rules, 'dropped' => $dropped];
    }

    /**
     * Apply the rules to post content.
     *
     * Runs at priority 12 on `the_content` (after wpautop and
     * do_shortcode, so paragraphs exist). Never recurses: a nested
     * application returns its content untouched. Never breaks the
     * page: any failure inside the engine leaves the content
     * unchanged. Feeds are skipped (ads do not belong in RSS).
     * The per-page budget and the per-post inserted-rule tracking
     * live in static state for the request.
     *
     * @param string $content Filtered post content.
     * @return string Content with ads inserted, or unchanged.
     */
    public static function filterContent(string $content): string
    {
        if (self::$filtering) {
            return $content;
        }
        if (function_exists('is_feed') && is_feed()) {
            return $content;
        }
        $postId = get_the_ID();
        if ($postId === false || $postId <= 0) {
            return $content;
        }
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return $content;
        }
        $settings = self::getSettings();
        if ($settings['rules'] === []) {
            return $content;
        }
        $context = [
            'post_type' => $post->post_type,
            'category_ids' => self::postTermIds($postId, 'category'),
            'tag_ids' => self::postTermIds($postId, 'post_tag'),
            'is_singular' => is_singular(),
            'is_home' => is_home(),
            'is_archive' => is_archive(),
            'is_search' => is_search(),
            'is_mobile' => wp_is_mobile(),
            'ads_remaining' => $settings['max_ads_per_page'] - self::$adsInsertedCount,
            'already_inserted' => self::$insertedRuleIdsByPost[$postId] ?? [],
        ];
        self::$filtering = true;
        try {
            $result = PlacementEngine::insertAds($content, $settings['rules'], $postId, $context);
        } catch (Throwable $exception) {
            error_log('TrayDigita Ads: placement failed for post ' . $postId . ': ' . $exception->getMessage());
            $result = ['content' => $content, 'inserted' => []];
        } finally {
            self::$filtering = false;
        }
        if ($result['inserted'] !== []) {
            self::$adsInsertedCount += count($result['inserted']);
            foreach ($result['inserted'] as $ruleId) {
                self::$insertedRuleIdsByPost[$postId][] = $ruleId;
            }
        }
        return $result['content'];
    }

    /**
     * Plain-language summary of one rule for the settings page.
     *
     * Example: "Shows after paragraph 3 on posts in News, on
     * desktop only. Not on home, archive, or search pages." A
     * disabled rule or a rule without ad code says so up front.
     *
     * @param array<string, mixed> $rule Normalized rule.
     * @return string
     */
    public static function summarizeRule(array $rule): string
    {
        if (!self::asBool($rule['enabled'] ?? true)) {
            return __('Disabled — this rule shows nothing until it is enabled.', 'traydigita-core');
        }
        if (!is_string($rule['ad_code'] ?? null) || $rule['ad_code'] === '') {
            return __('No ad code yet — this rule shows nothing until ad code is pasted in.', 'traydigita-core');
        }
        $placement = is_string($rule['placement'] ?? null) ? $rule['placement'] : '';
        $phrase = match ($placement) {
            PlacementEngine::PLACEMENT_BEFORE_CONTENT => __('Shows before the content', 'traydigita-core'),
            PlacementEngine::PLACEMENT_AFTER_PARAGRAPH => sprintf(
                /* translators: %d is the paragraph number. */
                __('Shows after paragraph %d', 'traydigita-core'),
                absint($rule['paragraph'] ?? self::DEFAULT_PARAGRAPH)
            ),
            PlacementEngine::PLACEMENT_AFTER_RANDOM_PARAGRAPH => __('Shows after a random paragraph (same spot for each post)', 'traydigita-core'),
            PlacementEngine::PLACEMENT_AFTER_PERCENT => sprintf(
                /* translators: %d is the percentage of the content. */
                __('Shows after %d%% of the content', 'traydigita-core'),
                absint($rule['percent'] ?? self::DEFAULT_PERCENT)
            ),
            PlacementEngine::PLACEMENT_BEFORE_LAST_PARAGRAPH => __('Shows before the last paragraph', 'traydigita-core'),
            PlacementEngine::PLACEMENT_AFTER_CONTENT => __('Shows after the content', 'traydigita-core'),
            default => __('Shows in the content', 'traydigita-core'),
        };
        $postTypes = is_array($rule['post_types'] ?? null) ? $rule['post_types'] : [];
        $typeNames = [];
        foreach ($postTypes as $type) {
            if (!is_string($type) || $type === '') {
                continue;
            }
            $typeNames[] = $type;
        }
        if ($typeNames !== []) {
            $phrase .= ' ' . sprintf(
                /* translators: %s is a comma-separated list of post type names. */
                __('on %s', 'traydigita-core'),
                implode(', ', $typeNames)
            );
        }
        $conditions = [];
        $includeCategories = self::termNames(
            is_array($rule['include_categories'] ?? null) ? $rule['include_categories'] : [],
            'category'
        );
        if ($includeCategories !== []) {
            $conditions[] = sprintf(
                /* translators: %s is a comma-separated list of category names. */
                __('in %s', 'traydigita-core'),
                implode(', ', $includeCategories)
            );
        }
        $excludeCategories = self::termNames(
            is_array($rule['exclude_categories'] ?? null) ? $rule['exclude_categories'] : [],
            'category'
        );
        if ($excludeCategories !== []) {
            $conditions[] = sprintf(
                /* translators: %s is a comma-separated list of category names. */
                __('except in %s', 'traydigita-core'),
                implode(', ', $excludeCategories)
            );
        }
        $includeTags = self::termNames(
            is_array($rule['include_tags'] ?? null) ? $rule['include_tags'] : [],
            'post_tag'
        );
        if ($includeTags !== []) {
            $conditions[] = sprintf(
                /* translators: %s is a comma-separated list of tag names. */
                __('tagged %s', 'traydigita-core'),
                implode(', ', $includeTags)
            );
        }
        $excludeTags = self::termNames(
            is_array($rule['exclude_tags'] ?? null) ? $rule['exclude_tags'] : [],
            'post_tag'
        );
        if ($excludeTags !== []) {
            $conditions[] = sprintf(
                /* translators: %s is a comma-separated list of tag names. */
                __('except when tagged %s', 'traydigita-core'),
                implode(', ', $excludeTags)
            );
        }
        $device = is_string($rule['device'] ?? null) ? $rule['device'] : 'all';
        if ($device === 'desktop') {
            $conditions[] = __('on desktop only', 'traydigita-core');
        } elseif ($device === 'mobile') {
            $conditions[] = __('on mobile only', 'traydigita-core');
        }
        if ($conditions !== []) {
            $phrase .= ' ' . implode(', ', $conditions);
        }
        $phrase .= '.';
        if (self::asBool($rule['exclude_home_archive'] ?? true)) {
            $phrase .= ' ' . __('Not on home, archive, or search pages.', 'traydigita-core');
        } else {
            $phrase .= ' ' . __('Also on home, archive, and search pages.', 'traydigita-core');
        }
        return $phrase;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Rendered inline (no template file): this track owns
     * src/Admin/Modules/Ads/ only. The settings form carries no
     * `data-traydigita-module-form` / `data-action` attributes on
     * purpose — the shell's generic handler would submit the raw
     * ad code through the dispatcher and mangle it; the module's
     * own JavaScript submits the form and base64-encodes the ad
     * code first (see the class docblock).
     *
     * The live preview section is shown only while the module is
     * enabled: the preview AJAX endpoint is registered from
     * boot(), like the SMTP test endpoint, so it does not exist
     * while the module is disabled. Rules can still be saved while
     * disabled.
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
        $postTypes = self::publicPostTypes();
        $categories = self::pickerTerms('category');
        $tags = self::pickerTerms('post_tag');
        $previewPosts = self::previewPosts();
        $moduleEnabled = ModuleRegistry::isEnabled(self::id());
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        echo '<div class="traydigita-ads-settings" data-ads-admin'
            . ' data-msg-saving="' . esc_attr(__('Saving…', 'traydigita-core')) . '"'
            . ' data-msg-saved="' . esc_attr(__('Ads settings saved.', 'traydigita-core')) . '"'
            . ' data-msg-save-error="' . esc_attr(__('Could not save the ads settings. Please try again.', 'traydigita-core')) . '"'
            . ' data-msg-preview-loading="' . esc_attr(__('Loading preview…', 'traydigita-core')) . '"'
            . ' data-msg-preview-error="' . esc_attr(__('Could not load the preview. Please try again.', 'traydigita-core')) . '"'
            . ' data-msg-preview-empty="' . esc_attr(__('No ads were placed on this post with the current rules.', 'traydigita-core')) . '"'
            . ' data-msg-confirm-remove="' . esc_attr(__('Remove this rule? Its ad code will be lost when you save.', 'traydigita-core')) . '"'
            . ' data-preview-action="' . esc_attr(AdsAjax::AJAX_ACTION) . '">';
        echo '<p class="description">' . SettingsControls::helpBlock(
            __('Insert ads inside article content.', 'traydigita-core'),
            __('The AdSense widget is for sidebars; this inserter is for in-article ads — after a paragraph, at a percentage of the article, or at a seeded-random spot that stays the same for each post.', 'traydigita-core')
        ) . '</p>';
        echo '<div class="traydigita-admin-notice traydigita-admin-notice-warning"><p>' . SettingsControls::helpBlock(
            __('Ad code is stored exactly as pasted and may contain scripts.', 'traydigita-core'),
            __('Only administrators can save it — the same trust level as the Custom HTML widget. Never paste code from a source you do not trust.', 'traydigita-core')
        ) . '</p></div>';
        echo '<form method="post" data-ads-settings-form>';
        echo '<input type="hidden" name="action" value="traydigita_admin_save">';
        echo '<input type="hidden" name="module" value="ads">';
        echo '<input type="hidden" name="_ajax_nonce" value="' . esc_attr($nonce) . '">';
        echo '<input type="hidden" name="ad_code_encoding" value="base64">';
        SettingsControls::sectionOpen('general', __('General', 'traydigita-core'));
        SettingsControls::number([
            'id' => 'ads-max-ads',
            'name' => 'max_ads_per_page',
            'label' => __('Maximum ads per page', 'traydigita-core'),
            'value' => (string) $settings['max_ads_per_page'],
            'help' => __('Shared across all rules on one page. Set to 0 to show no ads at all.', 'traydigita-core'),
            'attrs' => [
                'min' => '0',
                'max' => (string) self::MAX_ADS_PER_PAGE,
                'step' => '1',
            ],
        ]);
        SettingsControls::sectionClose();
        SettingsControls::sectionOpen(
            'rules',
            __('Rules', 'traydigita-core'),
            __('Each rule places one ad.', 'traydigita-core'),
            __('Rules run in order; when the per-page limit is reached, later rules are skipped. A rule whose target does not exist (for example paragraph 9 in a 4-paragraph article) shows nothing.', 'traydigita-core')
        );
        echo '<div data-ads-rules>';
        foreach ($settings['rules'] as $index => $rule) {
            echo self::renderRuleFields($index, $rule, $postTypes, $categories, $tags);
        }
        echo '</div>';
        echo '<template data-ads-rule-template>';
        echo self::renderRuleFields('__INDEX__', self::blankRule(), $postTypes, $categories, $tags);
        echo '</template>';
        echo '<p><button type="button" class="traydigita-admin-button" data-ads-add-rule>' . esc_html__('Add rule', 'traydigita-core') . '</button></p>';
        echo '<p class="description">' . esc_html__('Rule summaries refresh after saving and reloading the page.', 'traydigita-core') . '</p>';
        SettingsControls::sectionClose();
        SettingsControls::saveBar();
        echo '<p><span data-ads-save-status role="status" aria-live="polite"></span></p>';
        echo '</form>';
        SettingsControls::sectionOpen('preview', __('Live preview', 'traydigita-core'));
        if (!$moduleEnabled) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-warning"><p>'
                . SettingsControls::helpBlock(
                    __('Enable the Ads Inserter module from the Modules grid to '
                        . 'use the live preview.', 'traydigita-core'),
                    __('Rules can still be saved while the module is disabled.', 'traydigita-core')
                )
                . ' <a href="' . esc_attr(admin_url('admin.php?page=' . AdminMenu::MENU_SLUG)) . '">' . esc_html__('Back to Modules', 'traydigita-core') . '</a></p></div>';
        } elseif ($previewPosts === []) {
            echo '<p class="description">' . esc_html__('No published posts yet — publish a post first to preview ad placement on it.', 'traydigita-core') . '</p>';
        } else {
            echo '<form method="post" data-ads-preview-form>';
            echo '<input type="hidden" name="_ajax_nonce" value="' . esc_attr($nonce) . '">';
            $previewOptions = [];
            foreach ($previewPosts as $previewPost) {
                $previewOptions[$previewPost['id']] = $previewPost['title'];
            }
            SettingsControls::select([
                'id' => 'ads-preview-post',
                'name' => 'post_id',
                'label' => __('Preview on post', 'traydigita-core'),
                'value' => (string) $previewPosts[0]['id'],
                'options' => $previewOptions,
            ]);
            echo '<p class="traydigita-ads-form-actions">'
                . '<button class="traydigita-admin-button traydigita-admin-button-secondary" type="submit">' . esc_html__('Preview with current rules', 'traydigita-core') . '</button> '
                . '<span data-ads-preview-status role="status" aria-live="polite"></span>'
                . '</p>';
            echo '</form>';
            echo '<p class="description">' . SettingsControls::helpBlock(
                __('The preview renders the post with the rules from the form above, '
                    . 'including unsaved changes.', 'traydigita-core'),
                __('Scripts do not run inside the preview — it shows where the ads will appear, not the ads themselves.', 'traydigita-core')
            ) . '</p>';
            echo '<div class="traydigita-ads-preview" data-ads-preview-pane aria-live="polite"></div>';
        }
        SettingsControls::sectionClose();
        echo '</div>';
    }

    /**
     * Blank rule for the "add rule" template.
     *
     * @return array<string, mixed>
     */
    public static function blankRule(): array
    {
        return [
            'id' => '',
            'enabled' => true,
            'label' => '',
            'ad_code' => '',
            'placement' => PlacementEngine::PLACEMENT_AFTER_PARAGRAPH,
            'paragraph' => self::DEFAULT_PARAGRAPH,
            'percent' => self::DEFAULT_PERCENT,
            'post_types' => ['post'],
            'include_categories' => [],
            'exclude_categories' => [],
            'include_tags' => [],
            'exclude_tags' => [],
            'exclude_home_archive' => true,
            'device' => 'all',
        ];
    }

    /**
     * Generate a fresh stable rule id.
     *
     * Random (not sequential): two rules added in one save must
     * never share an id, and the id feeds the seeded-random
     * placement, so it must be stable once stored.
     *
     * @return string
     */
    private static function generateRuleId(): string
    {
        try {
            return 'rule_' . bin2hex(random_bytes(6));
        } catch (Throwable $exception) {
            return 'rule_' . substr((string) time(), -6) . bin2hex(random_bytes(4));
        }
    }

    /**
     * Read a submitted value as a bool.
     *
     * Accepts the checkbox values '1'/'0', real booleans and
     * integers; anything else is false.
     *
     * @param mixed $value Submitted value.
     * @return bool
     */
    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0' && $value !== 'false';
        }
        return false;
    }

    /**
     * Sanitize a submitted post-type allowlist.
     *
     * Keeps only public post types; an empty result falls back to
     * ['post'] so a rule can never silently apply nowhere.
     *
     * @param mixed $raw Submitted value.
     * @param array<int, string> $publicPostTypes Public post type slugs.
     * @return array<int, string>
     */
    private static function sanitizePostTypes(mixed $raw, array $publicPostTypes): array
    {
        $types = [];
        if (is_array($raw)) {
            foreach ($raw as $type) {
                if (!is_string($type)) {
                    continue;
                }
                $slug = sanitize_key($type);
                if ($slug !== '' && in_array($slug, $publicPostTypes, true) && !in_array($slug, $types, true)) {
                    $types[] = $slug;
                }
            }
        }
        if ($types === []) {
            $types = ['post'];
        }
        return $types;
    }

    /**
     * Sanitize a submitted term-id list (categories or tags).
     *
     * @param mixed $raw Submitted value.
     * @return array<int, int>
     */
    private static function sanitizeTermIds(mixed $raw): array
    {
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $id) {
                $termId = absint($id);
                if ($termId > 0 && !in_array($termId, $ids, true)) {
                    $ids[] = $termId;
                }
            }
        }
        return $ids;
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

    /**
     * Names of terms for a summary, falling back to "taxonomy #id".
     *
     * @param array<int, mixed> $ids Term ids.
     * @param string $taxonomy Taxonomy slug.
     * @return array<int, string>
     */
    private static function termNames(array $ids, string $taxonomy): array
    {
        $names = [];
        foreach ($ids as $id) {
            $termId = absint($id);
            if ($termId <= 0) {
                continue;
            }
            $term = get_term($termId, $taxonomy);
            if ($term instanceof WP_Term) {
                $names[] = $term->name;
                continue;
            }
            $names[] = sprintf(
                /* translators: 1: taxonomy label, 2: term id. */
                __('%1$s #%2$d', 'traydigita-core'),
                $taxonomy,
                $termId
            );
        }
        return $names;
    }

    /**
     * Public post types as slug => label, for the settings form.
     *
     * @return array<string, string>
     */
    private static function publicPostTypes(): array
    {
        $types = get_post_types(['public' => true], 'objects');
        $options = [];
        if (is_array($types)) {
            foreach ($types as $slug => $type) {
                if (!is_string($slug) || $slug === '') {
                    continue;
                }
                $label = '';
                if (is_object($type) && is_object($type->labels)) {
                    $label = is_string($type->labels->singular_name ?? null) ? (string) $type->labels->singular_name : '';
                }
                $options[$slug] = $label !== '' ? $label : $slug;
            }
        }
        if ($options === []) {
            $options = ['post' => 'Post', 'page' => 'Page'];
        }
        return $options;
    }

    /**
     * Terms for a picker: id => name, newest first, capped.
     *
     * @param string $taxonomy Taxonomy slug.
     * @return array<int, string>
     */
    private static function pickerTerms(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => self::TERM_PICKER_LIMIT,
        ]);
        $options = [];
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $options[$term->term_id] = $term->name;
                }
            }
        }
        return $options;
    }

    /**
     * Recent published posts for the preview picker: id + title.
     *
     * @return array<int, array{id: int, title: string}>
     */
    private static function previewPosts(): array
    {
        $posts = get_posts([
            'numberposts' => self::PREVIEW_POST_LIMIT,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $options = [];
        if (is_array($posts)) {
            foreach ($posts as $post) {
                if (!$post instanceof WP_Post) {
                    continue;
                }
                $title = trim($post->post_title);
                $options[] = [
                    'id' => $post->ID,
                    'title' => $title !== '' ? $title : sprintf(
                        /* translators: %d is the post id. */
                        __('(No title) #%d', 'traydigita-core'),
                        $post->ID
                    ),
                ];
            }
        }
        return $options;
    }

    /**
     * Render one rule's fieldset (stored rule or add-rule template).
     *
     * Field names are `rules[<index>][<field>]`; the template uses
     * the literal index `__INDEX__`, which the JavaScript replaces
     * with the next free index when cloning.
     *
     * @param int|string $index Rule index in the form, or '__INDEX__' for the template.
     * @param array<string, mixed> $rule Normalized rule.
     * @param array<string, string> $postTypes Post type slug => label.
     * @param array<int, string> $categories Category id => name.
     * @param array<int, string> $tags Tag id => name.
     * @return string Fieldset HTML.
     */
    private static function renderRuleFields(
        int|string $index,
        array $rule,
        array $postTypes,
        array $categories,
        array $tags
    ): string {
        $prefix = 'rules[' . $index . ']';
        $idPrefix = 'ads-rule-' . $index;
        $enabled = self::asBool($rule['enabled'] ?? true);
        $excludeHomeArchive = self::asBool($rule['exclude_home_archive'] ?? true);
        $placement = is_string($rule['placement'] ?? null) ? $rule['placement'] : PlacementEngine::PLACEMENT_AFTER_PARAGRAPH;
        $device = is_string($rule['device'] ?? null) ? $rule['device'] : 'all';
        $label = is_string($rule['label'] ?? null) ? $rule['label'] : '';
        $code = is_string($rule['ad_code'] ?? null) ? $rule['ad_code'] : '';
        $ruleId = is_string($rule['id'] ?? null) ? $rule['id'] : '';
        $paragraph = absint($rule['paragraph'] ?? self::DEFAULT_PARAGRAPH);
        $percent = absint($rule['percent'] ?? self::DEFAULT_PERCENT);
        $rulePostTypes = is_array($rule['post_types'] ?? null) ? $rule['post_types'] : ['post'];
        $html = '<fieldset class="traydigita-ads-rule" data-ads-rule data-ads-rule-index="' . esc_attr((string) $index) . '">';
        $html .= '<legend>' . esc_html(sprintf(
            /* translators: %s is the rule number. */
            __('Rule %s', 'traydigita-core'),
            (string) $index === '__INDEX__' ? '—' : (string) ((int) $index + 1)
        )) . '</legend>';
        if ((string) $index !== '__INDEX__') {
            $html .= '<p class="traydigita-ads-summary" data-ads-summary>' . esc_html(self::summarizeRule($rule)) . '</p>';
        }
        $html .= '<input type="hidden" name="' . esc_attr($prefix) . '[id]" value="' . esc_attr($ruleId) . '">';
        $html .= '<p class="traydigita-ads-field">'
            . '<label for="' . esc_attr($idPrefix) . '-label">' . esc_html__('Label', 'traydigita-core') . '</label><br>'
            . '<input type="text" id="' . esc_attr($idPrefix) . '-label" name="' . esc_attr($prefix) . '[label]" value="' . esc_attr($label) . '" class="traydigita-ui-input" maxlength="' . esc_attr((string) self::LABEL_MAX_LENGTH) . '">'
            . '<br><span class="description">' . esc_html__('Shown only here, to tell rules apart.', 'traydigita-core') . '</span>'
            . '</p>';
        $html .= '<p class="traydigita-ads-field">'
            . '<input type="hidden" name="' . esc_attr($prefix) . '[enabled]" value="0">'
            . '<input type="checkbox" class="traydigita-ui-checkbox" id="' . esc_attr($idPrefix) . '-enabled" name="' . esc_attr($prefix) . '[enabled]" value="1"' . ($enabled ? ' checked' : '') . '> '
            . '<label for="' . esc_attr($idPrefix) . '-enabled">' . esc_html__('Enabled', 'traydigita-core') . '</label>'
            . '</p>';
        $html .= '<p class="traydigita-ads-field">'
            . '<label for="' . esc_attr($idPrefix) . '-code">' . esc_html__('Ad code', 'traydigita-core') . '</label><br>'
            . '<textarea id="' . esc_attr($idPrefix) . '-code" name="' . esc_attr($prefix) . '[ad_code]" rows="4" class="traydigita-ui-textarea code" data-ads-code spellcheck="false">' . esc_textarea($code) . '</textarea>'
            . '<br><span class="description">' . SettingsControls::helpBlock(
                __('Paste the ad code here, for example an AdSense unit.', 'traydigita-core'),
                __('Scripts are kept as-is; only administrators can save this field.', 'traydigita-core')
            ) . '</span>'
            . '</p>';
        $html .= '<p class="traydigita-ads-field">'
            . '<label for="' . esc_attr($idPrefix) . '-placement">' . esc_html__('Placement', 'traydigita-core') . '</label><br>'
            . '<select class="traydigita-ui-select" id="' . esc_attr($idPrefix) . '-placement" name="' . esc_attr($prefix) . '[placement]" data-ads-placement>'
            . self::placementOptions($placement)
            . '</select></p>';
        $html .= '<p class="traydigita-ads-field" data-ads-paragraph-row>'
            . '<label for="' . esc_attr($idPrefix) . '-paragraph">' . esc_html__('After paragraph', 'traydigita-core') . '</label><br>'
            . '<input type="number" class="traydigita-ui-input" id="' . esc_attr($idPrefix) . '-paragraph" name="' . esc_attr($prefix) . '[paragraph]" value="' . esc_attr((string) $paragraph) . '" min="1" max="100" step="1">'
            . '</p>';
        $html .= '<p class="traydigita-ads-field" data-ads-percent-row>'
            . '<label for="' . esc_attr($idPrefix) . '-percent">' . esc_html__('After this much of the content (%)', 'traydigita-core') . '</label><br>'
            . '<input type="number" class="traydigita-ui-input" id="' . esc_attr($idPrefix) . '-percent" name="' . esc_attr($prefix) . '[percent]" value="' . esc_attr((string) $percent) . '" min="1" max="99" step="1">'
            . '</p>';
        $html .= '<p class="traydigita-ads-field">'
            . '<label for="' . esc_attr($idPrefix) . '-post-types">' . esc_html__('Post types', 'traydigita-core') . '</label><br>'
            . '<select class="traydigita-ui-select" id="' . esc_attr($idPrefix) . '-post-types" name="' . esc_attr($prefix) . '[post_types][]" multiple size="3">'
            . self::multiOptions($postTypes, $rulePostTypes)
            . '</select>'
            . '<br><span class="description">' . esc_html__('Hold Ctrl (or Cmd) to select more than one.', 'traydigita-core') . '</span>'
            . '</p>';
        $html .= self::termPicker($idPrefix, $prefix, 'include_categories', __('Include categories', 'traydigita-core'), $categories, $rule, __('Leave empty for all categories.', 'traydigita-core'));
        $html .= self::termPicker($idPrefix, $prefix, 'exclude_categories', __('Exclude categories', 'traydigita-core'), $categories, $rule, __('Leave empty to exclude nothing.', 'traydigita-core'));
        $html .= self::termPicker($idPrefix, $prefix, 'include_tags', __('Include tags', 'traydigita-core'), $tags, $rule, __('Leave empty for all tags.', 'traydigita-core'));
        $html .= self::termPicker($idPrefix, $prefix, 'exclude_tags', __('Exclude tags', 'traydigita-core'), $tags, $rule, __('Leave empty to exclude nothing.', 'traydigita-core'));
        $html .= '<p class="traydigita-ads-field">'
            . '<label for="' . esc_attr($idPrefix) . '-device">' . esc_html__('Device', 'traydigita-core') . '</label><br>'
            . '<select class="traydigita-ui-select" id="' . esc_attr($idPrefix) . '-device" name="' . esc_attr($prefix) . '[device]">'
            . self::deviceOptions($device)
            . '</select></p>';
        $html .= '<p class="traydigita-ads-field">'
            . '<input type="hidden" name="' . esc_attr($prefix) . '[exclude_home_archive]" value="0">'
            . '<input type="checkbox" class="traydigita-ui-checkbox" id="' . esc_attr($idPrefix) . '-exclude-home" name="' . esc_attr($prefix) . '[exclude_home_archive]" value="1"' . ($excludeHomeArchive ? ' checked' : '') . '> '
            . '<label for="' . esc_attr($idPrefix) . '-exclude-home">' . esc_html__('Only on single posts and pages (not on home, archive, or search pages)', 'traydigita-core') . '</label>'
            . '</p>';
        $html .= '<p><button type="button" class="traydigita-admin-button" data-ads-remove-rule>' . esc_html__('Remove rule', 'traydigita-core') . '</button></p>';
        $html .= '</fieldset>';
        return $html;
    }

    /**
     * Render one category/tag multi-select picker for a rule.
     *
     * @param string $idPrefix Id prefix for the rule's fields.
     * @param string $prefix Name prefix for the rule's fields.
     * @param string $field Field name (include_categories, exclude_categories, include_tags, exclude_tags).
     * @param string $label Field label.
     * @param array<int, string> $terms Term id => name.
     * @param array<string, mixed> $rule Normalized rule.
     * @param string $hint Hint shown under the picker.
     * @return string Field HTML.
     */
    private static function termPicker(
        string $idPrefix,
        string $prefix,
        string $field,
        string $label,
        array $terms,
        array $rule,
        string $hint
    ): string {
        $fieldId = $idPrefix . '-' . $field;
        $selected = is_array($rule[$field] ?? null) ? $rule[$field] : [];
        $html = '<p class="traydigita-ads-field">'
            . '<label for="' . esc_attr($fieldId) . '">' . esc_html($label) . '</label><br>';
        if ($terms === []) {
            $html .= '<select class="traydigita-ui-select" id="' . esc_attr($fieldId) . '" name="' . esc_attr($prefix) . '[' . esc_attr($field) . '][]" multiple size="1" disabled>'
                . '<option value="">' . esc_html__('None yet', 'traydigita-core') . '</option>'
                . '</select>';
        } else {
            $html .= '<select class="traydigita-ui-select" id="' . esc_attr($fieldId) . '" name="' . esc_attr($prefix) . '[' . esc_attr($field) . '][]" multiple size="5">'
                . self::multiOptions($terms, $selected)
                . '</select>';
        }
        $html .= '<br><span class="description">' . esc_html($hint) . '</span></p>';
        return $html;
    }

    /**
     * Placement <option> list.
     *
     * @param string $selected Selected placement value.
     * @return string
     */
    private static function placementOptions(string $selected): string
    {
        $labels = [
            PlacementEngine::PLACEMENT_BEFORE_CONTENT => __('Before the content', 'traydigita-core'),
            PlacementEngine::PLACEMENT_AFTER_PARAGRAPH => __('After paragraph…', 'traydigita-core'),
            PlacementEngine::PLACEMENT_AFTER_RANDOM_PARAGRAPH => __('After a random paragraph', 'traydigita-core'),
            PlacementEngine::PLACEMENT_AFTER_PERCENT => __('After …% of the content', 'traydigita-core'),
            PlacementEngine::PLACEMENT_BEFORE_LAST_PARAGRAPH => __('Before the last paragraph', 'traydigita-core'),
            PlacementEngine::PLACEMENT_AFTER_CONTENT => __('After the content', 'traydigita-core'),
        ];
        $html = '';
        foreach ($labels as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . ($value === $selected ? ' selected' : '') . '>'
                . esc_html($label) . '</option>';
        }
        return $html;
    }

    /**
     * Device <option> list.
     *
     * @param string $selected Selected device value.
     * @return string
     */
    private static function deviceOptions(string $selected): string
    {
        $labels = [
            'all' => __('All devices', 'traydigita-core'),
            'desktop' => __('Desktop only', 'traydigita-core'),
            'mobile' => __('Mobile only', 'traydigita-core'),
        ];
        $html = '';
        foreach ($labels as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . ($value === $selected ? ' selected' : '') . '>'
                . esc_html($label) . '</option>';
        }
        return $html;
    }

    /**
     * <option> list for a multi-select.
     *
     * @param array<int|string, string> $options Value => label.
     * @param array<int, mixed> $selected Selected values.
     * @return string
     */
    private static function multiOptions(array $options, array $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $valueString = (string) $value;
            $isSelected = false;
            foreach ($selected as $item) {
                if ((string) $item === $valueString) {
                    $isSelected = true;
                    break;
                }
            }
            $html .= '<option value="' . esc_attr($valueString) . '"' . ($isSelected ? ' selected' : '') . '>'
                . esc_html($label) . '</option>';
        }
        return $html;
    }
}
