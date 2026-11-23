<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Agenda;

use function __;
use function absint;
use function add_filter;
use function add_rewrite_rule;
use function ceil;
use function date_i18n;
use function esc_attr;
use function esc_html;
use function esc_url;
use function file_exists;
use function get_option;
use function get_post_meta;
use function get_query_var;
use function header;
use function home_url;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function nocache_headers;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function status_header;
use function trim;
use function wp_get_attachment_image_src;
use function wp_json_encode;
use function wp_unslash;
use function wpautop;
use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
use const JSON_UNESCAPED_UNICODE;
use TrayDigita\WP\Plugin\Core\Libs\ThemeImage;

/**
 * Frontend rendering for the agenda: rewrite routes, archive and
 * single views, the shortcode list, and the iCal download.
 *
 * There is NO custom post type: the archive lives at `/agenda/`,
 * a single event at `/agenda/<id>/`, and its iCal file at
 * `/agenda/<id>.ics`, all through rewrite rules plus query vars.
 * The templates in `templates/agenda/` are plain WordPress
 * templates (get_header() / get_footer()) selected through the
 * `template_include` filter, so the theme header, footer, and
 * sidebars render around them exactly like any other template.
 *
 * Cover images: an event cover is a media-library attachment id,
 * not a post, so the stack FeatureImage helper (which resolves a
 * post's featured image) cannot resolve it directly. The cover is
 * therefore rendered through ThemeImage::thumbnailFromArray() with
 * a FeatureImage-shaped array built from
 * wp_get_attachment_image_src() — the same escaped <img> output
 * (srcset, Photon, alt text) the rest of the theme gets.
 */
final class AgendaRenderer
{
    /**
     * Query variable carrying the agenda view ('archive' or 'single').
     */
    public const string QUERY_VAR_VIEW = 'traydigita_agenda';

    /**
     * Query variable carrying the event id for single / ICS views.
     */
    public const string QUERY_VAR_ID = 'traydigita_agenda_id';

    /**
     * Query variable flagging an iCal download request.
     */
    public const string QUERY_VAR_ICS = 'traydigita_agenda_ics';

    /**
     * Rewrite rule for the archive (`/agenda/`).
     */
    public const string ARCHIVE_REGEX = '^agenda/?$';

    /**
     * Rewrite rule for a single event (`/agenda/<id>/`).
     */
    public const string SINGLE_REGEX = '^agenda/([0-9]+)/?$';

    /**
     * Rewrite rule for an event iCal file (`/agenda/<id>.ics`).
     */
    public const string ICS_REGEX = '^agenda/([0-9]+)\\.ics$';

    /**
     * Register the rewrite rules and query variables.
     *
     * Called on `init` from AgendaModule::boot(). The rules are
     * static (they do not depend on settings), so no rewrite flush
     * is needed when settings change; the one-time flush after the
     * module is first enabled is handled by AgendaModule.
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        add_filter('query_vars', [self::class, 'addQueryVars']);
        add_rewrite_rule(
            self::ARCHIVE_REGEX,
            'index.php?' . self::QUERY_VAR_VIEW . '=archive',
            'top'
        );
        add_rewrite_rule(
            self::SINGLE_REGEX,
            'index.php?' . self::QUERY_VAR_VIEW . '=single&' . self::QUERY_VAR_ID . '=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            self::ICS_REGEX,
            'index.php?' . self::QUERY_VAR_VIEW . '=single&' . self::QUERY_VAR_ID . '=$matches[1]'
                . '&' . self::QUERY_VAR_ICS . '=1',
            'top'
        );
    }

    /**
     * Add the agenda query variables to the allowlist.
     *
     * @param array<int, string> $vars Registered public query variables.
     * @return array<int, string>
     */
    public static function addQueryVars(array $vars): array
    {
        $vars[] = self::QUERY_VAR_VIEW;
        $vars[] = self::QUERY_VAR_ID;
        $vars[] = self::QUERY_VAR_ICS;
        return $vars;
    }

    /**
     * Select the agenda template for agenda requests.
     *
     * Hooked on `template_include`. A single request for a missing
     * event id falls through to the normal 404 template instead of
     * rendering an empty single view.
     *
     * @param string $template Template WordPress selected.
     * @return string Template path to load.
     */
    public static function templateInclude(string $template): string
    {
        $view = get_query_var(self::QUERY_VAR_VIEW);
        if ($view === 'archive') {
            $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/agenda/archive.php';
            return file_exists($file) ? $file : $template;
        }
        if ($view === 'single') {
            $event = AgendaTable::get((int) get_query_var(self::QUERY_VAR_ID));
            if ($event === null) {
                $GLOBALS['wp_query']->set_404();
                status_header(404);
                return $template;
            }
            $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/agenda/single.php';
            return file_exists($file) ? $file : $template;
        }
        return $template;
    }

