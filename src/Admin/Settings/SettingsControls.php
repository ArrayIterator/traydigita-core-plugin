<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin\Settings;

use function __;
use function checked;
use function disabled;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function selected;
use function str_starts_with;

/**
 * Settings control library (Round 4, R4-5; Settings Redesign).
 *
 * One renderer per control type — text, email, url, password,
 * number, textarea, select, toggle, color and checkbox groups —
 * so every module settings page renders the same markup: a
 * `.traydigita-ui-field` row with a label on the left and the
 * control on the right (see
 * resources/css/components/admin-settings-framework.css), a help
 * line under the control, and the shared
 * `.traydigita-ui-*` classes from the admin UI library.
 *
 * Help pattern (Settings Redesign): only a TITLE and a SHORT
 * description are ever visible inline. Any LONG explanation
 * goes behind a Help toggle — the framework renders the short
 * text plus a Help button, and the long text in a hidden panel
 * that the button reveals (see helpText(), intro(), about(),
 * and the `help_long` argument on every control). Module code
 * never builds its own toggle: it passes `help` (short) and
 * `help_long` (long), and the framework does the rest, so the
 * pattern is identical on every settings page. The toggle
 * behaviour lives in `initSettingsHelp()` in
 * resources/js/admin.ts; the labels come from the shell's
 * data-msg-help / data-msg-hide-help attributes.
 *
 * Every method echoes escaped markup. Labels and help text are
 * passed in already translated by the caller (the caller owns the
 * `__()` call, so the strings stay greppable for the translators);
 * values are escaped here, never by the caller. Control ids must
 * be unique per page; names are flat and match the option keys,
 * exactly as the generic AJAX save endpoint expects.
 *
 * Form Controls Redesign: every control is a modern field (see
 * resources/css/components/admin-ui.css) — an 8px radius,
 * generous padding, a hover border and an identical focus ring
 * on every type. A textarea takes `'variant' => 'code'` for
 * JSON / code content (monospace, code tint, taller minimum)
 * and `'copy' => true` to add a copy button inside the field;
 * an input takes `'mono' => true` for key-like values (VAPID
 * keys, tokens) that read better in a monospace font. Readonly
 * and disabled states are styled by the CSS, never by the
 * caller: readonly is a muted dashed field, disabled is faded
 * and inert.
 *
 * Extra HTML attributes (for example the live-preview
 * `data-cookie-preview` hooks) pass through `$args['attrs']` as
 * a `name => value` map; attribute names are allowlisted to
 * `data-*`, `aria-*`, `placeholder`, `autocomplete`,
 * `spellcheck`, `maxlength`, `min`, `max`, `step`, `rows`,
 * `required`, `disabled`, `readonly` and `pattern`.
 */
final class SettingsControls
{
    /**
     * Attribute names a control may carry through $args['attrs'].
     *
     * @var array<int, string>
     */
    private const array ALLOWED_ATTR_PREFIXES = ['data-', 'aria-'];

    /**
     * Attribute names a control may carry through $args['attrs'].
     *
     * @var array<int, string>
     */
    private const array ALLOWED_ATTRS = [
        'placeholder',
        'autocomplete',
        'spellcheck',
        'maxlength',
        'min',
        'max',
        'step',
        'rows',
        'required',
        'disabled',
        'readonly',
        'pattern',
    ];

    /**
     * Counter for unique Help-panel ids on the page.
     *
     * @var int
     */
    private static int $helpCounter = 0;

    /**
     * Next unique id for a Help panel revealed by a toggle.
     *
     * @return string
     */
    private static function nextHelpId(): string
    {
        self::$helpCounter++;
        return 'td-help-' . self::$helpCounter;
    }

    /**
     * Help-toggle button markup for a hidden long-text panel.
     *
     * The button carries `aria-controls` (the panel id) and
     * `aria-expanded`; resources/js/admin.ts flips both and
     * swaps the label between the shell's Help / Hide help
     * strings. The label span is what the script rewrites, so
     * the icon span is kept separate.
     *
     * @param string $targetId Id of the hidden panel.
     * @return string
     */
    private static function helpToggle(string $targetId): string
    {
        return '<button type="button" class="td-settings-help-toggle"'
            . ' data-td-help-toggle aria-expanded="false"'
            . ' aria-controls="' . esc_attr($targetId) . '">'
            . '<span class="material-icons" aria-hidden="true">help_outline</span>'
            . '<span class="td-settings-help-toggle-label">' . esc_html__('Help', 'traydigita-core') . '</span>'
            . '</button>';
    }

