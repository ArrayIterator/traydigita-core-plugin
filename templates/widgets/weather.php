<?php
/**
 * Weather widget (Open-Meteo carousel).
 *
 * Variables (extracted by WidgetBase::renderPartial):
 * @var array<int, array{name: string, latitude: float, longitude: float,
 *     temperature: float|null, weather_code: int|null, condition: string|null,
 *     icon: string, updated_at: int|null}> $locations
 * @var string $ajax_url Admin AJAX URL for the frontend weather JS.
 * @var string $nonce Nonce for the public weather endpoints.
 *
 * Cards without a cached snapshot show a loading state; the frontend
 * JS fills them through the `traydigita_weather_current` endpoint.
 * The location list is exposed via data attributes for the shared
 * "Weather location" modal JS (Track C).
 */
declare(strict_types=1);

?>
<div class="widget-weather"
    data-weather-widget
    data-ajax-url="<?php echo esc_url($ajax_url); ?>"
    data-nonce="<?php echo esc_attr($nonce); ?>"
    data-weather-locations="<?php echo esc_attr((string) wp_json_encode($locations)); ?>">
    <div class="weather-carousel" role="region"
        aria-label="<?php esc_attr_e('Weather by city', 'traydigita-core'); ?>">
        <?php foreach ($locations as $location) : ?>
            <article class="weather-card weather-icon-<?php echo esc_attr($location['icon']); ?>"
                data-weather-card
                data-weather-name="<?php echo esc_attr($location['name']); ?>"
                data-weather-latitude="<?php echo esc_attr((string) $location['latitude']); ?>"
                data-weather-longitude="<?php echo esc_attr((string) $location['longitude']); ?>">
                <span class="weather-icon" aria-hidden="true"></span>
                <h3 class="weather-city"><?php echo esc_html($location['name']); ?></h3>
                <?php if ($location['temperature'] !== null) : ?>
                    <p class="weather-temperature">
                        <?php echo esc_html(number_format_i18n((float) $location['temperature'], 0)); ?>&deg;C
                    </p>
                    <p class="weather-condition"><?php echo esc_html((string) $location['condition']); ?></p>
                    <?php if ($location['updated_at'] !== null) : ?>
                        <p class="weather-updated">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s is the update time. */
                                    __('Updated %s', 'traydigita-core'),
                                    \TrayDigita\WP\Plugin\Core\Widgets\WeatherHelper::formatUpdatedAt(
                                        (int) $location['updated_at']
                                    )
                                )
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="weather-temperature weather-loading">
                        <?php esc_html_e('Loading weather…', 'traydigita-core'); ?>
                    </p>
                    <p class="weather-condition"></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="weather-controls">
        <button type="button" class="weather-control weather-prev"
            data-weather-prev aria-label="<?php esc_attr_e('Previous location', 'traydigita-core'); ?>">
            <?php esc_html_e('Previous', 'traydigita-core'); ?>
        </button>
        <button type="button" class="weather-control weather-next"
            data-weather-next aria-label="<?php esc_attr_e('Next location', 'traydigita-core'); ?>">
            <?php esc_html_e('Next', 'traydigita-core'); ?>
        </button>
        <button type="button" class="weather-control weather-change-location"
            data-weather-location-open>
            <?php esc_html_e('Change weather location', 'traydigita-core'); ?>
        </button>
    </div>
    <p class="weather-source"><?php esc_html_e('Source: Open-Meteo', 'traydigita-core'); ?></p>
</div>
