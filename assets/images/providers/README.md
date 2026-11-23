# Mailer provider logos

Flat SVG marks (24x24 viewBox) used by the Mailer provider and SMTP
preset picker popups. The set mirrors the shared Unified lettermark
set, so the picker shows the same logos the Unified mailer catalog
uses: most are initial lettermarks (two initials on a rounded rect,
brand-ish colours); 22 are real brand marks from simple-icons (CC0,
public domain) for providers that have one. No external fetches.

## Provenance

66 SVG files in this directory, counted 2026-11-23 by comparing
each file byte-for-byte (SHA-256) against the shared Unified set
(`~/workspace/unified-push/src/modules/email_providers/logos/`
— read only, never modified there):

- 56 copied verbatim (byte-identical to the source file): 34
  lettermarks + 22 real brand marks from simple-icons (CC0) —
  `email-gmail.svg`, `email-brevo.svg`, `email-mailchimp.svg`,
  `email-mailgun.svg`, `email-resend.svg`, `email-sparkpost.svg`,
  `email-zoho.svg`, `email-zoho-eu.svg`, `email-proton.svg`,
  `email-icloud.svg`, `email-gmx.svg`, `email-gandi.svg`,
  `email-infomaniak.svg`, `email-loops.svg`, `email-mail-com.svg`,
  `email-mail-ru.svg`, `email-mailtrap.svg`, `email-migadu.svg`,
  `email-namecheap.svg`, `email-ovh.svg`, `email-qq-mail.svg`,
  `email-campaign-monitor.svg`.
- 10 created here (listed below) — providers in Core's catalog
  with no file in the source set at all, drawn in the same
  lettermark style as the set's lettermarks.

The source set's `sprite.svg` was not copied: Core builds no
sprite; the picker popup renders the files with plain `<img>` tags.

## Created here (same lettermark style)

10 providers in Core's catalog had no lettermark in that set, so one
was created per provider in the exact same construction (rounded rect
`rx="5"`, one `<text>` with two initials, Arial/Helvetica bold 8):

- `email-mandrill.svg` — #232323, "Ma"
- `email-unisender.svg` — #00A99D, "Un"
- `email-mailerlite.svg` — #00A67E, "Ml"
- `email-hubspot.svg` — #FF7A59, "Hu"
- `email-omnisend.svg` — #00B67A, "Om"
- `email-klaviyo.svg` — #1A1A1A, "Kl"
- `email-getresponse.svg` — #00AEEF, "Ge"
- `email-aweber.svg` — #246BEB, "Aw"
- `email-constant-contact.svg` — #0078D4, "Cc"
- `email-moosend.svg` — #6C3BB5, "Mo"

Colours are approximations, not trademarked artwork.

## Wiring

`MailerModule::PROVIDER_LOGOS` and `::PRESET_LOGOS` map provider and
preset ids to file stems; `providerLogoUrl()` / `presetLogoUrl()`
resolve them (unknown id or missing file falls back to the generic
`email-smtp.svg` envelope). The picker popup renders them with plain
`<img>` tags.
