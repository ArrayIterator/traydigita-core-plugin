<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Widgets;

use TrayDigita\WP\Plugin\Core\Libs\ThemeImage;
use WP_Post;
use WP_Widget;
use function __;
use function absint;
use function admin_url;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function checked;
use function esc_attr;
use function esc_html;
use function esc_textarea;
use function esc_url;
use function explode;
use function file_exists;
use function get_term;
use function get_the_author_meta;
use function get_the_date;
use function get_the_excerpt;
use function get_the_permalink;
use function get_the_title;
use function get_transient;
use function has_term;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function is_wp_error;
use function ob_get_clean;
use function ob_start;
use function sanitize_key;
use function sanitize_text_field;
use function selected;
use function set_transient;
use function delete_transient;
use function wp_create_nonce;

/**
 * Shared base class for every TrayDigita classic widget.
 *
 * Responsibilities:
 * - Render the widget through a `templates/widgets/` partial, so the
 *   frontend render and the AJAX live preview share exactly the same
 *   markup (see renderContent()).
 * - Transient-cache helpers (widgets render from cache only).
 * - Admin form helpers, including Select2 AJAX term pickers and the
 *   live preview box (wired up by `resources/js/widgets-admin.ts`).
 * - Shared post-query and post-row normalisation for the post widgets.
 *
 * Templates receive their data as extracted variables; each partial
 * documents its variables in its header comment. Templates use
 * semantic CSS classes only — no Tailwind utilities.
 */
abstract class WidgetBase extends WP_Widget
{
    /**
     * Nonce action shared by all widget AJAX endpoints.
     */
    public const string NONCE_ACTION = 'traydigita-widgets';

    /**
     * Orderby values allowed in post widgets.
     *
     * @var string[]
     */
    public const array ORDERBY_VALUES = ['date', 'title', 'comment_count', 'rand'];

    /**
     * Orderby values allowed in the Popular Posts widget (adds views).
     *
     * @var string[]
     */
    public const array POPULAR_ORDERBY_VALUES = ['views', 'date', 'title', 'comment_count', 'rand'];

    /**
     * Partial name (without extension) under `templates/widgets/`.
     *
     * @return string
     */
    abstract protected function getPartial(): string;

    /**
     * Build the data array passed to the widget partial.
     *
     * This method must not perform network requests: it reads
     * transients (or the database) only.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return array<string, mixed>
     */
    abstract protected function getWidgetData(array $instance): array;

    /**
     * Render the widget on the frontend.
     *
     * @param array<string, mixed> $args Widget area arguments.
     * @param array<string, mixed> $instance Widget instance.
     * @return void
     */
    public function widget($args, $instance): void
    {
        $instance = $this->sanitizeInstance($instance);
        echo $args['before_widget'] ?? '';
        $title = $instance['title'] ?? '';
        if ($title !== '') {
            echo ($args['before_title'] ?? '') . esc_html($title) . ($args['after_title'] ?? '');
        }
        echo $this->renderContent($instance);
        echo $args['after_widget'] ?? '';
    }

    /**
     * Render the widget body (without area wrappers or title) as HTML.
     *
     * Used by widget() and by the AJAX live preview endpoint, so both
     * render through the same partial.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return string
     */
    public function renderContent(array $instance): string
    {
        $data = $this->getWidgetData($instance);
        $data['widget'] = $this;
        $data['instance'] = $instance;
        return $this->renderPartial($this->getPartial(), $data);
    }

    /**
     * Sanitize a raw instance array the same way update() does.
     *
     * Subclasses override update(); this helper keeps widget() and the
     * preview endpoint consistent with it.
     *
     * @param mixed $instance Raw instance data.
     * @return array<string, mixed>
     */
    public function sanitizeInstance(mixed $instance): array
    {
        if (!is_array($instance)) {
            return [];
        }
        return $this->update($instance, []);
    }

