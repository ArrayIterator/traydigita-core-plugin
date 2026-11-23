# Mailer provider logos

Flat SVG marks used by the Mailer provider and SMTP preset picker
popups. 66 files. 63 are real brand marks; 3 are not: 2 intentional
generic envelope fallbacks (`email-smtp.svg`, `email-sendmail.svg`,
see "Generic envelope fallbacks" below) and 1 kept two-initial
lettermark (`email-pepipost.svg`, see "Kept lettermark" below). No
external fetches: every mark is vendored in this directory and
rendered with plain `<img>` tags.

Correction to the first pass (2026-11-23): it reported "65 real +
1 lettermark", but 7 hand-drawn glyph files contain no `<text>` and
were never counted as lettermarks (`email-yahoo.svg`,
`email-outlook.svg`, `email-postmark.svg`, `email-sendgrid.svg`,
`email-ses.svg`, `email-smtp.svg`, `email-sendmail.svg`). The true
first-pass count was 58 real + 8 non-real. This fix replaces 5 of
those 7 with real marks, reworks 3 more (`email-mailerlite.svg`,
`email-dreamhost.svg`, `email-onesignal.svg`), and documents the
provenance of `email-constant-contact.svg`; the remaining 2 generic
fallbacks are kept deliberately.

Every file was verified by rendering at 32px (the picker list-row
size) and at 96px on light and dark backgrounds. Every file parses
as valid XML, is under 100 KB, keeps its original filename, and
contains no `<text>` element except the one kept lettermark.

## Allowlist coverage

`MailerModule::PROVIDER_LOGOS` (38 entries) and `::PRESET_LOGOS`
(30 entries) share 66 distinct stems (`smtp`, `gmail` in both lists).
All 66 stems have a file here, and every file here is referenced by
one of the two maps: 0 missing, 0 unreferenced. Verified 2026-11-23
by script against `src/Modules/Mailer/MailerModule.php`.

## How marks were sourced (priority order)

1. **simple-icons (CC0 1.0)** — only HubSpot of the 37 lettermarks
   is still in simple-icons; most of these brands were removed from
   simple-icons in past cleanups, so they are not there. Checked
   again 2026-11-23 against the live simple-icons dataset (3,475
   icons): Yahoo, Outlook, Postmark, SendGrid and Amazon SES are
   all absent, so none of the five could be sourced from there.
2. **Wikimedia Commons** — file description page checked (not just
   the filename). Three Commons files could actually be downloaded:
   upload.wikimedia.org rate-limited this VM's egress IP with
   HTTP 429 for most of the round (retried several times with 3s /
   25s / 90s spacing; `Yandex 2021.svg` eventually succeeded but
   was superseded by a better mark, and `MailerLite Logo.svg`
   succeeded on a late retry). In the fix round the same 429
   rate-limiting persisted for the SES file (`AWS Simple Icons
   Messaging Amazon SES Email.svg`, retried 4 times with up to 90s
   spacing), so the SES mark was taken from AWS's own Architecture
   Icons pack instead (see below); `Yahoo! (2019).svg`,
   `Microsoft Outlook Icon (2025–present).svg` and `SendGrid 2016
   Logo.png` downloaded fine.
3. **The brand's own website** (official favicon, header logo, or
   brand-asset URL on the brand's own domain) — the source for most
   replacements below. A favicon is the brand's own published mark.
4. SVGRepo was not needed for any file (including in this fix round).

No logo was redrawn, traced, or invented. Where a brand publishes
only a raster mark (favicon/logo PNG/WebP), the raster is embedded
unchanged (base64 PNG) inside a minimal SVG wrapper (`<image>` with
the raster's own dimensions as viewBox); see "Raster embeds". Where
a brand publishes only a white knockout logo, only its fill colour
was changed to the brand's own primary colour sampled from the
brand's own site; see "Recoloured knockouts".

## Provenance per file

"Vector" = the file is the source artwork as published.
"Raster embed" = the brand's published raster mark, byte-identical
pixels, wrapped in SVG (no vector version is published). "Mono" =
the brand's official monochrome (single-colour) mark.

### From simple-icons (CC0 1.0)

- `email-hubspot.svg` (678 B, vector) — HubSpot sprocket.
  Source: https://cdn.simpleicons.org/hubspot (slug `hubspot`,
  verified present in the simple-icons slug list 2026-11-23).
  Licence: CC0 1.0.

### From Wikimedia Commons (public domain)

