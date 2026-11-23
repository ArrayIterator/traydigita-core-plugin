<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Libs;

use function __;
use function abs;
use function array_map;
use function count;
use function esc_attr;
use function esc_html;
use function function_exists;
use function in_array;
use function is_array;
use function is_numeric;
use function max;
use function min;
use function number_format_i18n;
use function sprintf;
use function substr;
use function wp_enqueue_script_module;
use function wp_json_encode;

/**
 * Dependency-free SVG chart helper.
 *
 * Renders line, area, bar, and sparkline charts as inline SVG from
 * a series of points — no chart library, no canvas, no JavaScript
 * required for the picture itself. Colours come from `currentColor`
 * (see resources/css/components/charts.css), so charts follow the
 * theme tokens in light and dark mode.
 *
 * Data honesty: only real fetched points are drawn. A null value is
 * a gap — the line breaks there, it is never interpolated. A series
 * with fewer than two real points renders nothing (empty string),
 * never a fake flat line. Every chart carries its source and period
 * in an accessible description.
 *
 * Rendering a chart enqueues the `charts` script module once per
 * request (hover tooltips, see resources/js/charts.ts); callers
 * need no extra wiring. The script is loaded through the Vite
 * entry `charts` (see ViteLoader::CHARTS_SCRIPT_NAME).
 */
final class SvgChart
{
    /**
     * Chart types.
     *
     * @var string[]
     */
    public const array TYPES = ['line', 'area', 'bar', 'sparkline'];

    /**
     * Script-module handle of the standalone `charts` Vite entry.
     */
    public const string SCRIPT_HANDLE = 'traydigita-theme-charts';

    /**
     * Whether the charts script module has been enqueued this request.
     */
    private static bool $scriptEnqueued = false;

    private function __construct()
    {
    }

    /**
     * Render a chart from a series of points.
     *
     * Points are `['t' => int|null, 'v' => float|null]` — Unix
     * timestamp in seconds (or null) and value (or null for a gap).
     * The x position is time-based when every point has a timestamp
     * and the last is later than the first, otherwise evenly spaced
     * by index. The y scale is the value range padded by 5% (a flat
     * series is padded by 10% of its value instead, so it still
     * draws as a visible line rather than collapsing).
     *
     * @param array<int, array{t: int|null, v: float|null}> $points Series points.
     * @param array<string, mixed> $options Rendering options:
     *     type (line|area|bar|sparkline), width, height, title, label
     *     (aria-label), description, source, currency, class.
     * @return string Complete <svg> markup, or '' when the series has
     *     fewer than two real values.
     */
    public static function render(array $points, array $options = []): string
    {
        $type = self::sanitizeType((string) ($options['type'] ?? 'line'));
        $width = isset($options['width']) && is_numeric($options['width']) && (int) $options['width'] > 0
            ? (int) $options['width']
            : 600;
        $height = isset($options['height']) && is_numeric($options['height']) && (int) $options['height'] > 0
            ? (int) $options['height']
            : ($type === 'sparkline' ? 36 : 220);
        if ($type === 'sparkline') {
            $width = isset($options['width']) && is_numeric($options['width']) && (int) $options['width'] > 0
                ? (int) $options['width']
                : 120;
            $height = 36;
        }
        $label = isset($options['label']) && is_string($options['label']) ? $options['label'] : '';
        $title = isset($options['title']) && is_string($options['title']) ? $options['title'] : $label;
        $description = isset($options['description']) && is_string($options['description'])
            ? $options['description']
            : '';
        $source = isset($options['source']) && is_string($options['source']) ? $options['source'] : '';
        $currency = isset($options['currency']) && is_string($options['currency']) ? $options['currency'] : '';
        $extraClass = isset($options['class']) && is_string($options['class']) ? $options['class'] : '';

        $points = self::normalizePoints($points);
        $values = [];
        foreach ($points as $point) {
            if ($point['v'] !== null) {
                $values[] = $point['v'];
            }
        }
        if (count($values) < 2) {
            return '';
        }
        [$min, $max] = self::scale($values);
        if ($description === '') {
            $description = self::describe($label, $values, $source);
        }
        $geometry = self::geometry($points, $min, $max, $width, $height, $type);
        $data = [
            'type' => $type,
            'points' => array_map(
                static fn(array $point): array => [$point['t'], $point['v']],
                $points
            ),
            'min' => $min,
            'max' => $max,
            'currency' => $currency,
        ];
        $json = wp_json_encode($data);
        $class = 'traydigita-chart traydigita-chart--' . $type;
        if ($extraClass !== '') {
            $class .= ' ' . $extraClass;
        }
        $svg = '<svg class="' . esc_attr($class) . '" role="img"'
            . ' viewBox="0 0 ' . $width . ' ' . $height . '"'
            . ' aria-label="' . esc_attr($label !== '' ? $label : $title) . '"'
            . ' data-traydigita-chart="' . esc_attr(is_string($json) ? $json : '') . '">';
        if ($title !== '') {
            $svg .= '<title>' . esc_html($title) . '</title>';
        }
        if ($description !== '') {
            $svg .= '<desc>' . esc_html($description) . '</desc>';
        }
        $svg .= $geometry . '</svg>';
        self::enqueueScript();
        return $svg;
    }

