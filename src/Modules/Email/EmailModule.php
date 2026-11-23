<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Email;

use function __;
use function add_filter;
use function admin_url;
use function array_key_exists;
use function current_user_can;
use function esc_attr__;
use function esc_html__;
use function esc_url;
use function explode;
use function file_exists;
use function is_array;
use function is_email;
use function is_string;
use function ob_get_clean;
use function ob_start;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function str_contains;
use function strtolower;
use function trim;
use function wp_create_nonce;
use function wp_nonce_field;

/**
 * Email module: template builder + newsletter.
 *
 * Implements \TrayDigita\WP\Plugin\Core\Admin\ModuleInterface (defined by the
 * admin shell). Hooks are registered only from boot(), which the
 * shell calls only when the module is enabled.
 *
 * Default-template wrapping: boot() hooks the 'wp_mail' filter. When
 * a default template exists, every WordPress notification mail whose
 * body is plain text or simple HTML is wrapped in that template. Mail
 * that already carries the template marker comment
 * <!-- traydigita-email-template --> or an HTML body that already
 * contains <html is left untouched. Password-reset and other
 * security-sensitive mails are wrapped exactly the same way: only
 * the layout wraps, the message content is never altered.
 *
 * This module never touches PHPMailer configuration; it only calls
 * wp_mail(), so the SMTP module's phpmailer_init hook is respected
 * automatically.
 */
final class EmailModule implements \TrayDigita\WP\Plugin\Core\Admin\ModuleInterface
{
    /**
     * Nonce action for admin Email endpoints (see EmailAjax).
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Nonce action for the public subscribe form.
     *
     * Created with wp_create_nonce() and printed by
     * subscribeFormHtml(); verified by the public subscribe endpoint.
     */
    public const string PUBLIC_NONCE_ACTION = 'traydigita-email-public';

    /**
     * Module id in the registry.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'email';
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
        return 'communication';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Email', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Email templates and newsletter: build branded templates, wrap WordPress notification emails, and send newsletters to confirmed subscribers.', 'traydigita-core');
    }

    /**
     * Module icon (dashicon slug used by the module grid).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'email';
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
            __('Branded email templates.', 'traydigita-core'),
            __('Wraps WordPress notification emails.', 'traydigita-core'),
            __('Newsletter sending to confirmed subscribers.', 'traydigita-core'),
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
     * Settings page slug.
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
        return 'traydigita-email';
    }

    /**
     * Whether the module can run. The Email module has no hard
     * dependencies, so it is always available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured: true when a default template
     * exists, which is what enables notification-mail wrapping.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return TemplateBuilder::getDefaultTemplate() !== null;
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
     * Register hooks. Called only when the module is enabled.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_filter('wp_mail', [self::class, 'wrapWpMail'], 10, 1);
        add_filter('traydigita:core:mailer/unsubscribe-url', [self::class, 'unsubscribeUrl'], 10, 2);
        EmailAjax::register();
    }

    /**
     * Unsubscribe URL for a mail, for the Mailer
     * List-Unsubscribe header.
     *
     * When the mail goes to a subscriber, the header links to
     * that subscriber's one-click unsubscribe URL; otherwise the
     * incoming URL is returned unchanged (the Mailer falls back
     * to a `mailto:` unsubscribe link).
     *
     * @param string $url Incoming unsubscribe URL (usually '').
     * @param array<string, mixed> $atts wp_mail() arguments.
     * @return string
     */
    public static function unsubscribeUrl(string $url, array $atts): string
    {
        $to = $atts['to'] ?? '';
        $emails = [];
        if (is_string($to)) {
            $emails = explode(',', $to);
        } elseif (is_array($to)) {
            foreach ($to as $item) {
                if (is_string($item)) {
                    $emails[] = $item;
                }
            }
        }
        foreach ($emails as $email) {
            $email = sanitize_email(trim((string) $email));
            if ($email === '' || is_email($email) === false) {
                continue;
            }
            $link = Newsletter::unsubscribeUrlFor($email);
            if ($link !== '') {
                return $link;
            }
        }
        return $url;
    }

