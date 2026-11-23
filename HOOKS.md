# TrayDigita Core — Hooks

Every hook in Core starts with the prefix `traydigita:core` and uses `:` as the separator (the same convention as the theme's `traydigita:theme:` hooks). Filters return a value; actions return nothing. All hook names below are exact strings — copy them verbatim.

## Mailer

### `traydigita:core:mailer/providers` (filter)

Register a mail provider. Receives the built-in providers (keyed by id) and returns the full list. A third-party provider appears in the settings dropdown, works in test-send, and appears in the send log — the same path the 34 built-in API providers use.

```php
add_filter('traydigita:core:mailer/providers', function (array $providers): array {
    $providers['acme'] = [
        'id'          => 'acme',              // Required. Must match the array key.
        'label'       => 'Acme Mail',         // Required. Shown in the dropdown.
        'description' => 'Acme Mail — 5,000/month free. Best for transactional mail from Acme apps.',
        'free_tier'   => '5,000/month',
        'best_for'    => 'Transactional mail',
        'link'        => '',                  // Reserved for a future affiliate link; keep empty.
        'fields'      => [                    // Settings fields for this provider.
            [
                'key'      => 'acme_api_key',
                'label'    => 'API key',
                'type'     => 'password',     // text | password | select
                'hint'     => 'Found in the Acme dashboard under Settings → API.',
                'secret'   => true,           // Never shown; only overwritten when non-empty.
                'required' => true,           // Used by the configured check.
            ],
        ],
        // Either a send callable (below) or a 'class' (a class-string
        // of an AbstractApiProvider subclass) is required.
        'send' => static function (array $mail, array $settings): array {
            // $mail: to/cc/bcc address lists, subject, body, is_html,
            // from_email/from_name, reply_to, headers, is_bulk, attachments.
            // Return ['success' => bool, 'message' => string].
            return ['success' => true, 'message' => 'Accepted by Acme.'];
        },
        // Optional: override the configured check (default: every
        // 'required' field is filled in).
        // 'is_configured' => static fn (array $settings): bool => ...,
    ];
    return $providers;
});
```

Field types: `text`, `password`, `select` (with an `options` map). Secret fields render empty with a `•••••••• (saved)` placeholder when a value is stored. The Mailer validates every entry (id must match its key, label required, needs a `class` or a `send` callable) and silently drops invalid ones.

A complete, working example ships with Core: [`examples/mailer-dummy-provider/mailer-dummy-provider.php`](examples/mailer-dummy-provider/mailer-dummy-provider.php). It registers a `dummy` provider that accepts every mail without sending anything — proven end-to-end (dropdown → test-send → send log entry).

### `traydigita:core:mailer/send` (action)

Fires before every API-provider send attempt, with the provider id and the normalized mail:

```php
do_action('traydigita:core:mailer/send', $provider, $mail);
```

Use it to inspect or count outgoing mail. It does not fire for the `smtp` and `local` providers (those go through `wp_mail()` normally).

### `traydigita:core:mailer/sent` / `traydigita:core:mailer/failed` (actions)

Fire after an API-provider send attempt, with the provider id, the normalized mail, and the provider's result message:

```php
do_action('traydigita:core:mailer/sent', $provider, $mail, $message);
do_action('traydigita:core:mailer/failed', $provider, $mail, $message);
```

Only one of the two fires per attempt. The send is already written to the Mailer send log by the time these fire, so use them to react (notify, count, retry) rather than to log.

### `traydigita:core:mailer/unsubscribe-url` (filter)

Supplies an unsubscribe URL for the `List-Unsubscribe` header of a mail. Receives the current URL (empty by default) and the normalized `wp_mail` arguments (`$atts`, including `to`); return a URL string or `''`:

```php
apply_filters('traydigita:core:mailer/unsubscribe-url', '', $atts);
```

The Email (Newsletter) module hooks this filter and returns the per-subscriber unsubscribe link when the recipient is a subscriber.

### `traydigita:core:mailer/feedback-id` (filter)

Supplies the list id used in the `Feedback-ID` header for bulk mail. Receives the default `'traydigita'`; return a short identifier:

```php
apply_filters('traydigita:core:mailer/feedback-id', 'traydigita');
```

The header is rendered as `Feedback-ID: <list-id>:<mail host>`.

## Menu icons

### `traydigita:core:menu-icons/sets` (filter)

Receives the full icon-set list grouped by set and returns it. This is the hook to add, remove, relabel, or reorder icon sets for the menu-item picker:

```php
apply_filters('traydigita:core:menu-icons/sets', $sets);
// $sets shape: ['entypo' => ['label' => 'Entypo', 'icons' => [['id' => 'entypo-home', 'name' => 'home'], ...]], ...]
```

The list is normalized after filtering (set ids sanitised, empty sets dropped, icons sorted by name, duplicates dropped) and cached in a transient for one day. Saving an icon validates against both the filtered list and the Stack icon collections, so an icon the Stack cannot render can never be stored.

The legacy filter `traydigita:core:menu-icons` receives the same list and still works; it runs first, and `/sets` runs after it and has the final word.

### `traydigita:core:menu-icons/render` (filter)

Receives the rendered menu-item HTML and may replace it — for example to move the icon after the label or wrap it in extra markup:

```php
apply_filters('traydigita:core:menu-icons/render', $html, $icon, $item, $depth);
// $html: the finished item HTML (icon + label, separated by a space)
// $icon: ['set' => ..., 'name' => ..., 'color' => ...] (colour is '' for currentColor)
// $item: the WP_Post menu item; $depth: the menu depth
```

It runs only when the icon actually renders. An item with no icon, or rendering disabled in the Menu Icons module settings, never reaches it. The module settings page also holds the default behaviour: render icons on/off, and icon before/after the label (option `traydigita_menu_icons`).

## Module system

### `traydigita:core:modules` (filter)

The module class list. Receives the registered module classes and returns the list (first class wins on duplicate ids). Child themes and plugins register their own modules here:

```php
add_filter('traydigita:core:modules', function (array $classes): array {
    $classes[] = MyModule::class;
    return $classes;
});
```

A module class implements `TrayDigita\WP\Plugin\Core\Admin\ModuleInterface` (`id()`, `category()`, `name()`, `description()`, `boot()`, `renderSettingsPage()`, `saveSettings()`) and may declare the optional statics `author()`, `isCore()`, `isRecommended()`, `showInSubmenu()`, `openLink()`, `submenuPosition()`, and `tabs()`.

### `traydigita:core:modules/categories` (filter)

The category list for the module list page (slug → label). Receives the built-in categories.

### `traydigita:core:admin:module_tabs` (filter)

Adds or changes the tabs on a module's settings page. Receives the tab list (`[['id' => ..., 'label' => ..., 'disabled' => ...], ...]`) and the module class. A module without declared tabs gets no tab bar.

### `traydigita:core:admin:settings_sections` (filter)

Adds or changes the settings sections on the Settings hub page.

### `traydigita:core:customizer/definitions` (filter)

Registers Customizer setting definitions (`id`, `type`, `section`, `default`, `sanitize`, `label`, `transport`, `partial`). Modules that expose theme-facing settings (Author Box, Breadcrumb, Fonts, Table of Contents, …) register theirs here; the theme reads them through the Customizer.

### `traydigita:core:template:section` (filter)

Overrides which template file renders a section. Receives the resolved candidate path, the section slug, and the name:

```php
apply_filters('traydigita:core:template:section', $candidate, $slug, $name);
```

## Content & comments

### `traydigita:core:comments/providers` (filter)

Registers comment providers. Each provider supplies an id, a label, and the render/submit callbacks; the Comments module renders one provider per post. See `src/Modules/Comments/CommentProviderInterface.php` for the entry shape.

### `traydigita:core:member/sections` (filter)

Registers sections of the contributor member dashboard (id, label, capability, render callback). The Contributor Frontend module manages them: ticking a section hides it from the dashboard and from its URL.

## Markets

### `traydigita:core:market_provider` (filter)

Resolves a market-data provider by id. Receives `null` and the provider id; return a provider object or `null` to fall through to the built-ins.

### `traydigita:core:markets/gold-providers` / `traydigita:core:markets/crypto-providers` (filters)

Register gold-price and crypto-price providers (id → provider class or factory). The Markets module and its widgets read through these filters, so a child theme can add a price source without touching the module.

### `traydigita:core:market_cache_ttl` (filter)

The cache lifetime (seconds) for market prices. Receives the default TTL; return a number.

## Forms

### `traydigita:core:form-builder/submitted` (action)

Fires after a Form Builder shortcode form passes validation, with the form id and the sanitised values:

```php
do_action('traydigita:core:form-builder/submitted', $formId, $values);
```

The Form Builder itself stores nothing — this action is the integration point. (The Contact Form module listens to its own submit path and stores submissions in its own table; it does not use this action.)

## Security

### `traydigita:core:two-factor:verified` (action)

Fires when a user completes two-factor verification, with the user id, the method (`totp` or `passkey`), the timestamp, and the context. The Activity Log module listens to it.

### `traydigita:core:two-factor:disable` (filter)

Forces two-factor authentication off site-wide. Receives `false`; return `true` to disable 2FA for every user (used by emergency recovery flows).

### `traydigita:core:turnstile:fail-open` (filter)

Decides what happens when the Turnstile verification request itself fails (network error, Cloudflare unreachable). Receives `false` (fail closed — block the submission); return `true` to fail open and let the submission through. Default is fail closed.

## Integrations & detection

### `traydigita:core:integrations` (filter)

Registers third-party integrations shown on the Integrations module page. Each entry carries an id, a label, a description, and a status callback; the module validates every entry and renders one row per integration.

### `traydigita:core:server/detect` (filter)

Overrides server detection (OpenLiteSpeed / Apache / nginx / other). Receives the detected server string; return a server slug. Used by modules that adapt behaviour to the server stack.

### `traydigita:core:analytics_allowed` (filter)

The privacy gate for analytics: any theme feature that adds analytics code must check this filter (or `SiteKitModule::isSiteKitActive()`) first. Receives `false` by default — Core ships no analytics code at all.

## Dynamic sidebars

### `traydigita:core:dynamic-sidebars/sidebars` (filter)

Registers dynamic sidebar areas (id → label and conditions). The Dynamic Sidebars module resolves them per post/term.

### `traydigita:core:dynamic-sidebars/resolved-area` (filter)

Overrides the resolved sidebar area for the current request. Receives the resolved area id (or `null`); return an area id to force a sidebar.
