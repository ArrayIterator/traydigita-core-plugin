<?php
/**
 * Default HTML email wrapper layout.
 *
 * Variables (set by TemplateBuilder::renderTemplate()):
 * @var string $innerHtml    Rendered table rows (template blocks + wrapped content).
 * @var string $templateName Template name, used as the document title.
 *
 * This partial is the canonical layout for every templated mail: the
 * marker comment is always first so the wp_mail filter can detect
 * already-templated mail and leave it untouched. All styling is
 * inline so the mail renders in common mail clients.
 */
declare(strict_types=1);

?>
<!-- traydigita-email-template -->
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo esc_html($templateName); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f5;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">
<?php echo $innerHtml; ?>
</table>
</td></tr>
</table>
</body>
</html>
