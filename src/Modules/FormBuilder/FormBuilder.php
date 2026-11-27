<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FormBuilder;

use function __;
use function array_map;
use function count;
use function esc_attr;
use function esc_html;
use function esc_textarea;
use function esc_url;
use function esc_url_raw;
use function explode;
use function filter_var;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sprintf;
use function str_replace;
use function trim;
use function wp_nonce_field;

/**
 * Reusable form field builder.
 *
 * A form definition is one plain array:
 *
 *     [
 *         'fields' => [
 *             [
 *                 'id'          => 'name',   // machine name, a-z0-9_
 *                 'type'        => 'text',   // see FIELD_TYPES
 *                 'label'       => 'Name',
 *                 'required'    => true,
 *                 'placeholder' => '',
 *                 'options'     => [],       // select / radio / checkbox
 *                 'default'     => '',
 *             ],
 *         ],
 *     ]
 *
 * The same helpers are used by the admin builder, the frontend
 * renderer and every submission validator (Contact Form, generic
 * shortcode forms), so a form can never render a field it cannot
 * validate, and validation can never accept a value the renderer
 * did not offer. Definitions are sanitized on save
 * (sanitizeDefinition()) and re-validated on every render, so a
 * stored definition edited by hand in the database is still safe.
 *
 * Spam protection is NOT part of a definition: the calling module
 * (Contact Form) adds Turnstile / reCAPTCHA - each from its own
 * module - around the rendered form and verifies the token
 * before calling validateSubmission().
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\FormBuilder
 */
final class FormBuilder
{
    /**
     * Allowlisted field types.
     *
     * @var array<int, string>
     */
    public const array FIELD_TYPES = [
        'text',
        'email',
        'url',
        'tel',
        'number',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'date',
        'hidden',
    ];

    /**
     * Maximum number of fields in one form.
     */
    public const int MAX_FIELDS = 30;

    /**
     * Maximum number of options on one choice field.
     */
    public const int MAX_OPTIONS = 50;

    /**
     * Maximum length (characters) of a submitted text value.
     */
    public const int MAX_VALUE_LENGTH = 5000;

    /**
     * Honeypot field name. Bots fill it; humans never see it
     * (it is hidden with CSS and aria-hidden). A non-empty value
     * rejects the submission before any other check.
     */
    public const string HONEYPOT_FIELD = 'td_website';

    /**
     * Nonce action for frontend form submissions.
     */
    public const string NONCE_ACTION = 'traydigita_form_builder_submit';

    /**
     * Translatable display labels for the field types.
     *
     * @return array<string, string> Field type => label.
     */
    public static function fieldLabels(): array
    {
        return [
            'text' => __('Text', 'traydigita-core'),
            'email' => __('Email', 'traydigita-core'),
            'url' => __('URL', 'traydigita-core'),
            'tel' => __('Phone', 'traydigita-core'),
            'number' => __('Number', 'traydigita-core'),
            'textarea' => __('Long text', 'traydigita-core'),
            'select' => __('Dropdown', 'traydigita-core'),
            'radio' => __('Radio buttons', 'traydigita-core'),
            'checkbox' => __('Checkboxes', 'traydigita-core'),
            'date' => __('Date', 'traydigita-core'),
            'hidden' => __('Hidden', 'traydigita-core'),
        ];
    }