- `email-aol.svg` (3,807 B, vector) — AOL wordmark, current mark
  since 6 Dec 2024. Source: `AOL logo (2024).svg`,
  https://upload.wikimedia.org/wikipedia/commons/2/2b/AOL_logo_%282024%29.svg
  Licence: public domain (per file page).
- `email-outlook.svg` (13,116 B, vector) — Microsoft Outlook icon
  (2025–present). Source: `Microsoft Outlook Icon
  (2025–present).svg`,
  https://upload.wikimedia.org/wikipedia/commons/c/cc/Microsoft_Outlook_Icon_%282025%E2%80%93present%29.svg
  Licence: public domain (per file page). The file page states the
  work is by Microsoft, uploaded from
  https://www.microsoft.com/en-gb/microsoft-365.
- `email-posteo.svg` (37,663 B, raster embed, 256x138 PNG) —
  Posteo logo lockup (green field, white `//POSTEO`). Source:
  `Posteo.png`,
  https://upload.wikimedia.org/wikipedia/commons/3/3f/Posteo.png
  Licence: public domain (per file page). Embedded because Posteo's
  own site only publishes the same lockup as a white knockout SVG
  (invisible on a light tile); the Commons PNG is the published
  lockup with its green field intact.
- `email-sendgrid.svg` (3,274 B, raster embed, 256x256 PNG) —
  SendGrid icon (grid of blue squares). Source: `SendGrid 2016
  Logo.png`,
  https://upload.wikimedia.org/wikipedia/commons/b/b6/SendGrid_2016_Logo.png
  Licence: public domain (per file page; source "SendGrid
  website", author SendGrid, 9 June 2016 — the 2016 rebrand is
  still the current SendGrid identity). The icon is cropped out of
  the official lockup (tight crop x118–646, y94–622 of the 3300x724
  file), downscaled 528 to 256; no pixels altered. The full lockup
  (4.56:1) renders ~7px tall at 32px, so the icon-only crop is
  used. SendGrid's own site no longer publishes a distinct
  SendGrid mark: sendgrid.com now redirects to twilio.com and its
  favicon is the Twilio mark, which must not be used here.
- `email-yahoo.svg` (3,896 B, vector) — Yahoo! wordmark, current
  mark since 23 September 2019. Source: `Yahoo! (2019).svg`,
  https://upload.wikimedia.org/wikipedia/commons/3/3a/Yahoo%21_%282019%29.svg
  Licence: public domain (per file page; source yahoo.com, author
  Verizon Media – Pentagram). Yahoo's own favicon
  (yahoo.com/favicon.ico, 48x48, byte-identical on mail.yahoo.com)
  is only the "!" punctuation mark, so the full wordmark is used
  instead.

### From the brand's own website (trademarked marks)

These are the brands' own published marks, used unaltered for
provider identification in the picker. They are trademarks, not
open-licensed artwork; no licence is claimed for them.

- `email-aweber.svg` (990 B, vector, recoloured knockout) — AWeber
  mark (blue rounded shape, white stripes). Source: Safari
  pinned-tab icon,
  https://assets.aweber-static.com/www/assets/metadata/mask.cb73d066.svg
  Fill changed from black (`#000`) to AWeber blue `#2A7DED`
  (median blue sampled from AWeber's own logo PNG at
  https://assets.aweber-static.com/www/assets/img/aw-logo.png);
  `viewBox="0 0 16 16"` added (the source used the non-standard
  `viewport` attribute). Shape untouched.
- `email-courier.svg` (1,125 B, vector) — Courier paper-plane mark.
  Source: https://www.courier.com/favicon.svg
- `email-constant-contact.svg` (56,257 B, raster embed, 128x128
  PNG) — Constant Contact "C" mark (blue ring + orange arc).
  Source: https://www.constantcontact.com/favicon.ico (256px frame
  downscaled to 128). Provenance verified 2026-11-23: the embedded
  PNG's pixels are exactly equal to the live favicon's 256px frame
  downscaled to 128 with LANCZOS (the favicon's 128px frame is a
  different, edge-cropped render of the same mark). The brand
  publishes no vector mark.
- `email-dreamhost.svg` (805 B, vector) — DreamHost "D" swirl icon.
  Source: https://www.dreamhost.com/icon.svg (32x32, as published).
  The file's own white rounded-square background is kept as
  published: the swirl's dark-navy crescent (`#071C26`) would be
  near-invisible on a dark tile without it. (The previous wordmark
  from `dh-logo.white.svg`, recoloured blue, rendered ~4.5px tall
  at 32px and is superseded.)
