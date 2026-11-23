<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Comments;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Comments\Providers\DisabledProvider;
use TrayDigita\WP\Plugin\Core\Modules\Comments\Providers\DisqusProvider;
use TrayDigita\WP\Plugin\Core\Modules\Comments\Providers\FacebookProvider;
use TrayDigita\WP\Plugin\Core\Modules\Comments\Providers\WordpressProvider;
use TrayDigita\WP\Plugin\Core\Modules\CookieConsent\CookieConsentModule;
use TrayDigita\WP\Plugin\Core\Modules\Integrations\IntegrationsModule;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function admin_url;
use function apply_filters;
use function checked;
use function class_exists;
use function comments_open;
use function current_user_can;
use function esc_attr;
use function esc_html_e;
use function get_comments_number;
use function get_option;
use function get_post;
use function in_array;
use function is_array;
use function is_singular;
use function is_string;
use function ob_get_clean;
use function ob_start;
use function post_password_required;
use function sanitize_key;
use function sanitize_text_field;
use function selected;
use function update_option;
use function wp_nonce_field;
use function wp_unslash;

/**
 * Comments module.
 *
 * Renders the comment area below articles and pages, with a
 * pluggable provider model. The provider (native WordPress
 * comments, Facebook Comments, Disqus, or totally disabled),
 * the scope (articles, pages, or both), and the follow-setting
 * toggle live on the module settings page. The module ships
 * DISABLED by default: nothing renders until the admin enables
 * it.
 *
 * Providers register through the `traydigita:core:comments/providers`
 * filter (see providers()): built-ins and third-party providers
 * go through the same registry, and the module never
 * special-cases a provider id for rendering. Each provider
 * describes its own settings fields (see
 * CommentProviderInterface::getSettings()), decides whether it
 * can render (isConfigured()), and renders + enqueues itself.
 *
 * Facebook and Disqus comments are stored and moderated by
 * their own services, never in this site's database. Without
 * their credential (Facebook App ID, Disqus shortname) the
 * provider renders nothing at all, and the settings page shows
 * an admin-only notice. When Cookie Consent is active, the
 * Facebook widget only loads after the visitor accepts cookies.
 *
 * The 'disabled' provider renders nothing anywhere and also
 * filters `comments_open` and `pings_open` to false, so
 * WordPress itself stops accepting comments and pingbacks too;
 * switching back to another provider restores normal behaviour.
 *
 * Settings live in the single option `traydigita_comments`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type. Secrets (the
 * Facebook App ID) are stored like the Turnstile secret: the
 * settings form renders an always-empty password field, an
 * empty submission keeps the saved value, and the value is
 * never printed, logged, or returned by any endpoint.
 */
final class CommentsModule implements ModuleInterface
{
    /**
     * Option name for all comment settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_comments';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_comments_save';

    /**
     * Filter exposing the provider class list to child themes and plugins.
     *
     * Built from Core::HOOK_PREFIX so a custom hook prefix applies.
     */
    public const string PROVIDERS_FILTER = Core::HOOK_PREFIX . ':comments/providers';

