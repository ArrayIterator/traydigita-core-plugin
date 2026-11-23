<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Widgets\Market;

/**
 * Contract for market data providers (gold, stocks, crypto).
 *
 * A provider turns a list of symbols into quote rows. The widget layer
 * only ever renders quotes that came from a transient cache; providers
 * are called from admin save, WP-Cron refresh, or the admin preview
 * endpoint, never from a frontend render path.
 *
 * A provider must never fabricate a price: when it has no data for a
 * symbol it returns no quote for that symbol, and the widget shows an
 * honest empty state instead.
 */
interface MarketDataProvider
{
    /**
     * Fetch quotes for the requested symbols.
     *
     * @param string[] $symbols Provider-specific symbols (brand labels
     *                          for gold, tickers for stocks, CoinGecko
     *                          ids for crypto). An empty list means
     *                          "return every quote this provider knows".
     * @return MarketQuote[]
     */
    public function getQuotes(array $symbols): array;

    /**
     * Short human-readable source label, e.g. "Manual snapshot" or
     * "CoinGecko". Shown next to the timestamp in the widget.
     *
     * @return string
     */
    public function getSourceLabel(): string;
}
