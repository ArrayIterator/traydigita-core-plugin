<?php
/**
 * Chart widget partial.
 *
 * Variables from ChartWidget::getWidgetData():
 * - string $provider ('coingecko' or 'yahoo')
 * - string $symbol CoinGecko id or Yahoo ticker
 * - int $days Period in days
 * - string $type Chart type (line, area, bar)
 * - array $points Series points ['t' => int|null, 'v' => float|null]
 * - string $source Source label (CoinGecko / Yahoo Finance)
 * - string $currency Currency code
 * - int|null $updated_at Last point timestamp, or null
 * - array $instance Sanitized widget instance (has 'title')
 *
 * @var string $provider
 * @var string $symbol
 * @var int $days
 * @var string $type
 * @var array<int, array{t: int|null, v: float|null}> $points
 * @var string $source
 * @var string $currency
 * @var int|null $updated_at
 * @var array<string, mixed> $instance
 */
declare(strict_types=1);

$label = isset($instance['title']) && is_string($instance['title']) && $instance['title'] !== ''
    ? $instance['title']
    : $symbol;
$chart = \TrayDigita\WP\Plugin\Core\Libs\SvgChart::render($points, [
    'type' => $type,
    'label' => sprintf(
        /* translators: 1: chart label. 2: period in days. */
        __('Price chart: %1$s (%2$d days)', 'traydigita-core'),
        $label,
        $days
    ),
    'source' => $source,
    'currency' => $currency,
]);
if ($chart === '') :
    ?>
    <p class="traydigita-chart-empty">
        <?php esc_html_e('Chart data is currently unavailable.', 'traydigita-core'); ?>
    </p>
    <?php
else :
    echo $chart;
    $meta = sprintf(
        /* translators: 1: source label. 2: period, e.g. "7 days". */
        __('Source: %1$s · %2$s', 'traydigita-core'),
        $source,
        sprintf(
            /* translators: %d is the period in days. */
            _n('%d day', '%d days', $days, 'traydigita-core'),
            $days
        )
    );
    if ($updated_at !== null) :
        $meta .= ' · ' . sprintf(
            /* translators: %s is the last-update date and time. */
            __('Updated %s', 'traydigita-core'),
            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $updated_at)
        );
    endif;
    ?>
    <p class="traydigita-chart-source"><?php echo esc_html($meta); ?></p>
<?php endif; ?>
