<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use function __;
use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function rawurlencode;
use function strtoupper;
use function trim;

/**
 * CoinMarketCap crypto provider.
 *
 * Uses the quotes endpoint with the `X-CMC_PRO_API_KEY` header.
 * A key is REQUIRED even on the free Basic plan (10,000 credits a
 * month) - the free and paid tiers share the same endpoint and
 * differ only in credit limits, so one key field covers both.
 * Without a key this provider returns no prices at all (and the
 * module shows a "key required" notice) - never a fabricated price.
 *
 * The live path could not be verified from this sandbox (no API key
 * exists); the endpoint contract below is the documented public
 * one (`/v1/cryptocurrency/quotes/latest` with `symbol` and
 * `convert` parameters). On a host with a key it works; without a
 * key the widget shows an honest empty state.
 *
 * Coin ids are CoinGecko ids; the built-in map below translates the
 * common ones to CoinMarketCap symbols. Unmapped ids are skipped,
 * never guessed.
 *
 * @see https://coinmarketcap.com/api/documentation/v1/#operation/getV1CryptocurrencyQuotesLatest
 */
final class CoinMarketCapCryptoProvider extends AbstractCryptoPriceProvider implements ApiKeyRequired
{
    /**
     * API endpoint host.
     */
    public const string HOST = 'pro-api.coinmarketcap.com';

    /**
     * CoinGecko id => CoinMarketCap symbol for the common coins.
     *
     * @var array<string, string>
     */
    public const array SYMBOL_MAP = [
        'bitcoin' => 'BTC',
        'ethereum' => 'ETH',
        'solana' => 'SOL',
        'tether' => 'USDT',
        'binancecoin' => 'BNB',
        'ripple' => 'XRP',
        'cardano' => 'ADA',
        'dogecoin' => 'DOGE',
        'tron' => 'TRX',
        'chainlink' => 'LINK',
        'avalanche-2' => 'AVAX',
        'polkadot' => 'DOT',
        'litecoin' => 'LTC',
        'bitcoin-cash' => 'BCH',
        'uniswap' => 'UNI',
        'cosmos' => 'ATOM',
        'stellar' => 'XLM',
        'ethereum-classic' => 'ETC',
        'filecoin' => 'FIL',
        'aptos' => 'APT',
        'arbitrum' => 'ARB',
    ];

    /**
     * @param string $apiKey CoinMarketCap API key (mandatory, even for the free plan).
     */
    public function __construct(private readonly string $apiKey = '')
    {
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'coinmarketcap';
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return __('CoinMarketCap', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('An API key is required, even for the free Basic plan (free and paid tiers share the same endpoint). Without a key this provider returns no prices.', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), ['USD', 'IDR'], true);
    }

    /**
     * @inheritDoc
     */
    public function requiresKey(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getSettings(): array
    {
        return [
            [
                'id' => 'coinmarketcap_key',
                'type' => 'password',
                'label' => __('CoinMarketCap API key', 'traydigita-core'),
                'description' => __('Required, even for the free Basic plan. Get one at coinmarketcap.com/api. Only used when CoinMarketCap is the selected crypto provider.', 'traydigita-core'),
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function fetchPrices(array $coinIds, string $currency): array
    {
        if ($this->apiKey === '') {
            return [];
        }
        $ids = array_values(array_filter(array_map(
            static fn(string $id): string => trim($id),
            $coinIds
        )));
        if ($ids === []) {
            return [];
        }
        $symbols = [];
        foreach ($ids as $id) {
            $symbol = self::SYMBOL_MAP[$id] ?? null;
            if ($symbol !== null) {
                $symbols[$id] = $symbol;
            }
        }
        if ($symbols === []) {
            return [];
        }
        $currency = strtoupper(trim($currency));
        if (!$this->supportsCurrency($currency)) {
            $currency = 'USD';
        }
        $url = 'https://' . self::HOST . '/v1/cryptocurrency/quotes/latest?symbol='
            . rawurlencode(implode(',', array_values($symbols)))
            . '&convert=' . rawurlencode($currency);
        $data = $this->fetchJson($url, self::HOST, ['X-CMC_PRO_API_KEY' => $this->apiKey]);
        $rows = is_array($data) ? ($data['data'] ?? null) : null;
        if (!is_array($rows)) {
            return [];
        }
        $bySymbol = [];
        foreach ($symbols as $id => $symbol) {
            $bySymbol[$symbol] = $id;
        }
        $prices = [];
        foreach ($rows as $symbol => $row) {
            $id = is_string($symbol) ? ($bySymbol[$symbol] ?? null) : null;
            if ($id === null || !is_array($row)) {
                continue;
            }
            $quote = $row['quote'][$currency] ?? null;
            if (!is_array($quote)) {
                continue;
            }
            $price = $this->numericValue($quote['price'] ?? null);
            if ($price === null) {
                continue;
            }
            $prices[$id] = [
                'price' => $price,
                'change_24h' => $this->numericValue($quote['percent_change_24h'] ?? null),
            ];
        }
        return $prices;
    }
}
