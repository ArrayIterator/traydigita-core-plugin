<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use TrayDigita\WP\Plugin\Core\Market\MarketDataProvider;
use TrayDigita\WP\Plugin\Core\Market\MarketQuote;
use function __;
use function is_array;
use function is_numeric;
use function time;

/**
 * Adapts a GoldPriceProvider to the widget layer's
 * MarketDataProvider contract.
 *
 * Gold has a single spot price, so the adapter returns exactly one
 * quote — labelled "Gold spot", priced per gram in IDR — whatever
 * symbols the widget instance tracks. Per-brand rows are never
 * synthesized from the spot price: the widget shows the spot row
 * alone when a live gold provider is selected, and the manual
 * snapshots take over when the provider fails (via the widget
 * layer's fallback). A provider failure yields an empty list,
 * never a fabricated price.
 */
final class GoldProviderAdapter implements MarketDataProvider
{
    /**
     * @param GoldPriceProvider $provider Selected gold provider.
     */
    public function __construct(
        private readonly GoldPriceProvider $provider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getQuotes(array $symbols): array
    {
        try {
            $spot = $this->provider->fetchSpot();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($spot) || !isset($spot['price_per_gram_idr'])) {
            return [];
        }
        $price = $spot['price_per_gram_idr'];
        if (!is_numeric($price)) {
            return [];
        }
        return [
            new MarketQuote(
                'spot',
                __('Gold spot', 'traydigita-core'),
                (float) $price,
                isset($spot['change']) && is_numeric($spot['change']) ? (float) $spot['change'] : null,
                isset($spot['change_percent']) && is_numeric($spot['change_percent'])
                    ? (float) $spot['change_percent']
                    : null,
                null,
                isset($spot['updated_at']) && is_numeric($spot['updated_at'])
                    ? (int) $spot['updated_at']
                    : time(),
                $this->provider->getLabel()
            ),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getSourceLabel(): string
    {
        return $this->provider->getLabel();
    }
}
