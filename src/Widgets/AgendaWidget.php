<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Widgets;

use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaRenderer;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaTable;
use function __;
use function is_array;
use function md5;
use function sanitize_text_field;
use function serialize;

/**
 * Upcoming Events widget.
 *
 * Shows the next few upcoming (and currently ongoing) events,
 * soonest first, with cover, date range, location, and an iCal
 * link per event. Results are cached in a transient for 10
 * minutes; a newly saved event appears once the TTL expires.
 *
 * NOTE: unlike the post widgets, this widget does NOT render
 * through a `templates/widgets/` partial — the owned file list
 * for the agenda build has no widget partial, so renderContent()
 * is overridden to build the list through
 * AgendaRenderer::renderListHtml(), the same renderer the
 * shortcode and the archive use. getPartial() still returns the
 * conventional partial name for the base class contract.
 */
final class AgendaWidget extends WidgetBase
{
    /**
     * Widget id base (also the AJAX preview allowlist key).
     */
    public const string ID_BASE = 'traydigita-agenda';

    /**
     * Transient TTL in seconds (10 minutes).
     */
    public const int CACHE_TTL = 600;

    public function __construct()
    {
        parent::__construct(
            self::ID_BASE,
            __('Tray Digita: Upcoming Events', 'traydigita-core'),
            [
                'description' => __('The next upcoming agenda events.', 'traydigita-core'),
                'classname' => 'widget-agenda',
            ]
        );
    }

    /**
     * @inheritDoc
     */
    protected function getPartial(): string
    {
        return 'agenda';
    }

    /**
     * Render the widget body without a `templates/widgets/` partial.
     *
     * See the class docblock: the list is built by
     * AgendaRenderer::renderListHtml(), shared with the shortcode
     * and the archive, so the widget, the shortcode, and the
     * archive can never drift apart in markup.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return string
     */
    public function renderContent(array $instance): string
    {
        $data = $this->getWidgetData($instance);
        $events = is_array($data['events'] ?? null) ? $data['events'] : [];
        return '<div class="widget-agenda-list">'
            . AgendaRenderer::renderListHtml($events, ['show_cover' => (bool) ($data['show_cover'] ?? true)])
            . '</div>';
    }

    /**
     * @inheritDoc
     */
    protected function getWidgetData(array $instance): array
    {
        $count = $this->sanitizeCount($instance['count'] ?? 5);
        $category = sanitize_text_field((string) ($instance['category'] ?? ''));
        $showCover = $this->sanitizeBool($instance['show_cover'] ?? true);
        $cacheKey = 'agenda_' . md5(serialize([$count, $category, $showCover]));
        $cached = $this->getCached($cacheKey);
        if (is_array($cached)) {
            $cached['show_cover'] = $showCover;
            return $cached;
        }
        $events = AgendaTable::isInstalled()
            ? AgendaTable::upcoming($count, $category)
            : [];
        $data = [
            'events' => $events,
            'show_cover' => $showCover,
        ];
        $this->setCached($cacheKey, $data, self::CACHE_TTL);
        return $data;
    }

    /**
     * @param array<string, mixed> $instance Widget settings.
     */
    public function form($instance): ?string
    {
        $this->textField($instance, 'title', __('Title:', 'traydigita-core'));
        $this->numberField($instance, 'count', __('Number of events:', 'traydigita-core'), 5, 1, 20);
        $this->textField(
            $instance,
            'category',
            __('Category (optional, exact match):', 'traydigita-core')
        );
        $this->checkboxField($instance, 'show_cover', __('Show cover image', 'traydigita-core'), true);
        $this->previewField($instance);
        return null;
    }

    /**
     * @param array<string, mixed> $newInstance Raw submitted instance.
     * @param array<string, mixed> $oldInstance Previous instance.
     * @return array<string, mixed> Sanitized instance.
     */
    public function update($newInstance, $oldInstance): array
    {
        if (!is_array($newInstance)) {
            return [];
        }
        return [
            'title' => sanitize_text_field($newInstance['title'] ?? ''),
            'count' => $this->sanitizeCount($newInstance['count'] ?? 5),
            'category' => sanitize_text_field($newInstance['category'] ?? ''),
            'show_cover' => $this->sanitizeBool($newInstance['show_cover'] ?? false),
        ];
    }
}
