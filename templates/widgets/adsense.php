<?php
/**
 * AdSense ad widget.
 *
 * Variables (extracted by WidgetBase::renderPartial):
 * @var string $ad_html Filtered ad code (wp_kses allowlist, see
 *     AdSenseWidget::filterAdCode). Printed unescaped on purpose:
 *     it contains the AdSense script/ins tags and was already
 *     filtered to the allowlist on save.
 * @var bool $configured Whether an ad is configured.
 */
declare(strict_types=1);

?>
<div class="widget-adsense">
    <?php if ($configured) : ?>
        <?php echo $ad_html; ?>
    <?php else : ?>
        <p class="widget-empty">
            <?php esc_html_e('This ad has not been configured yet.', 'traydigita-core'); ?>
        </p>
    <?php endif; ?>
</div>