    /**
     * Render a partial from `templates/widgets/`.
     *
     * @param string $partial Partial name without extension.
     * @param array<string, mixed> $data Variables exposed to the partial.
     * @return string Rendered HTML, or an empty string when missing.
     */
    protected function renderPartial(string $partial, array $data): string
    {
        $partial = sanitize_key($partial);
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/widgets/' . $partial . '.php';
        if (!file_exists($file)) {
            return '';
        }
        ob_start();
        extract($data, EXTR_SKIP);
        require $file;
        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * Read a namespaced transient.
     *
     * @param string $key Transient key suffix (prefixed automatically).
     * @return mixed Stored value, or false on a miss.
     */
    protected function getCached(string $key): mixed
    {
        return get_transient('traydigita_' . $key);
    }

    /**
     * Store a namespaced transient.
     *
     * @param string $key Transient key suffix (prefixed automatically).
     * @param mixed $value Value to store.
     * @param int $ttl Time to live in seconds.
     * @return bool
     */
    protected function setCached(string $key, mixed $value, int $ttl): bool
    {
        return set_transient('traydigita_' . $key, $value, $ttl);
    }

    /**
     * Delete a namespaced transient.
     *
     * @param string $key Transient key suffix (prefixed automatically).
     * @return bool
     */
    protected function deleteCached(string $key): bool
    {
        return delete_transient('traydigita_' . $key);
    }

    /**
     * Clamp an article count to the allowed range.
     *
     * @param mixed $value Raw value.
     * @param int $default Fallback when invalid.
     * @param int $min Minimum allowed.
     * @param int $max Maximum allowed.
     * @return int
     */
    protected function sanitizeCount(mixed $value, int $default = 5, int $min = 1, int $max = 20): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        $count = (int) $value;
        if ($count < $min) {
            return $min;
        }
        if ($count > $max) {
            return $max;
        }
        return $count;
    }

    /**
     * Sanitize an orderby value against an allowlist.
     *
     * @param mixed $value Raw value.
     * @param string[] $allowed Allowed values.
     * @param string $default Fallback when invalid.
     * @return string
     */
    protected function sanitizeOrderby(mixed $value, array $allowed, string $default = 'date'): string
    {
        $value = is_string($value) ? sanitize_key($value) : '';
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Sanitize a list of term ids (array or comma-separated string).
     *
     * @param mixed $value Raw value.
     * @return int[]
     */
    protected function sanitizeTermIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $ids = array_map(static fn(mixed $id): int => absint($id), $value);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    /**
     * Sanitize a checkbox value.
     *
     * @param mixed $value Raw value.
     * @return bool
     */
    protected function sanitizeBool(mixed $value): bool
    {
        return $value === '1' || $value === 1 || $value === true || $value === 'on';
    }

    /**
     * Human-readable orderby labels for post widget forms.
     *
     * @param bool $withViews Include the views option (Popular Posts only).
     * @return array<string, string>
     */
    public static function getOrderbyOptions(bool $withViews = false): array
    {
        $options = [
            'date' => __('Date', 'traydigita-core'),
            'title' => __('Title', 'traydigita-core'),
            'comment_count' => __('Comment count', 'traydigita-core'),
            'rand' => __('Random', 'traydigita-core'),
        ];
        if ($withViews) {
            $options = ['views' => __('Views', 'traydigita-core')] + $options;
        }
        return $options;
    }

    /**
     * Query posts for a post widget from its instance settings.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @param array<string, mixed> $extraArgs Extra WP_Query arguments.
     * @return WP_Post[]
     */
    protected function queryPosts(array $instance, array $extraArgs = []): array
    {
        $count = $this->sanitizeCount($instance['count'] ?? 5);
        $orderby = $this->sanitizeOrderby(
            $instance['orderby'] ?? 'date',
            self::ORDERBY_VALUES,
            'date'
        );
        $termIds = $this->sanitizeTermIds($instance['term_ids'] ?? []);
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'orderby' => $orderby,
            'order' => $orderby === 'title' ? 'ASC' : 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ];
        if ($termIds !== []) {
            $args['tax_query'] = [
                'relation' => 'OR',
                [
                    'taxonomy' => 'category',
                    'field' => 'term_id',
                    'terms' => $termIds,
                ],
                [
                    'taxonomy' => 'post_tag',
                    'field' => 'term_id',
                    'terms' => $termIds,
                ],
            ];
        }
        $query = new \WP_Query(array_merge($args, $extraArgs));
        $posts = $query->posts ?? [];
        return array_values(array_filter($posts, static fn($post): bool => $post instanceof \WP_Post));
    }

