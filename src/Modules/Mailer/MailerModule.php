<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\AbstractApiProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\AWeberProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\BrevoProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\CampaignMonitorProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\ConstantContactProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\CourierProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\ElasticEmailProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\GetResponseProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\GmailOAuth;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\GmailProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\HubSpotProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\KlaviyoProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\LoopsProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\MailchimpProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\MailerLiteProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\MailgunProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\MailerSendProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\MailjetProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\MailtrapProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\MandrillProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\MoosendProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\OmnisendProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\OneSignalProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\PlunkProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\PostmarkProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\ResendProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\SenderProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\SendGridProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\SendLayerProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\SendPulseProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\SesProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\Smtp2goProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\SmtpComProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\SocketLabsProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\SparkPostProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\UnisenderProvider;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers\ZeptoMailProvider;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function admin_url;
use function apply_filters;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unshift;
use function base64_encode;
use function basename;
use function check_admin_referer;
use function chunk_split;
use function count;
use function current_user_can;
use function ctype_digit;
use function did_action;
use function do_action;
use function doing_action;
use function esc_html__;
use function esc_url;
use function explode;
use function file_exists;
use function file_get_contents;
use function filter_var;
use function function_exists;
use function get_bloginfo;
use function get_current_screen;
use function get_option;
use function home_url;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_callable;
use function is_email;
use function is_float;
use function is_int;
use function is_object;
use function is_readable;
use function is_string;
use function mb_substr;
use function md5;
use function parse_url;
use function preg_match;
use function remove_filter;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function time;
use function trim;
use function uniqid;
use function update_option;
use function wp_check_filetype;
use function wp_create_nonce;
use function wp_mail;
use function wp_nonce_url;
use function wp_redirect;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Mailer module (multi-provider rework of the old SMTP module).
 *
 * Sends all WordPress mail through the provider the site owner
 * picks: plain SMTP (with one-click presets for the major
 * mailbox providers and for the SMTP relays of the API
 * providers), or one of the transactional API providers
 * (SendGrid, Gmail OAuth, Sender.net, SendPulse, Brevo,
 * Mailjet, Resend, Mailgun, SMTP2GO, MailerSend, Postmark,
 * Elastic Email, Zoho CPaaS, Bird Email). Every provider is
 * first-party and built into Core; the
 * `traydigita:core:mailer/providers` filter stays available so
 * a third-party plugin MAY register an extra provider, but
 * Core never depends on one.
 *
 * Provider routing:
 * - `smtp`: WordPress core mail, configured through the
 *   `phpmailer_init` hook exactly as before.
 * - Every other provider: the `pre_wp_mail` filter
 *   short-circuits `wp_mail()` and sends through the provider
 *   API instead. This keeps every existing `wp_mail()` caller
 *   working unchanged — the Email/newsletter module, password
 *   resets, everything — while the provider does the delivery.
 *   (Verified against WP 7.1.5: a `pre_wp_mail` short-circuit
 *   returns before the `wp_mail_succeeded` / `wp_mail_failed`
 *   actions fire, so the API path logs its own result directly
 *   in maybeSendViaApi(); the SMTP path is logged from those
 *   two actions instead. Neither path can double-log a send.)
 *
 * Deliverability headers (settings, all default on): the
 * `wp_mail` filter adds a domain-aligned Reply-To, a
 * List-Unsubscribe header (mailto, plus an unsubscribe URL
 * when a module provides one through the
 * `traydigita:core:mailer/unsubscribe-url` filter) with
 * List-Unsubscribe-Post for one-click unsubscribe, a unique
 * Message-ID, an X-Mailer identifier, and Auto-Submitted
 * (plus Precedence: bulk and a Feedback-ID for bulk mail such
 * as newsletters). See README.md for why each header matters.
 *
 * Identity stability (the rename must not orphan anything):
 * - The module id stays `smtp` and the settings page slug stays
 *   `traydigita-smtp`: the stored `traydigita_modules` list, admin
 *   links and bookmarks keep working, and the registry's
 *   DEFAULT_ENABLED entry for `smtp` still matches. Only the
 *   display name, the class/namespace and the settings storage
 *   are new. The old `SmtpModule` / `SmtpAjax` classes are gone;
 *   nothing else in the theme referenced them.
 * - Settings migrate from the old `traydigita_smtp` option into
 *   the new `traydigita_mailer` option on first read (provider
 *   `smtp`, preset `custom`, all SMTP fields copied). The old
 *   option is left in place as a fallback and is never deleted,
 *   so a rollback still finds the saved config. The last-test
 *   record migrates the same way.
 *
 * Secrets (SMTP password, API keys, Gmail client secret) are
 * stored as-is in the option, like every mail plugin does, and
 * are never printed, never returned by any endpoint, never
 * logged, and never included in a JSON response. Settings forms
 * always render secret fields empty; a stored secret is only
 * overwritten when a non-empty value is submitted. The Gmail
 * OAuth tokens live in their own option
 * (`traydigita_mailer_gmail`, see Providers\GmailOAuth) and are redacted
 * from every error message by redact().
 *
 * The send log (last 50 sends: time, recipient, subject,
 * provider, result) lives in `traydigita_mailer_log` and is
 * written for every provider. The message body is never stored.
 *
 * When the module is enabled but not configured, `wp_mail()`
 * keeps working with the WordPress default mailer, and an admin
 * notice on TrayDigita pages explains that setup is incomplete.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer
 */
final class MailerModule implements ModuleInterface
{
    /**
     * Option name holding all mailer settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_mailer';

    /**
     * Legacy SMTP option, read as a migration source and left
     * in place (never deleted) so a rollback still finds it.
     */
    public const string LEGACY_OPTION = 'traydigita_smtp';

    /**
     * Option name holding the last test-email result (autoload `no`).
     *
     * Shape: `['time' => int, 'success' => bool]`. The recipient
     * address and any error text are never stored here.
     */
    public const string LAST_TEST_OPTION = 'traydigita_mailer_last_test';

    /**
     * Legacy last-test option, read as a migration fallback.
     */
    public const string LEGACY_LAST_TEST_OPTION = 'traydigita_smtp_last_test';

    /**
     * Option name holding the send log (autoload `no`).
     *
     * A list of at most LOG_MAX entries, newest first:
     * `['time' => int, 'to' => string, 'subject' => string,
     * 'provider' => string, 'result' => 'sent'|'failed',
     * 'error' => string]`. The message body is never stored.
     */
    public const string LOG_OPTION = 'traydigita_mailer_log';

    /**
     * Option name holding the Gmail OAuth tokens (autoload `no`).
     *
     * Shape: `['access_token' => string, 'refresh_token' =>
     * string, 'expires_at' => int, 'email' => string]`. Owned by
     * Providers\GmailOAuth; this class only reads it through that class.
     */
    public const string GMAIL_TOKENS_OPTION = 'traydigita_mailer_gmail';

    /**
     * Shared nonce action for the admin AJAX endpoints.
     *
     * The nonce itself must be sent as `_ajax_nonce` (see
     * `check_ajax_referer()`), never as a field named `nonce`.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Nonce action for the Gmail OAuth start / callback / disconnect.
     */
    public const string GMAIL_OAUTH_NONCE_ACTION = 'traydigita_mailer_gmail_oauth';

    /**
     * Built-in API provider classes, keyed by provider id.
     *
     * `smtp` goes through PHPMailer and `local` through the
     * wp_mail default transport, so neither is listed here;
     * every other built-in provider — SendGrid and Gmail
     * included — is a class in this list, in dropdown order.
     * The hookable registry (see providerDefinitions())
     * merges these with any third-party providers registered
     * through the `traydigita:core:mailer/providers` filter.
     *
     * @var array<string, class-string<AbstractApiProvider>>
     */
    public const array API_PROVIDER_CLASSES = [
        'sendgrid' => SendGridProvider::class,
        'gmail' => GmailProvider::class,
        'sender' => SenderProvider::class,
        'sendpulse' => SendPulseProvider::class,
        'brevo' => BrevoProvider::class,
        'mailjet' => MailjetProvider::class,
        'resend' => ResendProvider::class,
        'mailgun' => MailgunProvider::class,
        'smtp2go' => Smtp2goProvider::class,
        'mailersend' => MailerSendProvider::class,
        'postmark' => PostmarkProvider::class,
        'elasticemail' => ElasticEmailProvider::class,
        'zeptomail' => ZeptoMailProvider::class,
        'sparkpost' => SparkPostProvider::class,
        'onesignal' => OneSignalProvider::class,
        'mailtrap' => MailtrapProvider::class,
        'ses' => SesProvider::class,
        'mandrill' => MandrillProvider::class,
        'sendlayer' => SendLayerProvider::class,
        'socketlabs' => SocketLabsProvider::class,
        'courier' => CourierProvider::class,
        'smtpcom' => SmtpComProvider::class,
        'unisender' => UnisenderProvider::class,
        'plunk' => PlunkProvider::class,
        'loops' => LoopsProvider::class,
        'mailerlite' => MailerLiteProvider::class,
        'mailchimp' => MailchimpProvider::class,
        'hubspot' => HubSpotProvider::class,
        'omnisend' => OmnisendProvider::class,
        'klaviyo' => KlaviyoProvider::class,
        'getresponse' => GetResponseProvider::class,
        'aweber' => AWeberProvider::class,
        'constantcontact' => ConstantContactProvider::class,
        'moosend' => MoosendProvider::class,
        'campaignmonitor' => CampaignMonitorProvider::class,
    ];

    /**
     * Providers ready to pick, and providers proven by a live send.
     *
     * Readiness is curated, not proven. A provider is ready when
     * (1) its endpoint answered in the 2026-11-24 health audit
     * (`files/mailer-providers-health-2026-11-24.md`) — `smtp`
     * and `local` need no remote endpoint, so they pass on their
     * transport alone; (2) it is well-known — real public docs
     * and a standard auth flow (API key or OAuth) a new user can
     * obtain today; and (3) its send path is implemented through
     * the shared helpers (`AbstractApiProvider::postJson()` and
     * friends, PHPMailer for `smtp`, the `wp_mail` default for
     * `local`). Ready does NOT mean a send was proven: no real
     * send has ever been tested from this VM (no API keys exist
     * here), so a ready provider is curated-ready until a real
     * test send with a real key promotes it to PROVEN_PROVIDERS
     * below. Live-proven is currently empty: the first
     * candidate, Zoho Mail over SMTP, cannot be tested from this
     * VM yet — outbound SMTP is denied for this assistant until
     * the site owner allows it — and the Zoho CPaaS API route
     * was declined by the user.
     *
     * The ready set (15 of 37): `smtp`, `local`, Gmail OAuth and
     * the household-name transactional APIs (SendGrid, Mailgun,
     * Amazon SES, Brevo, Mailjet, Postmark, Resend, SMTP2GO,
     * MailerSend, Elastic Email, Mandrill / Mailchimp
     * Transactional, ZeptoMail / Zoho CPaaS — both renames keep
     * the old API host and credentials, per the audit). The other
     * 22 start unverified: the 10 marketing campaign providers
     * (their send is a campaign, not a direct transactional
     * send), the orchestrator / routed sends (Courier, Plunk),
     * the testing sandbox (Mailtrap), the rename whose new keys
     * are unverified on the old API (SparkPost / Bird), and the
     * smaller second-tier APIs (Sender.net, SendPulse, OneSignal,
     * SendLayer, SocketLabs, SMTP.com, Unisender, Loops) — all
     * alive per the audit, none proven to send.
     *
     * `providerDefinitions()` stamps every definition with
     * `is_ready` (in either list, or an explicit third-party
     * opt-in — see below) and `ready_proven` (in PROVEN only),
     * so the picker, the settings page and `saveSettings()` all
     * read one source of truth. A new selection of an unverified
     * provider is refused in `saveSettings()`; a provider that
     * was already selected keeps sending (grandfathered).
     *
     * @var array<int, string>
     */
    public const array READY_PROVIDERS = [
        'smtp',
        'local',
        'gmail',
        'sendgrid',
        'mailgun',
        'ses',
        'brevo',
        'mailjet',
        'postmark',
        'resend',
        'smtp2go',
        'mailersend',
        'elasticemail',
        'mandrill',
        'zeptomail',
    ];

