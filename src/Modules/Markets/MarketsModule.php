<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Integrations\IntegrationsModule;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Market\ManualSnapshotProvider;
use TrayDigita\WP\Plugin\Core\Market\MarketDataProvider;
use TrayDigita\WP\Plugin\Core\Market\MarketQuote;
use TrayDigita\WP\Plugin\Core\Market\YahooFinanceProvider;
use TrayDigita\WP\Plugin\Core\Widgets\MarketWidgetBase;
use function __;
use function absint;
use function add_filter;
use function admin_url;
use function apply_filters;
use function array_key_exists;
use function array_map;
use function array_merge;
use function current_user_can;
use function esc_attr;
use function esc_html_e;
use function explode;
use function get_option;
use function in_array;
use function is_array;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sprintf;
use function time;
use function trim;
use function update_option;
use function wp_nonce_field;

/**
 * Markets module.
 *
 * One module for all market data — gold, stocks, and crypto — instead
 * of three separate ones. It owns:
 *
 * - The master switch: turning the module off hides the market strip
 *   and unregisters the Gold, Stock, and Crypto widgets (see
 *   Sidebars::MODULE_WIDGETS), so existing widget instances never
 *   fatal — they simply stop rendering.
 * - The market strip toggle and one toggle per provider
 *   (gold / stock / crypto): a disabled provider contributes nothing
 *   anywhere, and a strip with no data hides entirely.
 * - Centralised provider config shared by the strip and the widgets:
 *   manual snapshot editors for gold and stock (rows in the exact
 *   ManualSnapshotProvider shape, `label|price|change|
 *   change_percent|buyback`), an optional Yahoo Finance stock
 *   provider, a gold provider choice (manual snapshot, Yahoo
 *   Finance gold futures, or GoldAPI.io with a key), a crypto
 *   provider choice (CoinGecko, CoinPaprika, Binance, Indodax,
 *   CoinMarketCap with a key) with an API key field only where a
 *   Pro tier exists, a display currency, and a cache TTL.
 * - Honest empty states everywhere: no data means no output, never
 *   a fabricated price. A failed fetch keeps serving the last good
 *   cached value (with its timestamp); with no cached value the
 *   provider contributes nothing.
 *
 * Crypto providers are resolved through the
 * `traydigita:core:markets/crypto-providers` filter
 * (MarketsModule::cryptoProviders()), and gold providers through
 * `traydigita:core:markets/gold-providers`
 * (MarketsModule::goldProviders()), so child themes and plugins
 * can add their own CryptoPriceProvider or GoldPriceProvider. Coin
 * ids are always CoinGecko ids; providers with native ids map them
 * internally. Gold has a single spot price per gram in IDR: a live
 * gold provider returns one "Gold spot" quote, never per-brand
 * prices.
 *
 * Settings live in the single option `traydigita_markets`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type. The API key is
 * never printed: the form field is always empty and shows only a
 * "set / not set" status, and an empty submission keeps the stored
 * key.
 */
final class MarketsModule implements ModuleInterface
{
    /**
     * Option name for all markets settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_markets';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_markets_save';

    /**
     * Filter exposing the crypto provider list to child themes.
     * Built from Core::HOOK_PREFIX so a custom hook prefix applies.
     */
    public const string CRYPTO_PROVIDERS_FILTER = Core::HOOK_PREFIX . ':markets/crypto-providers';

    /**
     * Filter exposing the gold provider list to child themes.
     * Built from Core::HOOK_PREFIX so a custom hook prefix applies.
     */
    public const string GOLD_PROVIDERS_FILTER = Core::HOOK_PREFIX . ':markets/gold-providers';

    /**
     * Widget-layer filter this module answers for provider resolution.
     */
    public const string MARKET_PROVIDER_FILTER = 'traydigita:core:market_provider';

    /**
     * Widget-layer filter this module answers for the cache TTL.
     */
    public const string MARKET_CACHE_TTL_FILTER = 'traydigita:core:market_cache_ttl';

    /**
     * Market kinds handled by this module.
     *
     * @var array<int, string>
     */
    public const array KINDS = ['gold', 'stock', 'crypto'];

    /**
     * Stock provider ids.
     *
     * @var array<int, string>
     */
    public const array STOCK_PROVIDERS = ['manual', 'yahoo'];

    /**
     * Gold provider ids.
     *
     * @var array<int, string>
     */
    public const array GOLD_PROVIDERS = ['manual', 'yahoo', 'goldapi'];

