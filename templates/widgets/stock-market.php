<?php
/**
 * Stock market widget (IDX).
 *
 * Variables (extracted by WidgetBase::renderPartial):
 * @var array<int, array{symbol: string, label: string, price: float|null,
 *     change: float|null, change_percent: float|null, buyback: float|null,
 *     updated_at: int|null, source: string}> $quotes
 * @var string $source Source label.
 * @var int|null $updated_at Newest snapshot timestamp.
 * @var string $currency Currency code (e.g. "IDR").
 */
declare(strict_types=1);

?>
<div class="widget-market widget-stock-market" data-market-kind="stock">
    <?php if (!empty($quotes)) : ?>
        <?php
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $caption = __('Indonesian stock prices', 'traydigita-core');
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        require __DIR__ . '/market-table.php';
        ?>
        <?php
        // 7-day sparkline for the first quote (Yahoo history,
        // prime-on-miss). Renders nothing when history is unavailable.
        $sparkline = \TrayDigita\WP\Plugin\Core\Libs\SvgChart::sparklineForMarket('stock', $quotes, $currency);
        ?>
        <?php if ($sparkline !== '') : ?>
            <div class="market-sparkline"><?php echo $sparkline; ?></div>
        <?php endif; ?>
        <p class="market-source">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: 1: source label. 2: update time. */
                    __('Source: %1$s · Updated %2$s', 'traydigita-core'),
                    $source,
                    $updated_at !== null
                        ? \TrayDigita\WP\Plugin\Core\Widgets\WeatherHelper::formatUpdatedAt((int) $updated_at)
                        : __('never', 'traydigita-core')
                )
            );
            ?>
        </p>
    <?php else : ?>
        <p class="widget-empty">
            <?php esc_html_e('Stock prices have not been configured yet.', 'traydigita-core'); ?>
        </p>
    <?php endif; ?>
</div>