    /**
     * Providers with a live-proven send: a real test send
     * succeeded with a real key. Record the proof next to the
     * id when one is added (date, endpoint, response).
     *
     * Currently empty — see READY_PROVIDERS above for why no
     * provider is proven yet.
     *
     * @var array<int, string>
     */
    public const array PROVEN_PROVIDERS = [];

    /**
     * Allowed SMTP presets: the mailbox providers from the
     * final provider catalog (37 providers in the dropdown,
     * 30 SMTP presets inside the SMTP provider).
     *
     * @var array<int, string>
     */
    public const array SMTP_PRESETS = [
        'custom',
        'gmail',
        'yahoo',
        'outlook',
        'zoho',
        'zoho_eu',
        'proton',
        'yandex',
        'fastmail',
        'icloud',
        'gmx',
        'mailcom',
        'aol',
        'qq',
        'mail163',
        'mail126',
        'sina',
        'mailru',
        'posteo',
        'migadu',
        'mailfence',
        'startmail',
        'runbox',
        'kolabnow',
        'infomaniak',
        'ovh',
        'gandi',
        'namecheap',
        'dreamhost',
        'mxroute',
    ];

    /**
     * SMTP connection details per preset (host, port, encryption).
     *
     * The preset wins over the submitted host / port / encryption
     * in saveSettings(): picking a preset fills the connection
     * in server-side, so the preset works with no JavaScript at
     * all. `custom` keeps the submitted values. Hosts and ports
     * were checked against the providers' own documentation
     * (Nov 2026). Proton Mail has no public SMTP server: the
     * Bridge app runs on the same host (127.0.0.1:1025), and
     * configurePhpMailer() allows its self-signed certificate
     * for loopback hosts only. Two catalog calls: Kolab Now
     * follows the catalog (587 TLS) although the vendor prefers
     * 465 implicit TLS, and MXroute's host is per-server - the
     * catalog placeholder `mail.mxroute.com` is used here, and
     * the description tells the site owner to use the real
     * server hostname from the MXroute panel.
     *
     * @var array<string, array{host: string, port: int, encryption: string}>
     */
    public const array SMTP_PRESET_VALUES = [
        'gmail' => ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'],
        'yahoo' => ['host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'ssl'],
        'outlook' => ['host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls'],
        'zoho' => ['host' => 'smtp.zoho.com', 'port' => 465, 'encryption' => 'ssl'],
        'zoho_eu' => ['host' => 'smtp.zoho.eu', 'port' => 465, 'encryption' => 'ssl'],
        'proton' => ['host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'tls'],
        'yandex' => ['host' => 'smtp.yandex.com', 'port' => 465, 'encryption' => 'ssl'],
        'fastmail' => ['host' => 'smtp.fastmail.com', 'port' => 465, 'encryption' => 'ssl'],
        'icloud' => ['host' => 'smtp.mail.me.com', 'port' => 587, 'encryption' => 'tls'],
        'gmx' => ['host' => 'mail.gmx.com', 'port' => 587, 'encryption' => 'tls'],
        'mailcom' => ['host' => 'smtp.mail.com', 'port' => 587, 'encryption' => 'tls'],
        'aol' => ['host' => 'smtp.aol.com', 'port' => 587, 'encryption' => 'tls'],
        'qq' => ['host' => 'smtp.qq.com', 'port' => 465, 'encryption' => 'ssl'],
        'mail163' => ['host' => 'smtp.163.com', 'port' => 465, 'encryption' => 'ssl'],
        'mail126' => ['host' => 'smtp.126.com', 'port' => 465, 'encryption' => 'ssl'],
        'sina' => ['host' => 'smtp.sina.com', 'port' => 465, 'encryption' => 'ssl'],
        'mailru' => ['host' => 'smtp.mail.ru', 'port' => 465, 'encryption' => 'ssl'],
        'posteo' => ['host' => 'posteo.de', 'port' => 587, 'encryption' => 'tls'],
        'migadu' => ['host' => 'smtp.migadu.com', 'port' => 587, 'encryption' => 'tls'],
        'mailfence' => ['host' => 'smtp.mailfence.com', 'port' => 465, 'encryption' => 'ssl'],
        'startmail' => ['host' => 'smtp.startmail.com', 'port' => 587, 'encryption' => 'tls'],
        'runbox' => ['host' => 'smtp.runbox.com', 'port' => 587, 'encryption' => 'tls'],
        'kolabnow' => ['host' => 'smtp.kolabnow.com', 'port' => 587, 'encryption' => 'tls'],
        'infomaniak' => ['host' => 'mail.infomaniak.com', 'port' => 587, 'encryption' => 'tls'],
        'ovh' => ['host' => 'ssl0.ovh.net', 'port' => 465, 'encryption' => 'ssl'],
        'gandi' => ['host' => 'mail.gandi.net', 'port' => 587, 'encryption' => 'tls'],
        'namecheap' => ['host' => 'mail.privateemail.com', 'port' => 465, 'encryption' => 'ssl'],
        'dreamhost' => ['host' => 'smtp.dreamhost.com', 'port' => 587, 'encryption' => 'tls'],
        'mxroute' => ['host' => 'mail.mxroute.com', 'port' => 465, 'encryption' => 'ssl'],
    ];

    /**
     * Display label and one-line description per SMTP preset.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const array SMTP_PRESET_DESCRIPTIONS = [
        'custom' => ['label' => 'Custom SMTP server', 'description' => 'Enter the host, port, and encryption below by hand.'],
        'gmail' => ['label' => 'Gmail SMTP', 'description' => 'Needs a Google app password (account with 2-Step Verification); the account password does not work.'],
        'yahoo' => ['label' => 'Yahoo SMTP', 'description' => 'Needs a Yahoo app password when 2-Step Verification is on.'],
        'outlook' => ['label' => 'Outlook / Microsoft 365 / Hotmail', 'description' => 'Microsoft mailbox (personal or work); SMTP AUTH must be turned on for the mailbox.'],
        'zoho' => ['label' => 'Zoho Mail', 'description' => 'Forever Free plan: 1 domain, 5 users, 5 GB. Domain-based organisation accounts use smtppro.zoho.com instead. App password needed with 2FA.'],
        'zoho_eu' => ['label' => 'Zoho Mail (EU)', 'description' => 'Zoho Mail hosted in the EU (zoho.eu). Organisation accounts use smtppro.zoho.eu instead. App password needed with 2FA.'],
        'proton' => ['label' => 'Proton Mail (via Bridge)', 'description' => 'Proton Mail Bridge must be running on this same host (paid Proton plan); use the Bridge-generated password.'],
        'yandex' => ['label' => 'Yandex Mail', 'description' => 'Needs a Yandex app password.'],
        'fastmail' => ['label' => 'Fastmail', 'description' => 'Paid service (30-day trial). Needs a Fastmail app password; the main password never works over SMTP.'],
        'icloud' => ['label' => 'iCloud Mail', 'description' => 'Needs an Apple app-specific password; use the full @icloud.com address as the username.'],
        'gmx' => ['label' => 'GMX Mail', 'description' => 'Enable SMTP access in the GMX settings first.'],
        'mailcom' => ['label' => 'Mail.com', 'description' => 'SMTP access is a premium Mail.com feature.'],
        'aol' => ['label' => 'AOL Mail', 'description' => 'Needs an AOL app password when 2-Step Verification is on.'],
        'qq' => ['label' => 'QQ Mail', 'description' => 'Needs a QQ Mail authorization code (not the account password).'],
        'mail163' => ['label' => '163 Mail', 'description' => 'Needs a 163 Mail authorization code (not the account password).'],
        'mail126' => ['label' => '126 Mail', 'description' => 'Needs a 126 Mail authorization code (not the account password).'],
        'sina' => ['label' => 'Sina Mail', 'description' => 'Needs a 16-character Sina client authorization code (not the account password).'],
        'mailru' => ['label' => 'Mail.ru', 'description' => 'Needs a Mail.ru app password.'],
        'posteo' => ['label' => 'Posteo', 'description' => 'Paid service (about 1 euro/month). Needs a Posteo password; enable SMTP in the Posteo settings.'],
        'migadu' => ['label' => 'Migadu', 'description' => 'Paid service (free trial). Needs a Migadu app password.'],
        'mailfence' => ['label' => 'Mailfence', 'description' => 'Free plan with 500 MB storage; SMTP works on paid plans. Needs a Mailfence app password.'],
        'startmail' => ['label' => 'StartMail', 'description' => 'Paid service (free trial). Needs a StartMail app password.'],
        'runbox' => ['label' => 'Runbox', 'description' => 'Paid service (free trial). Needs a Runbox password; enable SMTP in the Runbox settings.'],
        'kolabnow' => ['label' => 'Kolab Now', 'description' => 'Paid service. The vendor prefers port 465 (implicit TLS); this preset uses 587 (STARTTLS) as listed in the catalog — switch to Custom with port 465 if 587 fails.'],
        'infomaniak' => ['label' => 'Infomaniak', 'description' => 'Paid service (bundled with Infomaniak hosting or mail). Needs an Infomaniak mail password.'],
        'ovh' => ['label' => 'OVH Mail', 'description' => 'Bundled with OVH hosting or MX Plan. Needs an OVH mailbox password; Email Pro and Exchange use pro?.mail.ovh.net or ex?.mail.ovh.net on port 587 instead.'],
        'gandi' => ['label' => 'Gandi Mail', 'description' => 'Bundled with a Gandi domain or hosting. Needs a Gandi mailbox password.'],
        'namecheap' => ['label' => 'Namecheap Private Email', 'description' => 'Paid service (Namecheap Private Email). Needs a Private Email password; an app password is required when 2FA is on.'],
        'dreamhost' => ['label' => 'DreamHost Mail', 'description' => 'Bundled with DreamHost hosting. Needs a DreamHost mailbox password.'],
        'mxroute' => ['label' => 'MXroute', 'description' => 'Paid service. The SMTP host is per-server: use the real server hostname shown in the MXroute panel (for example server.mxrouting.net) instead of mail.mxroute.com when it differs.'],
    ];

    /**
     * Logo file stem per provider id.
     *
     * The files live in assets/images/providers/ and are
     * lettermark SVGs (initials on a rounded rect, brand-ish
     * colours) copied from the shared Unified lettermark set
     * — see assets/images/providers/README.md for provenance.
     * They are clean initial monograms, not trademarked artwork
     * redrawn in detail. A provider without an entry here (for
     * example a third-party provider from the filter) falls
     * back to the generic SMTP envelope in providerLogo().
     *
     * @var array<string, string> Provider id => file stem without '.svg'.
     */
    public const array PROVIDER_LOGOS = [
        'smtp' => 'email-smtp',
        'local' => 'email-sendmail',
        'sendgrid' => 'email-sendgrid',
        'gmail' => 'email-gmail',
        'sender' => 'email-sender-net',
        'sendpulse' => 'email-sendpulse',
        'brevo' => 'email-brevo',
        'mailjet' => 'email-mailjet',
        'resend' => 'email-resend',
        'mailgun' => 'email-mailgun',
        'smtp2go' => 'email-smtp2go',
        'mailersend' => 'email-mailersend',
        'postmark' => 'email-postmark',
        'elasticemail' => 'email-elastic-email',
        'zeptomail' => 'email-zeptomail',
        'sparkpost' => 'email-sparkpost',
        'onesignal' => 'email-onesignal',
        'mailtrap' => 'email-mailtrap',
        'ses' => 'email-ses',
        'mandrill' => 'email-mandrill',
        'sendlayer' => 'email-sendlayer',
        'socketlabs' => 'email-socketlabs',
        'courier' => 'email-courier',
        'smtpcom' => 'email-smtp-com',
        'unisender' => 'email-unisender',
        'plunk' => 'email-plunk',
        'loops' => 'email-loops',
        'mailerlite' => 'email-mailerlite',
        'mailchimp' => 'email-mailchimp',
        'hubspot' => 'email-hubspot',
        'omnisend' => 'email-omnisend',
        'klaviyo' => 'email-klaviyo',
        'getresponse' => 'email-getresponse',
        'aweber' => 'email-aweber',
        'constantcontact' => 'email-constant-contact',
        'moosend' => 'email-moosend',
        'campaignmonitor' => 'email-campaign-monitor',
    ];

    /**
     * Logo file stem per SMTP preset id.
     *
     * Same lettermark set as PROVIDER_LOGOS above. The
     * 'custom' preset has no brand, so it uses the generic
     * SMTP envelope.
     *
     * @var array<string, string> Preset id => file stem without '.svg'.
     */
    public const array PRESET_LOGOS = [
        'custom' => 'email-smtp',
        'gmail' => 'email-gmail',
        'yahoo' => 'email-yahoo',
        'outlook' => 'email-outlook',
        'zoho' => 'email-zoho',
        'zoho_eu' => 'email-zoho-eu',
        'proton' => 'email-proton',
        'yandex' => 'email-yandex',
        'fastmail' => 'email-fastmail',
        'icloud' => 'email-icloud',
        'gmx' => 'email-gmx',
        'mailcom' => 'email-mail-com',
        'aol' => 'email-aol',
        'qq' => 'email-qq-mail',
        'mail163' => 'email-mail-163',
        'mail126' => 'email-mail-126',
        'sina' => 'email-sina',
        'mailru' => 'email-mail-ru',
        'posteo' => 'email-posteo',
        'migadu' => 'email-migadu',
        'mailfence' => 'email-mailfence',
        'startmail' => 'email-startmail',
        'runbox' => 'email-runbox',
        'kolabnow' => 'email-kolabnow',
        'infomaniak' => 'email-infomaniak',
        'ovh' => 'email-ovh',
        'gandi' => 'email-gandi',
        'namecheap' => 'email-namecheap',
        'dreamhost' => 'email-dreamhost',
        'mxroute' => 'email-mxroute',
    ];

    /**
     * Logo URL for a provider id.
     *
     * Returns the provider's lettermark from
     * assets/images/providers/, or the generic SMTP envelope
     * when the provider has no logo (a third-party provider
     * from the filter, or a missing file). The stem is taken
     * from the allowlisted PROVIDER_LOGOS map only, never from
     * raw input, so no path traversal is possible.
     *
     * @param string $id Provider id.
     * @return string Logo URL.
     */
    public static function providerLogoUrl(string $id): string
    {
        $stem = self::PROVIDER_LOGOS[$id] ?? 'email-smtp';
        return self::logoUrl($stem);
    }

    /**
     * Logo URL for an SMTP preset id.
     *
     * Unknown presets fall back to the generic SMTP envelope.
     *
     * @param string $preset Preset id.
     * @return string Logo URL.
     */
    public static function presetLogoUrl(string $preset): string
    {
        $stem = self::PRESET_LOGOS[$preset] ?? 'email-smtp';
        return self::logoUrl($stem);
    }

    /**
     * URL of a logo file stem, with a missing-file fallback.
     *
     * The stem must name a file under assets/images/providers/;
     * anything outside that directory (or a missing file) falls
     * back to the generic SMTP envelope, so a bad stem can never
     * produce a broken image or an outside URL.
     *
     * @param string $stem File stem without '.svg'.
     * @return string Logo URL.
     */
    private static function logoUrl(string $stem): string
    {
        $baseUrl = \TrayDigita\WP\Plugin\Core\Core::pluginUrl() . '/assets/images/providers/';
        $baseDir = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/assets/images/providers/';
        if ($stem === '' || str_contains($stem, '/') || str_contains($stem, '\\')) {
            return $baseUrl . 'email-smtp.svg';
        }
        if (!file_exists($baseDir . $stem . '.svg')) {
            return $baseUrl . 'email-smtp.svg';
        }
        return $baseUrl . $stem . '.svg';
    }

    /**
     * Allowed encryption values.
     *
     * @var array<int, string>
     */
    public const array ENCRYPTIONS = ['none', 'ssl', 'tls'];

    /**
     * Default SMTP port.
     */
    public const int DEFAULT_PORT = 587;

    /**
     * Maximum send-log entries kept.
     */
    public const int LOG_MAX = 50;

    /**
     * Maximum characters stored for a log recipient / subject / error.
     */
    public const int LOG_TEXT_MAX = 200;

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Cached provider definitions (built-ins + filtered extras).
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $providerDefinitions = null;

    /**
     * All provider definitions: built-ins plus third-party extras.
     *
     * Built-ins: `smtp` (WordPress core PHPMailer), `local`
     * (the wp_mail default transport, no credentials), and
     * every class in API_PROVIDER_CLASSES (SendGrid and Gmail
     * included). Each definition
     * carries: `id`, `label`, `description` (free tier + best
     * for), `free_tier`, `best_for`, `link` (empty in v1.0.0 —
     * a slot reserved for a future affiliate link, never an
     * active link), `fields` (settings fields), `type`
     * (`smtp`, `oauth`, or `api`), and either `class` (a
     * provider class) or `send` (a callable) for the send path.
     * Every definition also carries `is_ready` (see
     * READY_PROVIDERS: in the ready list, the proven list, or
     * an explicit third-party opt-in) and `ready_proven` (in
     * PROVEN_PROVIDERS only) — the single source of truth the
     * picker, the settings page and `saveSettings()` read.
     *
     * Third-party plugins register extra providers through the
     * `traydigita:core:mailer/providers` filter by adding an
     * entry in the same shape, with a `send` callable that
     * takes `($mail, $settings)` and returns
     * `['success' => bool, 'message' => string]`, and
     * optionally an `is_configured` callable. Entries without
     * a usable id, label, or send path are dropped. A
     * third-party entry starts unverified unless it explicitly
     * declares `'is_ready' => true`; the site owner installed
     * that plugin on purpose, so its own readiness claim is
     * honoured — built-ins never self-declare, the two lists
     * above decide for them. The result is cached for the
     * request.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function providerDefinitions(): array
    {
        if (self::$providerDefinitions !== null) {
            return self::$providerDefinitions;
        }
        $definitions = [
            'smtp' => [
                'id' => 'smtp',
                'label' => __('SMTP', 'traydigita-core'),
                'description' => __(
                    'SMTP — your own mail server, with one-click presets for the major mailbox providers and the SMTP relays of the API providers.',
                    'traydigita-core'
                ),
                'free_tier' => '',
                'best_for' => __('Your own mail server', 'traydigita-core'),
                'link' => '',
                'fields' => [],
                'type' => 'smtp',
            ],
            'local' => [
                'id' => 'local',
                'label' => __('Local / PHP mail', 'traydigita-core'),
                'description' => __(
                    'Local / PHP mail — no provider, no credentials: WordPress sends through the server’s own mail setup (wp_mail default). Worst deliverability of all providers (no authentication, no reputation), but it works with no account at all. Best for local development only.',
                    'traydigita-core'
                ),
                'free_tier' => '',
                'best_for' => __('Local development only', 'traydigita-core'),
                'link' => '',
                'fields' => [],
                'type' => 'local',
            ],
        ];
        foreach (self::API_PROVIDER_CLASSES as $id => $class) {
            $definition = $class::definition();
            // A provider may declare its own type (Gmail is
            // 'oauth', not 'api'); default to 'api' otherwise.
            if (!isset($definition['type']) || !is_string($definition['type'])) {
                $definition['type'] = 'api';
            }
            $definition['class'] = $class;
            $definitions[$id] = $definition;
        }
        /** @var mixed $filtered */
        $filtered = apply_filters('traydigita:core:mailer/providers', $definitions);
        if (!is_array($filtered)) {
            $filtered = $definitions;
        }
        $validated = [];
        foreach ($filtered as $id => $definition) {
            if (!is_array($definition) || !is_string($id) || $id === '') {
                continue;
            }
            $entryId = isset($definition['id']) && is_string($definition['id']) ? sanitize_key($definition['id']) : '';
            if ($entryId === '' || $entryId !== $id) {
                continue;
            }
            if (empty($definition['label']) || !is_string($definition['label'])) {
                continue;
            }
            $hasClass = isset($definition['class']) && is_string($definition['class']) && class_exists($definition['class']);
            $hasSend = isset($definition['send']) && is_callable($definition['send']);
            if ($id === 'smtp' || $id === 'local') {
                // These two have their own send paths (SMTP via
                // PHPMailer, local via the wp_mail default
                // transport).
                $hasClass = true;
            }
            if (!$hasClass && !$hasSend) {
                continue;
            }
            $definition['description'] = isset($definition['description']) && is_string($definition['description']) ? $definition['description'] : '';
            $definition['free_tier'] = isset($definition['free_tier']) && is_string($definition['free_tier']) ? $definition['free_tier'] : '';
            $definition['best_for'] = isset($definition['best_for']) && is_string($definition['best_for']) ? $definition['best_for'] : '';
            $definition['link'] = isset($definition['link']) && is_string($definition['link']) ? $definition['link'] : '';
            $definition['fields'] = isset($definition['fields']) && is_array($definition['fields']) ? $definition['fields'] : [];
            $definition['type'] = isset($definition['type']) && is_string($definition['type']) ? $definition['type'] : 'api';
            // Readiness, stamped here so every definition that
            // leaves this method carries it: built-ins follow
            // the two lists above; a third-party entry may opt
            // in explicitly, and defaults to unverified.
            $definition['ready_proven'] = in_array($id, self::PROVEN_PROVIDERS, true);
            $definition['is_ready'] = $definition['ready_proven']
                || in_array($id, self::READY_PROVIDERS, true)
                || ($definition['is_ready'] ?? false) === true;
            $validated[$id] = $definition;
        }
        self::$providerDefinitions = $validated;
        return self::$providerDefinitions;
    }

    /**
     * Registered provider ids, in definition order.
     *
     * @return array<int, string>
     */
    public static function providerIds(): array
    {
        return array_keys(self::providerDefinitions());
    }

    /**
     * One provider definition by id, or null when unknown.
     *
     * @param string $id Provider id.
     * @return array<string, mixed>|null
     */
    public static function providerDefinition(string $id): ?array
    {
        $definitions = self::providerDefinitions();
        return $definitions[$id] ?? null;
    }

    /**
     * Whether a provider may be picked for a new setup.
     *
     * Reads the `is_ready` flag `providerDefinitions()` stamps
     * on every definition (see READY_PROVIDERS), so callers
     * never re-implement the readiness rule. Unknown ids are
     * not ready. Readiness gates new selection only — a stored
     * unverified provider keeps sending (see `saveSettings()`).
     *
     * @param string $id Provider id.
     * @return bool
     */
    public static function providerIsReady(string $id): bool
    {
        $definition = self::providerDefinition($id);
        return $definition !== null && ($definition['is_ready'] ?? false) === true;
    }

    /**
     * Whether a provider's send was proven with a real key.
     *
     * Reads the `ready_proven` flag `providerDefinitions()`
     * stamps on every definition (see PROVEN_PROVIDERS).
     * Unknown ids are not proven.
     *
     * @param string $id Provider id.
     * @return bool
     */
    public static function providerIsProven(string $id): bool
    {
        $definition = self::providerDefinition($id);
        return $definition !== null && ($definition['ready_proven'] ?? false) === true;
    }

    /**
     * Whether a provider definition is configured.
     *
     * Local: always (no credentials). SMTP: a host plus a
     * username when authentication is on. API providers:
     * their class's isConfigured(), or every `required`
     * field filled in for third-party providers (or their
     * own `is_configured` callable when given).
     *
     * @param array<string, mixed> $definition Provider definition.
     * @param array<string, mixed> $settings Mailer settings.
     * @return bool
     */
    private static function definitionIsConfigured(array $definition, array $settings): bool
    {
        $id = (string) ($definition['id'] ?? '');
        if ($id === 'local') {
            // Local mail needs no credentials at all.
            return true;
        }
        if ($id === 'smtp') {
            if (($settings['host'] ?? '') === '') {
                return false;
            }
            return empty($settings['auth']) || ($settings['username'] ?? '') !== '';
        }
        if (isset($definition['is_configured']) && is_callable($definition['is_configured'])) {
            return (bool) ($definition['is_configured'])($settings);
        }
        $class = $definition['class'] ?? null;
        if (is_string($class) && class_exists($class) && is_subclass_of($class, AbstractApiProvider::class)) {
            return $class::isConfigured($settings);
        }
        foreach ($definition['fields'] as $field) {
            if (!is_array($field) || empty($field['required'])) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || empty($settings[$key]) || !is_string($settings[$key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Send one normalized mail through a provider.
     *
     * Built-in provider classes use their static send()
     * method; third-party providers use the `send` callable
     * from their definition (or a `class` with a static
     * `send(array $mail, array $settings)` method). A provider
     * that throws is caught here and reported as a failure
     * instead of fataling the mail send.
     *
     * @param string $id Provider id.
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, to_text: string, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>} $mail Normalized mail.
     * @param array<string, mixed> $settings Mailer settings.
     * @return array{success: bool, message: string}
     */
    public static function sendViaProvider(string $id, array $mail, array $settings): array
    {
        $definition = self::providerDefinition($id);
        if ($definition === null) {
            return ['success' => false, 'message' => __('Unknown mail provider.', 'traydigita-core')];
        }
        try {
            $class = $definition['class'] ?? null;
            if (is_string($class) && class_exists($class) && is_subclass_of($class, AbstractApiProvider::class)) {
                return $class::send($mail, $settings);
            }
            if (isset($definition['send']) && is_callable($definition['send'])) {
                $result = ($definition['send'])($mail, $settings);
                if (is_array($result) && isset($result['success'])) {
                    return [
                        'success' => (bool) $result['success'],
                        'message' => isset($result['message']) && is_string($result['message']) ? $result['message'] : '',
                    ];
                }
                return ['success' => false, 'message' => __('The provider did not return a send result.', 'traydigita-core')];
            }
        } catch (Throwable $exception) {
            return ['success' => false, 'message' => self::redact($exception->getMessage())];
        }
        return ['success' => false, 'message' => __('The provider has no send method.', 'traydigita-core')];
    }

    /**
     * Module id used by the module registry and the shell save endpoint.
     *
     * Deliberately still `smtp`: the stored enabled-module list
     * and the registry DEFAULT_ENABLED entry use this id, and
     * renaming it would silently disable the module on every
     * site that already has it enabled.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'smtp';
    }

    /**
     * Default category slug for the module list.
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
        return __('Mailer', 'traydigita-core');
    }

    /**
     * Module description shown on the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Send WordPress mail through SMTP (30 presets: Gmail, Yahoo, Outlook, Zoho, Zoho EU, Proton, Yandex, Fastmail, iCloud, GMX, Mail.com, AOL, QQ, 163, 126, Sina, Mail.ru, Posteo, Migadu, Mailfence, StartMail, Runbox, Kolab Now, Infomaniak, OVH, Gandi, Namecheap, DreamHost, MXroute), Local PHP mail, or one of 25 transactional APIs (SendGrid, Gmail OAuth, Sender.net, SendPulse, Brevo, Mailjet, Resend, Mailgun, SMTP2GO, MailerSend, Postmark, Elastic Email, Zoho CPaaS, Bird Email, OneSignal, Mailtrap, Amazon SES, Mailchimp Transactional, SendLayer, SocketLabs, Courier, SMTP.com, Unisender, Plunk, Loops) plus 10 marketing APIs (MailerLite, Mailchimp, HubSpot, Omnisend, Klaviyo, GetResponse, AWeber, Constant Contact, Moosend, Campaign Monitor). Includes deliverability headers, a test email tool, and a send log.', 'traydigita-core');
    }

    /**
     * Dashicon shown on the module grid card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'email-alt';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '2.0.0';
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
            __('SMTP with 30 one-click presets for major mailbox providers.', 'traydigita-core'),
            __('Local PHP mail for development.', 'traydigita-core'),
            __('25 transactional APIs plus 10 marketing APIs.', 'traydigita-core'),
            __('Gmail OAuth sign-in.', 'traydigita-core'),
            __('Deliverability headers: Reply-To, List-Unsubscribe, Message-ID, X-Mailer.', 'traydigita-core'),
            __('Test email tool and a send log of the last 50 sends.', 'traydigita-core'),
            __('Third-party providers can register through a filter.', 'traydigita-core'),
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
                    __('SMTP mailer with presets for the major mailbox providers.', 'traydigita-core'),
                ],
            ],
            [
                'version' => '2.0.0',
                'changes' => [
                    __('Rebuilt as the multi-provider Mailer: 25 transactional APIs, 10 marketing '
                        . 'APIs, Gmail OAuth, and local PHP mail.', 'traydigita-core'),
                    __('Deliverability headers, test email tool, and send log added.', 'traydigita-core'),
                    __('Settings migrated from the old traydigita_smtp option; the old '
                        . 'option is kept as a fallback.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin page slug for the module settings page.
     *
     * Kept as `traydigita-smtp` (same reason as id()): admin
     * links and bookmarks to the old SMTP page keep working.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-smtp';
    }

    /**
     * Whether the module can run at all.
     *
     * No hard dependencies: PHPMailer ships with WordPress core
     * and the API providers use `wp_remote_post()`.
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether enough settings exist to send mail through the
     * selected provider (see definitionIsConfigured()).
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        $definition = self::providerDefinition($settings['provider']);
        if ($definition === null) {
            return false;
        }
        return self::definitionIsConfigured($definition, $settings);
    }

    /**
     * Whether this is a core module.
     *
     * @return bool Always false.
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Registers the `wp_mail` filter (deliverability headers),
     * the `phpmailer_init` hook (SMTP provider only), the
     * `pre_wp_mail` filter (API providers), the
     * `wp_mail_succeeded` / `wp_mail_failed` send-log hooks
     * (SMTP / default path only — see maybeSendViaApi()), the
     * incomplete-setup admin notice (only when unconfigured),
     * the test-email AJAX endpoint, and the Gmail OAuth
     * admin-post actions.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_filter('wp_mail', [self::class, 'applyDeliverabilityHeaders'], 10, 1);
        add_action('phpmailer_init', [self::class, 'configurePhpMailer']);
        add_filter('pre_wp_mail', [self::class, 'maybeSendViaApi'], 10, 2);
        add_action('wp_mail_succeeded', [self::class, 'onMailSucceeded']);
        add_action('wp_mail_failed', [self::class, 'onMailFailed']);
        if (!self::isConfigured()) {
            add_action('admin_notices', [self::class, 'maybeShowIncompleteSetupNotice']);
        }
        MailerAjax::register();
        add_action('admin_post_traydigita_mailer_gmail_start', [self::class, 'startGmailOAuth']);
        add_action('admin_post_traydigita_mailer_gmail_callback', [self::class, 'handleGmailCallback']);
        add_action('admin_post_traydigita_mailer_gmail_disconnect', [self::class, 'disconnectGmail']);
        // Coexistence with the theme's identical Mailer
        // implementation: when both Core and the theme are active
        // they each register the same `pre_wp_mail`,
        // `wp_mail_succeeded` / `wp_mail_failed` and
        // `admin_notices` callbacks (and the same test-mail AJAX
        // action), so one send is logged twice and the
        // not-configured notice renders twice. Core takes over
        // mail handling, so once the theme has booted (it wires
        // its hooks on `after_setup_theme` priority 10) any
        // foreign callback with the same method is removed.
        // Core boots on `after_setup_theme` priority 5, so the
        // removal cannot run here: the theme has not registered
        // its callbacks yet.
        if (did_action('after_setup_theme') && !doing_action('after_setup_theme')) {
            self::removeDuplicateMailerHooks();
        } else {
            add_action('after_setup_theme', [self::class, 'removeDuplicateMailerHooks'], 20);
        }
    }

    /**
     * Remove the theme's duplicate Mailer hooks, leaving Core as
     * the single owner of mail sending, the send log and the
     * not-configured admin notice.
     *
     * Runs on `after_setup_theme` priority 20, after the theme
     * has booted. Only callbacks whose method matches Core's own
     * but whose class is not Core's are removed; with any other
     * theme (or no Mailer implementation at all) nothing changes.
     * Removing each callback by its exact registered callable
     * (taken from `$wp_filter`) is what makes the removal
     * reliable: no class-name string matching is needed.
     *
     * @return void
     */
    public static function removeDuplicateMailerHooks(): void
    {
        self::removeForeignHookCallbacks('pre_wp_mail', 'maybeSendViaApi', self::class);
        self::removeForeignHookCallbacks('wp_mail_succeeded', 'onMailSucceeded', self::class);
        self::removeForeignHookCallbacks('wp_mail_failed', 'onMailFailed', self::class);
        self::removeForeignHookCallbacks('admin_notices', 'maybeShowIncompleteSetupNotice', self::class);
        self::removeForeignHookCallbacks(
            'wp_ajax_' . MailerAjax::AJAX_ACTION,
            'sendTest',
            MailerAjax::class
        );
    }

    /**
     * Remove every callback on a hook whose method matches the
     * given name but whose class is not the given class.
     *
     * The callbacks are collected from `$wp_filter` first and
     * removed afterwards, using each callback's exact registered
     * callable and priority, so `remove_filter()` always matches
     * the registration.
     *
     * @param string $hook Hook name.
     * @param string $method Method name identifying the duplicate.
     * @param string $ownClass Class whose callbacks must be kept.
     * @return void
     */
    private static function removeForeignHookCallbacks(
        string $hook,
        string $method,
        string $ownClass
    ): void {
        global $wp_filter;

        if (!isset($wp_filter[$hook])) {
            return;
        }

        $toRemove = [];

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;
                $class = null;

                if (is_array($function)
                    && isset($function[0], $function[1])
                    && $function[1] === $method
                ) {
                    $class = is_object($function[0])
                        ? $function[0]::class
                        : (is_string($function[0]) ? $function[0] : null);
                } elseif (is_string($function)
                    && str_contains($function, '::')
                ) {
                    [$className, $callbackMethod] = explode('::', $function, 2);

                    if ($callbackMethod === $method) {
                        $class = $className;
                    }
                }

                if ($class !== null && $class !== $ownClass) {
                    $toRemove[] = [$function, (int) $priority];
                }
            }
        }

        foreach ($toRemove as [$function, $priority]) {
            remove_filter($hook, $function, $priority);
        }
    }

    /**
     * Default settings, used when no option has been stored yet.
     *
     * Provider credential fields come from the provider
     * definitions (empty by default; `mailgun_region` defaults
     * to `us`). Deliverability headers default on — see
     * applyDeliverabilityHeaders() for what each group does.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [
            'provider' => 'smtp',
            'smtp_preset' => 'custom',
            'host' => '',
            'port' => self::DEFAULT_PORT,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_name' => (string) get_bloginfo('name'),
            'from_email' => (string) get_option('admin_email'),
            'headers_from' => true,
            'headers_unsubscribe' => true,
            'headers_message_id' => true,
            'headers_x_mailer' => true,
            'headers_auto' => true,
            'headers_feedback' => true,
        ];
        foreach (self::providerDefinitions() as $definition) {
            foreach ($definition['fields'] as $field) {
                if (!is_array($field) || empty($field['key']) || !is_string($field['key'])) {
                    continue;
                }
                $key = $field['key'];
                if (array_key_exists($key, $defaults)) {
                    continue;
                }
                $defaults[$key] = match ($key) {
                    'mailgun_region' => 'us',
                    'ses_region' => 'us-east-1',
                    default => '',
                };
            }
        }
        return $defaults;
    }

    /**
     * Persist one provider setting (used by providers that
     * refresh OAuth tokens in place, e.g. AWeber and Constant
     * Contact: the fresh token is saved back so the next send
     * uses it without the site owner pasting anything).
     *
     * The value is stored as-is; the caller is responsible for
     * validating it. Only keys that exist in the normalized
     * settings may be updated.
     *
     * @param string $key Setting key (a provider field key).
     * @param string $value New value.
     * @return void
     */
    public static function updateSetting(string $key, string $value): void
    {
        $settings = self::getSettings();
        if (!array_key_exists($key, $settings)) {
            return;
        }
        $settings[$key] = $value;
        update_option(self::OPTION, $settings, false);
    }

    /**
     * Read the stored settings, merged over the defaults and normalized.
     *
     * On first read, when the new option does not exist yet but
     * the legacy `traydigita_smtp` option does, the legacy SMTP
     * settings are migrated into the new option (provider `smtp`,
     * preset `custom`) and persisted. The legacy option is left
     * untouched. Values that cannot be valid fall back to their
     * defaults, so a hand-edited option can never break `wp_mail()`.
     * The returned array includes the secrets; callers must never
     * print, log, or return them. `renderSettingsPage()` strips
     * them before the template sees the settings.
     *
     * @return array<string, mixed> Normalized settings (SMTP fields, provider credential fields, deliverability header toggles).
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored)) {
            $legacy = get_option(self::LEGACY_OPTION, null);
            if (is_array($legacy) && $legacy !== []) {
                $migrated = array_merge(self::defaults(), $legacy);
                $migrated['provider'] = 'smtp';
                $migrated['smtp_preset'] = 'custom';
                unset($migrated['sendgrid_api_key'], $migrated['gmail_client_id'], $migrated['gmail_client_secret']);
                $stored = $migrated;
                update_option(self::OPTION, $migrated, false);
            } else {
                $stored = [];
            }
        }
        $settings = array_merge(self::defaults(), $stored);
        $provider = isset($settings['provider']) && is_string($settings['provider'])
            ? sanitize_key($settings['provider'])
            : 'smtp';
        if (!in_array($provider, self::providerIds(), true)) {
            $provider = 'smtp';
        }
        $preset = isset($settings['smtp_preset']) && is_string($settings['smtp_preset'])
            ? sanitize_key($settings['smtp_preset'])
            : 'custom';
        if (!in_array($preset, self::SMTP_PRESETS, true)) {
            $preset = 'custom';
        }
        $port = absint($settings['port'] ?? self::DEFAULT_PORT);
        if ($port < 1 || $port > 65535) {
            $port = self::DEFAULT_PORT;
        }
        $encryption = strtolower(trim(self::asString($settings['encryption'] ?? 'tls')));
        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            $encryption = 'tls';
        }
        $host = strtolower(trim(self::asString($settings['host'] ?? '')));
        if ($host !== '' && !self::isValidHost($host)) {
            $host = '';
        }
        $fromEmail = sanitize_email(self::asString($settings['from_email'] ?? ''));
        if ($fromEmail !== '' && is_email($fromEmail) === false) {
            $fromEmail = '';
        }
        $normalized = [
            'provider' => $provider,
            'smtp_preset' => $preset,
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'auth' => (bool) ($settings['auth'] ?? true),
            'username' => sanitize_text_field(self::asString($settings['username'] ?? '')),
            'password' => self::asString($settings['password'] ?? ''),
            'from_name' => sanitize_text_field(self::asString($settings['from_name'] ?? '')),
            'from_email' => $fromEmail,
            'headers_from' => (bool) ($settings['headers_from'] ?? true),
            'headers_unsubscribe' => (bool) ($settings['headers_unsubscribe'] ?? true),
            'headers_message_id' => (bool) ($settings['headers_message_id'] ?? true),
            'headers_x_mailer' => (bool) ($settings['headers_x_mailer'] ?? true),
            'headers_auto' => (bool) ($settings['headers_auto'] ?? true),
            'headers_feedback' => (bool) ($settings['headers_feedback'] ?? true),
        ];
        // Provider credential fields: secrets stored as-is,
        // select fields allowlisted against their definition,
        // text fields sanitized. Unknown keys are dropped so a
        // hand-edited option can never smuggle extra data in.
        $defaults = self::defaults();
        foreach (self::providerDefinitions() as $definition) {
            foreach ($definition['fields'] as $field) {
                if (!is_array($field) || empty($field['key']) || !is_string($field['key'])) {
                    continue;
                }
                $key = $field['key'];
                $raw = $settings[$key] ?? '';
                $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'text';
                if ($type === 'select' && is_array($field['options'] ?? null)) {
                    $value = is_string($raw) ? sanitize_key($raw) : '';
                    $normalized[$key] = array_key_exists($value, $field['options']) ? $value : $defaults[$key];
                } elseif (!empty($field['secret'])) {
                    $normalized[$key] = self::asString($raw);
                } else {
                    $normalized[$key] = sanitize_text_field(self::asString($raw));
                }
            }
        }
        return $normalized;
    }

    /**
     * Read the last test-email result, if one was recorded.
     *
     * Falls back to the legacy option when the new one is empty.
     *
     * @return array{time: int, success: bool}|null Null when no test has run yet.
     */
    public static function getLastTest(): ?array
    {
        $stored = get_option(self::LAST_TEST_OPTION, null);
        if (!is_array($stored) || !isset($stored['time'], $stored['success'])) {
            $stored = get_option(self::LEGACY_LAST_TEST_OPTION, null);
        }
        if (!is_array($stored) || !isset($stored['time'], $stored['success'])) {
            return null;
        }
        return [
            'time' => (int) $stored['time'],
            'success' => (bool) $stored['success'],
        ];
    }

    /**
     * Record the outcome of a test email (timestamp + success only).
     *
     * The recipient address and any error text are never stored.
     *
     * @param bool $success Whether the test email was sent.
     * @return void
     */
    public static function recordLastTest(bool $success): void
    {
        update_option(self::LAST_TEST_OPTION, ['time' => time(), 'success' => $success], false);
    }

    /**
     * Read the send log (newest first, at most LOG_MAX entries).
     *
     * @return array<int, array{time: int, to: string, subject: string, provider: string, result: string, error: string}>
     */
    public static function getSendLog(): array
    {
        $stored = get_option(self::LOG_OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $log = [];
        foreach ($stored as $entry) {
            if (!is_array($entry) || !isset($entry['time'], $entry['to'], $entry['subject'], $entry['provider'], $entry['result'])) {
                continue;
            }
            $log[] = [
                'time' => (int) $entry['time'],
                'to' => self::asString($entry['to']),
                'subject' => self::asString($entry['subject']),
                'provider' => self::asString($entry['provider']),
                'result' => self::asString($entry['result']) === 'sent' ? 'sent' : 'failed',
                'error' => self::asString($entry['error'] ?? ''),
            ];
        }
        return array_slice($log, 0, self::LOG_MAX);
    }

    /**
     * Append one entry to the send log, newest first, capped at LOG_MAX.
     *
     * The message body is never stored. The error text is
     * truncated and passed through redact() first.
     *
     * @param string $to Recipient list (already truncated by the caller).
     * @param string $subject Subject (already truncated by the caller).
     * @param string $provider Provider id that attempted the send.
     * @param bool $sent Whether the send succeeded.
     * @param string $error Error text, or '' on success.
     * @return void
     */
    public static function logSend(string $to, string $subject, string $provider, bool $sent, string $error = ''): void
    {
        $log = self::getSendLog();
        array_unshift($log, [
            'time' => time(),
            'to' => mb_substr($to, 0, self::LOG_TEXT_MAX),
            'subject' => mb_substr($subject, 0, self::LOG_TEXT_MAX),
            'provider' => in_array($provider, self::providerIds(), true) ? $provider : 'smtp',
            'result' => $sent ? 'sent' : 'failed',
            'error' => $error === '' ? '' : mb_substr(self::redact($error), 0, self::LOG_TEXT_MAX),
        ]);
        update_option(self::LOG_OPTION, array_slice($log, 0, self::LOG_MAX), false);
    }

    /**
     * Remove stored secrets from a string, if they appear in it.
     *
     * Used before writing exception or mailer error messages to
     * the log, a JSON response, or the Activity Log. Only secrets
     * of at least four characters are redacted: replacing a
     * one-character secret would mangle every message that
     * contains that letter.
     *
     * @param string $text Text that may contain a secret.
     * @return string
     */
    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $settings = self::getSettings();
        $secrets = [$settings['password']];
        foreach (self::providerDefinitions() as $definition) {
            foreach ($definition['fields'] as $field) {
                if (!is_array($field) || empty($field['secret']) || empty($field['key']) || !is_string($field['key'])) {
                    continue;
                }
                $secrets[] = isset($settings[$field['key']]) && is_string($settings[$field['key']]) ? $settings[$field['key']] : '';
            }
        }
        $tokens = GmailOAuth::getStoredTokens();
        if (is_array($tokens)) {
            $secrets[] = (string) ($tokens['access_token'] ?? '');
            $secrets[] = (string) ($tokens['refresh_token'] ?? '');
        }
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace($secret, '••••••••', $text);
            }
        }
        return $text;
    }