    /**
     * Render a 7-day sparkline for a market widget's first quote.
     *
     * Only kinds with a history source get one: crypto (CoinGecko
     * id) and stock (Yahoo symbol). Gold quotes carry brand labels,
     * not symbols with a history endpoint, so gold renders nothing.
     * History follows the Markets prime-on-miss pattern (fetch only
     * on a cache miss; a failed fetch means no sparkline, never a
     * fabricated one).
     *
     * @param string $kind Market kind: 'gold', 'stock' or 'crypto'.
     * @param array<int, array{symbol: string, label: string, price: float|null,
     *     change: float|null, change_percent: float|null, buyback: float|null,
     *     updated_at: int|null, source: string}> $quotes Widget quotes.
     * @param string $currency Currency code, e.g. 'IDR'.
     * @return string Sparkline <svg> markup, or ''.
     */
    public static function sparklineForMarket(string $kind, array $quotes, string $currency): string
    {
        $provider = match ($kind) {
            'crypto' => 'coingecko',
            'stock' => 'yahoo',
            default => '',
        };
        if ($provider === '' || $quotes === []) {
            return '';
        }
        $first = $quotes[0];
        $symbol = isset($first['symbol']) && is_string($first['symbol']) ? $first['symbol'] : '';
        if ($symbol === '') {
            return '';
        }
        $label = isset($first['label']) && is_string($first['label']) && $first['label'] !== ''
            ? $first['label']
            : $symbol;
        $points = ChartHistory::getHistory($provider, $symbol, 7, $currency);
        if ($points === []) {
            return '';
        }
        return self::render($points, [
            'type' => 'sparkline',
            'label' => sprintf(
                /* translators: %s is the market label, e.g. Bitcoin. */
                __('7-day trend for %s', 'traydigita-core'),
                $label
            ),
            'source' => ChartHistory::sourceLabel($provider),
            'currency' => $currency,
        ]);
    }

    /**
     * Enqueue the charts script module (hover tooltips) once.
     *
     * Safe to call during template rendering: script modules print
     * in the footer. Does nothing when the function is unavailable
     * (pre-6.5 WordPress).
     *
     * @return void
     */
    public static function enqueueScript(): void
    {
        if (self::$scriptEnqueued || !function_exists('wp_enqueue_script_module')) {
            return;
        }
        self::$scriptEnqueued = true;
        wp_enqueue_script_module(self::SCRIPT_HANDLE);
    }

    /**
     * Validate a chart type against the allowlist.
     *
     * @param string $type Raw type.
     * @return string Allowed type.
     */
    public static function sanitizeType(string $type): string
    {
        return in_array($type, self::TYPES, true) ? $type : 'line';
    }

