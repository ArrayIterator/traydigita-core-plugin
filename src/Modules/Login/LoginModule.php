<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Login;

use Throwable;
use function __;
use function add_action;
use function add_filter;
use function admin_url;
use function current_user_can;
use function esc_html__;
use function esc_url;
use function file_exists;
use function get_bloginfo;
use function is_string;
use function wp_add_inline_style;
use function wp_create_nonce;
use function wp_die;
use function wp_enqueue_media;
use function wp_enqueue_script;
use function wp_enqueue_style;

/**
 * Custom Login Page Builder module.
 *
 * Implements the module contract defined by the admin shell
 * (\TrayDigita\WP\Plugin\Core\Admin\ModuleInterface). The module stores one
 * design as the published 'login' row in the `traydigita_templates` table
 * (see Designer) and applies it to wp-login.php only while enabled:
 * generated CSS via `login_head`, the logo link/text via
 * `login_headerurl` / `login_headertext`, and the welcome text and
 * social links via the `login_message` filter.
 *
 * The login form itself is never replaced or intercepted: no
 * JavaScript runs on wp-login.php, no field is added or required,
 * and every rendering callback is wrapped in try/catch so any
 * failure (or an invalid stored design) falls back silently to the
 * WordPress default login page.
 *
 * Note: ModuleInterface is owned by the admin shell agent and does
 * not exist in the tree yet at the time this file was written. This
 * class is written against the agreed contract; the coordinator
 * verifies the interface matches.
 */
