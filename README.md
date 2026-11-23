# TrayDigita Core

![TrayDigita Core](assets/brand/traydigita-core-logo.gif)

> **Where Modules Connect.** The engine behind the TrayDigita theme: 49 admin modules, a 37-provider mailer with 30 one-click SMTP presets, forms, push notifications, and the vendored Stack libraries — in one self-contained WordPress plugin. The theme keeps presentation only (templates, styles, layouts) and depends on Core for everything else.

![Banner](assets/brand/banner-1544x500.png)

## Requirements

- WordPress 6.0 or later
- PHP 8.3 or later
- MySQL 5.7+ / MariaDB 10.4+ (for the Contact Form submissions table)

## Installation

1. Download the release zip (`traydigita-core-1.0.0.zip`) from the [releases page](https://github.com/ArrayIterator/traydigita-core-plugin/releases).
2. In WordPress: **Plugins → Add New → Upload Plugin**, upload the zip, activate it.
3. Open **TrayDigita** in the admin menu: the module list shows every module with its status. Enable the ones you need; core modules are always on.

The release zip is self-contained: it ships `vendor/` (the Composer autoloader plus `psr/log` and `psr/container`, the only external dependencies) and the compiled Indonesian translations (`languages/traydigita-core-id_ID.mo`). No build step is needed after activation.

## Modules

49 modules, grouped by category. Core modules are always enabled; recommended modules are off by default but marked **Recommended** in the module list. Every module renders its own settings page under the TrayDigita menu.

### Administration

| Module | ID | Notes |
| --- | --- | --- |
| Activity Log | `activity-log` | Every admin action, with filters and a live feed. |
| Custom Role Editor | `administration` | Edit roles and capabilities. |
| DB Optimizer & Checker | `db-tools` | Table optimisation, checks, and repair. |
| Maintenance Mode | `maintenance` | 503 page with a block-based page builder (see below). |
| Site Info | `site-info` | Server, PHP, and WordPress diagnostics. |
| User Manager | `user-manager` | User list, roles, and bulk actions. |

### Communication

| Module | ID | Notes |
| --- | --- | --- |
| Contact Form | `contact-form` | Forms via the Form Builder; submissions stored, never lost (see below). Default off. |
| Email (Newsletter) | `email` | Subscribers, double opt-in, templates, sends via the Core Mailer. |
| Mailer | `smtp` | 37 providers + 30 SMTP presets, deliverability headers, send log (see below). |

### Content

| Module | ID | Notes |
| --- | --- | --- |
| Accessibility Toolbar | `accessibility-toolbar` | Frontend accessibility controls. |
| Agenda | `agenda` | Agenda custom post type with contributor submission. |
| Author Box | `author-box` | Author bio box under posts. |
| Breadcrumb | `breadcrumb` | Breadcrumb navigation. |
| Comments | `comments` | Comment providers (WordPress native + pluggable providers). |
| Contributor Frontend Editor | `content` | Frontend post submission and editing for contributors. |
| E-Paper | `epaper` | E-paper / flipbook viewer. |
| Form Builder | `form-builder` | Reusable field + email template builder. Recommended, default off. |
| Metered Paywall | `paywall` | Metered paywall for posts. |
| Post Series | `post-series` | Multi-part post series with navigation. |
| Print Article | `print-article` | Print-friendly article view. |
| Read Aloud | `read-aloud` | Text-to-speech for posts. |
| Reading Progress | `reading-progress` | Reading progress bar. |
| Related Posts | `related-posts` | Related posts block. |
| Share Buttons | `share` | Share row (Facebook, X, WhatsApp, Telegram, Email, copy link, print). |
| Table of Contents | `table-of-contents` | Automatic table of contents. |

### Design

| Module | ID | Notes |
| --- | --- | --- |
| Custom Avatar | `avatar` | Custom avatar upload (SSRF-hardened remote fetch). |
| Dashboard Designer | `dashboard-designer` | Custom admin dashboard design. |
| Dynamic Sidebars | `dynamic-sidebars` | Per-post / per-term sidebar assignment. |
| Font Manager | `fonts` | Webfont management. |
| Menu Icons | `menu-icons` | Icons for nav menu items, with default behaviour settings and hooks. |

### Integrations

| Module | ID | Notes |
| --- | --- | --- |
| Plugin Suggestions | `installer` | Suggested plugins, one-click install. |
| Integrations | `integrations` | Third-party integration registry (hookable). |

### Monetization

| Module | ID | Notes |
| --- | --- | --- |
| Ads Inserter | `ads` | Ad placement (AdSense and custom). |
| Markets | `markets` | Gold, stock, and crypto price widgets (pluggable providers). |

### Performance

| Module | ID | Notes |
| --- | --- | --- |
| Cache | `cache` | Page and object cache controls. |
| Fast Search | `fast-search` | Fast full-text search. |
| Custom Headers | `headers` | Custom HTTP response headers. |
| Heartbeat Control | `heartbeat` | WordPress Heartbeat API throttling. |
| Image Optimizer | `image-optimizer` | Image optimisation (sizes, formats). |
| PWA & Push Notifications | `pwa` | PWA manifest, service worker, Firebase push. |

### Security

| Module | ID | Notes |
| --- | --- | --- |
| Cookie Consent | `cookie-consent` | Cookie consent banner. |
| Custom Login Page Builder | `security` | Login page designer (block-based). |
| Security Headers | `security-headers` | HSTS, CSP, and other security headers. |
| Security Monitor | `security-monitor` | Login attempts, 2FA events, and blocks. |
| Cloudflare Turnstile | `turnstile` | Turnstile spam protection (verified via the Cloudflare API). |
| Two-Factor Authentication | `two-factor` | TOTP (vendored Stack implementation, RFC 6238) + passkeys/WebAuthn. |

### SEO & Discovery

| Module | ID | Notes |
| --- | --- | --- |
| Redirect Manager | `redirects` | URL redirects. |
| SEO | `seo` | Meta tags, sitemaps, structured data. |
| Site Kit | `site-kit` | Google Site Kit integration (guarded; only active when Site Kit is installed). |

Four theme modules were deliberately **not** ported — they are presentation, not engine: Customizer, Styles, Theme Support, and Widgets (widget areas). They stay in the theme.

## Mailer — 37 providers and 30 SMTP presets, all built in

The Mailer is the single sending path for the whole plugin: WordPress notification mail, the Newsletter module, and the Contact Form all send through it, and every send is recorded in the send log. No third-party plugin is required for any provider; the provider hook stays available for plugins that want to add their own (see [HOOKS.md](HOOKS.md)).

Counting: the provider dropdown lists **37 providers** — SMTP, Local / PHP mail, Gmail (OAuth), SendGrid, and 33 API providers (`API_PROVIDER_CLASSES`). The SMTP provider itself offers **30 one-click presets**, so the Mailer presents **67 selectable choices in total (37 + 30)**. An earlier draft said "66 providers"; that figure added the 30 presets to the 33 API providers plus SendGrid and Local (30 + 33 + 1 + 1 = 65) while leaving Gmail out — presets are choices inside one provider, not providers.

### SMTP presets (30)

Custom, Gmail, Yahoo, Outlook / Microsoft 365, Zoho Mail, Zoho Mail EU, Proton Mail (via Proton Bridge), Yandex, Fastmail, iCloud, GMX, Mail.com, AOL, QQ, 163, 126, Sina, Mail.ru, Posteo, Migadu, Mailfence, StartMail, Runbox, Kolab Now, Infomaniak, OVH, Gandi, Namecheap Private Email, DreamHost, MXroute. Each preset ships with the correct host, port, and encryption plus a one-line description (including app-password / authorisation-code notes where the provider requires one).

### Transactional APIs (24)

| Provider | Free tier |
| --- | --- |
| Sender.net | 15,000/month (2,500 contacts) |
| SendPulse | 12,000/month |
| OneSignal Email | 10,000/month |
| Brevo | 300/day (~9,000/month) |
| Mailjet | 6,000/month (200/day) |
| Mailtrap | 4,000/month |
| Resend | 3,000/month (100/day) |
| Elastic Email | 3,000/month (100/day, no card) |
| Mailgun | 100/day |
| SMTP2GO | ~1,000/month |
| SparkPost / Bird Email | 1,000/month (50/day, no card) |
| MailerSend | 500/month (needs a credit card) |
| Postmark | 100/month (never expires) |
| ZeptoMail / Zoho CPaaS | First 10,000 emails free (one-time credit, 6 months), then $2.50 per 10,000 |
| SendGrid | 60-day trial only, then paid |
| Amazon SES | No free tier, $0.10 per 1,000 |
| Mailchimp Transactional (Mandrill) | Paid, no free tier |
| SendLayer | Trial only (~200), no free plan |
| SocketLabs | No free plan (1st month free: 40,000, then $39.95/month) |
| Courier | Free tier exists, amount not verified |
| SMTP.com | Paid from $25 per 50,000 |
| Unisender | Free plan exists, limits not verified |
| Plunk | 1,000/month |
| Loops | 4,000 sends per rolling 30 days (first 1,000 contacts; template-based only) |

### Marketing / newsletter APIs (10)

MailerLite, Mailchimp, HubSpot, Omnisend, Klaviyo, GetResponse, AWeber, Constant Contact, Moosend, Campaign Monitor. Most of these have **no single-email send API** — they send a campaign to a whole list/group/segment. Core implements the genuine flow per platform (create campaign → set content → send, or the platform's transactional endpoint where one exists), and the settings description says so plainly: a test mail goes to the whole configured audience, not to one address. AWeber and Constant Contact use OAuth 2.0 with automatic token refresh.

### Local mail (1)

Local / PHP mail — the WordPress default (`wp_mail` with no SMTP). Worst deliverability; listed so local development has an explicit choice.

### Deliverability headers

Every WordPress notification email (`wp_mail`) gets deliverability headers, controlled per header group on the Mailer settings page (all on by default):

| Setting | What it does | Why it matters |
| --- | --- | --- |
| Domain-aligned From + Reply-To | From uses the site domain; Reply-To points back at the sender. | Mail from `wordpress@random-host` fails SPF/DMARC alignment and looks forged to receivers. |
| List-Unsubscribe + List-Unsubscribe-Post | One-click unsubscribe header (mailto + URL). | Gmail and Yahoo require one-click unsubscribe for bulk senders since 2024; without it, mail is bulk-foldered or rejected. |
| Message-ID | A unique, domain-aligned message id. | Receivers use it for threading and duplicate detection; a missing or malformed id is a spam signal. |
| X-Mailer | `TrayDigita Core Mailer <version>`. | Identifies the sending software honestly; receivers and postmasters use it for reputation. |
| Precedence / Auto-Submitted | `Auto-Submitted: auto-generated` (bulk mail also gets `Precedence: bulk`). | Tells receivers and auto-responders the mail is automated, so out-of-office replies do not loop and bulk mail is classified correctly. |
| Feedback-ID | `traydigita:<mail host>` (filterable). | Lets receivers group mail by campaign/sender for reputation; used by Gmail's feedback loop. |

### Provider data

Each provider definition carries `id`, `label`, `description` (free tier + what it is good for), `free_tier`, `best_for`, `link` (reserved for a future affiliate link — empty in v1.0.0), and `fields` (the settings the provider needs). A minimal third-party provider example lives in `examples/mailer-dummy-provider/` and is documented in [HOOKS.md](HOOKS.md).

## Newsletter (Email module)

Subscribers with double opt-in (on by default), sender identity, unsubscribe links (token-based, per subscriber), and template CRUD with preview. Sending always goes through the Core Mailer provider and appears in the Mailer send log — never raw `wp_mail`. Settings: double opt-in, sender name/email, unsubscribe, default template.

## Contact Form

Forms are built with the Form Builder module; sending goes via the Core Mailer and appears in the send log.

- **Submissions are never lost.** Every entry is stored in its own table (`{prefix}traydigita_contact_submissions`, created lazily on the insert path with version-gated schema upgrades) **before** any email is attempted. If the notification email fails, the submission is still there and the form still reports success with a note that the email could not be sent.
- **Per-form toggle:** send to email, or save only (hold email sending).
- **Spam protection:** Cloudflare Turnstile (when the Turnstile module is active and configured) and reCAPTCHA v2 checkbox / v3 (site key + secret key in settings, score threshold for v3). The mode can be `auto` (Turnstile first, then reCAPTCHA v3, then none) or forced per form.
- **Admin page:** TrayDigita → Contact Submissions (appears only while the module is active) — list, filter (form, status, date range, search), detail view, mark read/unread, delete, CSV export (per form + per period), and a print view.
- **Retention:** submissions auto-prune after the configured retention (default 90 days; 0 = keep forever), via a daily cron.

## Form Builder / Email Builder

A reusable builder used by Contact Form, Newsletter, and any other module that needs forms or email. Field types: text, email, URL, telephone, number, textarea, select, radio, checkbox, date, hidden. Email blocks: header, text, button, divider, footer, with `{{field_id}}` placeholders. Status: **recommended** — default off, marked as recommended in the module list. Shortcode: `[traydigita_form id="..."]`.

## Maintenance Page Builder

The Maintenance module (503 page) gets a block-based builder modelled on the Login Designer: background, logo, heading, text, countdown, button, social links, newsletter signup, and custom CSS (sanitised with an allowlist: no scripts, no dangerous URLs, unbalanced CSS is rejected). The newsletter block subscribes through the Newsletter module. Live preview renders server-side on the settings page.

## Hooks

All hooks are documented in [HOOKS.md](HOOKS.md), including the Mailer provider hooks (`traydigita:core:mailer/providers`, `/send`, `/sent`, `/failed`), the menu-icons hooks (`traydigita:core:menu-icons/sets`, `/render`), the module list filter (`traydigita:core:modules`), and a complete third-party provider example.

## Brand

The plugin identity is the "Connecting To The Core" mark: the original TrayDigita T+D glyph, animated in three pieces (swoosh from the top, stem from the bottom-left, bowl from the bottom-right) with a gold flash at the seam and six chip-shaped nodes connected by circuit traces. Source files live in `assets/brand/` and are reproducible via `assets/brand/generate.py`:

- `traydigita-core-logo.svg` — animated SVG (admin menu icon, about page)
- `traydigita-core-logo-static.svg` — static fallback
- `traydigita-core-logo.gif` — animated GIF (readme)
- `traydigita-core-logo.mp4` — 5 s animation (about page)
- `icon-128x128.png`, `icon-256x256.png`, `icon-512x512.png` — WP.org icons
- `banner-1544x500.png`, `banner-772x250.png` — readme banners

The admin design is the theme's Elegant Blue settings framework, unchanged.

## Development

- **Code style:** PSR-2, `declare(strict_types=1)` as the first line of every PHP file, English doc comments and strings (Indonesian only in `languages/*.po` + compiled `.mo`).
- **Security:** nonce + capability on every AJAX/REST endpoint, sanitised input, escaped output, no raw SQL (`$wpdb->prepare` / QueryBuilder), allowlisted fetch hosts.
- **Dependencies:** only `psr/log` and `psr/container` (via Composer). Mailer SMTP runs on WordPress core's PHPMailer; TOTP is the vendored Stack implementation; Turnstile verification is a direct `wp_remote_post` to Cloudflare. No other external libraries.
- **Linting:** `composer lint` (phpcs, 0 errors). `php -l` clean on all PHP files.
- **i18n:** text domain `traydigita-core`. `languages/traydigita-core.pot` + `languages/traydigita-core-id_ID.po` (compiled `.mo` ships in the release). 84% translated to Indonesian; the rest falls back to English.
- **Releases:** tag-driven. Push a numeric semver tag (`v1.0.0`) and `.github/workflows/release.yml` lints, checks the plugin header version against the tag, builds `traydigita-core-<version>.zip` (production `vendor/`, translations, brand assets; no `.git`, `.github`, or dev files), verifies the zip contents, and attaches it to the GitHub release.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