    /**
     * Display currencies offered for crypto (Binance forces USDT).
     *
     * @var array<int, string>
     */
    public const array CURRENCIES = ['IDR', 'USD'];

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'markets';
    }

    /**
     * Default category slug for the module list.
     *
     * Optional: ModuleRegistry::categoryOf() falls back to the
     * 'administration' category when a module does not declare
     * one, so a card is never uncategorised.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'monetization';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Markets', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Market data — gold, stocks, and crypto — with a master switch, a market strip, centralised manual snapshots, and a choice of crypto price providers. No price is ever invented.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'chart-line';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Gold, stock, and crypto market data.', 'traydigita-core'),
            __('Master switch for all market output.', 'traydigita-core'),
            __('Market strip on the frontend.', 'traydigita-core'),
            __('Centralised manual price snapshots.', 'traydigita-core'),
            __('Choice of crypto price providers.', 'traydigita-core'),
            __('No price is ever invented.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-markets';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * Configured means at least one source can produce data: crypto
     * works keyless through CoinGecko, so an enabled crypto provider
     * is enough; gold and stock need a manual snapshot configured,
     * unless a live gold provider is selected (Yahoo needs no key;
     * GoldAPI.io needs its key stored).
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        if ($settings['crypto_enabled']) {
            return true;
        }
        if ($settings['gold_enabled']
            && $settings['gold_provider'] === 'yahoo'
        ) {
            return true;
        }
        if ($settings['gold_enabled']
            && $settings['gold_provider'] === 'goldapi'
            && $settings['goldapi_key'] !== ''
        ) {
            return true;
        }
        return trim($settings['gold_snapshots']) !== ''
            || trim($settings['stock_snapshots']) !== '';
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether the module is currently enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return ModuleRegistry::isEnabled(self::id());
    }

    /**
     * Whether one market kind is enabled.
     *
     * A kind is enabled only when the module itself is enabled and
     * its own toggle is on. Unknown kinds are never enabled.
     *
     * @param string $kind Market kind: 'gold', 'stock', or 'crypto'.
     * @return bool
     */
    public static function isKindEnabled(string $kind): bool
    {
        if (!self::isEnabled() || !in_array($kind, self::KINDS, true)) {
            return false;
        }
        return (bool) self::getSettings()[$kind . '_enabled'];
    }

    /**
     * Whether the market strip may render.
     *
     * The strip needs the module on, its own toggle on, and at least
     * one of gold or stock enabled. The strip template additionally
     * hides itself when no provider has data.
     *
     * @return bool
     */
    public static function isStripVisible(): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        $settings = self::getSettings();
        return $settings['show_strip'] && ($settings['gold_enabled'] || $settings['stock_enabled']);
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{show_strip: bool, gold_enabled: bool, stock_enabled: bool,
     *     crypto_enabled: bool, gold_snapshots: string, stock_snapshots: string,
     *     stock_provider: string, gold_provider: string, crypto_provider: string,
     *     crypto_currency: string, api_key: string, coinmarketcap_key: string,
     *     goldapi_key: string, cache_ttl_minutes: int, snapshots_updated_at: int}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $stockProvider = isset($settings['stock_provider']) && is_string($settings['stock_provider'])
            ? $settings['stock_provider']
            : '';
        if (!in_array($stockProvider, self::STOCK_PROVIDERS, true)) {
            $stockProvider = 'manual';
        }
        $goldProvider = isset($settings['gold_provider']) && is_string($settings['gold_provider'])
            ? $settings['gold_provider']
            : '';
        $knownGoldProviders = array_map(
            static fn(GoldPriceProvider $provider): string => $provider->getId(),
            self::goldProviders()
        );
        if (!in_array($goldProvider, $knownGoldProviders, true)) {
            $goldProvider = 'manual';
        }
        $cryptoProvider = isset($settings['crypto_provider']) && is_string($settings['crypto_provider'])
            ? $settings['crypto_provider']
            : '';
        $knownProviders = array_map(
            static fn(CryptoPriceProvider $provider): string => $provider->getId(),
            self::cryptoProviders()
        );
        if (!in_array($cryptoProvider, $knownProviders, true)) {
            $cryptoProvider = 'coingecko';
        }
        $currency = isset($settings['crypto_currency']) && is_string($settings['crypto_currency'])
            ? strtoupper(trim($settings['crypto_currency']))
            : '';
        if (!in_array($currency, self::CURRENCIES, true)) {
            $currency = 'IDR';
        }
        $ttl = isset($settings['cache_ttl_minutes']) ? (int) $settings['cache_ttl_minutes'] : 5;
        if ($ttl < 1) {
            $ttl = 1;
        }
        if ($ttl > 60) {
            $ttl = 60;
        }
        return [
            'show_strip' => (bool) ($settings['show_strip'] ?? false),
            'gold_enabled' => (bool) ($settings['gold_enabled'] ?? false),
            'stock_enabled' => (bool) ($settings['stock_enabled'] ?? false),
            'crypto_enabled' => (bool) ($settings['crypto_enabled'] ?? false),
            'gold_snapshots' => isset($settings['gold_snapshots']) && is_string($settings['gold_snapshots'])
                ? $settings['gold_snapshots']
                : '',
            'stock_snapshots' => isset($settings['stock_snapshots']) && is_string($settings['stock_snapshots'])
                ? $settings['stock_snapshots']
                : '',
            'stock_provider' => $stockProvider,
            'gold_provider' => $goldProvider,
            'crypto_provider' => $cryptoProvider,
            'crypto_currency' => $currency,
            'api_key' => isset($settings['api_key']) && is_string($settings['api_key'])
                ? $settings['api_key']
                : '',
            'coinmarketcap_key' => isset($settings['coinmarketcap_key']) && is_string($settings['coinmarketcap_key'])
                ? $settings['coinmarketcap_key']
                : '',
            'goldapi_key' => isset($settings['goldapi_key']) && is_string($settings['goldapi_key'])
                ? $settings['goldapi_key']
                : '',
            'cache_ttl_minutes' => $ttl,
            'snapshots_updated_at' => isset($settings['snapshots_updated_at'])
                ? (int) $settings['snapshots_updated_at']
                : 0,
        ];
    }

    /**
     * Default settings.
     *
     * Everything on: the module preserves the phase-1 behaviour on
     * existing installs (strip and widgets already show there).
     * Crypto defaults to CoinGecko, public tier, IDR. Gold defaults
     * to the manual snapshot.
     *
     * @return array{show_strip: bool, gold_enabled: bool, stock_enabled: bool,
     *     crypto_enabled: bool, gold_snapshots: string, stock_snapshots: string,
     *     stock_provider: string, gold_provider: string, crypto_provider: string,
     *     crypto_currency: string, api_key: string, coinmarketcap_key: string,
     *     goldapi_key: string, cache_ttl_minutes: int, snapshots_updated_at: int}
     */
    public static function getDefaults(): array
    {
        return [
            'show_strip' => true,
            'gold_enabled' => true,
            'stock_enabled' => true,
            'crypto_enabled' => true,
            'gold_snapshots' => '',
            'stock_snapshots' => '',
            'stock_provider' => 'manual',
            'gold_provider' => 'manual',
            'crypto_provider' => 'coingecko',
            'crypto_currency' => 'IDR',
            'api_key' => '',
            'coinmarketcap_key' => '',
            'goldapi_key' => '',
            'cache_ttl_minutes' => 5,
            'snapshots_updated_at' => 0,
        ];
    }

    /**
     * All known crypto price providers, filterable for child themes.
     *
     * Built-ins are constructed with the stored API keys (CoinGecko
     * Pro uses `api_key`; CoinMarketCap uses `coinmarketcap_key`).
     * The filter receives the built-in instances and may add,
     * replace, or remove providers; entries that are not
     * CryptoPriceProvider instances are dropped, and duplicate ids
     * are dropped (first wins).
     *
     * Reads the API keys straight from the option (not through
     * getSettings()) so getSettings() can safely call this to
     * validate the selected provider id.
     *
     * @return array<int, CryptoPriceProvider>
     */
    public static function cryptoProviders(): array
    {
        $stored = get_option(self::OPTION, []);
        $apiKey = is_array($stored) && isset($stored['api_key']) && is_string($stored['api_key'])
            ? $stored['api_key']
            : '';
        $coinMarketCapKey = is_array($stored)
            && isset($stored['coinmarketcap_key']) && is_string($stored['coinmarketcap_key'])
            ? $stored['coinmarketcap_key']
            : '';
        $defaults = [
            new CoinGeckoCryptoProvider($apiKey),
            new CoinPaprikaCryptoProvider(),
            new BinanceCryptoProvider(),
            new IndodaxCryptoProvider(),
            new CoinMarketCapCryptoProvider($coinMarketCapKey),
        ];
        $providers = apply_filters(self::CRYPTO_PROVIDERS_FILTER, $defaults);
        if (!is_array($providers)) {
            return $defaults;
        }
        $valid = [];
        $seen = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof CryptoPriceProvider) {
                continue;
            }
            $id = $provider->getId();
            if ($id === '' || in_array($id, $seen, true)) {
                continue;
            }
            $seen[] = $id;
            $valid[] = $provider;
        }
        return $valid !== [] ? $valid : $defaults;
    }

    /**
     * Find a crypto provider by id, or null when unknown.
     *
     * @param string $id Provider id.
     * @return CryptoPriceProvider|null
     */
    public static function getCryptoProvider(string $id): ?CryptoPriceProvider
    {
        foreach (self::cryptoProviders() as $provider) {
            if ($provider->getId() === $id) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * All known gold price providers, filterable for child themes.
     *
     * Built-ins are constructed with the stored GoldAPI.io key
     * (`goldapi_key`); Yahoo Finance needs no key. The filter
     * receives the built-in instances and may add, replace, or
     * remove providers; entries that are not GoldPriceProvider
     * instances are dropped, and duplicate ids are dropped (first
     * wins). The manual snapshot is not a provider object — it is
     * the fallback when the selected provider is `manual` or fails.
     *
     * Reads the API key straight from the option (not through
     * getSettings()) so getSettings() can safely call this to
     * validate the selected provider id.
     *
     * @return array<int, GoldPriceProvider>
     */
    public static function goldProviders(): array
    {
        $stored = get_option(self::OPTION, []);
        $goldApiKey = is_array($stored)
            && isset($stored['goldapi_key']) && is_string($stored['goldapi_key'])
            ? $stored['goldapi_key']
            : '';
        $defaults = [
            new YahooGoldPriceProvider(),
            new GoldApiPriceProvider($goldApiKey),
        ];
        $providers = apply_filters(self::GOLD_PROVIDERS_FILTER, $defaults);
        if (!is_array($providers)) {
            return $defaults;
        }
        $valid = [];
        $seen = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof GoldPriceProvider) {
                continue;
            }
            $id = $provider->getId();
            if ($id === '' || in_array($id, $seen, true)) {
                continue;
            }
            $seen[] = $id;
            $valid[] = $provider;
        }
        return $valid !== [] ? $valid : $defaults;
    }

    /**
     * Find a gold provider by id, or null when unknown.
     *
     * `manual` has no provider object, so it always returns null.
     *
     * @param string $id Provider id.
     * @return GoldPriceProvider|null
     */
    public static function getGoldProvider(string $id): ?GoldPriceProvider
    {
        foreach (self::goldProviders() as $provider) {
            if ($provider->getId() === $id) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Whether a provider that needs an API key has none stored.
     *
     * Only providers implementing ApiKeyRequired are checked: an
     * optional Pro key (CoinGecko) missing is fine, a mandatory key
     * (CoinMarketCap, GoldAPI.io) missing means the provider cannot
     * return prices. The check scans the provider's password fields
     * so third-party providers work the same way.
     *
     * @param CryptoPriceProvider|GoldPriceProvider $provider Provider to check.
     * @param array<string, mixed> $settings Current module settings.
     * @return bool
     */
    public static function providerKeyMissing(CryptoPriceProvider|GoldPriceProvider $provider, array $settings): bool
    {
        if (!$provider instanceof ApiKeyRequired) {
            return false;
        }
        foreach ($provider->getSettings() as $field) {
            if (!isset($field['type']) || $field['type'] !== 'password') {
                continue;
            }
            $id = isset($field['id']) && is_string($field['id']) ? sanitize_key($field['id']) : '';
            if ($id === '') {
                continue;
            }
            $stored = isset($settings[$id]) && is_string($settings[$id]) ? $settings[$id] : '';
            if ($stored === '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Effective display currency for crypto prices.
     *
     * The configured currency when the selected provider supports
     * it; otherwise the first supported currency in the order IDR,
     * USD, USDT (Indodax only quotes IDR, Binance only USDT).
     *
     * @return string
     */
    public static function effectiveCryptoCurrency(): string
    {
        $settings = self::getSettings();
        $provider = self::getCryptoProvider($settings['crypto_provider']);
        if ($provider === null) {
            return 'IDR';
        }
        $currency = $settings['crypto_currency'];
        if ($provider->supportsCurrency($currency)) {
            return $currency;
        }
        if ($provider->supportsCurrency('IDR')) {
            return 'IDR';
        }
        if ($provider->supportsCurrency('USD')) {
            return 'USD';
        }
        return 'USDT';
    }

    /**
     * Parsed manual snapshot rows for one kind, from the module settings.
     *
     * Rows use the exact ManualSnapshotProvider shape
     * (`label|price|change|change_percent|buyback`).
     *
     * @param string $kind Market kind: 'gold' or 'stock'.
     * @return array<int, array<string, mixed>>
     */
    public static function moduleSnapshotRows(string $kind): array
    {
        $settings = self::getSettings();
        $key = $kind === 'gold' ? 'gold_snapshots' : ($kind === 'stock' ? 'stock_snapshots' : '');
        if ($key === '') {
            return [];
        }
        return ManualSnapshotProvider::parseLines($settings[$key]);
    }

    /**
     * Timestamp of the last snapshot edit, or 0 when never saved.
     *
     * @return int
     */
    public static function snapshotUpdatedAt(): int
    {
        return self::getSettings()['snapshots_updated_at'];
    }

    /**
     * Widget-shaped snapshot from the module snapshots, for the strip.
     *
     * The strip reads this first (no widget instance needed) and
     * falls back to the first saved widget instance when the module
     * has no snapshots for the kind. Returns null when the module
     * has no usable rows — or when stocks use Yahoo Finance, or
     * gold uses a live provider (the strip then falls back to the
     * widget instance, which is primed from that provider, so strip
     * and widget never disagree) — so the strip can hide instead of
     * showing an empty bar.
     *
     * @param string $kind Market kind: 'gold' or 'stock'.
     * @return array{title: string, quotes: array<int, array<string, mixed>>, source: string,
     *     updated_at: int|null, currency: string, kind: string}|null
     */
    public static function snapshotForStrip(string $kind): ?array
    {
        if ($kind === 'stock' && self::getSettings()['stock_provider'] === 'yahoo') {
            return null;
        }
        if ($kind === 'gold' && self::getSettings()['gold_provider'] !== 'manual') {
            return null;
        }
        $rows = self::moduleSnapshotRows($kind);
        if ($rows === []) {
            return null;
        }
        $provider = new ManualSnapshotProvider($rows, self::snapshotUpdatedAt());
        $quotes = array_map(
            static fn(MarketQuote $quote): array => $quote->toArray(),
            $provider->getQuotes([])
        );
        if ($quotes === []) {
            return null;
        }
        return [
            'title' => '',
            'quotes' => $quotes,
            'source' => $provider->getSourceLabel(),
            'updated_at' => self::snapshotUpdatedAt() > 0 ? self::snapshotUpdatedAt() : null,
            'currency' => 'IDR',
            'kind' => $kind,
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * The provider filter resolves gold/stock/crypto providers from
     * the central settings; the TTL filter applies the configured
     * cache lifetime to the widget transients.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!self::isEnabled()) {
            return;
        }
        add_filter(self::MARKET_PROVIDER_FILTER, [self::class, 'filterMarketProvider'], 10, 3);
        add_filter(self::MARKET_CACHE_TTL_FILTER, [self::class, 'filterCacheTtl'], 10, 2);
    }

    /**
     * Register the crypto markets entry on the Integrations hub.
     *
     * Registered centrally by IntegrationsModule::registerEntries()
     * (not from boot(), so the entry appears even while this
     * module is disabled). One entry for the selected crypto
     * provider, reusing getCryptoProvider() and providerKeyMissing():
     * 'active' when crypto is enabled and the provider can return
     * prices, 'configured' when a provider is selected but crypto
     * is switched off, 'not-configured' when no provider is
     * available or a provider that needs an API key has none stored,
     * 'module-disabled' with a link to the Modules page when this
     * module is disabled. The description names the selected
     * provider.
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the crypto entry appended.
     */
    public static function registerIntegrationEntry(array $entries): array
    {
        $settings = self::getSettings();
        $provider = self::getCryptoProvider($settings['crypto_provider']);
        if ($provider === null) {
            $label = 'Crypto';
            $description = __('No crypto provider is available.', 'traydigita-core');
            $status = 'not-configured';
        } elseif (self::providerKeyMissing($provider, $settings)) {
            $label = $provider->getLabel();
            $description = sprintf(
                /* translators: %s is the crypto provider name, e.g. "CoinMarketCap". */
                __('Crypto prices from %s — the provider needs an API key to return prices.', 'traydigita-core'),
                $label
            );
            $status = 'not-configured';
        } elseif (!$settings['crypto_enabled']) {
            $label = $provider->getLabel();
            $description = sprintf(
                /* translators: %s is the crypto provider name, e.g. "CoinGecko". */
                __('Crypto prices from %s are set up, but crypto is switched off.', 'traydigita-core'),
                $label
            );
            $status = 'configured';
        } else {
            $label = $provider->getLabel();
            $description = sprintf(
                /* translators: %s is the crypto provider name, e.g. "CoinGecko". */
                __('Live crypto prices from %s.', 'traydigita-core'),
                $label
            );
            $status = 'active';
        }
        if (!ModuleRegistry::isEnabled(self::id())) {
            $description = __(
                'The Markets module is disabled — enable it in Modules to use market integrations.',
                'traydigita-core'
            );
            $status = 'module-disabled';
            $url = IntegrationsModule::modulesUrl();
        } else {
            $url = admin_url('admin.php?page=' . self::settingsPageSlug());
        }
        $entries[] = [
            'id' => 'markets-crypto',
            'name' => $label,
            'description' => $description,
            'status' => $status,
            'url' => $url,
            'module' => self::id(),
            'icon' => 'trending_up',
        ];
        return $entries;
    }

    /**
     * Resolve the provider for a market kind from the central settings.
     *
     * An earlier filter result (child theme / plugin) is respected
     * as-is. Otherwise: Yahoo Finance for stocks when selected;
     * the selected live gold provider (Yahoo futures or GoldAPI.io)
     * for gold when selected — the adapter turns its spot price into
     * the single "Gold spot" quote, and a provider failure falls
     * back to the manual snapshots through the widget layer; the
     * module's manual snapshots for gold/stock when configured;
     * the selected crypto provider (adapted) for crypto. Returning
     * null keeps the widget's own default (per-instance snapshots
     * for gold/stock, CoinGecko public for crypto).
     *
     * @param mixed $provider Provider supplied by an earlier filter, or null.
     * @param string $kind Market kind.
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return MarketDataProvider|null
     */
    public static function filterMarketProvider(mixed $provider, string $kind, array $instance): ?MarketDataProvider
    {
        if ($provider instanceof MarketDataProvider) {
            return $provider;
        }
        if (!self::isKindEnabled($kind)) {
            return null;
        }
        if ($kind === 'stock' && self::getSettings()['stock_provider'] === 'yahoo') {
            return new YahooFinanceProvider();
        }
        if ($kind === 'gold') {
            $goldProvider = self::getGoldProvider(self::getSettings()['gold_provider']);
            if ($goldProvider !== null) {
                return new GoldProviderAdapter($goldProvider);
            }
            $rows = self::moduleSnapshotRows($kind);
            if ($rows !== []) {
                return new ManualSnapshotProvider($rows, self::snapshotUpdatedAt());
            }
            return null;
        }
        if ($kind === 'stock') {
            $rows = self::moduleSnapshotRows($kind);
            if ($rows !== []) {
                return new ManualSnapshotProvider($rows, self::snapshotUpdatedAt());
            }
            return null;
        }
        if ($kind === 'crypto') {
            $settings = self::getSettings();
            $cryptoProvider = self::getCryptoProvider($settings['crypto_provider'])
                ?? new CoinGeckoCryptoProvider($settings['api_key']);
            return new CryptoProviderAdapter(
                $cryptoProvider,
                self::parseCoinLabels($instance),
                self::effectiveCryptoCurrency()
            );
        }
        return null;
    }

    /**
     * Apply the configured cache TTL to market transients.
     *
     * @param mixed $ttl Default TTL in seconds (unused — the setting wins).
     * @param string $kind Market kind (unused — one TTL for all kinds).
     * @return int TTL in seconds, clamped to 1–60 minutes.
     */
    public static function filterCacheTtl(mixed $ttl, string $kind): int
    {
        $minutes = self::getSettings()['cache_ttl_minutes'];
        return max(60, min(3600, $minutes * 60));
    }

    /**
     * Parse coin labels from a widget instance's symbols field.
     *
     * Lines are `id|Label`; a missing label falls back to the id.
     * An empty field yields an empty map and the adapter falls back
     * to the id as the label.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return array<string, string> CoinGecko id => display label.
     */
    private static function parseCoinLabels(array $instance): array
    {
        $labels = [];
        $raw = (string) ($instance['symbols'] ?? '');
        foreach (explode("\n", $raw) as $line) {
            $parts = explode('|', trim($line));
            $id = trim($parts[0]);
            if ($id === '') {
                continue;
            }
            $label = trim($parts[1] ?? '');
            $labels[$id] = $label !== '' ? $label : $id;
        }
        return $labels;
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'display', 'label' => __('Display', 'traydigita-core'), 'icon' => 'visibility'],
            ['id' => 'providers', 'label' => __('Providers', 'traydigita-core'), 'icon' => 'chart-area'],
            ['id' => 'snapshots', 'label' => __('Manual snapshots', 'traydigita-core'), 'icon' => 'edit'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * One toggle per provider, a stock provider select, a crypto
     * provider select with currency and cache TTL, the provider
     * extra settings (shown only for the selected provider — a
     * small inline script toggles the containers), and the manual
     * snapshot editors. The form is submitted through the generic
     * module-settings AJAX dispatcher (see
     * `data-traydigita-module-form`).
     *
     * Framework (R4-5): the fields are rendered with
     * SettingsControls inside framework sections (`td-section-*`,
     * matching settingsSections() above) and the form ends in the
     * sticky framework save bar.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        $providers = self::cryptoProviders();
        ?>
        <div class="traydigita-module-settings markets-settings">
            <?php
            SettingsControls::intro(
                __('One place for all market data.', 'traydigita-core'),
                __('Turning the module off hides the market strip and removes the Gold, Stock, and Crypto widgets. No price is ever invented: a provider with no data contributes nothing.', 'traydigita-core')
            );
            ?>

            <form class="markets-settings-form" data-traydigita-module-form data-traydigita-module="markets" data-module="markets" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <?php SettingsControls::sectionOpen('display', __('Display', 'traydigita-core')); ?>
                <?php
                SettingsControls::toggle([
                    'id' => 'markets-show-strip',
                    'name' => 'show_strip',
                    'label' => __('Show the market strip', 'traydigita-core'),
                    'checked' => $settings['show_strip'],
                    'help' => __('The strip under the ticker shows the first gold price and up to four stocks.', 'traydigita-core'),
                    'help_long' => __('When no provider has data, the strip hides entirely instead of showing an empty bar.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose();
                ?>

                <?php SettingsControls::sectionOpen('providers', __('Providers', 'traydigita-core')); ?>

                    <?php
                    SettingsControls::toggle([
                        'id' => 'markets-gold-enabled',
                        'name' => 'gold_enabled',
                        'label' => __('Gold prices', 'traydigita-core'),
                        'checked' => $settings['gold_enabled'],
                    ]);
                    SettingsControls::toggle([
                        'id' => 'markets-stock-enabled',
                        'name' => 'stock_enabled',
                        'label' => __('Stock prices', 'traydigita-core'),
                        'checked' => $settings['stock_enabled'],
                    ]);
                    SettingsControls::toggle([
                        'id' => 'markets-crypto-enabled',
                        'name' => 'crypto_enabled',
                        'label' => __('Crypto prices', 'traydigita-core'),
                        'checked' => $settings['crypto_enabled'],
                    ]);
                    ?>

                    <?php
                    SettingsControls::select([
                        'id' => 'markets-stock-provider',
                        'name' => 'stock_provider',
                        'label' => __('Stock provider', 'traydigita-core'),
                        'value' => $settings['stock_provider'],
                        'options' => [
                            'manual' => __('Manual snapshot (default)', 'traydigita-core'),
                            'yahoo' => __('Yahoo Finance (unofficial)', 'traydigita-core'),
                        ],
                        'help' => __('Yahoo Finance is an unofficial endpoint and may break or rate-limit without notice.', 'traydigita-core'),
                        'help_long' => __('When it is selected, the manual stock snapshot below is only used if Yahoo returns nothing. IDX tickers get the .JK suffix automatically.', 'traydigita-core'),
                    ]);
                    ?>

                    <?php
                    $goldProviders = self::goldProviders();
                    $goldOptions = ['manual' => __('Manual snapshot (default)', 'traydigita-core')];
                    foreach ($goldProviders as $goldProvider) {
                        $goldOptions[$goldProvider->getId()] = $goldProvider->getLabel();
                    }
                    SettingsControls::select([
                        'id' => 'markets-gold-provider',
                        'name' => 'gold_provider',
                        'label' => __('Gold provider', 'traydigita-core'),
                        'value' => $settings['gold_provider'],
                        'options' => $goldOptions,
                        'help' => __('A live gold provider returns one spot price per gram in IDR ("Gold spot").', 'traydigita-core'),
                        'help_long' => __('Per-brand prices always stay manual snapshots — a spot price is never presented as a brand price. When the live provider returns nothing, the manual snapshot below is used instead.', 'traydigita-core'),
                    ]);
                    ?>
                        <?php foreach ($goldProviders as $goldProvider) : ?>
                            <span class="markets-field-help">
                                <?php
                                [$marketsShort, $marketsLong] = SettingsControls::splitHelp($goldProvider->getDescription());
                                SettingsControls::helpText($marketsShort, $marketsLong);
                                ?>
                            </span>
                        <?php endforeach; ?>

                    <?php $selectedGoldProvider = self::getGoldProvider($settings['gold_provider']); ?>
                    <?php if ($selectedGoldProvider !== null && self::providerKeyMissing($selectedGoldProvider, $settings)) : ?>
                        <p class="markets-notice markets-notice-warning">
                            <?php esc_html_e('This provider needs an API key to return prices. Enter it below.', 'traydigita-core'); ?>
                        </p>
                    <?php endif; ?>

                    <?php foreach ($goldProviders as $goldProvider) : ?>
                        <?php $goldFields = $goldProvider->getSettings(); ?>
                        <?php if ($goldFields !== []) : ?>
                            <div class="markets-provider-settings" data-markets-gold-provider-settings="<?php echo esc_attr($goldProvider->getId()); ?>" <?php echo $settings['gold_provider'] !== $goldProvider->getId() ? 'style="display:none"' : ''; ?>>
                                <?php foreach ($goldFields as $field) : ?>
                                    <?php self::renderProviderField($field, $settings); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php
                    $cryptoOptions = [];
                    foreach ($providers as $provider) {
                        $cryptoOptions[$provider->getId()] = $provider->getLabel();
                    }
                    SettingsControls::select([
                        'id' => 'markets-crypto-provider',
                        'name' => 'crypto_provider',
                        'label' => __('Crypto provider', 'traydigita-core'),
                        'value' => $settings['crypto_provider'],
                        'options' => $cryptoOptions,
                    ]);
                    ?>
                        <?php foreach ($providers as $provider) : ?>
                            <span class="markets-field-help">
                                <?php
                                [$marketsShort, $marketsLong] = SettingsControls::splitHelp($provider->getDescription());
                                SettingsControls::helpText($marketsShort, $marketsLong);
                                ?>
                            </span>
                        <?php endforeach; ?>

                    <?php $selectedCryptoProvider = self::getCryptoProvider($settings['crypto_provider']); ?>
                    <?php if ($selectedCryptoProvider !== null && self::providerKeyMissing($selectedCryptoProvider, $settings)) : ?>
                        <p class="markets-notice markets-notice-warning">
                            <?php esc_html_e('This provider needs an API key to return prices. Enter it below.', 'traydigita-core'); ?>
                        </p>
                    <?php endif; ?>

                    <?php foreach ($providers as $provider) : ?>
                        <?php $fields = $provider->getSettings(); ?>
                        <?php if ($fields !== []) : ?>
                            <div class="markets-provider-settings" data-markets-provider-settings="<?php echo esc_attr($provider->getId()); ?>" <?php echo $settings['crypto_provider'] !== $provider->getId() ? 'style="display:none"' : ''; ?>>
                                <?php foreach ($fields as $field) : ?>
                                    <?php self::renderProviderField($field, $settings); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php
                    SettingsControls::select([
                        'id' => 'markets-crypto-currency',
                        'name' => 'crypto_currency',
                        'label' => __('Crypto display currency', 'traydigita-core'),
                        'value' => $settings['crypto_currency'],
                        'options' => [
                            'IDR' => __('Indonesian Rupiah (IDR)', 'traydigita-core'),
                            'USD' => __('US Dollar (USD)', 'traydigita-core'),
                        ],
                        'help' => __('Each provider quotes in the currencies it supports.', 'traydigita-core'),
                        'help_long' => __('When your choice is not supported, the provider falls back to its own currency: Indodax always uses IDR, Binance always uses USDT.', 'traydigita-core'),
                    ]);
                    ?>

                    <?php
                    SettingsControls::number([
                        'id' => 'markets-cache-ttl',
                        'name' => 'cache_ttl_minutes',
                        'label' => __('Cache lifetime (minutes)', 'traydigita-core'),
                        'value' => (string) $settings['cache_ttl_minutes'],
                        'help' => __('Prices are cached for this long (default 5 minutes) so public rate limits are never hit on every page load.', 'traydigita-core'),
                        'help_long' => __('A failed fetch keeps serving the last good cached value.', 'traydigita-core'),
                        'attrs' => ['min' => '1', 'max' => '60', 'step' => '1'],
                    ]);
                    SettingsControls::sectionClose();
                    ?>

                <?php SettingsControls::sectionOpen('snapshots', __('Manual snapshots', 'traydigita-core')); ?>
                <?php
                SettingsControls::textarea([
                    'id' => 'markets-gold-snapshots',
                    'name' => 'gold_snapshots',
                    'label' => __('Gold prices (one brand per line)', 'traydigita-core'),
                    'value' => $settings['gold_snapshots'],
                    'variant' => 'code',
                    'help' => __('Format per line: Brand|price|change|change_percent|buyback. Only the brand and price are required.', 'traydigita-core'),
                    'help_long' => __('Leave empty when a widget instance already carries its own snapshot.', 'traydigita-core'),
                    'attrs' => ['rows' => '6'],
                ]);
                SettingsControls::textarea([
                    'id' => 'markets-stock-snapshots',
                    'name' => 'stock_snapshots',
                    'label' => __('Stock prices (one ticker per line)', 'traydigita-core'),
                    'value' => $settings['stock_snapshots'],
                    'variant' => 'code',
                    'help' => __('Format per line: TICKER|price|change|change_percent. Only the ticker and price are required.', 'traydigita-core'),
                    'help_long' => __('Used only when the stock provider is Manual snapshot.', 'traydigita-core'),
                    'attrs' => ['rows' => '6'],
                ]);
                SettingsControls::sectionClose();
                ?>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <script>
        (function () {
            var toggleFor = function (selectId, attribute) {
                var select = document.getElementById(selectId);
                if (!select) {
                    return;
                }
                var toggle = function () {
                    var containers = document.querySelectorAll('[' + attribute + ']');
                    for (var i = 0; i < containers.length; i++) {
                        containers[i].style.display = containers[i].getAttribute(attribute) === select.value ? '' : 'none';
                    }
                };
                select.addEventListener('change', toggle);
                toggle();
            };
            toggleFor('markets-crypto-provider', 'data-markets-provider-settings');
            toggleFor('markets-gold-provider', 'data-markets-gold-provider-settings');
        })();
        </script>
        <?php
    }

    /**
     * Render one provider-declared settings field.
     *
     * Password fields are never prefilled: the value is always empty
     * and a status line says whether a value is stored. An empty
     * submission keeps the stored value (see saveSettings()).
     *
     * @param array<string, mixed> $field Field definition from CryptoPriceProvider::getSettings().
     * @param array<string, mixed> $settings Current module settings.
     * @return void
     */
    private static function renderProviderField(array $field, array $settings): void
    {
        $id = isset($field['id']) && is_string($field['id']) ? sanitize_key($field['id']) : '';
        $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'text';
        $label = isset($field['label']) && is_string($field['label']) ? $field['label'] : '';
        $description = isset($field['description']) && is_string($field['description']) ? $field['description'] : '';
        // Provider descriptions arrive as one string; split the
        // long ones so only the first sentence shows inline.
        [$descriptionShort, $descriptionLong] = SettingsControls::splitHelp($description);
        if ($id === '' || $label === '') {
            return;
        }
        $stored = isset($settings[$id]) && is_string($settings[$id]) ? $settings[$id] : '';
        $fieldId = 'markets-provider-' . $id;
        if ($type === 'password') {
            SettingsControls::input([
                'type' => 'password',
                'id' => $fieldId,
                'name' => $id,
                'label' => $label,
                'value' => '',
                'help' => $stored !== ''
                    ? __('API key is set. Leave empty to keep it.', 'traydigita-core')
                    : __('No API key set.', 'traydigita-core'),
                'attrs' => ['autocomplete' => 'off'],
            ]);
        } elseif ($type === 'textarea') {
            SettingsControls::textarea([
                'id' => $fieldId,
                'name' => $id,
                'label' => $label,
                'value' => $stored,
                'help' => $description !== '' ? $descriptionShort : null,
                'help_long' => $descriptionLong,
                'attrs' => ['rows' => '4'],
            ]);
        } elseif ($type === 'checkbox') {
            SettingsControls::toggle([
                'id' => $fieldId,
                'name' => $id,
                'label' => $label,
                'checked' => $stored !== '' && $stored !== '0',
                'help' => $description !== '' ? $descriptionShort : null,
                'help_long' => $descriptionLong,
                'hidden_zero' => false,
            ]);
        } elseif ($type === 'select' && isset($field['options']) && is_array($field['options'])) {
            $options = [];
            foreach ($field['options'] as $value => $optionLabel) {
                $options[(string) $value] = (string) $optionLabel;
            }
            SettingsControls::select([
                'id' => $fieldId,
                'name' => $id,
                'label' => $label,
                'value' => $stored,
                'options' => $options,
                'help' => $description !== '' ? $descriptionShort : null,
                'help_long' => $descriptionLong,
            ]);
        } else {
            SettingsControls::input([
                'id' => $fieldId,
                'name' => $id,
                'label' => $label,
                'value' => $stored,
                'help' => $description !== '' ? $descriptionShort : null,
                'help_long' => $descriptionLong,
            ]);
        }
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `show_strip`, `gold_enabled`,
     * `stock_enabled`, `crypto_enabled` (each `1` when checked; an
     * absent key means the checkbox was unchecked), `stock_provider`,
     * `gold_provider` and `crypto_provider` (allowlisted),
     * `crypto_currency` (IDR/USD), `cache_ttl_minutes` (1–60),
     * `gold_snapshots` and `stock_snapshots` (plain text), plus one
     * key per provider-declared field (e.g. `api_key`,
     * `coinmarketcap_key`, `goldapi_key`). Every value is
     * re-sanitized for its own type; unknown keys are dropped. A
     * password field left empty keeps the stored value, and the
     * returned array never contains a secret.
     *
     * After saving, every market widget instance is re-primed so
     * the strip and widgets pick the new configuration up at once
     * instead of serving stale transients until the hourly cron.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change markets settings.', 'traydigita-core'),
            ];
        }
        $previous = self::getSettings();
        $stockProvider = isset($data['stock_provider']) && is_string($data['stock_provider'])
            ? $data['stock_provider']
            : '';
        if (!in_array($stockProvider, self::STOCK_PROVIDERS, true)) {
            $stockProvider = 'manual';
        }
        $goldProvider = isset($data['gold_provider']) && is_string($data['gold_provider'])
            ? $data['gold_provider']
            : '';
        $knownGoldProviders = array_map(
            static fn(GoldPriceProvider $provider): string => $provider->getId(),
            self::goldProviders()
        );
        if (!in_array($goldProvider, $knownGoldProviders, true)) {
            $goldProvider = 'manual';
        }
        $cryptoProvider = isset($data['crypto_provider']) && is_string($data['crypto_provider'])
            ? $data['crypto_provider']
            : '';
        $knownProviders = array_map(
            static fn(CryptoPriceProvider $provider): string => $provider->getId(),
            self::cryptoProviders()
        );
        if (!in_array($cryptoProvider, $knownProviders, true)) {
            $cryptoProvider = 'coingecko';
        }
        $currency = isset($data['crypto_currency']) && is_string($data['crypto_currency'])
            ? strtoupper(trim($data['crypto_currency']))
            : '';
        if (!in_array($currency, self::CURRENCIES, true)) {
            $currency = 'IDR';
        }
        $ttl = isset($data['cache_ttl_minutes']) ? absint($data['cache_ttl_minutes']) : 5;
        if ($ttl < 1) {
            $ttl = 1;
        }
        if ($ttl > 60) {
            $ttl = 60;
        }
        $goldSnapshots = isset($data['gold_snapshots']) && is_string($data['gold_snapshots'])
            ? sanitize_textarea_field($data['gold_snapshots'])
            : '';
        $stockSnapshots = isset($data['stock_snapshots']) && is_string($data['stock_snapshots'])
            ? sanitize_textarea_field($data['stock_snapshots'])
            : '';
        $settings = [
            'show_strip' => !empty($data['show_strip']),
            'gold_enabled' => !empty($data['gold_enabled']),
            'stock_enabled' => !empty($data['stock_enabled']),
            'crypto_enabled' => !empty($data['crypto_enabled']),
            'gold_snapshots' => $goldSnapshots,
            'stock_snapshots' => $stockSnapshots,
            'stock_provider' => $stockProvider,
            'gold_provider' => $goldProvider,
            'crypto_provider' => $cryptoProvider,
            'crypto_currency' => $currency,
            'api_key' => $previous['api_key'],
            'coinmarketcap_key' => $previous['coinmarketcap_key'],
            'goldapi_key' => $previous['goldapi_key'],
            'cache_ttl_minutes' => $ttl,
            'snapshots_updated_at' => $previous['snapshots_updated_at'],
        ];
        if ($goldSnapshots !== $previous['gold_snapshots'] || $stockSnapshots !== $previous['stock_snapshots']) {
            $settings['snapshots_updated_at'] = time();
        }
        $fieldProviders = array_merge(self::cryptoProviders(), self::goldProviders());
        foreach ($fieldProviders as $provider) {
            foreach ($provider->getSettings() as $field) {
                $id = isset($field['id']) && is_string($field['id']) ? sanitize_key($field['id']) : '';
                if ($id === '') {
                    continue;
                }
                $settings[$id] = isset($previous[$id]) && is_string($previous[$id]) ? $previous[$id] : '';
            }
        }
        foreach ($fieldProviders as $provider) {
            foreach ($provider->getSettings() as $field) {
                $id = isset($field['id']) && is_string($field['id']) ? sanitize_key($field['id']) : '';
                $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'text';
                if ($id === '') {
                    continue;
                }
                $raw = $data[$id] ?? null;
                if ($type === 'password') {
                    // Empty means "keep the stored value" (see above).
                    if (is_string($raw) && trim($raw) !== '') {
                        $settings[$id] = sanitize_text_field($raw);
                    }
                    continue;
                }
                if ($type === 'textarea') {
                    $settings[$id] = is_string($raw) ? sanitize_textarea_field($raw) : '';
                    continue;
                }
                if ($type === 'checkbox') {
                    $settings[$id] = !empty($raw);
                    continue;
                }
                if ($type === 'select' && isset($field['options']) && is_array($field['options'])) {
                    $value = is_string($raw) ? $raw : '';
                    $settings[$id] = array_key_exists($value, $field['options']) ? $value : '';
                    continue;
                }
                $settings[$id] = is_string($raw) ? sanitize_text_field($raw) : '';
            }
        }
        update_option(self::OPTION, $settings, false);
        try {
            if ($stockProvider !== $previous['stock_provider']
                || $goldProvider !== $previous['gold_provider']
                || $cryptoProvider !== $previous['crypto_provider']
            ) {
                // A provider switch must not keep serving the previous
                // provider's cached quotes until the TTL expires.
                MarketWidgetBase::clearAllCaches();
            }
            MarketWidgetBase::primeAllInstances();
        } catch (\Throwable) {
            // Priming is best-effort: the hourly cron refreshes the
            // snapshots anyway, and the settings themselves are saved.
        }
        return [
            'success' => true,
            'message' => __('Markets settings saved.', 'traydigita-core'),
        ];
    }
}
