# Vendored: PHP QR Code (phpqrcode)

Source: https://github.com/t0k4rt/phpqrcode (`master`, files as of
2026-11-21). License: **GNU LGPL 3** (see the header of each file;
copyright Kentaro Fukuchi 2006–2009 and Dominik Dzienia 2010).
The library is used only to render the TOTP `otpauth://` URI as a
QR code on the user profile page, so an authenticator app can be
enrolled by scanning instead of typing the secret.

## Why vendored

The theme ships zero runtime Composer dependencies, and a QR
encoder is a large, stable, self-contained algorithm — vendoring
keeps the Two-Factor module dependency-free. The files are used
exactly as upstream, except for the patch below.

## TrayDigita patch (`qrconfig.php` only)

- Every `define()` is guarded with `defined()` so the including
  code may pre-define the constants.
- `QR_CACHEABLE` defaults to `false` instead of `true`: the theme
  renders a QR code on demand (one per TOTP enrolment) and must
  never write mask/frame cache files into the theme directory at
  runtime, and must never emit PHP warnings into AJAX JSON
  responses when the cache directory is missing.

No other file was modified. Do not edit the library files; if the
encoder ever needs an upgrade, replace this directory with the new
upstream copy and re-apply the `qrconfig.php` patch.

## Usage (from `QrCode.php`)

```php
$enc = QRencode::factory(QR_ECLEVEL_M, 4, 4);
$frame = $enc->encode($text); // array of '0'/'1' strings
```

The frame is rendered to inline SVG by the module's own
`QrCode::renderSvg()` — the library's PNG/SVG entry points are
not used, because they send HTTP headers and would break AJAX
JSON responses.

## Verification

Round-trip tested 2026-11-21 in the build VM: `QRcode::png()`
output for an `otpauth://` URI was decoded back by `zbarimg`
to the exact same payload, and the encoder frame shows the
correct finder patterns (`1111111` / `1000001` / `1011101`).
