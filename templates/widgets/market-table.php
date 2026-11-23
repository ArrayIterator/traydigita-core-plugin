<?php
/**
 * Shared accessible market table (stock and crypto widgets).
 *
 * Variables (extracted by WidgetBase::renderPartial):
 * @var array<int, array{symbol: string, label: string, price: float|null,
 *     change: float|null, change_percent: float|null, buyback: float|null,
 *     updated_at: int|null, source: string}> $quotes
 * @var string $caption Table caption.
 * @var string $currency Currency code (e.g. "IDR").
 *
 * Trend is shown by text and direction class, never by colour alone.
 */
declare(strict_types=1);

?>
<?php if (!empty($quotes)) : ?>
    <table class="market-table">
        <caption><?php echo esc_html($caption); ?></caption>
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Symbol', 'traydigita-core'); ?></th>
                <th scope="col"><?php esc_html_e('Price', 'traydigita-core'); ?></th>
                <th scope="col"><?php esc_html_e('Change', 'traydigita-core'); ?></th>
                <th scope="col"><?php esc_html_e('Change %', 'traydigita-core'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($quotes as $quote) : ?>
                <?php
                $direction = ($quote['change_percent'] ?? $quote['change']) === null
                    || (float) ($quote['change_percent'] ?? $quote['change']) === 0.0
                    ? 'flat'
                    : ((float) ($quote['change_percent'] ?? $quote['change']) > 0 ? 'up' : 'down');
                ?>
                <tr class="market-row market-row-<?php echo esc_attr($direction); ?>">
                    <th scope="row"><?php echo esc_html($quote['label']); ?></th>
                    <td class="market-price">
                        <?php if ($quote['price'] !== null) : ?>
                            <?php echo esc_html(\TrayDigita\WP\Plugin\Core\Widgets\MarketWidgetBase::formatMoney((float) $quote['price'], (string) ($currency ?? 'IDR'))); ?>
                        <?php else : ?>
                            <?php esc_html_e('Unavailable', 'traydigita-core'); ?>
                        <?php endif; ?>
                    </td>
                    <td class="market-change market-change-<?php echo esc_attr($direction); ?>">
                        <?php if ($quote['change'] !== null) : ?>
                            <?php
                            echo esc_html(
                                ($direction === 'up' ? '+' : '')
                                . \TrayDigita\WP\Plugin\Core\Widgets\MarketWidgetBase::formatMoney((float) $quote['change'], (string) ($currency ?? 'IDR'))
                                . ($direction === 'up' ? ' ' . __('Up', 'traydigita-core')
                                    : ($direction === 'down' ? ' ' . __('Down', 'traydigita-core')
                                        : ' ' . __('Unchanged', 'traydigita-core')))
                            );
                            ?>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td class="market-change-percent market-change-<?php echo esc_attr($direction); ?>">
                        <?php if ($quote['change_percent'] !== null) : ?>
                            <?php
                            echo esc_html(
                                ($direction === 'up' ? '+' : '')
                                . number_format_i18n((float) $quote['change_percent'], 2) . '%'
                            );
                            ?>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