    /**
     * Serve the .ics download, or 404 when the event does not exist.
     *
     * Hooked on `template_redirect`. Only requests carrying the ICS
     * query variable reach this; everything else returns at once.
     * When the ICS toggle is off, the request is answered 404.
     *
     * @return void
     */
    public static function maybeServeIcs(): void
    {
        if (get_query_var(self::QUERY_VAR_ICS) === '') {
            return;
        }
        $event = AgendaTable::get((int) get_query_var(self::QUERY_VAR_ID));
        if ($event === null || !AgendaModule::getSettings()['ics_enabled']) {
            $GLOBALS['wp_query']->set_404();
            status_header(404);
            return;
        }
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . IcsExport::filename($event) . '"');
        echo IcsExport::build($event);
        exit;
    }

    /**
     * Print Event JSON-LD on single agenda views.
     *
     * Hooked on `wp_head`. The template cannot print this itself —
     * get_header() (and wp_head) already ran before the template
     * body — so the renderer emits it from the hook when the
     * single view carries a real event.
     *
     * @return void
     */
    public static function renderJsonLd(): void
    {
        if (get_query_var(self::QUERY_VAR_VIEW) !== 'single') {
            return;
        }
        $event = AgendaTable::get((int) get_query_var(self::QUERY_VAR_ID));
        if ($event === null) {
            return;
        }
        $data = self::buildEventJsonLd($event);
        if ($data === null) {
            return;
        }
        $json = wp_json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') {
            return;
        }
        echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /**
     * Build schema.org Event data for one event, or null with no title.
     *
     * Dates are ISO 8601 with the site timezone offset; the
     * location is a Place when a location string exists, and the
     * event URL is the single-event permalink.
     *
     * @param array<string, mixed> $event Event row.
     * @return array<string, mixed>|null
     */
    public static function buildEventJsonLd(array $event): ?array
    {
        $title = (string) ($event['title'] ?? '');
        if ($title === '') {
            return null;
        }
        $start = AgendaTable::parseStoredDatetime((string) ($event['start_datetime'] ?? ''));
        if ($start === null) {
            return null;
        }
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $title,
            'startDate' => $start->format('c'),
            'url' => self::eventUrl($event),
        ];
        $endRaw = (string) ($event['end_datetime'] ?? '');
        if ($endRaw !== '') {
            $end = AgendaTable::parseStoredDatetime($endRaw);
            if ($end !== null) {
                $data['endDate'] = $end->format('c');
            }
        }
        $description = trim((string) ($event['description'] ?? ''));
        if ($description !== '') {
            $data['description'] = $description;
        }
        $location = trim((string) ($event['location'] ?? ''));
        if ($location !== '') {
            $data['location'] = [
                '@type' => 'Place',
                'name' => $location,
            ];
        }
        $imageUrl = self::coverUrl($event, 'full');
        if ($imageUrl !== '') {
            $data['image'] = [$imageUrl];
        }
        return $data;
    }

    /**
     * Archive URL (`/agenda/`).
     *
     * @return string
     */
    public static function archiveUrl(): string
    {
        return home_url('/agenda/');
    }

    /**
     * Single-event URL (`/agenda/<id>/`).
     *
     * @param array<string, mixed> $event Event row.
     * @return string
     */
    public static function eventUrl(array $event): string
    {
        return home_url('/agenda/' . (int) ($event['id'] ?? 0) . '/');
    }

    /**
     * iCal download URL (`/agenda/<id>.ics`).
     *
     * @param array<string, mixed> $event Event row.
     * @return string
     */
    public static function icsUrl(array $event): string
    {
        return home_url('/agenda/' . (int) ($event['id'] ?? 0) . '.ics');
    }

    /**
     * Archive page data for the archive template.
     *
     * Reads the list filter (`upcoming` / `ongoing` / `past` / all)
     * and search term from the query string, sanitized here so the
     * template never touches $_GET itself.
     *
     * @return array{events: array<int, array<string, mixed>>, total: int,
     *     page: int, pages: int, per_page: int, filter: string, search: string}
     */
    public static function archiveData(): array
    {
        $settings = AgendaModule::getSettings();
        $filter = sanitize_key((string) ($_GET['filter'] ?? ''));
        if (!in_array($filter, AgendaTable::STATUSES, true)) {
            $filter = '';
        }
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        $page = max(1, (int) get_query_var('paged', 1));
        if ($page === 1) {
            $page = max(1, absint($_GET['paged'] ?? 1));
        }
        $perPage = $settings['archive_per_page'];
        $result = AgendaTable::list([
            'status' => $filter,
            'search' => $search,
            'page' => $page,
            'per_page' => $perPage,
            // With no explicit filter and past events hidden in
            // settings, the archive lists upcoming + ongoing only.
            'exclude_past' => $filter === '' && !$settings['show_past_in_archive'],
        ]);
        return [
            'events' => $result['events'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / $perPage)),
            'per_page' => $perPage,
            'filter' => $filter,
            'search' => $search,
        ];
    }

    /**
     * Single-event data for the single template.
     *
     * @return array<string, mixed>|null Event row, or null when the id is unknown.
     */
    public static function singleData(): ?array
    {
        return AgendaTable::get((int) get_query_var(self::QUERY_VAR_ID));
    }

    /**
     * Render one event list as HTML (shortcode, widget, archive).
     *
     * Every value is escaped; the description is not shown in list
     * rows (it belongs on the single view). The ICS link is only
     * rendered when the ICS toggle is on.
     *
     * @param array<int, array<string, mixed>> $events Event rows.
     * @param array{show_cover?: bool} $args Display options.
     * @return string
     */
    public static function renderListHtml(array $events, array $args = []): string
    {
        if ($events === []) {
            return '<p class="agenda-empty">' . esc_html__('No events found.', 'traydigita-core') . '</p>';
        }
        $showCover = (bool) ($args['show_cover'] ?? true);
        $icsEnabled = AgendaModule::getSettings()['ics_enabled'];
        $html = '<ul class="agenda-list">';
        foreach ($events as $event) {
            $status = (string) ($event['status'] ?? AgendaTable::computeStatus($event));
            $html .= '<li class="agenda-event agenda-event-status-' . esc_attr($status) . '">';
            if ($showCover) {
                $cover = self::coverHtml($event, 'traydigita-card');
                if ($cover !== '') {
                    $html .= '<div class="agenda-event-cover">'
                        . '<a href="' . esc_url(self::eventUrl($event)) . '">' . $cover . '</a>'
                        . '</div>';
                }
            }
            $html .= '<div class="agenda-event-body">';
            $html .= '<h3 class="agenda-event-title">'
                . '<a href="' . esc_url(self::eventUrl($event)) . '">'
                . esc_html((string) ($event['title'] ?? '')) . '</a></h3>';
            $html .= '<p class="agenda-event-meta">';
            $html .= '<span class="agenda-event-status">' . esc_html(self::statusLabel($status)) . '</span>';
            $html .= '<span class="agenda-event-dates">' . esc_html(self::formatRange($event)) . '</span>';
            $location = trim((string) ($event['location'] ?? ''));
            if ($location !== '') {
                $html .= '<span class="agenda-event-location">' . esc_html($location) . '</span>';
            }
            $html .= '</p>';
            $link = trim((string) ($event['link'] ?? ''));
            if ($link !== '') {
                $html .= '<p class="agenda-event-link"><a href="' . esc_url($link) . '">'
                    . esc_html__('More information', 'traydigita-core') . '</a></p>';
            }
            if ($icsEnabled) {
                $html .= '<p class="agenda-event-ics"><a href="' . esc_url(self::icsUrl($event)) . '">'
                    . esc_html__('Add to calendar (.ics)', 'traydigita-core') . '</a></p>';
            }
            $html .= '</div></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Render the event description as safe HTML.
     *
     * The description is stored as plain text (sanitize_textarea_field),
     * so it is escaped first and then given paragraphs — no HTML
     * from the input can ever reach the page.
     *
     * @param array<string, mixed> $event Event row.
     * @return string
     */
    public static function renderDescription(array $event): string
    {
        $description = trim((string) ($event['description'] ?? ''));
        if ($description === '') {
            return '';
        }
        return wpautop(esc_html($description));
    }

    /**
     * Cover image <img> tag for an event, or '' when it has none.
     *
     * Built as a FeatureImage-shaped array from the attachment and
     * rendered through ThemeImage::thumbnailFromArray(), so the
     * markup (srcset, Photon, alt) matches every other theme image.
     *
     * @param array<string, mixed> $event Event row.
     * @param string $size Registered image size name.
     * @return string Complete <img> tag, or ''.
     */
    public static function coverHtml(array $event, string $size = 'traydigita-card'): string
    {
        $thumb = self::coverThumbnailArray($event, $size);
        if ($thumb === null) {
            return '';
        }
        return ThemeImage::thumbnailFromArray($thumb, $size, ['class' => 'agenda-event-image']);
    }

    /**
     * Cover image URL for an event, or '' when it has none.
     *
     * @param array<string, mixed> $event Event row.
     * @param string $size Registered image size name.
     * @return string
     */
    public static function coverUrl(array $event, string $size = 'traydigita-card'): string
    {
        $thumb = self::coverThumbnailArray($event, $size);
        if ($thumb === null) {
            return '';
        }
        return ThemeImage::urlFromArray($thumb, $size);
    }

    /**
     * Build the FeatureImage-shaped thumbnail array for a cover.
     *
     * The alt text comes from the attachment's
     * `_wp_attachment_image_alt` meta, falling back to the event
     * title; a missing or non-image attachment returns null.
     *
     * @param array<string, mixed> $event Event row.
     * @param string $size Registered image size name.
     * @return array{id: int, url: string, width: int, height: int, alt: ?string}|null
     */
    public static function coverThumbnailArray(array $event, string $size = 'traydigita-card'): ?array
    {
        $coverId = (int) ($event['cover_image_id'] ?? 0);
        if ($coverId <= 0) {
            return null;
        }
        $src = wp_get_attachment_image_src($coverId, $size);
        if (!is_array($src) || !isset($src[0]) || !is_string($src[0]) || $src[0] === '') {
            return null;
        }
        $alt = get_post_meta($coverId, '_wp_attachment_image_alt', true);
        if (!is_string($alt) || $alt === '') {
            $alt = (string) ($event['title'] ?? '');
        }
        return [
            'id' => $coverId,
            'url' => $src[0],
            'width' => isset($src[1]) ? (int) $src[1] : 0,
            'height' => isset($src[2]) ? (int) $src[2] : 0,
            'alt' => $alt,
        ];
    }

    /**
     * Human-readable status label.
     *
     * @param string $status One of the AgendaTable STATUS_* constants.
     * @return string
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            AgendaTable::STATUS_ONGOING => __('Ongoing', 'traydigita-core'),
            AgendaTable::STATUS_PAST => __('Past', 'traydigita-core'),
            default => __('Upcoming', 'traydigita-core'),
        };
    }

    /**
     * Format an event's date range in the site date/time formats.
     *
     * A single-day event shows one date with start–end times; a
     * multi-day event shows both dates; an event without an end
     * shows the start date and time only.
     *
     * @param array<string, mixed> $event Event row.
     * @return string
     */
    public static function formatRange(array $event): string
    {
        $start = AgendaTable::parseStoredDatetime((string) ($event['start_datetime'] ?? ''));
        if ($start === null) {
            return '';
        }
        $dateFormat = (string) get_option('date_format', 'F j, Y');
        $timeFormat = (string) get_option('time_format', 'g:i a');
        $endRaw = (string) ($event['end_datetime'] ?? '');
        $end = $endRaw !== '' ? AgendaTable::parseStoredDatetime($endRaw) : null;
        if ($end === null) {
            return date_i18n($dateFormat . ' ' . $timeFormat, $start->getTimestamp());
        }
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return sprintf(
                /* translators: 1: date, 2: start time, 3: end time. */
                __('%1$s, %2$s – %3$s', 'traydigita-core'),
                date_i18n($dateFormat, $start->getTimestamp()),
                date_i18n($timeFormat, $start->getTimestamp()),
                date_i18n($timeFormat, $end->getTimestamp())
            );
        }
        return sprintf(
            /* translators: 1: start date and time, 2: end date and time. */
            __('%1$s – %2$s', 'traydigita-core'),
            date_i18n($dateFormat . ' ' . $timeFormat, $start->getTimestamp()),
            date_i18n($dateFormat . ' ' . $timeFormat, $end->getTimestamp())
        );
    }

    /**
     * Machine-readable datetime attribute for a <time> element.
     *
     * @param array<string, mixed> $event Event row.
     * @return string ISO 8601 datetime, or '' when the start is invalid.
     */
    public static function startDatetimeAttr(array $event): string
    {
        $start = AgendaTable::parseStoredDatetime((string) ($event['start_datetime'] ?? ''));
        return $start === null ? '' : $start->format('c');
    }
}
