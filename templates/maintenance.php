<?php
/**
 * Public maintenance page (standalone 503 page).
 *
 * Rendered by MaintenanceModule::renderMaintenancePage() when
 * maintenance mode is enabled and the request is not bypassed or
 * excluded. This is a complete HTML document on purpose: it must
 * render even when the theme stylesheet bundle is unavailable, so
 * it carries a small critical inline <style> built from the theme
 * design tokens (brand green, gold accent, paper/surface/ink) and
 * loads no external asset.
 *
 * Variables (set by the renderer before this file is required):
 * @var string $maintenance_title Maintenance title.
 * @var string $maintenance_message Maintenance message (sanitized with wp_kses_post on save).
 * @var string $maintenance_site_name Site name.
 * @var string $maintenance_countdown Countdown target as an ISO 8601 string, or '' when unset.
 * @var array{days: int, hours: int, minutes: int, seconds: int} $maintenance_countdown_parts Countdown parts for the no-JavaScript render.
 * @var array<string, mixed> $maintenance_design Page design from the Maintenance Page Builder.
 * @var string $maintenance_design_css Generated stylesheet for the design (already sanitized).
 * @var string $maintenance_newsletter_nonce Nonce for the newsletter signup form.
 * @var string $maintenance_subscribed Newsletter signup status flag ('ok', 'error', or '').
 */
declare(strict_types=1);

