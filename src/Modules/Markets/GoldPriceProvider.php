<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

/**
 * Contract for gold price providers (Yahoo Finance, GoldAPI…).
 *
 * Unlike crypto, gold has a single spot price: providers return
 * one price per gram in Indonesian Rupiah, not one price per
 * brand. The GoldProviderAdapter turns that spot price into the
 * single "Gold spot" quote the gold widget and the market strip
 * render; per-brand prices stay manual snapshots only, because
 * presenting a spot price as a brand's price would be dishonest.
 *
 * Providers are resolved through the Markets module registry
 * (MarketsModule::goldProviders(), filter
 * `traydigita:core:markets/gold-providers`) so child themes and
 * plugins can add their own. A provider must never fabricate a
 * price: on any failure it returns null and the widget falls back
 * to the manual snapshot or an honest empty state.
 */
interface GoldPriceProvider
{
    /**
     * Stable machine id, e.g. 'yahoo'.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Human-readable provider name, e.g. 'Yahoo Finance'.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Short description shown on the module settings page, including
     * any honesty note (unofficial endpoint, futures not spot…).
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Whether this provider offers a Pro tier that needs an API key.
     *
     * True means the module settings form shows an API key field for
     * this provider. Providers that implement ApiKeyRequired need
     * the key unconditionally; the others treat it as optional.
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
     * Fetch the current gold spot price per gram in IDR.
     *
     * @return array{price_per_gram_idr: float, change: float|null,
     *     change_percent: float|null, updated_at: int|null}|null
     *     Spot price row, or null when it cannot be priced.
     */
    public function fetchSpot(): ?array;
}