    /**
     * Render the settings UI inside the admin shell content area.
     *
     * Loads templates/admin/modules/email.php with the current
     * templates, default id, newsletter settings, subscribers
     * and send log.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $templates = TemplateBuilder::getTemplates();
        $allTemplates = TemplateBuilder::getTemplates(TemplateStore::TYPE_EMAIL, null);
        $defaultId = TemplateBuilder::getDefaultId();
        $newsletterSettings = Newsletter::getSettings();
        $subscribers = Newsletter::getSubscribers();
        $log = Newsletter::getLog();
        $adminNonce = wp_create_nonce(self::NONCE_ACTION);
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/email.php';
        if (file_exists($partial)) {
            require $partial;
            return;
        }
        echo '<div class="email-module"><p>' . esc_html__('Email settings are unavailable.', 'traydigita-core') . '</p></div>';
    }

    /**
     * Save module settings from the shell's generic save endpoint.
     *
     * Handles the default-template selection, template renames,
     * and the newsletter settings (double opt-in, sender
     * identity). Template create/update/delete go through the
     * EmailAjax endpoints instead. All input is sanitized here.
     *
     * Expected $data keys:
     * - default_id: id of the template to mark default ('' clears it)
     * - rename_id / rename_name: rename one template
     * - double_opt_in / from_name / from_email: newsletter settings
     *
     * @param array<string, mixed> $data Posted settings data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        // Belt and braces: the admin AJAX dispatcher already verifies
        // the nonce and manage_options before calling here, but this
        // method mutates stored templates, so it must refuse on its
        // own like every other mutating saveSettings() does.
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Email settings.', 'traydigita-core'),
            ];
        }
        if (array_key_exists('double_opt_in', $data) || array_key_exists('from_name', $data) || array_key_exists('from_email', $data)) {
            return Newsletter::saveSettings($data);
        }
        $renameId = sanitize_key((string) ($data['rename_id'] ?? ''));
        $renameName = sanitize_text_field((string) ($data['rename_name'] ?? ''));
        if ($renameId !== '' && $renameName !== '') {
            $template = TemplateBuilder::getTemplate($renameId);
            if ($template === null) {
                return ['success' => false, 'message' => esc_html__('Template not found.', 'traydigita-core')];
            }
            $template['name'] = $renameName;
            TemplateBuilder::saveTemplate($template);
            return ['success' => true, 'message' => esc_html__('Template renamed.', 'traydigita-core')];
        }
        if (array_key_exists('default_id', $data)) {
            $defaultId = sanitize_key((string) $data['default_id']);
            if ($defaultId === '') {
                $stored = TemplateBuilder::getStored();
                TemplateBuilder::saveStored($stored['templates'], '');
                return ['success' => true, 'message' => esc_html__('Default template cleared.', 'traydigita-core')];
            }
            if (!TemplateBuilder::setDefault($defaultId)) {
                return ['success' => false, 'message' => esc_html__('Template not found.', 'traydigita-core')];
            }
            return ['success' => true, 'message' => esc_html__('Default template updated.', 'traydigita-core')];
        }
        return ['success' => false, 'message' => esc_html__('Nothing to save.', 'traydigita-core')];
    }

    /**
     * Render the public newsletter subscribe form.
     *
     * Themes and widgets can call this to print a subscribe form
     * anywhere. The form posts to admin-ajax.php
     * (action traydigita_email_subscribe) and carries the public
     * nonce for the 'traydigita-email-public' action.
     *
     * @return string Subscribe form HTML.
     */
    public static function subscribeFormHtml(): string
    {
        ob_start();
        ?>
        <form class="email-subscribe-form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <input type="hidden" name="action" value="traydigita_email_subscribe" />
            <?php wp_nonce_field(self::PUBLIC_NONCE_ACTION); ?>
            <label class="email-subscribe-label" for="traydigita-email-subscribe-email">
                <?php echo esc_html__('Email address', 'traydigita-core'); ?>
            </label>
            <input class="email-subscribe-email" type="email" id="traydigita-email-subscribe-email"
                name="email" required autocomplete="email"
                placeholder="<?php echo esc_attr__('Your email address', 'traydigita-core'); ?>" />
            <label class="email-subscribe-label" for="traydigita-email-subscribe-name">
                <?php echo esc_html__('Name', 'traydigita-core'); ?>
            </label>
            <input class="email-subscribe-name" type="text" id="traydigita-email-subscribe-name"
                name="name" autocomplete="name"
                placeholder="<?php echo esc_attr__('Your name (optional)', 'traydigita-core'); ?>" />
            <button class="email-subscribe-submit" type="submit">
                <?php echo esc_html__('Subscribe', 'traydigita-core'); ?>
            </button>
            <p class="email-subscribe-message" aria-live="polite"></p>
        </form>
        <?php
        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * Wrap a WordPress mail in the default template (wp_mail filter).
     *
     * Only when a default template exists. Mail is left untouched when
     * the body already carries the template marker comment or when an
     * HTML body already contains an <html> document. Password-reset
     * and other security-sensitive mails are wrapped the same way as
     * any other mail: the content is unchanged, only the layout wraps.
     *
     * @param array<string, mixed> $args wp_mail arguments.
     * @return array<string, mixed> Possibly wrapped wp_mail arguments.
     */
    public static function wrapWpMail(array $args): array
    {
        $template = TemplateBuilder::getDefaultTemplate();
        if ($template === null) {
            return $args;
        }
        $body = (string) ($args['message'] ?? '');
        if ($body === '' || str_contains($body, TemplateBuilder::MARKER)) {
            return $args;
        }
        $isHtml = self::mailIsHtml($args);
        if ($isHtml && str_contains(strtolower($body), '<html')) {
            return $args;
        }
        $content = TemplateBuilder::sanitizeBodyForTemplate($body, $isHtml);
        $args['message'] = TemplateBuilder::renderTemplate($template, $content);
        $args['headers'] = self::forceHtmlHeaders($args['headers'] ?? []);
        return $args;
    }

    /**
     * Detect whether a wp_mail call is HTML mail from its headers.
     *
     * @param array<string, mixed> $args wp_mail arguments.
     * @return bool
     */
    private static function mailIsHtml(array $args): bool
    {
        $headers = $args['headers'] ?? [];
        if (is_string($headers)) {
            $headers = [$headers];
        }
        if (!is_array($headers)) {
            return false;
        }
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (str_contains(strtolower($header), 'content-type: text/html')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replace any Content-Type header with text/html; charset UTF-8.
     *
     * @param mixed $headers Existing headers (string, array or empty).
     * @return list<string>
     */
    private static function forceHtmlHeaders(mixed $headers): array
    {
        if (is_string($headers)) {
            $headers = $headers === '' ? [] : [$headers];
        }
        if (!is_array($headers)) {
            $headers = [];
        }
        $kept = [];
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (str_contains(strtolower(trim($header)), 'content-type:')) {
                continue;
            }
            $kept[] = $header;
        }
        $kept[] = 'Content-Type: text/html; charset=UTF-8';
        return $kept;
    }
}