- `email-elastic-email.svg` (33,558 B, raster embed, 256x256 PNG)
  — Elastic Email "e" arrow mark. Source:
  https://elasticemail.com/favicon.ico (270x270 PNG inside;
  downscaled to 256). The published wordmark
  (`/img/brand/logo-horizontal.svg`, 180x23, 7.8:1) renders
  ~4px tall at 32px, so the favicon mark is used instead.
- `email-fastmail.svg` (1,054 B, vector) — Fastmail mark (blue ring,
  yellow/black envelope). Source: https://www.fastmail.com/favicon.svg
- `email-getresponse.svg` (15,228 B, raster embed, 184x184 PNG) —
  GetResponse smile mark. Source:
  https://www.getresponse.com/favicon.ico (184px frame).
- `email-klaviyo.svg` (2,532 B, raster embed, 32x32 PNG) —
  Klaviyo "K" mark. Source: https://www.klaviyo.com/icons/icon-32x32.png
- `email-kolabnow.svg` (651 B, vector) — Kolab orange ring mark.
  Source: https://kolabnow.com/themes/kolabnow/images/logo.svg
- `email-mail-126.svg` (4,557 B, raster embed, 48x48 PNG) —
  NetEase "e" mark. Source: https://mail.126.com/favicon.ico
  (byte-identical to the 163 Mail favicon; both are NetEase mail
  services sharing the same mark).
- `email-mail-163.svg` (4,557 B, raster embed, 48x48 PNG) —
  NetEase "e" mark. Source: https://mail.163.com/favicon.ico
- `email-mailersend.svg` (2,762 B, vector) — MailerSend symbol
  (teal/blue mark). Source:
  https://www.mailersend.com/images/logo-symbol.svg (`<title>`
  normalised from the Sketch export artifact "symbol copy").
- `email-mailfence.svg` (1,807 B, vector, mono) — Mailfence
  envelope mark in black. Source: Safari pinned-tab icon,
  https://mailfence.com/c/mailfence/images/favicon/safari-pinned-tab.svg
  The colour Mailfence logo on Commons (`Mailfence logo.png`)
  could not be downloaded (upload.wikimedia.org HTTP 429, see
  above); the monochrome official mark is used instead.
- `email-mailjet.svg` (64,256 B, raster embed, 256x256 PNG) —
  Mailjet paper-plane mark (purple rounded square). Source:
  https://www.mailjet.com/wp-content/themes/simpletexting-theme-blank/assets/favicon/android-chrome-512x512.png
  (served as WebP, converted to PNG, downscaled 512 to 256).
  The published vector is the `mailjet by Sinch` lockup
  (`logo-mailjet-sinch.svg`), which renders as a ~10px plane with
  unreadable text at 32px; the plane mark renders clearly.
- `email-mailerlite.svg` (12,060 B, raster embed, 180x180 PNG) —
  MailerLite icon (green `#09C269` speech bubble, white "lite").
  Source:
  https://www.mailerlite.com/site/themes/new/img/favicons/apple-touch-icon.png
  (180x180, from MailerLite's own favicon set). Note: the 48x48
  favicon in the same set (`favicon-48x48.png`) was checked
  2026-11-23 and is a solid green rounded square with no glyph at
  all, so it cannot identify the provider; the 180x180
  apple-touch-icon from the same official set is used instead.
  (The previous 1200x630 banner logo rendered ~3px tall at 32px
  and is superseded.)
- `email-mandrill.svg` (1,069 B, raster embed, 16x16 PNG) —
  Mandrill "M" mark (blue/black/yellow/red bars). Source:
  https://mandrillapp.com/img/favicon.ico (16px is the only size
  Mandrill publishes; upscaled 2x at 32px display, slightly soft
  but the mark is simple geometry). The published wordmark
  (`mandrillapp.com/img/logo.png`) is white text for dark
  backgrounds.
- `email-moosend.svg` (3,343 B, vector) — Moosend cow logomark.
  Source:
  https://moosend.com/wp-content/uploads/2026/06/logomark-white-background.svg
  (despite the filename, there is no background rect; the white
  shape is the muzzle).
- `email-mxroute.svg` (1,110 B, vector) — Mxroute pink envelope
  mark. Source: https://mxroute.com/brand-mark.svg
- `email-omnisend.svg` (17,305 B, raster embed, 192x192 PNG) —
  Omnisend mark (black rounded square, white mark). Source:
  https://www.omnisend.com/wp-content/uploads/2022/07/cropped-favi-192x192.png
  (served as WebP, converted to PNG). The published wordmark
  (`omnisend_logo_dark.svg`, 180x26, 6.9:1) is unreadable at 32px.
