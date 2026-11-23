<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use TrayDigita\WP\Plugin\Core\Widgets\Market\YahooFinanceProvider;
use function __;
use function is_array;
use function is_numeric;
use function is_wp_error;
use function json_decode;
use function wp_parse_url;
use function wp_remote_get;
use function wp_remote_retrieve_body;

/**
 * GoldAPI.io gold provider.
 *
 * Uses `/api/XAU/USD` with the `X-API-KEY` header. A key is
 * REQUIRED (there is no keyless tier). The response carries the
 * price per gram in USD (`price_gram_24k`), which is converted to
 * IDR with the Yahoo `USDIDR=X` rate — whether GoldAPI serves
 * `XAU/IDR` directly could not be verified from the public docs,
 * so USD plus the Yahoo rate is used and stated here instead of
 * guessed. The change (`ch`, USD per ounce) is converted the same
 * way; the change percent (`chp`) is used as reported.
 *
 * The live path could not be verified from this sandbox (no API
 * key exists). Without a key this provider returns no prices at
 * all (and the module shows a "key required" notice) — never a
 * fabricated price.
 *
 * @see https://www.goldapi.io/documentation
 */
final class GoldApiPriceProvider implements GoldPriceProvider, ApiKeyRequired
{
    /**
     * API endpoint host.
     */
    public const string HOST = 'www.goldapi.io';

    /**
     * Grams in one troy ounce.
     */
    public const float TROY_OUNCE_GRAMS = 31.1035;

    /**
     * Request timeout in seconds.
     */
    protected const int TIMEOUT = 8;

    /**
     * @param string $apiKey GoldAPI.io API key (mandatory, no keyless tier).
     */
    public function __construct(private readonly string $apiKey = '')
    {
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'goldapi';
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return __('GoldAPI.io', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('An API key is required (no keyless tier). Gold spot per gram in IDR, converted from the USD price with the Yahoo USD/IDR rate. Without a key this provider returns no prices.', 'traydigita-core');
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
                'id' => 'goldapi_key',
                'type' => 'password',
                'label' => __('GoldAPI.io API key', 'traydigita-core'),
                'description' => __('Required — GoldAPI.io has no keyless tier. Get one at goldapi.io. Only used when GoldAPI.io is the selected gold provider.', 'traydigita-core'),
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function fetchSpot(): ?array
    {
        if ($this->apiKey === '') {
            return null;
        }
        $url = 'https://' . self::HOST . '/api/XAU/USD';
        if (wp_parse_url($url, PHP_URL_HOST) !== self::HOST) {
            return null;
        }
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Accept' => 'application/json',
                'X-API-KEY' => $this->apiKey,
            ],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }
        $priceGramUsd = isset($data['price_gram_24k']) && is_numeric($data['price_gram_24k'])
            ? (float) $data['price_gram_24k']
            : null;
        if ($priceGramUsd === null) {
            return null;
        }
        $yahoo = new YahooFinanceProvider();
        $fx = $yahoo->fetchChartMeta(YahooGoldPriceProvider::FX_SYMBOL);
        $rate = is_array($fx) && isset($fx['regularMarketPrice']) && is_numeric($fx['regularMarketPrice'])
            ? (float) $fx['regularMarketPrice']
            : null;
        if ($rate === null || $rate <= 0) {
            return null;
        }
        $changeUsdOz = isset($data['ch']) && is_numeric($data['ch']) ? (float) $data['ch'] : null;
        return [
            'price_per_gram_idr' => $priceGramUsd * $rate,
            'change' => $changeUsdOz !== null ? $changeUsdOz * $rate / self::TROY_OUNCE_GRAMS : null,
            'change_percent' => isset($data['chp']) && is_numeric($data['chp']) ? (float) $data['chp'] : null,
            'updated_at' => isset($data['timestamp']) && is_numeric($data['timestamp'])
                ? (int) $data['timestamp']
                : null,
        ];
    }
}
