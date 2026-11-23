<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

/**
 * Contract for crypto price providers (CoinGecko, CoinPaprika, Binance…).
 *
 * A provider turns a list of CoinGecko ids into prices. Providers are
 * resolved through the Markets module registry
 * (MarketsModule::cryptoProviders(), filter
 * `traydigita:core:markets/crypto-providers`) so child themes and
 * plugins can add their own. A provider must never fabricate a price:
 * on any failure it returns an empty array (or omits the failed coin)
 * and the widget falls back to the manual snapshot or an honest empty
 * state.
 *
 * Coin ids are always CoinGecko ids (bitcoin, ethereum, solana);
 * providers whose native ids differ (CoinPaprika, Binance) map them
 * internally and silently skip ids they cannot map — never guess.
 */
interface CryptoPriceProvider
{
    /**
     * Stable machine id, e.g. 'coingecko'.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Human-readable provider name, e.g. 'CoinGecko'.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Short description shown on the module settings page, including
     * any honesty note (keyless tier, USDT-only, unofficial…).
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Whether this provider can quote in the given currency
     * ('USD', 'IDR', 'USDT' — case-insensitive).
     *
     * @param string $currency Currency code.
     * @return bool
     */
    public function supportsCurrency(string $currency): bool;

    /**
     * Whether this provider offers a Pro tier that needs an API key.
     *
     * True means the module settings form shows an API key field for
     * this provider. The key is optional unless the provider also
     * implements ApiKeyRequired (CoinMarketCap): then the key is
     * mandatory and the provider returns no prices without one.
     *
     * @return bool
     */
    public function requiresKey(): bool;

    /**
     * Extra settings fields this provider needs, in the module's
     * field-definition shape:
     *   [['id' => string, 'type' => 'text'|'password'|'select'|
     *     'textarea'|'checkbox', 'label' => string,
     *     'description' => string, 'options' => array<string,string>]]
     * An empty array means the provider needs no extra settings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSettings(): array;

    /**
     * Fetch prices for the requested CoinGecko ids.
     *
     * @param string[] $coinIds CoinGecko ids, e.g. ['bitcoin', 'ethereum'].
     * @param string $currency Display currency code ('USD', 'IDR', 'USDT').
     * @return array<string, array{price: float, change_24h: float|null}> Coin id => price row.
     *     Coins the provider cannot price are omitted, never zero-filled.
     */
    public function fetchPrices(array $coinIds, string $currency): array;
}
