<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

/**
 * Marker for price providers whose API key is mandatory, not optional.
 *
 * A provider implementing this interface cannot return any price
 * without a key (e.g. CoinMarketCap, which requires a key even on
 * its free Basic plan). The Markets module shows a "key required"
 * notice for such providers when no key is stored, and the provider
 * itself returns no prices until a key is set - never a fabricated
 * price. Providers that merely offer an optional Pro tier
 * (CoinGecko) do not implement this interface.
 */
interface ApiKeyRequired
{
}