    /**
     * Normalize a post into a plain row for the post-list partial.
     *
     * The thumbnail is resolved once through ThemeImage (stack
     * FeatureImage helper): $thumbnail is a FeatureImage-shaped
     * array when the caller already holds one (Popular Posts
     * records), otherwise the post is resolved here. thumbnail_html
     * is the complete escaped <img> tag; thumbnail_url is the same
     * image's (Photon-aware) URL, kept for rows cached before this
     * field existed.
     *
     * @param WP_Post $post Post object.
     * @param int $views View count (Popular Posts only, 0 otherwise).
     * @param string|null $thumbnailUrl Pre-resolved thumbnail URL, if any.
     * @param array{id: int, url: string, width: int, height: int, alt: ?string}|null $thumbnail
     *     Pre-resolved thumbnail array, if any.
     * @return array{
     *     id: int, title: string, url: string, date: string,
     *     excerpt: string, comment_count: int, views: int,
     *     thumbnail_url: string, thumbnail_html: string, thumbnail_alt: string, author: string
     * }
     */
    protected function normalizePost(WP_Post $post, int $views = 0, ?string $thumbnailUrl = null, ?array $thumbnail = null): array
    {
        $postId = $post->ID;
        $size = ThemeImage::slotSize('list');
        $thumbnail ??= ThemeImage::thumbnailArray($post, $size);
        $thumbnailUrl ??= ThemeImage::urlFromArray($thumbnail, $size);
        $author = get_the_author_meta('display_name', (int) $post->post_author);
        return [
            'id' => $postId,
            'title' => get_the_title($postId),
            'url' => (string) get_the_permalink($postId),
            'date' => (string) get_the_date('', $postId),
            'excerpt' => get_the_excerpt($postId),
            'comment_count' => (int) $post->comment_count,
            'views' => $views,
            'thumbnail_url' => $thumbnailUrl,
            'thumbnail_html' => ThemeImage::thumbnailFromArray($thumbnail, $size),
            'thumbnail_alt' => get_the_title($postId),
            'author' => is_string($author) ? $author : '',
        ];
    }

    /**
     * Check whether a post belongs to any of the given terms.
     *
     * @param int $postId Post id.
     * @param int[] $termIds Term ids to match.
     * @return bool
     */
    protected function postMatchesTerms(int $postId, array $termIds): bool
    {
        if ($termIds === []) {
            return true;
        }
        foreach (['category', 'post_tag'] as $taxonomy) {
            if (has_term($termIds, $taxonomy, $postId)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Admin form helpers. All helpers echo their markup (WP_Widget::form
    // convention) and escape every value.
    // ------------------------------------------------------------------

    /**
     * Text input field.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @param string $key Instance key.
     * @param string $label Field label.
     * @param string $default Default value.
     * @return void
     */
    protected function textField(array $instance, string $key, string $label, string $default = ''): void
    {
        $value = (string) ($instance[$key] ?? $default);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id($key)); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <input class="widefat" type="text"
                id="<?php echo esc_attr($this->get_field_id($key)); ?>"
                name="<?php echo esc_attr($this->get_field_name($key)); ?>"
                value="<?php echo esc_attr($value); ?>">
        </p>
        <?php
    }

    /**
     * Textarea field.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @param string $key Instance key.
     * @param string $label Field label.
     * @param string $description Help text shown below the field.
     * @param string $default Default value.
     * @param int $rows Visible rows.
     * @return void
     */
    protected function textareaField(
        array $instance,
        string $key,
        string $label,
        string $description = '',
        string $default = '',
        int $rows = 4
    ): void {
        $value = (string) ($instance[$key] ?? $default);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id($key)); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <textarea class="widefat" rows="<?php echo esc_attr((string) $rows); ?>"
                id="<?php echo esc_attr($this->get_field_id($key)); ?>"
                name="<?php echo esc_attr($this->get_field_name($key)); ?>"><?php
                echo esc_textarea($value);
                ?></textarea>
            <?php if ($description !== '') : ?>
                <small><?php echo esc_html($description); ?></small>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Number input field.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @param string $key Instance key.
     * @param string $label Field label.
     * @param int $default Default value.
     * @param int $min Minimum value.
     * @param int $max Maximum value.
     * @return void
     */
    protected function numberField(
        array $instance,
        string $key,
        string $label,
        int $default = 5,
        int $min = 1,
        int $max = 20
    ): void {
        $value = $this->sanitizeCount($instance[$key] ?? $default, $default, $min, $max);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id($key)); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <input class="tiny-text" type="number" step="1"
                min="<?php echo esc_attr((string) $min); ?>"
                max="<?php echo esc_attr((string) $max); ?>"
                id="<?php echo esc_attr($this->get_field_id($key)); ?>"
                name="<?php echo esc_attr($this->get_field_name($key)); ?>"
                value="<?php echo esc_attr((string) $value); ?>">
        </p>
        <?php
    }

    /**
     * Checkbox field.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @param string $key Instance key.
     * @param string $label Field label.
     * @param bool $default Default value.
     * @return void
     */
    protected function checkboxField(array $instance, string $key, string $label, bool $default = true): void
    {
        $checkedValue = isset($instance[$key]) ? $this->sanitizeBool($instance[$key]) : $default;
        ?>
        <p>
            <input type="checkbox" value="1"
                id="<?php echo esc_attr($this->get_field_id($key)); ?>"
                name="<?php echo esc_attr($this->get_field_name($key)); ?>"
                <?php checked($checkedValue); ?>>
            <label for="<?php echo esc_attr($this->get_field_id($key)); ?>">
                <?php echo esc_html($label); ?>
            </label>
        </p>
        <?php
    }

    /**
     * Select field.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @param string $key Instance key.
     * @param string $label Field label.
     * @param array<int|string, string> $options Value => label pairs.
     * @param string $default Default value.
     * @return void
     */
    protected function selectField(
        array $instance,
        string $key,
        string $label,
        array $options,
        string $default = ''
    ): void {
        $value = (string) ($instance[$key] ?? $default);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id($key)); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <select class="widefat"
                id="<?php echo esc_attr($this->get_field_id($key)); ?>"
                name="<?php echo esc_attr($this->get_field_name($key)); ?>">
                <?php foreach ($options as $optionValue => $optionLabel) : ?>
                    <option value="<?php echo esc_attr((string) $optionValue); ?>"
                        <?php selected($value, $optionValue); ?>>
                        <?php echo esc_html($optionLabel); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }

    /**
     * Multiple Select2 term picker (categories and/or tags).
     *
     * Options are loaded via AJAX by `resources/js/widgets-admin.ts`;
     * only the already-selected terms are rendered as options, so the
     * form never pre-loads every term in the database.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @param string $key Instance key (stores an array of term ids).
     * @param string $label Field label.
     * @param string $taxonomy Taxonomy to search ("category", "post_tag", or "any").
     * @return void
     */
    protected function termSelectField(
        array $instance,
        string $key,
        string $label,
        string $taxonomy = 'any'
    ): void {
        $selectedIds = $this->sanitizeTermIds($instance[$key] ?? []);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id($key)); ?>">
                <?php echo esc_html($label); ?>
            </label>
            <select class="widefat traydigita-select2-terms" multiple="multiple"
                data-taxonomy="<?php echo esc_attr($taxonomy); ?>"
                data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>"
                id="<?php echo esc_attr($this->get_field_id($key)); ?>"
                name="<?php echo esc_attr($this->get_field_name($key)); ?>[]">
                <?php foreach ($selectedIds as $termId) : ?>
                    <?php $term = get_term($termId); ?>
                    <?php if ($term && !is_wp_error($term)) : ?>
                        <option value="<?php echo esc_attr((string) $termId); ?>" selected="selected">
                            <?php echo esc_html(is_object($term) ? $term->name : ''); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <small>
                <?php esc_html_e('Type at least 2 characters to search categories and tags.', 'traydigita-core'); ?>
            </small>
        </p>
        <?php
    }