final class LoginModule implements \TrayDigita\WP\Plugin\Core\Admin\ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'login';

    /**
     * Nonce action for the module's own save requests.
     *
     * The shell's generic save endpoint uses `traydigita-admin`;
     * this nonce is printed into the builder markup so the builder
     * JavaScript can save directly when the shell endpoint is not
     * yet wired, and so the shell can read it from the DOM.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Whether the generated stylesheet was already attached to the
     * `login` handle (or printed) for this request, so it is never
     * emitted twice.
     */
    private static bool $styleEmitted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return self::MODULE_ID;
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
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Custom Login Page Builder', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Design the WordPress login page with drag-and-drop blocks: logo, background, welcome text, social links and custom CSS.',
            'traydigita-core'
        );
    }

    /**
     * Dashicon slug for the module grid card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'admin-users';
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
            __('Design the login page with drag-and-drop blocks.', 'traydigita-core'),
            __('Logo, background, welcome text, and social links.', 'traydigita-core'),
            __('Custom CSS support.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here - never invent earlier versions.
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
     * Admin page slug for this module's settings page.
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
        return 'traydigita-login';
    }

    /**
     * Whether the module can run (it has no hard dependencies).
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether a valid saved design exists.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        try {
            return Designer::getSavedDesign() !== null;
        } catch (Throwable) {
            return false;
        }
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
            ['id' => 'designer', 'label' => __('Login designer', 'traydigita-core'), 'icon' => 'admin-appearance'],
        ];
    }

    /**
     * Render the builder UI inside the admin shell content area.
     *
     * Enqueues the media library (logo/background pickers) and the
     * colour picker, then renders the builder partial. The shell
     * owns the page chrome and the `admin` Vite entry; the builder
     * JavaScript is initialized from that entry through the
     * `window.TrayDigitaLoginBuilder.init()` global.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        $design = Designer::getDesign();
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/login.php';
        if (!file_exists($partial)) {
            wp_die(esc_html__('The login builder template is missing.', 'traydigita-core'));
        }
        require $partial;
    }

    /**
     * Save a design submitted by the shell's generic save endpoint.
     *
     * The payload is either the design array itself, an array wrapping
     * it under a `design` key, a JSON string, or a base64-encoded
     * JSON string (see Designer::sanitizeDesign() for why the base64
     * shape exists). Everything is re-validated and deeply sanitized
     * server-side by Designer::sanitizeDesign(); the client is never
     * trusted. The whole design is stored as the published row of
     * its type in the templates table.
     *
     * @param array<string, mixed> $data Submitted data.
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
        try {
            $result = Designer::sanitizeDesign($data);
        } catch (Throwable) {
            return [
                'success' => false,
                'message' => __('The login design could not be saved.', 'traydigita-core'),
            ];
        }
        if ($result['design'] === null) {
            return [
                'success' => false,
                'message' => $result['error'] ?? __('The login design is invalid.', 'traydigita-core'),
            ];
        }
        if (!Designer::saveDesign($result['design'])) {
            return [
                'success' => false,
                'message' => __('The login design is invalid.', 'traydigita-core'),
            ];
        }
        return [
            'success' => true,
            'message' => __('Login design saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the login-page hooks. Called only when the module is enabled.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_action('login_enqueue_scripts', [self::class, 'enqueueLoginStyles']);
        add_action('login_head', [self::class, 'printLoginStyles']);
        add_filter('login_headerurl', [self::class, 'filterLoginHeaderUrl']);
        add_filter('login_headertext', [self::class, 'filterLoginHeaderText']);
        add_filter('login_message', [self::class, 'filterLoginMessage']);
    }

    /**
     * Attach the generated stylesheet to the `login` style handle.
     *
     * Runs on `login_enqueue_scripts`, after wp-login.php enqueues
     * its own `login` stylesheet. Falls back to printing the style
     * in `login_head` when the handle is not enqueued.
     *
     * @return void
     */
    public static function enqueueLoginStyles(): void
    {
        try {
            $design = Designer::getDesign();
            $css = Designer::buildCss($design);
            if ($css === '') {
                return;
            }
            if (!wp_add_inline_style('login', $css)) {
                // The handle is not registered (yet); login_head prints it.
                return;
            }
            self::$styleEmitted = true;
        } catch (Throwable) {
            // Never break wp-login.php: fall back to the default login.
            return;
        }
    }

    /**
     * Print the generated stylesheet in `<head>` on wp-login.php.
     *
     * Skipped when the stylesheet was already attached to the
     * `login` handle by enqueueLoginStyles(). The CSS comes only
     * from Designer::buildCss(), which emits values re-validated
     * from the sanitized design, so it is safe to print verbatim.
     *
     * @return void
     */
    public static function printLoginStyles(): void
    {
        try {
            if (self::$styleEmitted) {
                return;
            }
            $design = Designer::getDesign();
            $css = Designer::buildCss($design);
            if ($css === '') {
                return;
            }
            self::$styleEmitted = true;
            echo '<style id="traydigita-login-css">' . $css . '</style>';
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Logo link URL for the login page header.
     *
     * Uses the logo block's link URL when set, otherwise the site
     * home URL (the WordPress default).
     *
     * @param string $url Default URL.
     * @return string
     */
    public static function filterLoginHeaderUrl(string $url): string
    {
        try {
            $logo = Designer::blockSettings(Designer::getDesign(), 'logo');
            $link = is_string($logo['link_url'] ?? null) ? $logo['link_url'] : '';
            return $link !== '' ? esc_url($link) : $url;
        } catch (Throwable) {
            return $url;
        }
    }

    /**
     * Logo link text for the login page header.
     *
     * Uses the logo block's alt text when set, otherwise the site
     * name (the WordPress default is the site title).
     *
     * @param string $text Default text.
     * @return string
     */
    public static function filterLoginHeaderText(string $text): string
    {
        try {
            $logo = Designer::blockSettings(Designer::getDesign(), 'logo');
            $alt = is_string($logo['alt'] ?? null) ? $logo['alt'] : '';
            if ($alt !== '') {
                return $alt;
            }
            $name = get_bloginfo('name');
            return is_string($name) && $name !== '' ? $name : $text;
        } catch (Throwable) {
            return $text;
        }
    }

    /**
     * Prepend the welcome text and social links above the login form.
     *
     * The `login_message` filter runs before the form is printed;
     * returning the extra markup here keeps the form itself
     * untouched. The original message is preserved after the extras.
     *
     * @param string $message Original login message HTML.
     * @return string
     */
    public static function filterLoginMessage(string $message): string
    {
        try {
            $extras = Designer::renderLoginExtras(Designer::getDesign());
            if ($extras === '') {
                return $message;
            }
            return $extras . $message;
        } catch (Throwable) {
            return $message;
        }
    }

    /**
     * Nonce for the builder save requests, for the settings template.
     *
     * @return string
     */
    public static function saveNonce(): string
    {
        return wp_create_nonce(self::NONCE_ACTION);
    }

    /**
     * Admin AJAX URL for the builder save requests, for the template.
     *
     * @return string
     */
    public static function ajaxUrl(): string
    {
        return admin_url('admin-ajax.php');
    }
}