    /**
     * Normalize raw points to the canonical shape.
     *
     * Rows without a numeric-or-null value are dropped; timestamps
     * that are not numeric become null (index spacing is used then).
     *
     * @param array<int, mixed> $points Raw points.
     * @return array<int, array{t: int|null, v: float|null}> Normalized points.
     */
    private static function normalizePoints(array $points): array
    {
        $clean = [];
        foreach ($points as $point) {
            if (!is_array($point) || !array_key_exists('v', $point)) {
                continue;
            }
            $clean[] = [
                't' => isset($point['t']) && is_numeric($point['t']) ? (int) $point['t'] : null,
                'v' => $point['v'] === null ? null : (is_numeric($point['v']) ? (float) $point['v'] : null),
            ];
        }
        return $clean;
    }

    /**
     * Value range with padding.
     *
     * @param float[] $values Real values.
     * @return array{0: float, 1: float} [min, max] with padding.
     */
    private static function scale(array $values): array
    {
        if ($values === []) {
            return [0.0, 1.0];
        }
        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $pad = $range > 0 ? $range * 0.05 : max(1.0, abs($min) * 0.1);
        return [$min - $pad, $max + $pad];
    }

    /**
     * X positions as fractions of the width (0..1), time-based when
     * possible, otherwise evenly spaced by index.
     *
     * @param array<int, array{t: int|null, v: float|null}> $points Normalized points.
     * @return float[] X fractions, one per point.
     */
    private static function xFractions(array $points): array
    {
        $count = count($points);
        $fractions = [];
        $timeBased = $count > 1;
        $first = null;
        $last = null;
        foreach ($points as $point) {
            if ($point['t'] === null) {
                $timeBased = false;
                break;
            }
            $first ??= $point['t'];
            $last = $point['t'];
        }
        if ($timeBased && $first !== null && $last !== null && $last > $first) {
            $span = $last - $first;
            foreach ($points as $point) {
                $fractions[] = ($point['t'] - $first) / $span;
            }
            return $fractions;
        }
        for ($i = 0; $i < $count; $i++) {
            $fractions[] = $count > 1 ? $i / ($count - 1) : 0.5;
        }
        return $fractions;
    }

    /**
     * Build the SVG geometry (path / rects) for a chart type.
     *
     * Line and area share the segmented polyline (segments break at
     * null values, so gaps are never bridged); area closes each
     * segment to the baseline. Bars draw one rect per real value.
     *
     * @param array<int, array{t: int|null, v: float|null}> $points Normalized points.
     * @param float $min Padded minimum.
     * @param float $max Padded maximum.
     * @param int $width ViewBox width.
     * @param int $height ViewBox height.
     * @param string $type Chart type.
     * @return string SVG inner markup.
     */
    private static function geometry(
        array $points,
        float $min,
        float $max,
        int $width,
        int $height,
        string $type
    ): string {
        $pad = $type === 'sparkline' ? 3 : 8;
        $innerHeight = $height - 2 * $pad;
        $fractions = self::xFractions($points);
        $yFor = static function (?float $value) use ($min, $max, $pad, $innerHeight): float {
            if ($value === null) {
                return 0.0;
            }
            return $pad + (1 - ($value - $min) / ($max - $min)) * $innerHeight;
        };
        if ($type === 'bar') {
            return self::barGeometry($points, $fractions, $yFor, $width, $height, $pad);
        }
        $segments = self::lineSegments($points, $fractions, $yFor, $width);
        if ($segments === []) {
            return '';
        }
        $markup = '';
        $baseline = $height - $pad;
        foreach ($segments as $segment) {
            $line = '';
            foreach ($segment as $index => $point) {
                $line .= ($index === 0 ? 'M' : ' L') . self::num($point[0]) . ',' . self::num($point[1]);
            }
            $markup .= '<path class="traydigita-chart-line" d="' . esc_attr($line) . '" fill="none" />';
            if ($type === 'area') {
                $firstX = self::num($segment[0][0]);
                $lastX = self::num($segment[count($segment) - 1][0]);
                $area = 'M' . $firstX . ',' . $baseline . ' L' . substr($line, 1)
                    . ' L' . $lastX . ',' . $baseline . ' Z';
                $markup .= '<path class="traydigita-chart-area" d="' . esc_attr($area) . '" />';
            }
        }
        return $markup;
    }