    /**
     * Live preview box, refreshed via AJAX by `widgets-admin.ts`.
     *
     * The preview renders with the same partial as the frontend.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @return void
     */
    protected function previewField(array $instance): void
    {
        ?>
        <div class="widget-preview"
            data-widget-id-base="<?php echo esc_attr($this->id_base); ?>"
            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
            <p class="widget-preview-label">
                <strong><?php esc_html_e('Live preview', 'traydigita-core'); ?></strong>
            </p>
            <div class="widget-preview-content">
                <?php echo $this->renderContent($instance); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Shared form fields for every post widget.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @param bool $withViews Include the views orderby option.
     * @return void
     */
    protected function postWidgetFormFields(array $instance, bool $withViews = false): void
    {
        $this->textField($instance, 'title', __('Title:', 'traydigita-core'));
        $this->termSelectField(
            $instance,
            'term_ids',
            __('Filter by categories or tags:', 'traydigita-core'),
            'any'
        );
        $this->selectField(
            $instance,
            'orderby',
            __('Order by:', 'traydigita-core'),
            self::getOrderbyOptions($withViews),
            $withViews ? 'views' : 'date'
        );
        $this->numberField($instance, 'count', __('Number of articles:', 'traydigita-core'), 5, 1, 20);
        $this->checkboxField($instance, 'show_thumbnail', __('Show thumbnail', 'traydigita-core'), true);
    }

    /**
     * Shared sanitization for every post widget instance.
     *
     * @param array<string, mixed> $newInstance Raw submitted instance.
     * @param bool $withViews Whether the views orderby is allowed.
     * @return array<string, mixed>
     */
    protected function sanitizePostInstance(array $newInstance, bool $withViews = false): array
    {
        return [
            'title' => sanitize_text_field($newInstance['title'] ?? ''),
            'term_ids' => $this->sanitizeTermIds($newInstance['term_ids'] ?? []),
            'orderby' => $this->sanitizeOrderby(
                $newInstance['orderby'] ?? 'date',
                $withViews ? self::POPULAR_ORDERBY_VALUES : self::ORDERBY_VALUES,
                $withViews ? 'views' : 'date'
            ),
            'count' => $this->sanitizeCount($newInstance['count'] ?? 5),
            'show_thumbnail' => $this->sanitizeBool($newInstance['show_thumbnail'] ?? false),
        ];
    }
}
