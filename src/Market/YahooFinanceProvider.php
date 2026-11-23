<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Market;

use function is_array;
use function is_numeric;
use function is_wp_error;
use function json_decode;
use function rawurlencode;
use function str_contains;
use function time;
use function trim;
use function wp_parse_url;
use function wp_remote_get;
use function wp_remote_retrieve_body;

/**
 * Yahoo Finance stock provider (optional, unofficial).
 *
 * Fetches one quote per symbol from the public chart endpoint
 * (`query1.finance.yahoo.com/v8/finance/chart/{symbol}`), one
 * request per symbol, with an 8 second timeout and a browser
 * user agent. IDX symbols get the `.JK` suffix when they have
 * none (BBCA → BBCA.JK). Prices come back in the listing currency
 * (IDR for IDX).
 *
 * This endpoint is unofficial and can break or start rate-limiting
 * without notice — verified live from this VM on 2026-11-21
 * (BBCA.JK and TLKM.JK both returned prices), but the manual
 * snapshot provider stays the default and any failure here falls
 * back to it. A symbol that cannot be priced is skipped, never
 * zero-filled.
 *
 * Only ever called from admin save, WP-Cron refresh, or the admin
 * preview — never from a frontend render path.
 */
final class YahooFinanceProvider implements MarketDataProvider
{
    /**
     * Only this host may be fetched.
     */
    public const string ALLOWED_HOST = 'query1.finance.yahoo.com';

    /**
     * Request timeout in seconds.
     */
    public const int TIMEOUT = 8;

    /**
     * @inheritDoc
     */
    public function getQuotes(array $symbols): array
    {
        $quotes = [];
        foreach ($symbols as $symbol) {
            $symbol = trim((string) $symbol);
            if ($symbol === '') {
                continue;
            }
            $quote = $this->fetchSymbol($symbol);
            if ($quote !== null) {
                $quotes[] = $quote;
            }
        }
        return $quotes;
    }

    /**
     * Fetch one symbol's quote, or null when it cannot be priced.
     *
     * @param string $symbol Ticker as configured (e.g. 'BBCA').
     * @return MarketQuote|null
     */
    private function fetchSymbol(string $symbol): ?MarketQuote
    {
        $yhSymbol = str_contains($symbol, '.') ? $symbol : $symbol . '.JK';
        $meta = $this->fetchChartMeta($yhSymbol);
        if ($meta === null) {
            return null;
        }
        $price = isset($meta['regularMarketPrice']) && is_numeric($meta['regularMarketPrice'])
            ? (float) $meta['regularMarketPrice']
            : null;
        if ($price === null) {
            return null;
        }
        return new MarketQuote(
            $symbol,
            $symbol,
            $price,
            isset($meta['regularMarketChange']) && is_numeric($meta['regularMarketChange'])
                ? (float) $meta['regularMarketChange']
                : null,
            isset($meta['regularMarketChangePercent']) && is_numeric($meta['regularMarketChangePercent'])
                ? (float) $meta['regularMarketChangePercent']
                : null,
            null,
            isset($meta['regularMarketTime']) && is_numeric($meta['regularMarketTime'])
                ? (int) $meta['regularMarketTime']
                : time(),
            $this->getSourceLabel()
        );
    }

    /**
     * Fetch the raw chart meta for one Yahoo symbol, verbatim.
     *
     * Unlike fetchSymbol() this applies no `.JK` suffix and no
     * currency assumption: the caller passes the exact Yahoo symbol
     * (`GC=F`, `USDIDR=X`…) and gets the meta array back. Used by
     * the gold providers, which combine two symbols (gold in USD
     * per troy ounce plus the USD/IDR rate) into an IDR per-gram
     * price. Returns null on any failure.
     *
     * @param string $symbol Exact Yahoo symbol.
     * @return array<string, mixed>|null Chart meta, or null when unavailable.
     */
    public function fetchChartMeta(string $symbol): ?array
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return null;
        }
        $url = 'https://' . self::ALLOWED_HOST . '/v8/finance/chart/' . rawurlencode($symbol);
        if (wp_parse_url($url, PHP_URL_HOST) !== self::ALLOWED_HOST) {
            return null;
        }
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (compatible; TrayDigita/1.0)',
            ],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $meta = is_array($data) ? ($data['chart']['result'][0]['meta'] ?? null) : null;
        return is_array($meta) ? $meta : null;
    }

    /**
     * @inheritDoc
     */
    public function getSourceLabel(): string
    {
        return 'Yahoo Finance';
    }
}