    /**
     * Split an already-translated description into short + long.
     *
     * For descriptions that arrive as one dynamic string — a
     * market provider's getDescription(), for example — where
     * the author cannot split short/long at the call site. The
     * split is the first sentence boundary (". ", "! " or "? ")
     * past 40 characters; when the text is short, or has no
     * usable boundary, the whole text is the short part and the
     * long part is null. Sentence boundaries work the same in
     * the English source and the Indonesian translation, and the
     * boundary must be followed by a space, so decimals such as
     * "1.5" never split. The msgid stays untouched, so no new
     * translation is needed for a split.
     *
     * @param string $text Already-translated description.
     * @return array{0: string, 1: string|null} Short text, long text or null.
     */
    public static function splitHelp(string $text): array
    {
        $text = trim($text);
        if (mb_strlen($text) <= 110) {
            return [$text, null];
        }
        // The 260 cap keeps the visible short part to one
        // reasonable sentence: Indonesian translations run
        // longer than the English source, and a 140 cap left
        // the (one-sentence, ~150-char) cache recommendation
        // note unsplit in id_ID.
        if (preg_match('/^(.{40,260}?[.?!])\s+(.+)$/us', $text, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }
        return [$text, null];
    }

    /**
     * Short help text with an optional long explanation behind
     * a Help toggle.
     *
     * Inline sequence for use inside any existing help wrapper
     * (a `<p class="description">`, a `<span class="…-help">`,
     * or a field help line): the short text stays visible, the
     * toggle reveals the long text in place. With no long text
     * this renders the short text alone.
     *
     * @param string $short Already-translated short help text.
     * @param string|null $long Already-translated long help text, if any.
     * @return void
     */
    public static function helpText(string $short, ?string $long = null): void
    {
        echo esc_html($short);
        if ($long === null || $long === '') {
            return;
        }
        $targetId = self::nextHelpId();
        echo ' ' . self::helpToggle($targetId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by this class from an escaped id.
        ?>
        <span class="td-settings-help-long" id="<?php echo esc_attr($targetId); ?>" hidden><?php echo esc_html($long); ?></span>
        <?php
    }

    /**
     * Inline help sequence as a string, for HTML-string contexts.
     *
     * Same output as helpText(), but returned instead of echoed,
     * so callers that build pre-escaped HTML — an AdminNotice
     * body, for example — can embed a Help toggle without
     * restructuring the notice. The short text is visible; the
     * long text sits in a hidden panel the toggle reveals.
     *
     * @param string $short Already-translated short text.
     * @param string|null $long Already-translated long text, if any.
     * @return string Escaped short text, toggle button, hidden long panel.
     */
    public static function helpBlock(string $short, ?string $long = null): string
    {
        $out = esc_html($short);
        if ($long === null || $long === '') {
            return $out;
        }
        $targetId = self::nextHelpId();
        $out .= ' ' . self::helpToggle($targetId);
        $out .= '<span class="td-settings-help-long" id="' . esc_attr($targetId) . '" hidden>'
            . esc_html($long) . '</span>';
        return $out;
    }

    /**
     * Page intro: short text visible, long text behind Help.
     *
     * For the overview paragraph at the top of a settings page.
     * The short text is the one-line summary; the long text is
     * everything else that used to sit in front of the controls.
     *
     * @param string $short Already-translated short intro text.
     * @param string|null $long Already-translated long intro text, if any.
     * @return void
     */
    public static function intro(string $short, ?string $long = null): void
    {
        ?>
        <div class="td-settings-intro">
            <p class="td-settings-intro-short"><?php echo esc_html($short); ?></p>
            <?php if ($long !== null && $long !== '') : ?>
                <?php
                $targetId = self::nextHelpId();
                echo self::helpToggle($targetId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by this class from an escaped id.
                ?>
                <div class="td-settings-help-long" id="<?php echo esc_attr($targetId); ?>" hidden>
                    <p><?php echo esc_html($long); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Titled about block: heading, short text, long HTML behind Help.
     *
     * For an intro that needs richer long content than plain text
     * (for example a list of what a feature does). The long HTML
     * is built by the caller from escaped pieces — typically a
     * `<ul>` of `esc_html()` items — and printed as-is.
     *
     * @param string $title Already-translated block title.
     * @param string $short Already-translated short text.
     * @param string $longHtml Caller-built escaped HTML, if any.
     * @return void
     */
    public static function about(string $title, string $short, string $longHtml = ''): void
    {
        ?>
        <div class="td-settings-about">
            <h3 class="td-settings-about-title"><?php echo esc_html($title); ?></h3>
            <p class="td-settings-about-short"><?php echo esc_html($short); ?></p>
            <?php if ($longHtml !== '') : ?>
                <?php
                $targetId = self::nextHelpId();
                echo self::helpToggle($targetId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by this class from an escaped id.
                ?>
                <div class="td-settings-help-long" id="<?php echo esc_attr($targetId); ?>" hidden><?php echo $longHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller-built escaped HTML, documented on this method. ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render one field row: label, control, optional help line.
     *
     * The label is linked to the control through `for` / `id`
     * unless the control is a toggle, whose label wraps the
     * checkbox itself. The help line shows only the short text;
     * a long explanation (`$helpLong`) sits behind a Help
     * toggle in a panel below the row.
     *
     * @param string $id Control id (also the label `for` value).
     * @param string $label Already-translated field label.
     * @param string $control Control markup (already escaped).
     * @param string|null $help Already-translated short help text, if any.
     * @param bool $isToggle True when the label wraps the control.
     * @param string|null $helpLong Already-translated long help text, if any.
     * @return void
     */
    public static function field(
        string $id,
        string $label,
        string $control,
        ?string $help = null,
        bool $isToggle = false,
        ?string $helpLong = null
    ): void {
        $targetId = ($helpLong !== null && $helpLong !== '') ? self::nextHelpId() : '';
        ?>
        <div class="traydigita-ui-field td-settings-field">
            <?php if ($isToggle) : ?>
                <div class="td-settings-control"><?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Control markup is built by this class from escaped values. ?></div>
            <?php else : ?>
                <label class="traydigita-ui-field-label td-settings-label" for="<?php echo esc_attr($id); ?>">
                    <?php echo esc_html($label); ?>
                </label>
                <div class="td-settings-control"><?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Control markup is built by this class from escaped values. ?></div>
            <?php endif; ?>
            <?php if ($help !== null && $help !== '') : ?>
                <p class="traydigita-ui-field-help td-settings-help"><?php echo esc_html($help); ?>
                <?php if ($targetId !== '') : ?>
                    <?php echo self::helpToggle($targetId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by this class from an escaped id. ?>
                <?php endif; ?>
                </p>
            <?php elseif ($targetId !== '') : ?>
                <?php echo self::helpToggle($targetId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by this class from an escaped id. ?>
            <?php endif; ?>
            <?php if ($targetId !== '') : ?>
                <div class="td-settings-help-long" id="<?php echo esc_attr($targetId); ?>" hidden>
                    <p><?php echo esc_html((string) $helpLong); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a single-line text-like input (text, email, url,
     * password, search) as a full field row.
     *
     * Pass `'mono' => true` for key-like values (API keys,
     * tokens) that read better in a monospace font.
     *
     * @param array{type?: string, id: string, name: string, label: string, value?: string|int, help?: string|null, help_long?: string|null, attrs?: array<string, string|bool>, mono?: bool} $args Field arguments.
     * @return void
     */
    public static function input(array $args): void
    {
        $type = $args['type'] ?? 'text';
        if (!in_array($type, ['text', 'email', 'url', 'password', 'search', 'tel', 'datetime-local'], true)) {
            $type = 'text';
        }
        $id = (string) $args['id'];
        $value = isset($args['value']) ? (string) $args['value'] : '';
        $attrs = self::attrs($args['attrs'] ?? []);
        if ($type === 'password' && $value === '') {
            $attrs .= ' value=""';
        } else {
            $attrs .= ' value="' . esc_attr($value) . '"';
        }
        $class = self::inputClass($args, 'traydigita-ui-input');
        if (!empty($args['mono'])) {
            $class .= ' traydigita-ui-input-mono';
        }
        $control = '<input class="' . esc_attr($class) . '" type="' . esc_attr($type) . '"'
            . ' id="' . esc_attr($id) . '"'
            . ' name="' . esc_attr((string) $args['name']) . '"'
            . $attrs . '>';
        self::field($id, (string) $args['label'], $control, $args['help'] ?? null, false, $args['help_long'] ?? null);
    }

    /**
     * Render a number input as a full field row.
     *
     * @param array{id: string, name: string, label: string, value?: int|string, help?: string|null, help_long?: string|null, attrs?: array<string, string|bool>} $args Field arguments.
     * @return void
     */
    public static function number(array $args): void
    {
        $id = (string) $args['id'];
        $attrs = self::attrs($args['attrs'] ?? []);
        $control = '<input class="' . esc_attr(self::inputClass($args, 'traydigita-ui-input')) . '" type="number"'
            . ' id="' . esc_attr($id) . '"'
            . ' name="' . esc_attr((string) $args['name']) . '"'
            . ' value="' . esc_attr((string) ($args['value'] ?? '')) . '"'
            . $attrs . '>';
        self::field($id, (string) $args['label'], $control, $args['help'] ?? null, false, $args['help_long'] ?? null);
    }

    /**
     * Render a textarea as a full field row.
     *
     * Pass `'variant' => 'code'` for JSON / code content
     * (monospace, code tint, taller minimum) and `'copy' => true`
     * to add a copy button inside the field — the button copies
     * the textarea value; the behaviour lives in
     * `initSettingsFramework()` in resources/js/admin.ts and the
     * "Copied" label comes from the shell's data-msg-copied
     * attribute.
     *
     * @param array{id: string, name: string, label: string, value?: string, help?: string|null, help_long?: string|null, attrs?: array<string, string|bool>, variant?: string, copy?: bool} $args Field arguments.
     * @return void
     */
    public static function textarea(array $args): void
    {
        $id = (string) $args['id'];
        $attrs = self::attrs($args['attrs'] ?? []);
        $class = self::inputClass($args, 'traydigita-ui-textarea');
        if (($args['variant'] ?? '') === 'code') {
            $class .= ' traydigita-ui-textarea-code';
        }
        $control = '<textarea class="' . esc_attr($class) . '"'
            . ' id="' . esc_attr($id) . '"'
            . ' name="' . esc_attr((string) $args['name']) . '"'
            . $attrs . '>'
            . esc_textarea((string) ($args['value'] ?? ''))
            . '</textarea>';
        if (!empty($args['copy'])) {
            $control = '<span class="td-settings-code-wrap">' . $control . self::copyButton($id) . '</span>';
        }
        self::field($id, (string) $args['label'], $control, $args['help'] ?? null, false, $args['help_long'] ?? null);
    }

    /**
     * Copy button for a code textarea, placed inside its wrap.
     *
     * Public so modules with their own form markup (the PWA
     * config form) can reuse the exact same button instead of
     * building their own. The button names its target through
     * `data-td-copy-for`; resources/js/admin.ts copies that
     * field's value and swaps the label to the shell's "Copied"
     * string for a moment. The button is `type="button"` so it
     * never submits the form.
     *
     * @param string $targetId Id of the textarea to copy from.
     * @return string
     */
    public static function copyButton(string $targetId): string
    {
        return '<button type="button" class="td-settings-copy-button"'
            . ' data-td-copy data-td-copy-for="' . esc_attr($targetId) . '"'
            . ' aria-label="' . esc_attr(__('Copy', 'traydigita-core')) . '">'
            . '<span class="material-icons" aria-hidden="true">content_copy</span>'
            . '<span class="td-settings-copy-button-label">' . esc_html__('Copy', 'traydigita-core') . '</span>'
            . '</button>';
    }

    /**
     * Render a select as a full field row.
     *
     * @param array{id: string, name: string, label: string, value?: string, options: array<string, string>, help?: string|null, help_long?: string|null, attrs?: array<string, string|bool>} $args Field arguments.
     * @return void
     */
    public static function select(array $args): void
    {
        $id = (string) $args['id'];
        $current = (string) ($args['value'] ?? '');
        $attrs = self::attrs($args['attrs'] ?? []);
        $options = '';
        foreach ($args['options'] as $value => $label) {
            $options .= '<option value="' . esc_attr((string) $value) . '"'
                . selected($current, (string) $value, false) . '>'
                . esc_html((string) $label) . '</option>';
        }
        $control = '<select class="' . esc_attr(self::inputClass($args, 'traydigita-ui-select')) . '"'
            . ' id="' . esc_attr($id) . '"'
            . ' name="' . esc_attr((string) $args['name']) . '"'
            . $attrs . '>' . $options . '</select>';
        self::field($id, (string) $args['label'], $control, $args['help'] ?? null, false, $args['help_long'] ?? null);
    }

    /**
     * Render a toggle (checkbox styled as a switch) as a field row.
     *
     * A hidden input with the same name and value `0` is printed
     * first, so an unchecked toggle still submits a value — the
     * generic save endpoint must see the field to store `false`.
     * Pass `'hidden_zero' => false` to skip it (for example when
     * the field is one of a checkbox group sharing a name).
     *
     * @param array{id: string, name: string, label: string, checked?: bool, help?: string|null, help_long?: string|null, attrs?: array<string, string|bool>, hidden_zero?: bool} $args Field arguments.
     * @return void
     */
    public static function toggle(array $args): void
    {
        $id = (string) $args['id'];
        $hiddenZero = ($args['hidden_zero'] ?? true) === true;
        $attrs = self::attrs($args['attrs'] ?? []);
        $control = '';
        if ($hiddenZero) {
            $control .= '<input type="hidden" name="' . esc_attr((string) $args['name']) . '" value="0">';
        }
        $control .= '<label class="traydigita-ui-toggle" for="' . esc_attr($id) . '">'
            . '<input class="traydigita-ui-toggle-input" type="checkbox"'
            . ' id="' . esc_attr($id) . '"'
            . ' name="' . esc_attr((string) $args['name']) . '"'
            . ' value="1"'
            . checked(!empty($args['checked']), true, false)
            . $attrs . '>'
            . '<span class="traydigita-ui-toggle-track" aria-hidden="true">'
            . '<span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span>'
            . '<span class="traydigita-ui-toggle-label">' . esc_html((string) $args['label']) . '</span>'
            . '</label>';
        self::field($id, (string) $args['label'], $control, $args['help'] ?? null, true, $args['help_long'] ?? null);
    }

    /**
     * Render a colour field as a full field row: a hex text input
     * (empty means "use the default") paired with a native colour
     * swatch that writes into it (see `initSettingsFramework()` in
     * resources/js/admin.ts).
     *
     * @param array{id: string, name: string, label: string, value?: string, help?: string|null, help_long?: string|null, attrs?: array<string, string|bool>} $args Field arguments.
     * @return void
     */
    public static function color(array $args): void
    {
        $id = (string) $args['id'];
        $value = (string) ($args['value'] ?? '');
        $attrs = self::attrs($args['attrs'] ?? []);
        $swatchValue = $value !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? $value
            : '#2563eb';
        $control = '<span class="td-settings-color-wrap">'
            . '<input class="' . esc_attr(self::inputClass($args, 'traydigita-ui-input td-settings-color-text')) . '" type="text"'
            . ' id="' . esc_attr($id) . '"'
            . ' name="' . esc_attr((string) $args['name']) . '"'
            . ' value="' . esc_attr($value) . '"'
            . $attrs . '>'
            . '<input class="td-settings-color-swatch" type="color"'
            . ' value="' . esc_attr($swatchValue) . '"'
            . ' data-td-color-for="' . esc_attr($id) . '"'
            . ' aria-label="' . esc_attr((string) $args['label']) . '">'
            . '</span>';
        self::field($id, (string) $args['label'], $control, $args['help'] ?? null, false, $args['help_long'] ?? null);
    }

    /**
     * Render a checkbox group as a field row: one labelled
     * checkbox per option, all sharing the same `name[]`.
     *
     * @param array{id: string, name: string, label: string, options: array<string, string>, checked?: array<int, string>, help?: string|null, help_long?: string|null, attrs?: array<string, string|bool>} $args Field arguments.
     * @return void
     */
    public static function checkboxGroup(array $args): void
    {
        $id = (string) $args['id'];
        $name = (string) $args['name'];
        $checkedValues = $args['checked'] ?? [];
        if (!is_array($checkedValues)) {
            $checkedValues = [];
        }
        $attrs = self::attrs($args['attrs'] ?? []);
        $boxes = '';
        foreach ($args['options'] as $value => $label) {
            $optionId = $id . '-' . (string) $value;
            $boxes .= '<label class="td-settings-checkbox" for="' . esc_attr($optionId) . '">'
                . '<input class="traydigita-ui-checkbox" type="checkbox"'
                . ' id="' . esc_attr($optionId) . '"'
                . ' name="' . esc_attr($name) . '"'
                . ' value="' . esc_attr((string) $value) . '"'
                . checked(in_array((string) $value, $checkedValues, true), true, false)
                . $attrs . '>'
                . esc_html((string) $label)
                . '</label>';
        }
        $control = '<fieldset class="td-settings-checkbox-group"><legend class="screen-reader-text">'
            . esc_html((string) $args['label']) . '</legend>' . $boxes . '</fieldset>';
        self::field($id, (string) $args['label'], $control, $args['help'] ?? null, false, $args['help_long'] ?? null);
    }

    /**
     * Open a settings section (card) inside a settings form.
     *
     * The id is the anchor the left navigation links to, so it
     * must match the module's `settingsSections()` entry. Only
     * the short description shows inline; a long description
     * sits behind a Help toggle, like every other long text on
     * a settings page.
     *
     * @param string $id Section anchor id, without the `td-section-` prefix.
     * @param string $title Already-translated section title.
     * @param string|null $description Already-translated short section description, if any.
     * @param string|null $longDescription Already-translated long section description, if any.
     * @return void
     */
    public static function sectionOpen(string $id, string $title, ?string $description = null, ?string $longDescription = null): void
    {
        $sectionId = 'td-section-' . $id;
        $targetId = ($longDescription !== null && $longDescription !== '') ? self::nextHelpId() : '';
        ?>
        <section class="traydigita-ui-section td-settings-section" id="<?php echo esc_attr($sectionId); ?>"
            aria-labelledby="<?php echo esc_attr($sectionId); ?>-title">
            <h2 class="traydigita-ui-section-title" id="<?php echo esc_attr($sectionId); ?>-title">
                <?php echo esc_html($title); ?>
            </h2>
            <?php if ($description !== null && $description !== '') : ?>
                <p class="traydigita-ui-field-help td-settings-section-description"><?php echo esc_html($description); ?>
                <?php if ($targetId !== '') : ?>
                    <?php echo self::helpToggle($targetId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by this class from an escaped id. ?>
                <?php endif; ?>
                </p>
            <?php elseif ($targetId !== '') : ?>
                <?php echo self::helpToggle($targetId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button markup is built by this class from an escaped id. ?>
            <?php endif; ?>
            <?php if ($targetId !== '') : ?>
                <div class="td-settings-help-long" id="<?php echo esc_attr($targetId); ?>" hidden>
                    <p><?php echo esc_html((string) $longDescription); ?></p>
                </div>
            <?php endif; ?>
        <?php
    }

    /**
     * Close a settings section opened by sectionOpen().
     *
     * @return void
     */
    public static function sectionClose(): void
    {
        ?>
        </section>
        <?php
    }

    /**
     * Render the sticky save bar at the end of a settings form.
     *
     * The bar sticks to the bottom of the viewport while the form
     * scrolls, so Save is always reachable on long pages. It holds
     * the form's submit button: the shell JavaScript observes that
     * button and hides its own injected save bar while this one is
     * visible, so a page never shows two save bars.
     *
     * @param string|null $label Already-translated button label; defaults to "Save changes".
     * @param bool $disabled True when the form must not be submittable (for example while Photon serves the images).
     * @return void
     */
    public static function saveBar(?string $label = null, bool $disabled = false): void
    {
        $buttonLabel = $label ?? __('Save changes', 'traydigita-core');
        ?>
        <div class="td-settings-savebar">
            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary" <?php disabled($disabled); ?>>
                <?php echo esc_html($buttonLabel); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Base input class plus a caller-supplied extra class.
     *
     * Extra classes are how a control keeps the hooks a module's
     * JavaScript relies on (for example the cookie module's
     * `traydigita-cookie-color-field`, which wpColorPicker binds
     * to). The value is escaped as a class attribute.
     *
     * @param array<string, mixed> $args Field arguments.
     * @param string $base Base class list.
     * @return string
     */
    private static function inputClass(array $args, string $base): string
    {
        $extra = isset($args['input_class']) && is_string($args['input_class'])
            ? trim($args['input_class'])
            : '';
        return $extra !== '' ? $base . ' ' . $extra : $base;
    }

    /**
     * Build an escaped attribute string from a caller-supplied map.
     *
     * Only allowlisted attribute names are emitted; anything else
     * is dropped silently, so a bad key can never inject markup.
     * Boolean `true` renders a valueless attribute (for example
     * `required`); `false` renders nothing.
     *
     * @param array<string, string|bool> $attrs Attribute map.
     * @return string
     */
    private static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $allowed = in_array($name, self::ALLOWED_ATTRS, true);
            if (!$allowed) {
                foreach (self::ALLOWED_ATTR_PREFIXES as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $allowed = true;
                        break;
                    }
                }
            }
            if (!$allowed) {
                continue;
            }
            if ($value === true) {
                $out .= ' ' . esc_attr($name);
            } elseif ($value === false) {
                continue;
            } else {
                $out .= ' ' . esc_attr($name) . '="' . esc_attr((string) $value) . '"';
            }
        }
        return $out;
    }
}