    /**
     * Allowed values for the scope setting.
     *
     * @var array<int, string>
     */
    public const array SHOW_ON_VALUES = ['articles', 'pages', 'both'];

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'comments';
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
        return 'content';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Comments', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Comment area for articles and pages with pluggable providers: native WordPress comments, Facebook Comments, Disqus, or totally disabled. Disabled by default — enable it to show comments.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'admin-comments';
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
            __('Comment area for articles and pages.', 'traydigita-core'),
            __('Pluggable providers: native WordPress, Facebook Comments, or Disqus.', 'traydigita-core'),
            __('Can be disabled entirely.', 'traydigita-core'),
            __('Disabled by default.', 'traydigita-core'),
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
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-comments';
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
     * The totally-disabled provider counts as configured: the
     * admin deliberately chose to hide comments, so no setup is
     * missing. Otherwise the active provider decides (Facebook
     * needs an App ID, Disqus needs a shortname).
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        if ($settings['provider'] === 'disabled') {
            return true;
        }
        $class = self::getProvider($settings['provider']);
        if ($class === null) {
            return false;
        }
        return $class::isConfigured($settings);
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * All known provider classes, filtered for child themes and plugins.
     *
     * Entries that do not exist, are not strings, or do not
     * implement CommentProviderInterface are dropped. Duplicate
     * ids are dropped (first class wins). A child theme or plugin
     * registers a provider by appending its class name to the
     * `traydigita:core:comments/providers` filter:
     *
     *     add_filter('traydigita:core:comments/providers', function (array $providers): array {
     *         $providers[] = MyCommentProvider::class;
     *         return $providers;
     *     });
     *
     * @return array<string, class-string<CommentProviderInterface>> Provider id => class.
     */
    public static function providers(): array
    {
        $classes = apply_filters(self::PROVIDERS_FILTER, [
            WordpressProvider::class,
            FacebookProvider::class,
            DisqusProvider::class,
            DisabledProvider::class,
        ]);
        if (!is_array($classes)) {
            return [];
        }
        $out = [];
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, CommentProviderInterface::class)) {
                continue;
            }
            $id = $class::id();
            if ($id === '' || isset($out[$id])) {
                continue;
            }
            $out[$id] = $class;
        }
        return $out;
    }

    /**
     * Find a provider class by its id, or null when unknown.
     *
     * @param string $id Provider id.
     * @return class-string<CommentProviderInterface>|null
     */
    public static function getProvider(string $id): ?string
    {
        $providers = self::providers();
        return $providers[$id] ?? null;
    }

    /**
     * Whether the comment area may appear for this post.
     *
     * Requires a singular view, a post type inside the scope
     * setting, and no password protection. When the
     * follow-post-setting toggle is on and the post itself does
     * not allow comments, only the WordPress provider may still
     * show existing comments (standard WordPress behaviour);
     * other providers show nothing.
     *
     * @param \WP_Post $post Post to check.
     * @return bool
     */
    public static function isVisibleForPost(\WP_Post $post): bool
    {
        if (!is_singular()) {
            return false;
        }
        $settings = self::getSettings();
        $showOn = $settings['show_on'];
        if ($showOn === 'articles' && $post->post_type !== 'post') {
            return false;
        }
        if ($showOn === 'pages' && $post->post_type !== 'page') {
            return false;
        }
        if ($showOn === 'both' && !in_array($post->post_type, ['post', 'page'], true)) {
            return false;
        }
        if (post_password_required($post)) {
            return false;
        }
        if ($settings['follow_post_setting'] && !comments_open($post)) {
            if ($settings['provider'] !== 'wordpress' || get_comments_number($post) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the Facebook widget may load for this visitor.
     *
     * True when Cookie Consent is not active, or when the visitor
     * accepted cookies. The consent cookie holds `accepted` or
     * `declined`; only `accepted` loads the widget.
     *
     * @return bool
     */
    public static function hasConsentForFacebook(): bool
    {
        if (!class_exists(CookieConsentModule::class) || !ModuleRegistry::isEnabled('cookie-consent')) {
            return true;
        }
        $cookie = $_COOKIE[CookieConsentModule::COOKIE_NAME] ?? null;
        if (!is_string($cookie)) {
            return false;
        }
        return sanitize_key(wp_unslash($cookie)) === 'accepted';
    }

    /**
     * Render the comment area for a post, or an empty string when it must not show.
     *
     * The gate is: module enabled, post inside the scope, no
     * password protection, the follow-setting rule, and a
     * provider that reports itself configured. The active
     * provider renders into an output buffer so this method
     * returns markup (the footer template echoes it).
     *
     * @param \WP_Post|null $post Post to render for; the current post when null.
     * @return string
     */
    public static function render(?\WP_Post $post = null): string
    {
        $post ??= get_post();
        if (!$post instanceof \WP_Post) {
            return '';
        }
        if (!ModuleRegistry::isEnabled(self::id())) {
            return '';
        }
        if (!self::isVisibleForPost($post)) {
            return '';
        }
        $settings = self::getSettings();
        $class = self::getProvider($settings['provider']);
        if ($class === null) {
            return '';
        }
        if (!$class::isConfigured($settings)) {
            return '';
        }
        ob_start();
        $class::render($post);
        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Provider select (populated from the registry), scope select,
     * follow-setting toggle, and one field group per provider —
     * each group is shown only while its provider is selected
     * (toggled by resources/js/modules/admin-comments.ts). The
     * form is submitted through the generic module-settings AJAX
     * dispatcher (see `data-traydigita-module-form`).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        $providers = self::providers();
        $provider = $settings['provider'];
        ?>
        <div class="traydigita-module-settings comments-settings" data-comments-admin>
            <?php
            SettingsControls::intro(
                __('Choose which comment system shows below articles and pages.', 'traydigita-core'),
                __('WordPress comments are stored on this site; Facebook and Disqus comments live on their own services. The module is disabled by default — enable it first, or nothing renders.', 'traydigita-core')
            );
            ?>

            <?php if ($provider === 'facebook' && $settings['facebook_app_id'] === '') : ?>
                <p class="comments-notice comments-notice-warning">
                    <?php esc_html_e('Facebook comments need an App ID. Until one is saved, no comment area renders on the site.', 'traydigita-core'); ?>
                </p>
            <?php endif; ?>
            <?php if ($provider === 'disqus' && $settings['disqus_shortname'] === '') : ?>
                <p class="comments-notice comments-notice-warning">
                    <?php esc_html_e('Disqus comments need a shortname. Until one is saved, no comment area renders on the site.', 'traydigita-core'); ?>
                </p>
            <?php endif; ?>

            <form class="comments-settings-form" data-traydigita-module-form data-traydigita-module="comments" data-module="comments" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="comments-field">
                    <label for="comments-provider"><?php esc_html_e('Provider', 'traydigita-core'); ?></label>
                    <select id="comments-provider" name="provider" data-comments-provider-select>
                        <?php foreach ($providers as $id => $class) : ?>
                            <option value="<?php echo esc_attr($id); ?>" <?php selected($provider, $id); ?>><?php echo esc_html($class::label()); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="comments-field-help">
                        <?php esc_html_e('Developers can register extra providers through the traydigita:core:comments/providers filter.', 'traydigita-core'); ?>
                    </span>
                </p>

                <?php foreach ($providers as $id => $class) : ?>
                    <div data-comments-provider-fields="<?php echo esc_attr($id); ?>" <?php echo $provider !== $id ? 'hidden' : ''; ?>>
                        <p class="comments-field-help"><?php
                            [$commentsShort, $commentsLong] = SettingsControls::splitHelp($class::description());
                            SettingsControls::helpText($commentsShort, $commentsLong);
                        ?></p>
                        <?php foreach ($class::getSettings() as $key => $field) : ?>
                            <?php self::renderField($key, $field, $settings[$key] ?? ($field['default'] ?? '')); ?>
                        <?php endforeach; ?>
                        <?php if ($id === 'facebook') : ?>
                            <p class="comments-field-help">
                                <?php
                                SettingsControls::helpText(
                                    __('Comments are moderated in Facebook’s moderation tools.', 'traydigita-core'),
                                    __('When Cookie Consent is active, the widget only loads after the visitor accepts cookies.', 'traydigita-core')
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($id === 'disqus') : ?>
                            <p class="comments-field-help">
                                <?php esc_html_e('Comments are moderated in Disqus’s moderation tools.', 'traydigita-core'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <p class="comments-field">
                    <label for="comments-show-on"><?php esc_html_e('Show on', 'traydigita-core'); ?></label>
                    <select id="comments-show-on" name="show_on">
                        <option value="articles" <?php selected($settings['show_on'], 'articles'); ?>><?php esc_html_e('Articles only', 'traydigita-core'); ?></option>
                        <option value="pages" <?php selected($settings['show_on'], 'pages'); ?>><?php esc_html_e('Pages only', 'traydigita-core'); ?></option>
                        <option value="both" <?php selected($settings['show_on'], 'both'); ?>><?php esc_html_e('Articles & pages', 'traydigita-core'); ?></option>
                    </select>
                </p>

                <p class="comments-field">
                    <input type="hidden" name="follow_post_setting" value="0">
                    <label class="traydigita-ui-toggle" for="comments-follow-post-setting"><input class="traydigita-ui-toggle-input" type="checkbox" id="comments-follow-post-setting" name="follow_post_setting" value="1" <?php checked($settings['follow_post_setting']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Follow post setting', 'traydigita-core'); ?></span></label>
                    <span class="comments-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('When on, the comment area only shows when the post or page itself allows '
                                . 'comments (the “Allow comments” checkbox).', 'traydigita-core'),
                            __('When off, the area shows whenever the provider and scope allow it.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="comments-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render one provider settings field.
     *
     * Field types: text, password (always rendered empty, like
     * the Turnstile secret — an empty submission keeps the saved
     * value), number (clamped on save), select, checkbox.
     *
     * @param string $key Option key (field name).
     * @param array{type: string, label: string, help?: string, default?: mixed, options?: array<string, string>, min?: int, max?: int, placeholder?: string, sanitize?: string} $field Field definition.
     * @param mixed $value Current value.
     * @return void
     */
    private static function renderField(string $key, array $field, mixed $value): void
    {
        $id = 'comments-' . sanitize_key($key);
        $type = $field['type'] ?? 'text';
        $label = isset($field['label']) && is_string($field['label']) ? $field['label'] : $key;
        $help = isset($field['help']) && is_string($field['help']) ? $field['help'] : '';
        $placeholder = isset($field['placeholder']) && is_string($field['placeholder']) ? $field['placeholder'] : '';
        if ($type === 'checkbox') {
            ?>
            <p class="comments-field">
                <input type="hidden" name="<?php echo esc_attr($key); ?>" value="0">
                <label class="comments-toggle" for="<?php echo esc_attr($id); ?>">
                    <input class="traydigita-ui-checkbox" type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>" value="1" <?php checked(!empty($value)); ?>>
                    <?php echo esc_html($label); ?>
                </label>
                <?php if ($help !== '') : ?>
                    <span class="comments-field-help"><?php
                    [$commentsShort, $commentsLong] = SettingsControls::splitHelp($help);
                    SettingsControls::helpText($commentsShort, $commentsLong);
                    ?></span>
                <?php endif; ?>
            </p>
            <?php
            return;
        }
        if ($type === 'select') {
            $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
            $current = is_string($value) ? $value : '';
            ?>
            <p class="comments-field">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>">
                    <?php foreach ($options as $optionValue => $optionLabel) : ?>
                        <option value="<?php echo esc_attr($optionValue); ?>" <?php selected($current, $optionValue); ?>><?php echo esc_html($optionLabel); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($help !== '') : ?>
                    <span class="comments-field-help"><?php
                    [$commentsShort, $commentsLong] = SettingsControls::splitHelp($help);
                    SettingsControls::helpText($commentsShort, $commentsLong);
                    ?></span>
                <?php endif; ?>
            </p>
            <?php
            return;
        }
        if ($type === 'number') {
            $number = is_numeric($value) ? (int) $value : (int) ($field['default'] ?? 0);
            $min = isset($field['min']) ? (int) $field['min'] : 0;
            $max = isset($field['max']) ? (int) $field['max'] : 0;
            ?>
            <p class="comments-field">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                <input type="number" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr((string) $number); ?>" min="<?php echo esc_attr((string) $min); ?>" <?php echo $max > 0 ? 'max="' . esc_attr((string) $max) . '"' : ''; ?>>
                <?php if ($help !== '') : ?>
                    <span class="comments-field-help"><?php
                    [$commentsShort, $commentsLong] = SettingsControls::splitHelp($help);
                    SettingsControls::helpText($commentsShort, $commentsLong);
                    ?></span>
                <?php endif; ?>
            </p>
            <?php
            return;
        }
        $isPassword = $type === 'password';
        $hasValue = is_string($value) && $value !== '';
        $shownPlaceholder = $isPassword && $hasValue
            ? __('•••••••• (saved)', 'traydigita-core')
            : $placeholder;
        // Password fields always render empty (an empty submission
        // keeps the saved value); text fields show their value.
        $shownValue = $isPassword ? '' : (is_string($value) ? $value : '');
        ?>
        <p class="comments-field">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <input
                type="<?php echo $isPassword ? 'password' : 'text'; ?>"
                id="<?php echo esc_attr($id); ?>"
                name="<?php echo esc_attr($key); ?>"
                value="<?php echo esc_attr($shownValue); ?>"
                <?php echo $shownPlaceholder !== '' ? 'placeholder="' . esc_attr($shownPlaceholder) . '"' : ''; ?>
                <?php echo $isPassword ? 'autocomplete="new-password"' : ''; ?>
            >
            <?php if ($help !== '') : ?>
                <span class="comments-field-help"><?php
                    [$commentsShort, $commentsLong] = SettingsControls::splitHelp($help);
                    SettingsControls::helpText($commentsShort, $commentsLong);
                ?></span>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys: `provider` (allowlisted against the
     * registry), `show_on` (allowlisted), `follow_post_setting`
     * (checkbox), plus one key per registered provider field,
     * sanitized by its declared type. A password field left empty
     * keeps the saved value (Turnstile pattern). Unknown keys are
     * dropped. Secrets are never included in the returned array.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change comment settings.', 'traydigita-core'),
            ];
        }
        $stored = self::getSettings();
        $provider = isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : '';
        if (self::getProvider($provider) === null) {
            $provider = 'wordpress';
        }
        $showOn = isset($data['show_on']) && is_string($data['show_on']) ? $data['show_on'] : '';
        if (!in_array($showOn, self::SHOW_ON_VALUES, true)) {
            $showOn = 'articles';
        }
        $settings = [
            'provider' => $provider,
            'show_on' => $showOn,
            'follow_post_setting' => !empty($data['follow_post_setting']),
        ];
        foreach (self::providers() as $class) {
            foreach ($class::getSettings() as $key => $field) {
                $settings[$key] = self::sanitizeField($key, $data[$key] ?? null, $field, $stored[$key] ?? ($field['default'] ?? ''));
            }
        }
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Comment settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Sanitize one provider field value by its declared type.
     *
     * Password fields are the exception: an empty submission
     * keeps the previously stored value, so a saved secret is
     * never wiped by submitting the form untouched.
     *
     * @param string $key Option key.
     * @param mixed $value Submitted value (null when absent).
     * @param array{type: string, label: string, help?: string, default?: mixed, options?: array<string, string>, min?: int, max?: int, placeholder?: string, sanitize?: string} $field Field definition.
     * @param mixed $stored Previously stored value.
     * @return mixed Sanitized value.
     */
    private static function sanitizeField(string $key, mixed $value, array $field, mixed $stored): mixed
    {
        $type = $field['type'] ?? 'text';
        if ($type === 'password') {
            if (!is_string($value) || $value === '') {
                return is_string($stored) ? $stored : '';
            }
            return sanitize_text_field($value);
        }
        if ($type === 'number') {
            $number = absint($value);
            if (isset($field['min']) && $number < (int) $field['min']) {
                $number = (int) $field['min'];
            }
            if (isset($field['max']) && $number > (int) $field['max']) {
                $number = (int) $field['max'];
            }
            return $number;
        }
        if ($type === 'select') {
            $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
            $text = is_string($value) ? $value : '';
            return isset($options[$text]) ? $text : (string) ($field['default'] ?? '');
        }
        if ($type === 'checkbox') {
            return !empty($value);
        }
        if (($field['sanitize'] ?? '') === 'key') {
            return sanitize_key(is_string($value) ? $value : '');
        }
        return is_string($value) ? sanitize_text_field($value) : '';
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The totally-disabled provider filters
     * `comments_open` and `pings_open` to false (so WordPress
     * itself stops accepting comments and pingbacks); any other
     * provider enqueues its scripts from `wp_enqueue_scripts`
     * through enqueueProvider(). The comment area itself renders
     * explicitly from layouts/entry/footer.php — no content
     * filter is registered, so it can never appear twice.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        if (self::getSettings()['provider'] === 'disabled') {
            add_filter('comments_open', '__return_false', 20);
            add_filter('pings_open', '__return_false', 20);
            return;
        }
        add_action('wp_enqueue_scripts', [self::class, 'enqueueProvider']);
    }

    /**
     * Register the comments entry on the Integrations hub.
     *
     * Registered centrally by IntegrationsModule::registerEntries()
     * (not from boot(), so the entry appears even while this
     * module is disabled). One entry for the selected provider,
     * reusing getSettings() and the provider's own isConfigured():
     * native WordPress comments are 'active' (they need no
     * credentials), Facebook is 'active' when its App ID is saved
     * and Disqus when its shortname is saved, otherwise
     * 'not-configured'. A disabled provider is 'not-configured'
     * with a description saying so, and a disabled module is
     * 'module-disabled' — the entry appears in both cases because
     * registration is central, not in boot().
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the comments entry appended.
     */
    public static function registerIntegrationEntry(array $entries): array
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            $entries[] = [
                'id' => 'comments',
                'name' => 'Comments',
                'description' => __(
                    'The Comments module is disabled — enable it in Modules to use comment providers.',
                    'traydigita-core'
                ),
                'status' => 'module-disabled',
                'url' => IntegrationsModule::modulesUrl(),
                'module' => self::id(),
                'icon' => 'comment',
            ];
            return $entries;
        }
        $settings = self::getSettings();
        $provider = $settings['provider'];
        $class = self::getProvider($provider);
        if ($provider === 'disabled' || $class === null) {
            $entries[] = [
                'id' => 'comments',
                'name' => 'Comments',
                'description' => __('Comments are disabled.', 'traydigita-core'),
                'status' => 'not-configured',
                'url' => admin_url('admin.php?page=' . self::settingsPageSlug()),
                'module' => self::id(),
                'icon' => 'comment',
            ];
            return $entries;
        }
        $configured = $class::isConfigured($settings);
        $description = match ($provider) {
            'facebook' => $configured
                ? __('Facebook Comments are live.', 'traydigita-core')
                : __('Facebook Comments are selected — save the Facebook App ID to switch them on.', 'traydigita-core'),
            'disqus' => $configured
                ? __('Disqus comments are live.', 'traydigita-core')
                : __('Disqus is selected — save the Disqus shortname to switch it on.', 'traydigita-core'),
            default => __('Native WordPress comments are live.', 'traydigita-core'),
        };
        $entries[] = [
            'id' => 'comments',
            'name' => $class::label(),
            'description' => $description,
            'status' => $configured ? 'active' : 'not-configured',
            'url' => admin_url('admin.php?page=' . self::settingsPageSlug()),
            'module' => self::id(),
            'icon' => 'comment',
        ];
        return $entries;
    }

    /**
     * Enqueue the active provider's scripts.
     *
     * The provider itself decides whether anything is needed on
     * this request (see CommentProviderInterface::enqueue()).
     *
     * @return void
     */
    public static function enqueueProvider(): void
    {
        $class = self::getProvider(self::getSettings()['provider']);
        if ($class === null) {
            return;
        }
        $class::enqueue();
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * Defaults come from the module (provider, scope,
     * follow-setting) plus every registered provider's field
     * defaults, so a newly registered provider's fields exist
     * before their first save.
     *
     * The returned array includes the Facebook App ID; callers
     * must never print it (the settings form renders the field
     * empty, like the Turnstile secret).
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $provider = isset($settings['provider']) && is_string($settings['provider']) ? $settings['provider'] : '';
        if (self::getProvider($provider) === null) {
            $provider = 'wordpress';
        }
        $showOn = isset($settings['show_on']) && is_string($settings['show_on']) ? $settings['show_on'] : '';
        if (!in_array($showOn, self::SHOW_ON_VALUES, true)) {
            $showOn = 'articles';
        }
        $settings['provider'] = $provider;
        $settings['show_on'] = $showOn;
        $settings['follow_post_setting'] = (bool) ($settings['follow_post_setting'] ?? true);
        foreach (self::providers() as $class) {
            foreach ($class::getSettings() as $key => $field) {
                $settings[$key] = self::sanitizeField($key, $settings[$key] ?? ($field['default'] ?? ''), $field, $settings[$key] ?? '');
            }
        }
        return $settings;
    }

    /**
     * Default settings.
     *
     * Provider WordPress, articles only, follow the post's own
     * comment setting — plus every registered provider's field
     * defaults.
     *
     * @return array<string, mixed>
     */
    public static function getDefaults(): array
    {
        $defaults = [
            'provider' => 'wordpress',
            'show_on' => 'articles',
            'follow_post_setting' => true,
        ];
        foreach (self::providers() as $class) {
            foreach ($class::getSettings() as $key => $field) {
                $defaults[$key] = $field['default'] ?? '';
            }
        }
        return $defaults;
    }
}
