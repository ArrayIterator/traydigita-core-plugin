<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use function __;
use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function strtoupper;
use function trim;

/**
 * Indodax crypto provider.
 *
 * Uses the public tickers endpoint (no API key, one request returns
 * every pair). Prices are IDR pairs natively - Indodax is an
 * Indonesian exchange, so this provider only supports IDR. (A few
 * pairs also trade against USDT, e.g. btc_usdt, but only for BTC
 * and ETH, so USDT is not offered as a display currency.) The
 * tickers endpoint carries no 24h change, so change_24h is always
 * null - an honest omission, never a computed guess.
 *
 * Coin ids are CoinGecko ids; the built-in map below translates the
 * common ones to Indodax pairs (btc_idr, eth_idr...). Aptos is not
 * listed on Indodax (verified live 2026-11-21: no apt_idr pair),
 * so it is absent from the map. Unmapped ids are skipped, never
 * guessed.
 *
 * @see https://indodax.com/api/tickers
 */
final class IndodaxCryptoProvider extends AbstractCryptoPriceProvider
{
    /**
     * Public endpoint host.
     */
    public const string HOST = 'indodax.com';

    /**
     * CoinGecko id => Indodax pair for the coins listed on Indodax.
     *
     * @var array<string, string>
     */
    public const array PAIR_MAP = [
        'bitcoin' => 'btc_idr',
        'ethereum' => 'eth_idr',
        'solana' => 'sol_idr',
        'tether' => 'usdt_idr',
        'binancecoin' => 'bnb_idr',
        'ripple' => 'xrp_idr',
        'cardano' => 'ada_idr',
        'dogecoin' => 'doge_idr',
        'tron' => 'trx_idr',
        'chainlink' => 'link_idr',
        'avalanche-2' => 'avax_idr',
        'polkadot' => 'dot_idr',
        'litecoin' => 'ltc_idr',
        'bitcoin-cash' => 'bch_idr',
        'uniswap' => 'uni_idr',
        'cosmos' => 'atom_idr',
        'stellar' => 'xlm_idr',
        'ethereum-classic' => 'etc_idr',
        'filecoin' => 'fil_idr',
        'arbitrum' => 'arb_idr',
    ];

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'indodax';
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return __('Indodax', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('Free public endpoint, no API key needed. Prices are IDR pairs from the Indonesian exchange — this provider only supports IDR.', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), ['IDR'], true);
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
    public function fetchPrices(array $coinIds, string $currency): array
    {
        $ids = array_values(array_filter(array_map(
            static fn(string $id): string => trim($id),
            $coinIds
        )));
        if ($ids === []) {
            return [];
        }
        $pairs = [];
        foreach ($ids as $id) {
            $pair = self::PAIR_MAP[$id] ?? null;
            if ($pair !== null) {
                $pairs[$id] = $pair;
            }
        }
        if ($pairs === []) {
            return [];
        }
        $data = $this->fetchJson('https://' . self::HOST . '/api/tickers', self::HOST);
        $tickers = is_array($data) ? ($data['tickers'] ?? null) : null;
        if (!is_array($tickers)) {
            return [];
        }
        $prices = [];
        foreach ($pairs as $id => $pair) {
            $row = $tickers[$pair] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $price = $this->numericValue($row['last'] ?? null);
            if ($price === null) {
                continue;
            }
            $prices[$id] = [
                'price' => $price,
                'change_24h' => null,
            ];
        }
        return $prices;
    }
}
