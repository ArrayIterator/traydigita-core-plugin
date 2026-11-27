<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use TrayDigita\WP\Plugin\Core\Market\YahooFinanceProvider;
use function __;
use function is_numeric;

/**
 * Yahoo Finance gold provider.
 *
 * Gold spot on Yahoo is quoted through COMEX gold futures (`GC=F`,
 * USD per troy ounce) - the `XAUUSD=X` forex symbol is delisted
 * (verified live 2026-11-21: "No data found, symbol may be
 * delisted"). The price is converted to IDR per gram with the
 * `USDIDR=X` rate: price x rate / 31.1035. The change is derived
 * from the futures previous close the same way; the change percent
 * is the futures session move. Both conversions are plain
 * arithmetic on observed values, never guesses - and the provider
 * says "futures" in its label and description so nobody mistakes
 * it for a retail brand price.
 *
 * This endpoint is unofficial and can break or start rate-limiting
 * without notice, like the Yahoo stock provider. Any failure
 * returns null and the widget falls back to the manual snapshot.
 *
 * @see https://finance.yahoo.com/quote/GC=F/
 */
final class YahooGoldPriceProvider implements GoldPriceProvider
{
    /**
     * COMEX gold futures symbol (USD per troy ounce).
     */
    public const string GOLD_SYMBOL = 'GC=F';

    /**
     * USD/IDR forex symbol.
     */
    public const string FX_SYMBOL = 'USDIDR=X';

    /**
     * Grams in one troy ounce.
     */
    public const float TROY_OUNCE_GRAMS = 31.1035;

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'yahoo';
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return __('Yahoo Finance (gold futures)', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('COMEX gold futures priced in IDR per gram (converted from USD per troy ounce). Unofficial endpoint; the XAUUSD symbol is delisted, so futures are used and labelled as such. Per-brand prices stay manual snapshots.', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function requiresKey(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getSettings(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function fetchSpot(): ?array
    {
        $yahoo = new YahooFinanceProvider();
        $gold = $yahoo->fetchChartMeta(self::GOLD_SYMBOL);
        $fx = $yahoo->fetchChartMeta(self::FX_SYMBOL);
        if ($gold === null || $fx === null) {
            return null;
        }
        $priceUsd = isset($gold['regularMarketPrice']) && is_numeric($gold['regularMarketPrice'])
            ? (float) $gold['regularMarketPrice']
            : null;
        $rate = isset($fx['regularMarketPrice']) && is_numeric($fx['regularMarketPrice'])
            ? (float) $fx['regularMarketPrice']
            : null;
        if ($priceUsd === null || $rate === null || $rate <= 0) {
            return null;
        }
        $previousClose = isset($gold['previousClose']) && is_numeric($gold['previousClose'])
            ? (float) $gold['previousClose']
            : null;
        $change = $previousClose !== null
            ? ($priceUsd - $previousClose) * $rate / self::TROY_OUNCE_GRAMS
            : null;
        $changePercent = isset($gold['regularMarketChangePercent'])
            && is_numeric($gold['regularMarketChangePercent'])
            ? (float) $gold['regularMarketChangePercent']
            : null;
        return [
            'price_per_gram_idr' => $priceUsd * $rate / self::TROY_OUNCE_GRAMS,
            'change' => $change,
            'change_percent' => $changePercent,
            'updated_at' => isset($gold['regularMarketTime']) && is_numeric($gold['regularMarketTime'])
                ? (int) $gold['regularMarketTime']
                : null,
        ];
    }
}