- `email-onesignal.svg` (1,064 B, vector) — OneSignal mark, navy
  `#051B2C` only. Source: https://onesignal.com/favicon.svg The
  published file ships two variants switched by a
  `prefers-color-scheme` media query (navy mark / white mark), but
  the query follows the OS/browser scheme, not the tile, and this
  plugin has no dark theme — so a dark-OS user would get the white
  mark on a white tile (invisible). Only the navy variant is
  shipped here (the media query and the white group are stripped;
  the navy path data is byte-identical to the source). The navy
  mark is low-contrast on a dark tile, like the other dark marks
  noted below; the picker has no dark theme, so navy is the
  correct single variant.
- `email-plunk.svg` (1,648 B, vector) — Plunk "P" mark (black).
  Source:
  https://www.useplunk.com/_next/static/media/logo.02awr5i-j9sof.svg
- `email-postmark.svg` (2,150 B, raster embed, 48x48 PNG) —
  Postmark "P" mark (black P on Postmark yellow `#FFDE00`).
  Source: https://postmarkapp.com/favicon.ico (48px frame,
  PNG-decoded from the ICO).
- `email-runbox.svg` (57,647 B, raster embed, 152x147 PNG) —
  Runbox puzzle-cube mark. Source: https://runbox.com/favicon.ico
  (PNG inside).
- `email-sender-net.svg` (2,531 B, raster embed, 48x48 PNG) —
  Sender mark (orange rounded square, white envelope + check).
  Source: https://www.sender.net/assets/favicon.png The published
  wordmark (`sender-logo-coloured.svg`, 108x32) is small at 32px.
- `email-sendlayer.svg` (7,970 B, raster embed, 192x192 PNG) —
  SendLayer stacked-layers mark. Source:
  https://sendlayer.com/wp-content/uploads/2022/01/cropped-favicon-512x512-1-192x192.png
  (served as WebP, converted to PNG). The published wordmark
  (`logo-dark.svg`, 130x27, 4.8:1) is small at 32px.
- `email-sendpulse.svg` (794 B, vector) — SendPulse pulse mark
  (blue `#009FC1`). Source:
  https://sendpulse.com/templates/sendpulsev1/img/favicons/favicon.svg
  with the near-white `#F3FDFF` background rect removed so the
  mark is transparent (the pulse path itself is untouched). The
  full vertical SendPulse logo on Commons could not be downloaded
  (HTTP 429, see above).