?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($maintenance_title); ?> | <?php echo esc_html($maintenance_site_name); ?></title>
    <style>
        /* Design tokens mirrored from resources/css/app.css (:root for
           light, html.dark for dark) — this page must render without
           the theme bundle, so the values are copied here, not
           imported. Keep them identical to app.css when either file
           changes; in particular the dark --brand / --gold use the
           same color-mix() lightening as app.css, never a hard-coded
           hex that drifts from it. */
        :root {
            --traydigita-color-primary: #166534;
            --traydigita-color-accent: #b45309;
            --paper: #f8f7f4;
            --surface: #ffffff;
            --surface-2: #f3f0e6;
            --ink: #1c1917;
            --muted: #5c645e;
            --line: #e3dfd2;
            --brand: var(--traydigita-color-primary);
            --brand-dark: color-mix(in srgb, var(--traydigita-color-primary) 80%, #000000);
            --brand-deep: #063a20;
            --gold: var(--traydigita-color-accent);
            --gold-light: #e3b93e;
            --gold-soft: #f6ecd2;
            --gold-dark: #92400e;
            --danger: #b3261e;
            --shadow: 0 12px 32px rgba(6, 58, 32, 0.14);
            --font-sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            --font-serif: Georgia, "Times New Roman", serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #0b120e;
                --surface: #131b16;
                --surface-2: #182219;
                --ink: #e9ede9;
                --muted: #9aa79e;
                --line: #26332a;
                --brand: color-mix(in srgb, var(--traydigita-color-primary) 65%, #ffffff);
                --brand-dark: #3aa76a;
                --brand-deep: #063a20;
                --gold: color-mix(in srgb, var(--traydigita-color-accent) 55%, #ffffff);
                --gold-light: #e3b93e;
                --gold-soft: #2b2410;
                --gold-dark: #e3b93e;
                --danger: #ff8a80;
                --shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--paper);
            color: var(--ink);
            font-family: var(--font-serif);
            line-height: 1.6;
        }
        .maintenance-wrap { width: 100%; max-width: 36rem; }
        .maintenance-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-top: 0.375rem solid var(--brand);
            border-radius: 0.5rem;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: var(--shadow);
        }
        .maintenance-badge {
            display: inline-block;
            margin: 0 0 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            background: var(--surface-2);
            color: var(--gold);
            font-family: var(--font-sans);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .maintenance-site-name {
            margin: 0 0 0.5rem;
            font-family: var(--font-sans);
            font-size: 0.875rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .maintenance-title {
            margin: 0 0 1rem;
            font-size: 2rem;
            line-height: 1.25;
            font-weight: 700;
        }
        .maintenance-message { margin: 0 0 1.5rem; color: var(--muted); }
        .maintenance-message p { margin: 0 0 0.75rem; }
        .maintenance-message p:last-child { margin-bottom: 0; }
        .maintenance-message a { color: var(--brand); }
        .maintenance-countdown {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin: 0 0 1.5rem;
            padding: 0;
            list-style: none;
        }
        .maintenance-countdown-item {
            min-width: 4.25rem;
            padding: 0.75rem 0.5rem;
            border: 1px solid var(--line);
            border-radius: 0.375rem;
            background: var(--surface-2);
        }
        .maintenance-countdown-number {
            display: block;
            font-family: var(--font-sans);
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
        }
        .maintenance-countdown-label {
            display: block;
            font-family: var(--font-sans);
            font-size: 0.6875rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .maintenance-note {
            margin: 0;
            font-family: var(--font-sans);
            font-size: 0.875rem;
            color: var(--muted);
        }
        .traydigita-maintenance-logo { margin: 0 0 1rem; }
        .traydigita-maintenance-logo img { display: block; margin: 0 auto; }
        .traydigita-maintenance-subtext { margin: 0 0 1rem; color: var(--muted); }
        .traydigita-maintenance-extra-text { margin: 0 0 1.5rem; color: var(--muted); }
        .traydigita-maintenance-button { margin: 0 0 1.5rem; }
        .traydigita-maintenance-social { list-style: none; margin: 0 0 1rem; padding: 0; }
        .traydigita-maintenance-social li { display: inline-block; margin: 0 0.5rem; }
        .traydigita-maintenance-social a { color: var(--brand); font-family: var(--font-sans); font-size: 0.875rem; }
        .traydigita-maintenance-newsletter { margin: 0 0 1rem; }
        .traydigita-maintenance-newsletter h3 { margin: 0 0 0.5rem; font-family: var(--font-sans); font-size: 1rem; }
        .traydigita-maintenance-newsletter input[type="email"] { padding: 0.5rem 0.75rem; border: 1px solid var(--line); border-radius: 0.375rem; font-family: var(--font-sans); font-size: 0.875rem; }
        .traydigita-maintenance-newsletter button { padding: 0.5rem 1rem; border: 1px solid var(--brand); border-radius: 0.375rem; background: var(--brand); color: #fff; font-family: var(--font-sans); font-size: 0.875rem; font-weight: 700; cursor: pointer; }
        .traydigita-maintenance-subscribed { margin: 0 0 1rem; font-family: var(--font-sans); font-size: 0.875rem; color: var(--brand); }
    </style>
    <?php if ($maintenance_design_css !== '') : ?>
        <style>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MaintenanceDesigner::buildCss() re-validates every value and sanitizes the Custom CSS block.
            echo $maintenance_design_css;
            ?>
        </style>
    <?php endif; ?>
</head>
<body class="traydigita-maintenance-page traydigita-maintenance">
    <?php
    $maintenance_designer = \TrayDigita\WP\Plugin\Core\Modules\Maintenance\MaintenanceDesigner::class;
    $maintenance_logo = $maintenance_designer::blockSettings($maintenance_design, 'logo');
    $maintenance_logo_url = $maintenance_designer::attachmentUrl(absint($maintenance_logo['attachment_id'] ?? 0));
    $maintenance_heading = $maintenance_designer::blockSettings($maintenance_design, 'heading');
    $maintenance_heading_text = is_string($maintenance_heading['heading'] ?? null) && $maintenance_heading['heading'] !== '' ? $maintenance_heading['heading'] : $maintenance_title;
    $maintenance_subtext = is_string($maintenance_heading['subtext'] ?? null) ? $maintenance_heading['subtext'] : '';
    $maintenance_text = $maintenance_designer::blockSettings($maintenance_design, 'text');
    $maintenance_extra_text = is_string($maintenance_text['text'] ?? null) ? $maintenance_text['text'] : '';
    $maintenance_countdown_block = $maintenance_designer::blockSettings($maintenance_design, 'countdown');
    $maintenance_show_countdown = !empty($maintenance_countdown_block['show']);
    $maintenance_button = $maintenance_designer::blockSettings($maintenance_design, 'button');
    $maintenance_button_label = is_string($maintenance_button['label'] ?? null) ? $maintenance_button['label'] : '';
    $maintenance_button_url = is_string($maintenance_button['url'] ?? null) ? $maintenance_button['url'] : '';
    $maintenance_social = $maintenance_designer::blockSettings($maintenance_design, 'social');
    $maintenance_social_links = is_array($maintenance_social['links'] ?? null) ? $maintenance_social['links'] : [];
    $maintenance_newsletter = $maintenance_designer::blockSettings($maintenance_design, 'newsletter');
    $maintenance_show_newsletter = !empty($maintenance_newsletter['show']);
    $maintenance_newsletter_heading = is_string($maintenance_newsletter['heading'] ?? null) ? $maintenance_newsletter['heading'] : '';
    ?>
    <main class="maintenance-wrap">
        <div class="maintenance-card">
            <?php if ($maintenance_logo_url !== '') : ?>
                <p class="traydigita-maintenance-logo">
                    <?php if (is_string($maintenance_logo['link_url'] ?? null) && $maintenance_logo['link_url'] !== '') : ?>
                        <a href="<?php echo esc_url($maintenance_logo['link_url']); ?>"><img src="<?php echo esc_url($maintenance_logo_url); ?>" alt="<?php echo esc_attr(is_string($maintenance_logo['alt'] ?? null) ? $maintenance_logo['alt'] : ''); ?>" width="<?php echo esc_attr((string) absint($maintenance_logo['width'] ?? 280)); ?>"></a>
                    <?php else : ?>
                        <img src="<?php echo esc_url($maintenance_logo_url); ?>" alt="<?php echo esc_attr(is_string($maintenance_logo['alt'] ?? null) ? $maintenance_logo['alt'] : ''); ?>" width="<?php echo esc_attr((string) absint($maintenance_logo['width'] ?? 280)); ?>">
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <p class="maintenance-badge"><?php esc_html_e('Maintenance', 'traydigita-core'); ?></p>
            <p class="maintenance-site-name"><?php echo esc_html($maintenance_site_name); ?></p>
            <h1 class="maintenance-title"><?php echo esc_html($maintenance_heading_text); ?></h1>
            <?php if ($maintenance_subtext !== '') : ?>
                <p class="traydigita-maintenance-subtext"><?php echo esc_html($maintenance_subtext); ?></p>
            <?php endif; ?>
            <div class="maintenance-message"><?php echo wp_kses_post($maintenance_message); ?></div>
            <?php if ($maintenance_extra_text !== '') : ?>
                <p class="traydigita-maintenance-extra-text"><?php echo esc_html($maintenance_extra_text); ?></p>
            <?php endif; ?>
            <?php if ($maintenance_countdown !== '' && $maintenance_show_countdown) : ?>
                <ul class="maintenance-countdown" data-maintenance-countdown data-countdown-to="<?php echo esc_attr($maintenance_countdown); ?>">
                    <li class="maintenance-countdown-item">
                        <span class="maintenance-countdown-number" data-countdown-days><?php echo esc_html((string) $maintenance_countdown_parts['days']); ?></span>
                        <span class="maintenance-countdown-label"><?php esc_html_e('Days', 'traydigita-core'); ?></span>
                    </li>
                    <li class="maintenance-countdown-item">
                        <span class="maintenance-countdown-number" data-countdown-hours><?php echo esc_html(str_pad((string) $maintenance_countdown_parts['hours'], 2, '0', STR_PAD_LEFT)); ?></span>
                        <span class="maintenance-countdown-label"><?php esc_html_e('Hours', 'traydigita-core'); ?></span>
                    </li>
                    <li class="maintenance-countdown-item">
                        <span class="maintenance-countdown-number" data-countdown-minutes><?php echo esc_html(str_pad((string) $maintenance_countdown_parts['minutes'], 2, '0', STR_PAD_LEFT)); ?></span>
                        <span class="maintenance-countdown-label"><?php esc_html_e('Minutes', 'traydigita-core'); ?></span>
                    </li>
                    <li class="maintenance-countdown-item">
                        <span class="maintenance-countdown-number" data-countdown-seconds><?php echo esc_html(str_pad((string) $maintenance_countdown_parts['seconds'], 2, '0', STR_PAD_LEFT)); ?></span>
                        <span class="maintenance-countdown-label"><?php esc_html_e('Seconds', 'traydigita-core'); ?></span>
                    </li>
                </ul>
            <?php endif; ?>
            <p class="maintenance-note"><?php esc_html_e('Thank you for your patience. Please check back soon.', 'traydigita-core'); ?></p>
            <?php if ($maintenance_button_label !== '' && $maintenance_button_url !== '') : ?>
                <p class="traydigita-maintenance-button"><a class="traydigita-maintenance-button-link" href="<?php echo esc_url($maintenance_button_url); ?>"><?php echo esc_html($maintenance_button_label); ?></a></p>
            <?php endif; ?>
            <?php if ($maintenance_social_links !== []) : ?>
                <ul class="traydigita-maintenance-social">
                    <?php foreach ($maintenance_social_links as $maintenance_link) : ?>
                        <?php
                        if (!is_array($maintenance_link)) {
                            continue;
                        }
                        ?>
                        <?php $maintenance_link_label = is_string($maintenance_link['label'] ?? null) ? $maintenance_link['label'] : ''; ?>
                        <?php $maintenance_link_url = is_string($maintenance_link['url'] ?? null) ? $maintenance_link['url'] : ''; ?>
                        <?php
                        if ($maintenance_link_label === '' || $maintenance_link_url === '') {
                            continue;
                        }
                        ?>
                        <li><a href="<?php echo esc_url($maintenance_link_url); ?>"><?php echo esc_html($maintenance_link_label); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($maintenance_show_newsletter) : ?>
                <div class="traydigita-maintenance-newsletter">
                    <?php if ($maintenance_newsletter_heading !== '') : ?>
                        <h3><?php echo esc_html($maintenance_newsletter_heading); ?></h3>
                    <?php endif; ?>
                    <?php if ($maintenance_subscribed === 'ok') : ?>
                        <p class="traydigita-maintenance-subscribed"><?php esc_html_e('Thank you! Please check your email to confirm the subscription.', 'traydigita-core'); ?></p>
                    <?php elseif ($maintenance_subscribed === 'error') : ?>
                        <p class="traydigita-maintenance-subscribed"><?php esc_html_e('That email address could not be subscribed. Please try again.', 'traydigita-core'); ?></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="traydigita_maintenance_newsletter">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($maintenance_newsletter_nonce); ?>">
                        <label>
                            <span class="screen-reader-text"><?php esc_html_e('Email address', 'traydigita-core'); ?></span>
                            <input type="email" name="email" required autocomplete="email" placeholder="<?php esc_attr_e('Email address', 'traydigita-core'); ?>">
                        </label>
                        <button type="submit"><?php esc_html_e('Notify me', 'traydigita-core'); ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <?php if ($maintenance_countdown !== '' && $maintenance_show_countdown) : ?>
        <script>
        (function () {
            var countdown = document.querySelector('[data-maintenance-countdown]');
            if (!countdown) {
                return;
            }
            var target = new Date(countdown.getAttribute('data-countdown-to'));
            if (isNaN(target.getTime())) {
                return;
            }
            var days = countdown.querySelector('[data-countdown-days]');
            var hours = countdown.querySelector('[data-countdown-hours]');
            var minutes = countdown.querySelector('[data-countdown-minutes]');
            var seconds = countdown.querySelector('[data-countdown-seconds]');
            var pad = function (value) {
                return String(value).padStart(2, '0');
            };
            var tick = function () {
                var remaining = Math.max(0, Math.floor((target.getTime() - Date.now()) / 1000));
                var d = Math.floor(remaining / 86400);
                var h = Math.floor((remaining % 86400) / 3600);
                var m = Math.floor((remaining % 3600) / 60);
                var s = remaining % 60;
                if (days) {
                    days.textContent = String(d);
                }
                if (hours) {
                    hours.textContent = pad(h);
                }
                if (minutes) {
                    minutes.textContent = pad(m);
                }
                if (seconds) {
                    seconds.textContent = pad(s);
                }
                if (remaining <= 0) {
                    window.clearInterval(timer);
                }
            };
            var timer = window.setInterval(tick, 1000);
            tick();
        })();
        </script>
    <?php endif; ?>
</body>
</html>