    /**
     * Validate and save the settings submitted by the shell save endpoint.
     *
     * Only keys present in `$data` are changed; missing keys keep
     * their stored values. Secrets (SMTP password, SendGrid key,
     * Gmail client secret) are only overwritten when a non-empty
     * value is submitted; an empty or missing field keeps the
     * stored secret. When the provider is SMTP and the preset is
     * not `custom`, the preset's host / port / encryption win
     * over the submitted connection fields. An empty host is
     * allowed and means "not configured" for the SMTP provider.
     * On failure the returned message lists every invalid field
     * and nothing is saved. A new selection of an unverified
     * provider is refused the same way (nothing saved); saving
     * the already-stored provider again is always allowed, so a
     * grandfathered selection is never broken by re-saving.
     *
     * @param array<string, mixed> $data Submitted settings.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Mailer settings.', 'traydigita-core'),
            ];
        }
        $current = self::getSettings();
        $new = $current;
        $invalid = [];
        // A new selection of an unverified provider is refused:
        // only ready providers may be picked (see
        // READY_PROVIDERS). Re-saving the provider that is
        // already stored is always allowed, so a site that
        // selected a provider before verification keeps working
        // — grandfathered, not broken by this gate.
        $providerNotReady = false;

        if (array_key_exists('provider', $data)) {
            $provider = sanitize_key(self::inputString($data, 'provider'));
            if (!in_array($provider, self::providerIds(), true)) {
                $invalid[] = __('Provider', 'traydigita-core');
            } elseif ($provider !== $current['provider'] && !self::providerIsReady($provider)) {
                $providerNotReady = true;
            } else {
                $new['provider'] = $provider;
            }
        }

        if (array_key_exists('smtp_preset', $data)) {
            $preset = sanitize_key(self::inputString($data, 'smtp_preset'));
            if (!in_array($preset, self::SMTP_PRESETS, true)) {
                $invalid[] = __('SMTP preset', 'traydigita-core');
            } else {
                $new['smtp_preset'] = $preset;
            }
        }

        // A named preset owns the connection: its host, port and
        // encryption replace whatever was submitted for them.
        if ($new['provider'] === 'smtp' && $new['smtp_preset'] !== 'custom') {
            $presetValues = self::SMTP_PRESET_VALUES[$new['smtp_preset']];
            $new['host'] = $presetValues['host'];
            $new['port'] = $presetValues['port'];
            $new['encryption'] = $presetValues['encryption'];
        } else {
            if (array_key_exists('host', $data)) {
                $host = strtolower(trim(sanitize_text_field(wp_unslash(self::inputString($data, 'host')))));
                if ($host === '') {
                    $new['host'] = '';
                } elseif (!self::isValidHost($host)) {
                    $invalid[] = __('Host', 'traydigita-core');
                } else {
                    $new['host'] = $host;
                }
            }

            if (array_key_exists('port', $data)) {
                $rawPort = trim(self::inputString($data, 'port'));
                if ($rawPort === '') {
                    $new['port'] = self::DEFAULT_PORT;
                } else {
                    $port = self::parsePort($rawPort);
                    if ($port === null) {
                        $invalid[] = __('Port', 'traydigita-core');
                    } else {
                        $new['port'] = $port;
                    }
                }
            }

            if (array_key_exists('encryption', $data)) {
                $encryption = strtolower(trim(self::inputString($data, 'encryption')));
                if (!in_array($encryption, self::ENCRYPTIONS, true)) {
                    $invalid[] = __('Encryption', 'traydigita-core');
                } else {
                    $new['encryption'] = $encryption;
                }
            }
        }

        if (array_key_exists('auth', $data)) {
            $new['auth'] = (bool) filter_var(self::inputValue($data, 'auth'), FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('username', $data)) {
            $new['username'] = sanitize_text_field(wp_unslash(self::inputString($data, 'username')));
        }

        if (array_key_exists('password', $data)) {
            // Stored as-is (no sanitize_text_field: passwords may
            // legitimately contain characters it would strip).
            // Only a non-empty submission overwrites the stored value.
            $password = wp_unslash(self::inputString($data, 'password'));
            if ($password !== '') {
                $new['password'] = $password;
            }
        }

        if (array_key_exists('from_name', $data)) {
            $new['from_name'] = sanitize_text_field(wp_unslash(self::inputString($data, 'from_name')));
        }

        if (array_key_exists('from_email', $data)) {
            $email = sanitize_email(wp_unslash(self::inputString($data, 'from_email')));
            if ($email === '') {
                $new['from_email'] = '';
            } elseif (is_email($email) === false) {
                $invalid[] = __('From email', 'traydigita-core');
            } else {
                $new['from_email'] = $email;
            }
        }

        // Provider credential fields, from the provider
        // definitions: secrets (API keys, client secret) are
        // stored as-is and only overwritten when a non-empty
        // value is submitted; select fields are allowlisted
        // against their definition; text fields are sanitized.
        foreach (self::providerDefinitions() as $definition) {
            foreach ($definition['fields'] as $field) {
                if (!is_array($field) || empty($field['key']) || !is_string($field['key'])) {
                    continue;
                }
                $key = $field['key'];
                if (!array_key_exists($key, $data)) {
                    continue;
                }
                $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'text';
                if ($type === 'select' && is_array($field['options'] ?? null)) {
                    $value = sanitize_key(self::inputString($data, $key));
                    if (!array_key_exists($value, $field['options'])) {
                        $invalid[] = isset($field['label']) && is_string($field['label']) ? $field['label'] : $key;
                    } else {
                        $new[$key] = $value;
                    }
                    continue;
                }
                $submitted = wp_unslash(self::inputString($data, $key));
                if (!empty($field['secret'])) {
                    // API keys and secrets may contain characters
                    // sanitizers strip, so the value is stored
                    // as-is; only a non-empty submission
                    // overwrites the stored value.
                    if ($submitted !== '') {
                        $new[$key] = $submitted;
                    }
                    continue;
                }
                $new[$key] = sanitize_text_field($submitted);
            }
        }

        // Deliverability header toggles (on/off per group).
        foreach (['headers_from', 'headers_unsubscribe', 'headers_message_id', 'headers_x_mailer', 'headers_auto', 'headers_feedback'] as $headerKey) {
            if (array_key_exists($headerKey, $data)) {
                $new[$headerKey] = (bool) filter_var(self::inputValue($data, $headerKey), FILTER_VALIDATE_BOOLEAN);
            }
        }

        if ($invalid !== []) {
            return [
                'success' => false,
                /* translators: %s is a comma-separated list of invalid field names. */
                'message' => sprintf(__('Please check the following fields: %s.', 'traydigita-core'), implode(', ', $invalid)),
            ];
        }

        if ($providerNotReady) {
            return [
                'success' => false,
                'message' => __('That provider is not verified yet. Pick a ready provider.', 'traydigita-core'),
            ];
        }

        update_option(self::OPTION, $new, false);
        return [
            'success' => true,
            'message' => __('Mailer settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Framework sections for the left navigation.
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'provider', 'label' => __('Provider', 'traydigita-core'), 'icon' => 'email'],
            ['id' => 'connection', 'label' => __('Connection', 'traydigita-core'), 'icon' => 'email-alt'],
            ['id' => 'from', 'label' => __('From', 'traydigita-core'), 'icon' => 'admin-users'],
            ['id' => 'headers', 'label' => __('Deliverability', 'traydigita-core'), 'icon' => 'shield'],
            ['id' => 'test', 'label' => __('Test & Log', 'traydigita-core'), 'icon' => 'list-view'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Locates `templates/admin/modules/mailer.php` and requires it
     * with the settings (secret keys removed), the provider
     * definitions, the configured state, whether each secret is
     * stored, the Gmail connection state, the last test result,
     * the send log, and the nonce / AJAX action names. The
     * template never receives a secret value itself.
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
        $mailerSettings = $settings;
        // Strip every secret field (SMTP password + all
        // provider secrets) before the template sees the settings.
        unset($mailerSettings['password']);
        foreach (self::providerDefinitions() as $definition) {
            foreach ($definition['fields'] as $field) {
                if (is_array($field) && !empty($field['secret']) && isset($field['key']) && is_string($field['key'])) {
                    unset($mailerSettings[$field['key']]);
                }
            }
        }
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $mailerProviders = self::providerDefinitions();
        $mailerProviderHasSecret = [];
        foreach ($mailerProviders as $providerId => $definition) {
            $hasSecret = false;
            foreach ($definition['fields'] as $field) {
                if (!is_array($field) || empty($field['secret']) || empty($field['key']) || !is_string($field['key'])) {
                    continue;
                }
                if (($settings[$field['key']] ?? '') !== '') {
                    $hasSecret = true;
                    break;
                }
            }
            $mailerProviderHasSecret[$providerId] = $hasSecret;
        }
        $mailerConfigured = self::isConfigured();
        $mailerHasPassword = $settings['password'] !== '';
        $mailerHasSendGridKey = $settings['sendgrid_api_key'] !== '';
        $mailerHasGmailSecret = $settings['gmail_client_secret'] !== '';
        $mailerGmailConnected = GmailOAuth::isConnected();
        $mailerGmailEmail = GmailOAuth::connectedEmail();
        $mailerLastTest = self::getLastTest();
        $mailerSendLog = array_slice(self::getSendLog(), 0, 15);
        $mailerNonceAction = self::NONCE_ACTION;
        $mailerTestAction = MailerAjax::AJAX_ACTION;
        $mailerSaveAction = 'traydigita_admin_save';
        $mailerGmailStartUrl = wp_nonce_url(
            admin_url('admin-post.php?action=traydigita_mailer_gmail_start'),
            self::GMAIL_OAUTH_NONCE_ACTION
        );
        $mailerGmailDisconnectUrl = wp_nonce_url(
            admin_url('admin-post.php?action=traydigita_mailer_gmail_disconnect'),
            self::GMAIL_OAUTH_NONCE_ACTION
        );
        // Redirect URI the site owner registers in the Google
        // Cloud OAuth client: the same value GmailOAuth uses in
        // both the authorization request and the code exchange,
        // so the template shows it from the code, never a copy.
        $mailerGmailRedirectUri = GmailOAuth::redirectUri();
        $mailerResult = isset($_GET['mailer-result']) ? sanitize_key(wp_unslash($_GET['mailer-result'])) : '';
        $mailerResultCode = isset($_GET['mailer-code']) ? sanitize_key(wp_unslash($_GET['mailer-code'])) : '';
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/mailer.php';
        if (!file_exists($file)) {
            return;
        }
        require $file;
    }

    /**
     * Configure PHPMailer to send through the configured SMTP server.
     *
     * Only for the SMTP provider: the API providers never reach
     * PHPMailer (see maybeSendViaApi()). Does nothing when the
     * module is not configured for SMTP, so `wp_mail()` keeps
     * working with the WordPress default mailer. Any failure
     * while applying the settings is caught and logged (with
     * secrets redacted) instead of fataling the mail send.
     *
     * @param PHPMailer $phpmailer Mailer instance provided by WordPress.
     * @return void
     */
    public static function configurePhpMailer(PHPMailer $phpmailer): void
    {
        $settings = self::getSettings();
        if ($settings['provider'] !== 'smtp') {
            return;
        }
        if ($settings['host'] === '' || ($settings['auth'] && $settings['username'] === '')) {
            return;
        }
        try {
            $phpmailer->isSMTP();
            $phpmailer->Host = $settings['host'];
            $phpmailer->Port = $settings['port'];
            $phpmailer->SMTPSecure = match ($settings['encryption']) {
                'ssl' => 'ssl',
                'tls' => 'tls',
                default => '',
            };
            // Never opportunistically upgrade a connection the site
            // owner explicitly configured as unencrypted.
            $phpmailer->SMTPAutoTLS = $settings['encryption'] !== 'none';
            $phpmailer->SMTPAuth = $settings['auth'];
            if ($settings['auth']) {
                $phpmailer->Username = $settings['username'];
                $phpmailer->Password = $settings['password'];
            }
            $phpmailer->Timeout = 10;
            // Always verify the server certificate; never disable
            // verification to work around a bad certificate — with
            // one exception: a loopback host (Proton Mail Bridge,
            // 127.0.0.1:1025) presents a self-signed certificate
            // for a connection that never leaves this machine, so
            // verification is meaningless there and is skipped
            // for loopback hosts only.
            $loopback = in_array($settings['host'], ['127.0.0.1', '::1', 'localhost'], true);
            $phpmailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => !$loopback,
                    'verify_peer_name' => !$loopback,
                    'allow_self_signed' => $loopback,
                ],
            ];
            if ($settings['from_email'] !== '' && is_email($settings['from_email'])) {
                // Only override the From address when WordPress
                // left its own default (`wordpress@<site host>`)
                // or nothing at all: a caller-provided From header
                // (e.g. the newsletter sender identity) is
                // respected, matching plain wp_mail() behaviour.
                $defaultFrom = 'wordpress@' . strtolower((string) parse_url(home_url(), PHP_URL_HOST));
                $currentFrom = strtolower(trim((string) $phpmailer->From));
                if ($currentFrom === '' || $currentFrom === $defaultFrom) {
                    $phpmailer->From = $settings['from_email'];
                    $phpmailer->Sender = $settings['from_email'];
                    if ($settings['from_name'] !== '') {
                        $phpmailer->FromName = $settings['from_name'];
                    }
                }
            }
            // Deliverability headers for the SMTP path (the API
            // path gets them from normalizeMail() instead):
            // Reply-To aligned with From, a unique Message-ID,
            // and the X-Mailer identifier. List-Unsubscribe,
            // Auto-Submitted, Precedence, and Feedback-ID arrive
            // as custom headers through the `wp_mail` filter
            // (see applyDeliverabilityHeaders()).
            if (!empty($settings['headers_from']) && $settings['from_email'] !== '' && $phpmailer->getReplyToAddresses() === []) {
                $phpmailer->addReplyTo($settings['from_email'], $settings['from_name']);
            }
            if (!empty($settings['headers_message_id'])) {
                $phpmailer->MessageID = self::buildMessageId($settings);
            }
            if (!empty($settings['headers_x_mailer'])) {
                $phpmailer->XMailer = 'TrayDigita Core Mailer ' . \TrayDigita\WP\Plugin\Core\Core::VERSION;
            }
        } catch (Throwable) {
            // No error_log here: a mail send must never spam the
            // log on a hot path. The failure surfaces through the
            // wp_mail_failed action and the send log instead.
        }
    }

    /**
     * Send one mail through Core Mailer from another module.
     *
     * This is the way Core modules (Newsletter, Contact Form,
     * and anything else that sends mail) send mail: it goes
     * through `wp_mail()`, so the selected provider delivers it
     * and the send lands in the Mailer send log. Pass the
     * `X-TrayDigita-Bulk: 1` header for bulk mail (newsletters)
     * so the bulk deliverability headers (Precedence, Feedback-ID)
     * are applied.
     *
     * @param string|array<int, string> $to Recipient address(es).
     * @param string $subject Subject.
     * @param string $message Body (plain text or HTML; add a `Content-Type: text/html` header for HTML).
     * @param array<int, string> $headers Extra headers.
     * @param array<int, string> $attachments Attachment paths.
     * @return bool Whether the mail was accepted for delivery.
     */
    public static function sendMail(string|array $to, string $subject, string $message, array $headers = [], array $attachments = []): bool
    {
        return (bool) wp_mail($to, $subject, $message, $headers, $attachments);
    }

    /**
     * Add deliverability headers to every outgoing mail.
     *
     * `wp_mail` filter: runs before `pre_wp_mail`, so the headers
     * reach both the SMTP path (WordPress applies them to
     * PHPMailer) and the API path (normalizeMail() collects them
     * into the provider payload). Each group is a setting, all
     * default on:
     *
     * - `headers_from` — Reply-To set to the From address, so
     *   replies go to the site instead of bouncing from a
     *   no-reply address. Skipped when the caller already set a
     *   Reply-To header.
     * - `headers_unsubscribe` — List-Unsubscribe (a `mailto:`
     *   link to the From address, plus an unsubscribe URL when
     *   a module provides one through the
     *   `traydigita:core:mailer/unsubscribe-url` filter) and
     *   List-Unsubscribe-Post for one-click unsubscribe. Mailbox
     *   providers use this to show an unsubscribe button instead
     *   of a spam button, and Gmail requires it for bulk senders.
     * - `headers_auto` — Auto-Submitted: auto-generated, so
     *   auto-replies and out-of-office replies do not loop.
     *   Bulk mail (a mail carrying the `X-TrayDigita-Bulk`
     *   header, e.g. a newsletter) also gets Precedence: bulk.
     * - `headers_feedback` — Feedback-ID for bulk mail only,
     *   so the site owner can track complaints per list in the
     *   provider dashboard. The list id comes from the
     *   `traydigita:core:mailer/feedback-id` filter (default
     *   `traydigita`).
     *
     * Message-ID and X-Mailer are NOT added here: PHPMailer
     * generates its own Message-ID (overridden in
     * configurePhpMailer()) and its own X-Mailer header, so
     * adding them as custom headers would duplicate them.
     * normalizeMail() adds both to the API payload instead.
     *
     * @param array<string, mixed> $atts wp_mail() arguments.
     * @return array<string, mixed> Arguments with headers added.
     */
    public static function applyDeliverabilityHeaders(array $atts): array
    {
        $settings = self::getSettings();
        $headers = $atts['headers'] ?? [];
        if (!is_array($headers)) {
            $headers = $headers === '' ? [] : [$headers];
        }
        $present = [];
        $isBulk = false;
        $headerFrom = '';
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = strtolower(trim($parts[0]));
            $present[$name] = true;
            if ($name === 'x-traydigita-bulk') {
                $isBulk = true;
            }
            if ($name === 'from') {
                // A caller-provided From (e.g. the newsletter
                // sender identity) wins over the Mailer From for
                // the headers below; normalizeMail() applies the
                // same override to the provider payload.
                $parsed = self::parseAddresses(trim($parts[1]));
                if ($parsed !== []) {
                    $headerFrom = $parsed[0]['email'];
                }
            }
        }
        $fromEmail = $settings['from_email'] ?? '';
        if (!is_string($fromEmail)) {
            $fromEmail = '';
        }
        if ($headerFrom !== '') {
            $fromEmail = $headerFrom;
        }
        if (!empty($settings['headers_from']) && $fromEmail !== '' && !isset($present['reply-to'])) {
            $headers[] = 'Reply-To: ' . $fromEmail;
        }
        if (!empty($settings['headers_unsubscribe']) && $fromEmail !== '' && !isset($present['list-unsubscribe'])) {
            $links = ['<mailto:' . $fromEmail . '?subject=unsubscribe>'];
            /** @var mixed $unsubscribeUrl */
            $unsubscribeUrl = apply_filters('traydigita:core:mailer/unsubscribe-url', '', $atts);
            if (is_string($unsubscribeUrl) && $unsubscribeUrl !== '') {
                $links[] = '<' . $unsubscribeUrl . '>';
            }
            $headers[] = 'List-Unsubscribe: ' . implode(', ', $links);
            if (!isset($present['list-unsubscribe-post'])) {
                $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
            }
        }
        if (!empty($settings['headers_auto']) && !isset($present['auto-submitted'])) {
            $headers[] = 'Auto-Submitted: auto-generated';
        }
        if ($isBulk) {
            if (!empty($settings['headers_auto']) && !isset($present['precedence'])) {
                $headers[] = 'Precedence: bulk';
            }
            if (!empty($settings['headers_feedback']) && !isset($present['feedback-id'])) {
                /** @var mixed $listId */
                $listId = apply_filters('traydigita:core:mailer/feedback-id', 'traydigita');
                $listId = is_string($listId) && $listId !== '' ? $listId : 'traydigita';
                $headers[] = 'Feedback-ID: ' . $listId . ':' . self::mailHost($settings);
            }
        }
        $atts['headers'] = $headers;
        return $atts;
    }

    /**
     * Host used in Message-ID and Feedback-ID headers: the
     * domain of the From address, falling back to the site host.
     *
     * @param array<string, mixed> $settings Mailer settings.
     * @return string
     */
    public static function mailHost(array $settings): string
    {
        $fromEmail = isset($settings['from_email']) && is_string($settings['from_email']) ? $settings['from_email'] : '';
        if ($fromEmail !== '' && str_contains($fromEmail, '@')) {
            $domain = strtolower(trim((string) explode('@', $fromEmail)[1]));
            if ($domain !== '') {
                return $domain;
            }
        }
        $host = (string) parse_url(home_url(), PHP_URL_HOST);
        return $host !== '' ? $host : 'localhost';
    }

    /**
     * Build a unique Message-ID for one mail.
     *
     * @param array<string, mixed> $settings Mailer settings.
     * @return string
     */
    public static function buildMessageId(array $settings): string
    {
        return '<' . md5(uniqid('traydigita-', true)) . '@' . self::mailHost($settings) . '>';
    }

    /**
     * Send through an API provider, short-circuiting `wp_mail()`.
     *
     * `pre_wp_mail` filter: return null to let WordPress send
     * normally (SMTP provider, unconfigured provider, or a
     * provider whose credentials are missing — the test endpoint
     * refuses those before calling `wp_mail()`), or a boolean
     * for an API attempt. A non-null `$pre` is returned untouched:
     * another `pre_wp_mail` callback already handled the mail.
     * The result is logged here directly:
     * WordPress fires neither `wp_mail_succeeded` nor
     * `wp_mail_failed` for a short-circuited send, so the two
     * action hooks below only ever see the SMTP / default path
     * and a send can never be logged twice. (When the theme's
     * identical Mailer is also active, its duplicate callbacks
     * are removed by `removeDuplicateMailerHooks()` in `boot()`.)
     *
     * Fires `traydigita:core:mailer/send` before the attempt
     * (with the provider id and the normalized mail) and
     * `traydigita:core:mailer/sent` /
     * `traydigita:core:mailer/failed` after it, so other code
     * can react to sends without touching the send log.
     *
     * @param bool|null $pre Short-circuit value (always null on entry).
     * @param array<string, mixed> $atts Normalized wp_mail arguments.
     * @return bool|null True/false for an API attempt, null to fall through.
     */
    public static function maybeSendViaApi(?bool $pre, array $atts): ?bool
    {
        // Another `pre_wp_mail` callback already handled this
        // mail (a non-null short-circuit value): sending again
        // here would deliver the mail twice and log it twice.
        if ($pre !== null) {
            return $pre;
        }
        $settings = self::getSettings();
        $provider = $settings['provider'];
        if ($provider === 'smtp' || $provider === 'local') {
            // SMTP and local mail go through wp_mail() normally;
            // only API providers short-circuit here.
            return $pre;
        }
        $definition = self::providerDefinition($provider);
        if ($definition === null || !self::definitionIsConfigured($definition, $settings)) {
            return $pre;
        }
        $mail = self::normalizeMail($atts, $settings);
        if ($mail === null) {
            return $pre;
        }
        do_action('traydigita:core:mailer/send', $provider, $mail);
        $result = self::sendViaProvider($provider, $mail, $settings);
        self::logSend($mail['to_text'], $mail['subject'], $provider, $result['success'], $result['message']);
        do_action(
            $result['success'] ? 'traydigita:core:mailer/sent' : 'traydigita:core:mailer/failed',
            $provider,
            $mail,
            $result['message']
        );
        if (class_exists(ActivityLogModule::class)) {
            ActivityLogModule::record(
                'smtp',
                $result['success'] ? 'mail-sent' : 'mail-failed',
                $result['success']
                    ? sprintf('Mail sent through %s to %s.', $provider, $mail['to_text'])
                    : sprintf('Mail through %s failed: %s', $provider, $result['message'])
            );
        }
        return $result['success'];
    }

    /**
     * Log an SMTP / default-mailer success (wp_mail_succeeded).
     *
     * Only fires on the non-short-circuited path, so API sends
     * never reach this hook (see maybeSendViaApi()).
     *
     * @param array<string, mixed> $mailData Mail data passed by WordPress.
     * @return void
     */
    public static function onMailSucceeded(array $mailData): void
    {
        $settings = self::getSettings();
        $to = self::mailDataTo($mailData);
        $subject = isset($mailData['subject']) && is_string($mailData['subject']) ? $mailData['subject'] : '';
        self::logSend($to, $subject, $settings['provider'], true);
        if (class_exists(ActivityLogModule::class)) {
            ActivityLogModule::record(
                'smtp',
                'mail-sent',
                sprintf('Mail sent through %s to %s.', $settings['provider'], $to)
            );
        }
    }

    /**
     * Log an SMTP / default-mailer failure (wp_mail_failed).
     *
     * @param \WP_Error $error Mail error passed by WordPress.
     * @return void
     */
    public static function onMailFailed(\WP_Error $error): void
    {
        $settings = self::getSettings();
        $mailData = $error->get_error_data('wp_mail_failed');
        $to = '';
        $subject = '';
        if (is_array($mailData)) {
            $to = self::mailDataTo($mailData);
            $subject = isset($mailData['subject']) && is_string($mailData['subject']) ? $mailData['subject'] : '';
        }
        $message = self::redact($error->get_error_message());
        self::logSend($to, $subject, $settings['provider'], false, $message);
        if (class_exists(ActivityLogModule::class)) {
            ActivityLogModule::record(
                'smtp',
                'mail-failed',
                sprintf('Mail through %s failed: %s', $settings['provider'], $message)
            );
        }
    }

    /**
     * Recipient list from a wp_mail_succeeded / wp_mail_failed payload.
     *
     * @param array<string, mixed> $mailData Mail data passed by WordPress.
     * @return string Comma-separated recipients, truncated.
     */
    private static function mailDataTo(array $mailData): string
    {
        $to = $mailData['to'] ?? '';
        if (is_array($to)) {
            $to = implode(', ', array_map(static fn ($item): string => is_string($item) ? $item : '', $to));
        } elseif (!is_string($to)) {
            $to = '';
        }
        return mb_substr(trim($to), 0, self::LOG_TEXT_MAX);
    }

    /**
     * Start the Gmail OAuth flow (admin-post action).
     *
     * Verifies the nonce and the capability, then redirects to
     * Google's authorization page. The state parameter carries a
     * fresh nonce that the callback verifies.
     *
     * @return void
     */
    public static function startGmailOAuth(): void
    {
        check_admin_referer(self::GMAIL_OAUTH_NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_redirect(admin_url('admin.php?page=' . self::settingsPageSlug() . '&mailer-result=error&mailer-code=forbidden'));
            exit;
        }
        $settings = self::getSettings();
        if ($settings['gmail_client_id'] === '' || $settings['gmail_client_secret'] === '') {
            wp_redirect(admin_url('admin.php?page=' . self::settingsPageSlug() . '&mailer-result=error&mailer-code=no-client'));
            exit;
        }
        $state = wp_create_nonce(self::GMAIL_OAUTH_NONCE_ACTION);
        wp_redirect(GmailOAuth::authUrl($settings['gmail_client_id'], $state));
        exit;
    }

    /**
     * Handle the Gmail OAuth callback (admin-post action).
     *
     * Verifies the state nonce and the capability, exchanges the
     * code for tokens, and stores them. Google redirects here
     * with `?code=…&state=…`; a missing or invalid state, a
     * Google error, or a failed exchange all redirect back to
     * the settings page with an allowlisted error code — the
     * raw error text is never placed in the URL.
     *
     * @return void
     */
    public static function handleGmailCallback(): void
    {
        $base = admin_url('admin.php?page=' . self::settingsPageSlug());
        if (!current_user_can('manage_options')) {
            wp_redirect($base . '&mailer-result=error&mailer-code=forbidden');
            exit;
        }
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if ($state === '' || !wp_verify_nonce($state, self::GMAIL_OAUTH_NONCE_ACTION)) {
            wp_redirect($base . '&mailer-result=error&mailer-code=bad-state');
            exit;
        }
        $googleError = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';
        if ($googleError !== '') {
            wp_redirect($base . '&mailer-result=error&mailer-code=google-' . $googleError);
            exit;
        }
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if ($code === '') {
            wp_redirect($base . '&mailer-result=error&mailer-code=no-code');
            exit;
        }
        $settings = self::getSettings();
        $exchanged = GmailOAuth::exchangeCode(
            $code,
            $settings['gmail_client_id'],
            $settings['gmail_client_secret']
        );
        if ($exchanged instanceof \WP_Error) {
            wp_redirect($base . '&mailer-result=error&mailer-code=exchange-failed');
            exit;
        }
        wp_redirect($base . '&mailer-result=connected');
        exit;
    }

    /**
     * Disconnect the Gmail account (admin-post action).
     *
     * Deletes the stored OAuth tokens. The client id / secret
     * stay saved, so reconnecting only needs one click.
     *
     * @return void
     */
    public static function disconnectGmail(): void
    {
        check_admin_referer(self::GMAIL_OAUTH_NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_redirect(admin_url('admin.php?page=' . self::settingsPageSlug() . '&mailer-result=error&mailer-code=forbidden'));
            exit;
        }
        GmailOAuth::clearTokens();
        wp_redirect(admin_url('admin.php?page=' . self::settingsPageSlug() . '&mailer-result=disconnected'));
        exit;
    }

    /**
     * Human text for a Gmail OAuth redirect result code.
     *
     * Allowlist only: unknown codes render no notice at all, and
     * raw request input is never echoed.
     *
     * @param string $result Result flag from the redirect URL.
     * @param string $code Detail code from the redirect URL.
     * @return string Human-readable message, or '' for an unknown code.
     */
    public static function gmailResultMessage(string $result, string $code): string
    {
        if ($result === 'connected') {
            return __('Gmail is connected. Mail will now be sent through the Gmail API.', 'traydigita-core');
        }
        if ($result === 'disconnected') {
            return __('Gmail is disconnected. Mail falls back to the configured provider settings.', 'traydigita-core');
        }
        if ($result !== 'error') {
            return '';
        }
        return match ($code) {
            'forbidden' => __('You are not allowed to do that.', 'traydigita-core'),
            'no-client' => __('Enter the Google OAuth client ID and client secret first, then connect.', 'traydigita-core'),
            'bad-state' => __('The Google sign-in reply could not be verified. Please try connecting again.', 'traydigita-core'),
            'no-code' => __('Google did not return an authorization code. Please try connecting again.', 'traydigita-core'),
            'exchange-failed' => __('Google refused the authorization code. Check the client ID and secret, then try connecting again.', 'traydigita-core'),
            default => __('Gmail could not be connected. Please try again.', 'traydigita-core'),
        };
    }

    /**
     * Show a "Mailer not configured" notice on TrayDigita admin pages.
     *
     * Only registered by `boot()` while the module is unconfigured.
     * Shown only to administrators on screens whose id contains
     * `traydigita`, with a link to this module's settings page.
     *
     * @return void
     */
    public static function maybeShowIncompleteSetupNotice(): void
    {
        $screen = get_current_screen();
        if ($screen === null || !str_contains((string) $screen->id, 'traydigita')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $url = admin_url('admin.php?page=' . self::settingsPageSlug());
        echo '<div class="traydigita-admin-notice traydigita-admin-notice-warning"><p>'
            . esc_html__('Mailer is not configured yet. Outgoing mail will use the WordPress default mailer.', 'traydigita-core')
            . ' '
            . sprintf(
                '<a href="%s">%s</a>',
                esc_url($url),
                esc_html__('Open Mailer settings', 'traydigita-core')
            )
            . '</p></div>';
    }

    /**
     * Normalize `wp_mail()` arguments for the API providers.
     *
     * Parses To / Cc / Bcc (arguments and headers), the content
     * type (HTML when a `text/html` header is present, plain text
     * otherwise), Reply-To, the subject, the body, and the
     * attachments. Any other header (including the deliverability
     * headers added by applyDeliverabilityHeaders()) is collected
     * into `headers` so the provider payloads can carry it, and
     * an `X-TrayDigita-Bulk` header marks the mail as bulk and
     * is stripped from the payload headers. Message-ID and
     * X-Mailer are added here for the API path (the SMTP path
     * gets them from configurePhpMailer() instead). Returns null
     * when there is no usable recipient — the caller then falls
     * through to normal `wp_mail()` instead of short-circuiting
     * a send to nowhere.
     *
     * @param array<string, mixed> $atts Normalized wp_mail arguments.
     * @param array<string, mixed> $settings Mailer settings.
     * @return array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, to_text: string, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>}|null
     */
    public static function normalizeMail(array $atts, array $settings): ?array
    {
        $to = self::parseAddresses($atts['to'] ?? '');
        $headers = $atts['headers'] ?? [];
        if (!is_array($headers)) {
            $headers = $headers === '' ? [] : [$headers];
        }
        $cc = [];
        $bcc = [];
        $isHtml = false;
        $isBulk = false;
        $replyTo = '';
        $headerFrom = null;
        $extraHeaders = [];
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ($name === 'cc') {
                $cc = array_merge($cc, self::parseAddresses($value));
            } elseif ($name === 'bcc') {
                $bcc = array_merge($bcc, self::parseAddresses($value));
            } elseif ($name === 'content-type' && str_contains(strtolower($value), 'text/html')) {
                $isHtml = true;
            } elseif ($name === 'reply-to') {
                $parsed = self::parseAddresses($value);
                if ($parsed !== []) {
                    $replyTo = $parsed[0]['email'];
                }
            } elseif ($name === 'x-traydigita-bulk') {
                $isBulk = true;
            } elseif ($name === 'from') {
                // A caller-provided From (e.g. the newsletter
                // sender identity) overrides the Mailer From for
                // this mail; the provider sends from it.
                $parsed = self::parseAddresses($value);
                if ($parsed !== []) {
                    $headerFrom = $parsed[0];
                }
                continue;
            } else {
                // Keep the original header name casing for the
                // provider payloads.
                $extraHeaders[trim($parts[0])] = $value;
            }
        }
        if ($to === []) {
            return null;
        }
        $subject = isset($atts['subject']) && is_string($atts['subject']) ? $atts['subject'] : '';
        $body = isset($atts['message']) && is_string($atts['message']) ? $atts['message'] : '';
        $attachments = $atts['attachments'] ?? [];
        if (!is_array($attachments)) {
            $attachments = $attachments === '' ? [] : [$attachments];
        }
        $files = [];
        foreach ($attachments as $file) {
            if (is_string($file) && $file !== '') {
                $files[] = $file;
            }
        }
        $fromEmail = is_string($settings['from_email'] ?? null) ? (string) $settings['from_email'] : '';
        $fromName = is_string($settings['from_name'] ?? null) ? (string) $settings['from_name'] : '';
        if ($headerFrom !== null) {
            $fromEmail = $headerFrom['email'];
            if ($headerFrom['name'] !== '') {
                $fromName = $headerFrom['name'];
            }
        }
        if (!empty($settings['headers_message_id']) && !isset($extraHeaders['Message-ID'])) {
            $extraHeaders['Message-ID'] = self::buildMessageId($settings);
        }
        if (!empty($settings['headers_x_mailer']) && !isset($extraHeaders['X-Mailer'])) {
            $extraHeaders['X-Mailer'] = 'TrayDigita Core Mailer ' . \TrayDigita\WP\Plugin\Core\Core::VERSION;
        }
        // The Gmail API only accepts mail from the connected
        // account itself; the account address replaces the From
        // header there (see Providers\GmailOAuth::send()).
        $toText = implode(', ', array_map(static fn (array $address): string => $address['email'], $to));
        return [
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'to_text' => $toText,
            'subject' => $subject,
            'body' => $body,
            'is_html' => $isHtml,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => $replyTo,
            'headers' => $extraHeaders,
            'is_bulk' => $isBulk,
            'attachments' => $files,
        ];
    }

    /**
     * Parse a `wp_mail()` recipient value into email + name pairs.
     *
     * Accepts a comma-separated string, a string array, or a
     * single address; understands `Name <address@example.com>`
     * and bare addresses. Entries without a valid email are
     * dropped, so a malformed address can never reach an API.
     *
     * @param mixed $value Recipient value from wp_mail() arguments or headers.
     * @return array<int, array{email: string, name: string}>
     */
    public static function parseAddresses(mixed $value): array
    {
        $items = [];
        if (is_string($value)) {
            $items = explode(',', $value);
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    $items = array_merge($items, explode(',', $item));
                }
            }
        }
        $addresses = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $email = '';
            $name = '';
            if (preg_match('/^(.*)<([^<>]+)>$/', $item, $matches) === 1) {
                $name = trim(trim((string) $matches[1]), '"');
                $email = trim((string) $matches[2]);
            } else {
                $email = $item;
            }
            $email = sanitize_email($email);
            if ($email === '' || is_email($email) === false) {
                continue;
            }
            $addresses[] = ['email' => $email, 'name' => sanitize_text_field($name)];
        }
        return $addresses;
    }

    /**
     * Build an RFC 822 message for the Gmail API.
     *
     * Single-part text or HTML when there are no attachments;
     * `multipart/mixed` with base64 parts when there are. The
     * From header is the connected Gmail account (the API
     * rejects anything else); To / Cc / Bcc come from the
     * normalized mail. The subject is MIME-encoded when it
     * carries non-ASCII characters.
     *
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, subject: string, body: string, is_html: bool, headers: array<string, string>, attachments: array<int, string>} $mail Normalized mail.
     * @param string $fromEmail Connected Gmail account address.
     * @return string Raw message, or '' when there is nothing to send.
     */
    public static function buildRfc822(array $mail, string $fromEmail): string
    {
        if ($mail['to'] === [] || $fromEmail === '') {
            return '';
        }
        $formatList = static function (array $addresses): string {
            $parts = [];
            foreach ($addresses as $address) {
                $parts[] = $address['name'] !== ''
                    ? sprintf('%s <%s>', $address['name'], $address['email'])
                    : $address['email'];
            }
            return implode(', ', $parts);
        };
        $subject = $mail['subject'];
        if ($subject !== '' && preg_match('/[^\x20-\x7E]/', $subject) === 1 && function_exists('mb_encode_mimeheader')) {
            $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        }
        $contentType = $mail['is_html'] ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
        $lines = [];
        $lines[] = 'From: ' . $fromEmail;
        $lines[] = 'To: ' . $formatList($mail['to']);
        if ($mail['cc'] !== []) {
            $lines[] = 'Cc: ' . $formatList($mail['cc']);
        }
        if ($mail['bcc'] !== []) {
            $lines[] = 'Bcc: ' . $formatList($mail['bcc']);
        }
        $lines[] = 'Subject: ' . $subject;
        // Deliverability headers collected by normalizeMail()
        // (List-Unsubscribe, Auto-Submitted, Message-ID,
        // X-Mailer, and the bulk headers for newsletters).
        foreach ($mail['headers'] ?? [] as $name => $value) {
            if (is_string($name) && is_string($value) && $name !== '' && $value !== '') {
                $lines[] = $name . ': ' . $value;
            }
        }
        $lines[] = 'MIME-Version: 1.0';
        if ($mail['attachments'] === []) {
            $lines[] = 'Content-Type: ' . $contentType;
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            $lines[] = $mail['body'];
            return implode("\r\n", $lines);
        }
        $boundary = 'traydigita-' . md5(uniqid('', true));
        $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: ' . $contentType;
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        $lines[] = $mail['body'];
        foreach ($mail['attachments'] as $file) {
            if (!is_string($file) || !file_exists($file) || !is_readable($file)) {
                continue;
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            $type = 'application/octet-stream';
            if (function_exists('wp_check_filetype')) {
                $check = wp_check_filetype($file);
                if (is_array($check) && !empty($check['type'])) {
                    $type = (string) $check['type'];
                }
            }
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: ' . $type . '; name="' . basename($file) . '"';
            $lines[] = 'Content-Disposition: attachment; filename="' . basename($file) . '"';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = '';
            $lines[] = chunk_split(base64_encode($contents), 76, "\r\n");
        }
        $lines[] = '--' . $boundary . '--';
        return implode("\r\n", $lines);
    }

    /**
     * Check a host value: a valid hostname or IP address.
     *
     * Rejects anything containing spaces, slashes, backslashes, or
     * `@`, and anything longer than 253 characters.
     *
     * @param string $host Host value to check.
     * @return bool
     */
    private static function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (str_contains($host, ' ') || str_contains($host, '/') || str_contains($host, '\\') || str_contains($host, '@')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * Parse a submitted port value, or null when it is not a valid port.
     *
     * @param string $rawPort Trimmed submitted value.
     * @return int|null Valid port (1-65535), or null.
     */
    private static function parsePort(string $rawPort): ?int
    {
        if (!ctype_digit($rawPort)) {
            return null;
        }
        $port = absint($rawPort);
        if ($port < 1 || $port > 65535) {
            return null;
        }
        return $port;
    }

    /**
     * Read a submitted value as a string (empty string for anything else).
     *
     * @param array<string, mixed> $data Submitted data.
     * @param string $key Field name.
     * @return string
     */
    private static function inputString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Cast a stored option value to a string (empty string for
     * anything that is not a string, int, float, or bool).
     *
     * Guards against a hand-edited option holding an array where a
     * string is expected, which a plain `(string)` cast would turn
     * into a warning and the word "Array".
     *
     * @param mixed $value Stored value.
     * @return string
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Read a submitted value for boolean parsing (string, int, or bool).
     *
     * @param array<string, mixed> $data Submitted data.
     * @param string $key Field name.
     * @return string|int|bool
     */
    private static function inputValue(array $data, string $key): string|int|bool
    {
        $value = $data[$key] ?? false;
        if (is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        return false;
    }
}