- `email-ses.svg` (3,556 B, vector) — Amazon Simple Email Service
  icon (white envelope + "@" on `#DD344C`). Source: AWS
  Architecture Icons pack, `Icon-package_07312026` (downloaded
  2026-11-23 from https://aws.amazon.com/architecture/icons/),
  file
  `Architecture-Service-Icons_07312026/Arch_Business-Applications/32/Arch_Amazon-Simple-Email-Service_32.svg`
  (the 32px variant, as published). The older AWS Simple Icons SES
  mark on Commons (`AWS Simple Icons Messaging Amazon SES
  Email.svg`, 2011) was considered and not used: it is the 2011
  icon set's muted tan envelope, and upload.wikimedia.org
  rate-limited every download attempt (HTTP 429).
- `email-sina.svg` (4,798 B, raster embed, 32x32 PNG) — Sina eye
  mark (red). Source: https://www.sina.com.cn/favicon.ico
- `email-smtp-com.svg` (1,893 B, raster embed, 32x32 PNG) —
  SMTP.com envelope mark. Source:
  https://www.smtp.com/wp-content/uploads/2020/11/smtp-logo-fav-32x32-1.png
- `email-smtp2go.svg` (5,132 B, raster embed, 48x48 PNG) —
  SMTP2GO blue envelope mark. Source:
  https://www.smtp2go.com/favicon.ico (48px frame).
- `email-socketlabs.svg` (1,723 B, vector) — SocketLabs puzzle
  mark (orange `#fc6423`). Source:
  https://www.socketlabs.com/images/favicon.svg
- `email-startmail.svg` (4,078 B, raster embed, 48x48 PNG) —
  Startmail envelope mark (dark navy). Source:
  https://www.startmail.com/favicon.ico (48px frame). The published
  wordmark (`/_nuxt/startmail-logo.*.svg`, 137x20, 6.9:1) is
  unreadable at 32px.
- `email-unisender.svg` (11,182 B, raster embed, 48x48 PNG) —
  Unisender dog mascot mark. Source:
  https://www.unisender.com/favicon.ico (48px frame).
- `email-yandex.svg` (1,568 B, raster embed, 48x48 PNG) —
  Yandex Mail envelope mark (orange/yellow). Source:
  https://mail.yandex.com/favicon.ico (48px frame). The Commons
  `Yandex 2021.svg` logotype (512x123, 4.2:1) was downloaded but
  is a wide wordmark; the mail envelope mark renders better.
- `email-zeptomail.svg` (4,319 B, vector) — Zoho ZeptoMail
  lockup (blue envelope/Z + "Zoho ZeptoMail"). Source:
  https://www.zohowebstatic.com/sites/zweb/images/productlogos/zeptomail.svg
  Ratio 3.86:1, so it renders small (~8px tall) at 32px, but it
  is the current official lockup and no icon-only variant is
  published.

### Unchanged real marks (22, from the earlier set)

`email-brevo.svg`, `email-campaign-monitor.svg`, `email-gandi.svg`,
`email-gmail.svg`, `email-gmx.svg`, `email-icloud.svg`,
`email-infomaniak.svg`, `email-loops.svg`, `email-mail-com.svg`,
`email-mail-ru.svg`, `email-mailchimp.svg`, `email-mailgun.svg`,
`email-mailtrap.svg`, `email-migadu.svg`, `email-namecheap.svg`,
`email-ovh.svg`, `email-proton.svg`, `email-qq-mail.svg`,
`email-resend.svg`, `email-sparkpost.svg`, `email-zoho.svg`,
`email-zoho-eu.svg` — all 22 are simple-icons (CC0 1.0) marks.
None were touched in this round. (`email-outlook.svg`,
`email-postmark.svg`, `email-sendgrid.svg`, `email-ses.svg` and
`email-yahoo.svg` were removed from this list in this fix round —
they are now real marks, see above — and `email-sendmail.svg` /
`email-smtp.svg` moved to "Generic envelope fallbacks" below.
That is the correction to the first pass's "29 unchanged" list,
which wrongly included the 5 hand-drawn glyph files and the 2
generic fallbacks as real marks.)

## Generic envelope fallbacks (2, intentional)

- `email-smtp.svg` (220 B) and `email-sendmail.svg` (338 B) —
  hand-drawn generic envelope glyphs, kept deliberately. They are
  not brand marks: `smtp` is the generic SMTP preset and
  `sendmail` is the generic Sendmail transport, so there is no
  brand to depict. `providerLogoUrl()` / `presetLogoUrl()` also
  fall back to `email-smtp.svg` for unknown ids (see "Wiring"
  below).

## Kept lettermark

- `email-pepipost.svg` (330 B) — two-initial lettermark, unchanged.
  Pepipost merged with parent company Netcore's transactional
  email arm (announced Feb 2018) and was rebranded as Netcore
  Email API (publicised 2020; netcorecloud.com/email-api/ now
  301s to netcore.ai/email-api/, verified 2026-11-23); no Pepipost
  mark is published anymore, and using Netcore's "N" would
  mislabel the provider id `pepipost`. Keeping the lettermark is
  honest until the provider id itself is renamed or retired.

## Notes

- Black monochrome marks (`email-plunk.svg`, `email-mailfence.svg`,
  `email-klaviyo.svg`) are low-contrast on a dark tile, as is the
  pre-existing `email-resend.svg` (black simple-icons mark). That
  is the brands' own published artwork; no colour variant exists
  for these. `email-onesignal.svg` (navy `#051B2C`) is likewise
  low-contrast on a dark tile — see its entry above; the picker
  has no dark theme. (`email-mailerlite.svg` was wrongly listed
  here before: it is full-colour, a green bubble with white
  "lite", not a black monochrome mark.)
- Raster embeds are capped at 256px on the long edge (largest
  file in the set is `email-mailjet.svg` at 64,256 B). Total set
  size is 423,730 B for 66 files (sum of the 66 SVG files,
  recomputed 2026-11-23).
- The previous README's "Created here" section (10 lettermarks
  drawn for providers with no file in the Unified set) is
  superseded: all 10 of those files are now real marks
  (`email-mandrill.svg`, `email-unisender.svg`,
  `email-mailerlite.svg`, `email-hubspot.svg`,
  `email-omnisend.svg`, `email-klaviyo.svg`,
  `email-getresponse.svg`, `email-aweber.svg`,
  `email-constant-contact.svg`, `email-moosend.svg`).

## Wiring

`MailerModule::PROVIDER_LOGOS` and `::PRESET_LOGOS` map provider and
preset ids to file stems; `providerLogoUrl()` / `presetLogoUrl()`
resolve them (unknown id or missing file falls back to the generic
`email-smtp.svg` envelope). The picker popup renders them with plain
`<img>` tags.