    /**
     * Segmented polylines as [x, y] pairs, one segment per run of
     * real values. A null value ends the current segment, so gaps
     * are never bridged.
     *
     * @param array<int, array{t: int|null, v: float|null}> $points Normalized points.
     * @param float[] $fractions X fractions per point.
     * @param callable $yFor Value-to-y mapper.
     * @param int $width ViewBox width.
     * @return array<int, array<int, array{0: float, 1: float}>> Segments of [x, y] pairs.
     */
    private static function lineSegments(array $points, array $fractions, callable $yFor, int $width): array
    {
        $segments = [];
        $current = [];
        foreach ($points as $index => $point) {
            if ($point['v'] === null) {
                if ($current !== []) {
                    $segments[] = $current;
                    $current = [];
                }
                continue;
            }
            $current[] = [$fractions[$index] * $width, $yFor($point['v'])];
        }
        if ($current !== []) {
            $segments[] = $current;
        }
        return $segments;
    }

    /**
     * Bar geometry: one rect per real value.
     *
     * @param array<int, array{t: int|null, v: float|null}> $points Normalized points.
     * @param float[] $fractions X fractions per point.
     * @param callable $yFor Value-to-y mapper.
     * @param int $width ViewBox width.
     * @param int $height ViewBox height.
     * @param int $pad Vertical padding.
     * @return string SVG inner markup.
     */
    private static function barGeometry(
        array $points,
        array $fractions,
        callable $yFor,
        int $width,
        int $height,
        int $pad
    ): string {
        $count = count($points);
        if ($count === 0) {
            return '';
        }
        $slot = $width / $count;
        $barWidth = $slot * 0.7;
        $baseline = $height - $pad;
        $markup = '';
        foreach ($points as $index => $point) {
            if ($point['v'] === null) {
                continue;
            }
            $x = $fractions[$index] * $width - $barWidth / 2;
            if ($x < 0) {
                $x = 0;
            }
            $y = $yFor($point['v']);
            $barHeight = $baseline - $y;
            if ($barHeight < 0) {
                $barHeight = 0;
            }
            $markup .= '<rect class="traydigita-chart-bar" x="' . self::num($x)
                . '" y="' . self::num($y)
                . '" width="' . self::num($barWidth)
                . '" height="' . self::num($barHeight) . '" />';
        }
        return $markup;
    }

    /**
     * Accessible description for a series.
     *
     * @param string $label Chart label.
     * @param float[] $values Real values.
     * @param string $source Source label.
     * @return string Description text.
     */
    private static function describe(string $label, array $values, string $source): string
    {
        $first = $values[0];
        $last = $values[count($values) - 1];
        $text = $label !== '' ? $label : __('Price chart', 'traydigita-core');
        $text .= sprintf(
            /* translators: 1: first value. 2: last value. 3: number of points. */
            __(': from %1$s to %2$s across %3$d points.', 'traydigita-core'),
            number_format_i18n($first, 2),
            number_format_i18n($last, 2),
            count($values)
        );
        if ($source !== '') {
            $text .= ' ' . sprintf(
                /* translators: %s is the data source label. */
                __('Source: %s.', 'traydigita-core'),
                $source
            );
        }
        return $text;
    }

    /**
     * Format a coordinate with at most 2 decimals.
     *
     * @param float $value Coordinate.
     * @return string Formatted number.
     */
    private static function num(float $value): string
    {
        return sprintf('%.2F', $value);
    }
}
