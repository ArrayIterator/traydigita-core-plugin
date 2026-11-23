<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use function esc_attr;
use function esc_html;
use function is_string;

/**
 * Shared admin notification renderer (Part G).
 *
 * Every admin notification — inline notices and toasts — speaks
 * one visual language from the core admin UI library
 * (resources/css/components/admin-ui.css): a status icon (never
 * colour alone), a title plus a message, and a dismiss button.
 * No WP .notice classes are used anywhere in the TrayDigita
 * admin; older `traydigita-admin-notice` markup keeps rendering
 * through the same library styles (icon via CSS, dismiss button
 * injected by admin.ts).
 *
 * Types: 'success', 'error', 'warning', 'info'. The icon is a
 * Material Icons ligature (self-hosted font, see
 * resources/css/components/material-icons.css) so the status never
 * relies on colour alone.
 */
final class AdminNotice
{
    /**
     * Material Icons ligature per notice type.
     *
     * @var array<string, string>
     */
    private const array ICONS = [
        'success' => 'check_circle',
        'error' => 'error',
        'warning' => 'warning',
        'info' => 'info',
    ];

    /**
     * Echo one inline notice.
     *
     * The title is optional: when it is empty only the message is
     * shown. The message is escaped as plain text; pass pre-escaped
     * HTML through renderHtml() instead when a link is needed.
     *
     * @param string $type Notice type: success, error, warning or info.
     * @param string $message Notice message (plain text).
     * @param string $title Notice title (plain text, optional).
     * @return void
     */
    public static function render(string $type, string $message, string $title = ''): void
    {
        self::renderHtml(
            $type,
            '<p class="traydigita-ui-notice-message">' . esc_html($message) . '</p>',
            $title
        );
    }

    /**
     * Echo one inline notice with pre-escaped HTML as the message.
     *
     * Only callers that already escaped every interpolated value
     * (links, strong text) may use this; the title is escaped here.
     * The message HTML is inserted as-is, so it may hold one or
     * more paragraphs (each with class
     * traydigita-ui-notice-message).
     *
     * @param string $type Notice type: success, error, warning or info.
     * @param string $messageHtml Pre-escaped message HTML.
     * @param string $title Notice title (plain text, optional).
     * @return void
     */
    public static function renderHtml(string $type, string $messageHtml, string $title = ''): void
    {
        $type = isset(self::ICONS[$type]) ? $type : 'info';
        $icon = self::ICONS[$type];
        $titleHtml = $title === '' ? '' : '<p class="traydigita-ui-notice-title">' . esc_html($title) . '</p>';
        echo '<div class="traydigita-ui-notice traydigita-ui-notice-' . esc_attr($type) . '" role="status">'
            . '<span class="traydigita-ui-notice-icon material-icons" aria-hidden="true">' . esc_html($icon) . '</span>'
            . '<div class="traydigita-ui-notice-body">'
            . $titleHtml
            . $messageHtml
            . '</div>'
            . '<button type="button" class="traydigita-ui-notice-dismiss" data-traydigita-notice-dismiss aria-label="'
            . esc_attr__('Dismiss', 'traydigita-core')
            . '"><span class="material-icons" aria-hidden="true">close</span></button>'
            . '</div>';
    }

    /**
     * Whether a string is a known notice type.
     *
     * @param mixed $type Candidate type.
     * @return bool
     */
    public static function isType(mixed $type): bool
    {
        return is_string($type) && isset(self::ICONS[$type]);
    }
}