    /**
     * Deeply sanitize untrusted input into a form definition.
     *
     * Unknown field types, duplicate field ids, empty labels and
     * unknown keys are dropped. Choice fields keep only their
     * allowlisted options. A definition with no valid field at
     * all is rejected.
     *
     * @param mixed $input Untrusted input (usually the posted form).
     * @return array{definition: array<string, mixed>|null, error: string|null}
     */
    public static function sanitizeDefinition(mixed $input): array
    {
        if (!is_array($input)) {
            return [
                'definition' => null,
                'error' => __('The form definition is invalid.', 'traydigita-core'),
            ];
        }
        $fields = $input['fields'] ?? null;
        if (!is_array($fields)) {
            return [
                'definition' => null,
                'error' => __('The form has no fields.', 'traydigita-core'),
            ];
        }
        $sanitized = [];
        $seenIds = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $clean = self::sanitizeField($field);
            if ($clean === null) {
                continue;
            }
            if (in_array($clean['id'], $seenIds, true)) {
                continue;
            }
            $seenIds[] = $clean['id'];
            $sanitized[] = $clean;
            if (count($sanitized) >= self::MAX_FIELDS) {
                break;
            }
        }
        if ($sanitized === []) {
            return [
                'definition' => null,
                'error' => __('The form has no valid fields.', 'traydigita-core'),
            ];
        }
        return [
            'definition' => ['fields' => $sanitized],
            'error' => null,
        ];
    }

    /**
     * Sanitize one field; returns null when the field must be dropped.
     *
     * @param array<string, mixed> $field Raw field.
     * @return array<string, mixed>|null
     */
    private static function sanitizeField(array $field): ?array
    {
        $type = $field['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::FIELD_TYPES, true)) {
            return null;
        }
        $id = isset($field['id']) && is_string($field['id']) ? sanitize_key($field['id']) : '';
        if ($id === '' || preg_match('/^[a-z][a-z0-9_]*$/', $id) !== 1) {
            return null;
        }
        $label = isset($field['label']) && is_string($field['label']) ? sanitize_text_field($field['label']) : '';
        if ($label === '' && $type !== 'hidden') {
            return null;
        }
        $options = [];
        if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
            $rawOptions = $field['options'] ?? [];
            if (is_string($rawOptions)) {
                // One option per line, as posted by the editor textarea.
                $rawOptions = array_map('trim', explode("\n", str_replace("\r\n", "\n", $rawOptions)));
            }
            if (is_array($rawOptions)) {
                foreach ($rawOptions as $option) {
                    if (!is_string($option)) {
                        continue;
                    }
                    $option = sanitize_text_field($option);
                    if ($option === '' || in_array($option, $options, true)) {
                        continue;
                    }
                    $options[] = $option;
                    if (count($options) >= self::MAX_OPTIONS) {
                        break;
                    }
                }
            }
            if ($options === []) {
                return null;
            }
        }
        $default = isset($field['default']) && is_string($field['default']) ? sanitize_text_field($field['default']) : '';
        if ($default !== '' && $options !== [] && !in_array($default, $options, true)) {
            $default = '';
        }
        return [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'required' => !empty($field['required']),
            'placeholder' => isset($field['placeholder']) && is_string($field['placeholder']) ? sanitize_text_field($field['placeholder']) : '',
            'options' => $options,
            'default' => $default,
        ];
    }

    /**
     * Structural validation of a definition array.
     *
     * @param array<string, mixed> $definition Definition to check.
     * @return bool True when the shape is valid.
     */
    public static function validateDefinition(array $definition): bool
    {
        $fields = $definition['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || count($fields) > self::MAX_FIELDS) {
            return false;
        }
        $seenIds = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                return false;
            }
            if (self::sanitizeField($field) === null) {
                return false;
            }
            if (in_array($field['id'], $seenIds, true)) {
                return false;
            }
            $seenIds[] = $field['id'];
        }
        return true;
    }

    /**
     * Render a form definition as HTML.
     *
     * The form posts to $actionUrl with a nonce and a honeypot
     * field. Every value is escaped; the caller must verify the
     * nonce and call validateSubmission() on the posted data.
     *
     * @param array<string, mixed> $definition Sanitized definition.
     * @param string $actionUrl Form action URL.
     * @param string $submitLabel Submit button label.
     * @param array<string, string> $hidden Extra hidden fields (name => value).
     * @param string $beforeSubmit Extra HTML printed before the submit
     *                             button (e.g. a spam-protection widget).
     * @return string Rendered form HTML (empty when the definition is invalid).
     */
    public static function renderForm(array $definition, string $actionUrl, string $submitLabel = '', array $hidden = [], string $beforeSubmit = ''): string
    {
        if (!self::validateDefinition($definition)) {
            return '';
        }
        if ($submitLabel === '') {
            $submitLabel = __('Submit', 'traydigita-core');
        }
        $html = '<form class="traydigita-form" method="post" action="' . esc_url($actionUrl) . '">';
        $html .= wp_nonce_field(self::NONCE_ACTION, 'traydigita_form_nonce', true, false);
        foreach ($hidden as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr(sanitize_key($name)) . '" value="' . esc_attr($value) . '">';
        }
        // Honeypot: hidden from humans, irresistible to bots.
        $html .= '<div class="traydigita-form-honeypot" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">'
            . '<label>' . esc_html__('Leave this field empty', 'traydigita-core')
            . '<input type="text" name="' . esc_attr(self::HONEYPOT_FIELD) . '" value="" tabindex="-1" autocomplete="off"></label></div>';
        foreach ($definition['fields'] as $field) {
            $html .= self::renderField($field);
        }
        // $beforeSubmit is trusted markup from the calling module
        // (a Turnstile / reCAPTCHA widget from its own module); it is never user input.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $beforeSubmit is module-generated widget markup, not user input.
        $html .= $beforeSubmit;
        $html .= '<p class="traydigita-form-actions"><button type="submit" class="traydigita-form-submit">' . esc_html($submitLabel) . '</button></p>';
        $html .= '</form>';
        return $html;
    }

    /**
     * Render one field.
     *
     * @param array<string, mixed> $field Sanitized field.
     * @return string Field HTML.
     */
    private static function renderField(array $field): string
    {
        $id = (string) $field['id'];
        $type = (string) $field['type'];
        $label = (string) $field['label'];
        $required = !empty($field['required']);
        $placeholder = (string) ($field['placeholder'] ?? '');
        $default = (string) ($field['default'] ?? '');
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $name = 'td_field[' . $id . ']';
        $fieldId = 'traydigita-field-' . $id;
        $requiredAttr = $required ? ' required aria-required="true"' : '';
        $placeholderAttr = $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '';
        if ($type === 'hidden') {
            return '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($default) . '">';
        }
        $html = '<p class="traydigita-form-field traydigita-form-field-' . esc_attr($type) . '">';
        $html .= '<label for="' . esc_attr($fieldId) . '">' . esc_html($label);
        if ($required) {
            $html .= ' <span class="traydigita-form-required" aria-hidden="true">*</span>';
        }
        $html .= '</label>';
        if ($type === 'textarea') {
            $html .= '<textarea id="' . esc_attr($fieldId) . '" name="' . esc_attr($name) . '"' . $requiredAttr . $placeholderAttr . ' rows="5">' . esc_textarea($default) . '</textarea>';
        } elseif ($type === 'select') {
            $html .= '<select id="' . esc_attr($fieldId) . '" name="' . esc_attr($name) . '"' . $requiredAttr . '>';
            if (!$required) {
                $html .= '<option value="">' . esc_html__('— Select —', 'traydigita-core') . '</option>';
            }
            foreach ($options as $option) {
                $selected = $default === $option ? ' selected' : '';
                $html .= '<option value="' . esc_attr($option) . '"' . $selected . '>' . esc_html($option) . '</option>';
            }
            $html .= '</select>';
        } elseif ($type === 'radio') {
            foreach ($options as $index => $option) {
                $optionId = $fieldId . '-' . $index;
                $checked = $default === $option ? ' checked' : '';
                $html .= '<span class="traydigita-form-choice"><input type="radio" id="' . esc_attr($optionId) . '" name="' . esc_attr($name) . '" value="' . esc_attr($option) . '"' . $checked . $requiredAttr . '> <label for="' . esc_attr($optionId) . '">' . esc_html($option) . '</label></span>';
            }
        } elseif ($type === 'checkbox') {
            foreach ($options as $index => $option) {
                $optionId = $fieldId . '-' . $index;
                $checked = $default === $option ? ' checked' : '';
                $html .= '<span class="traydigita-form-choice"><input type="checkbox" id="' . esc_attr($optionId) . '" name="' . esc_attr($name) . '[]" value="' . esc_attr($option) . '"' . $checked . '> <label for="' . esc_attr($optionId) . '">' . esc_html($option) . '</label></span>';
            }
        } else {
            $inputType = $type === 'number' ? 'number' : $type;
            $html .= '<input type="' . esc_attr($inputType) . '" id="' . esc_attr($fieldId) . '" name="' . esc_attr($name) . '" value="' . esc_attr($default) . '"' . $requiredAttr . $placeholderAttr . '>';
        }
        $html .= '</p>';
        return $html;
    }

    /**
     * Validate a submission against a definition.
     *
     * Checks the honeypot first (bots are rejected without
     * revealing which field failed), then every field: required,
     * email / URL format, numbers, and choice allowlists. Values
     * are sanitized per type and returned keyed by field id.
     *
     * @param array<string, mixed> $definition Sanitized definition.
     * @param array<string, mixed> $data Posted data: either the
     *     unwrapped `td_field` array itself (what both AJAX
     *     handlers pass), or the full POST array wrapping it
     *     under `td_field`. Both shapes are accepted so the
     *     contract cannot silently validate an empty field list.
     * @return array{values: array<string, mixed>, errors: array<string, string>, honeypot: bool}
     */
    public static function validateSubmission(array $definition, array $data): array
    {
        $values = [];
        $errors = [];
        $honeypot = false;
        $fields = isset($data['td_field']) && is_array($data['td_field']) ? $data['td_field'] : $data;
        if (!empty($data[self::HONEYPOT_FIELD]) || !empty($fields[self::HONEYPOT_FIELD])) {
            // A filled honeypot is a bot: reject everything.
            $honeypot = true;
            return ['values' => [], 'errors' => ['_honeypot' => __('Spam detected.', 'traydigita-core')], 'honeypot' => true];
        }
        if (!is_array($fields)) {
            $fields = [];
        }
        if (!self::validateDefinition($definition)) {
            return ['values' => [], 'errors' => ['_form' => __('The form definition is invalid.', 'traydigita-core')], 'honeypot' => false];
        }
        foreach ($definition['fields'] as $field) {
            $id = (string) $field['id'];
            $type = (string) $field['type'];
            $label = (string) $field['label'];
            $required = !empty($field['required']);
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            $raw = $fields[$id] ?? null;
            if ($type === 'checkbox') {
                $raw = is_array($raw) ? $raw : ($raw === null ? [] : [$raw]);
                $clean = [];
                foreach ($raw as $choice) {
                    if (!is_string($choice)) {
                        continue;
                    }
                    $choice = sanitize_text_field($choice);
                    if ($choice !== '' && in_array($choice, $options, true) && !in_array($choice, $clean, true)) {
                        $clean[] = $choice;
                    }
                }
                if ($required && $clean === []) {
                    $errors[$id] = sprintf(
                        /* translators: %s: field label. */
                        __('%s is required.', 'traydigita-core'),
                        $label
                    );
                }
                $values[$id] = $clean;
                continue;
            }
            $value = is_string($raw) ? trim($raw) : '';
            if ($value === '') {
                if ($required) {
                    $errors[$id] = sprintf(
                        /* translators: %s: field label. */
                        __('%s is required.', 'traydigita-core'),
                        $label
                    );
                }
                $values[$id] = '';
                continue;
            }
            if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $errors[$id] = sprintf(
                    /* translators: %s: field label. */
                    __('%s is too long.', 'traydigita-core'),
                    $label
                );
                $values[$id] = '';
                continue;
            }
            if ($type === 'email') {
                $email = sanitize_text_field($value);
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[$id] = sprintf(
                        /* translators: %s: field label. */
                        __('%s must be a valid email address.', 'traydigita-core'),
                        $label
                    );
                    $values[$id] = '';
                    continue;
                }
                $values[$id] = $email;
            } elseif ($type === 'url') {
                $url = esc_url_raw($value);
                if ($url === '') {
                    $errors[$id] = sprintf(
                        /* translators: %s: field label. */
                        __('%s must be a valid URL.', 'traydigita-core'),
                        $label
                    );
                    $values[$id] = '';
                    continue;
                }
                $values[$id] = $url;
            } elseif ($type === 'number') {
                if (!is_numeric($value)) {
                    $errors[$id] = sprintf(
                        /* translators: %s: field label. */
                        __('%s must be a number.', 'traydigita-core'),
                        $label
                    );
                    $values[$id] = '';
                    continue;
                }
                $values[$id] = $value;
            } elseif (in_array($type, ['select', 'radio'], true)) {
                if (!in_array($value, $options, true)) {
                    $errors[$id] = sprintf(
                        /* translators: %s: field label. */
                        __('%s has an invalid choice.', 'traydigita-core'),
                        $label
                    );
                    $values[$id] = '';
                    continue;
                }
                $values[$id] = sanitize_text_field($value);
            } elseif ($type === 'date') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                    $errors[$id] = sprintf(
                        /* translators: %s: field label. */
                        __('%s must be a valid date.', 'traydigita-core'),
                        $label
                    );
                    $values[$id] = '';
                    continue;
                }
                $values[$id] = $value;
            } else {
                $values[$id] = $type === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value);
            }
        }
        return ['values' => $values, 'errors' => $errors, 'honeypot' => $honeypot];
    }
}
