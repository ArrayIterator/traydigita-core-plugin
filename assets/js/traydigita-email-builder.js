/* TrayDigita WYSIWYG email template builder.
 *
 * Plain JavaScript, no build step (same pattern as
 * traydigita-modal.js): versioned with Core::VERSION and
 * enqueued only on the Email and Form Builder pages.
 *
 * The editor IS the live rendered email: every block is
 * rendered with the same padding, colours and type scale
 * TemplateBuilder::renderBlock() uses, and edited inline
 * in place (contenteditable). The source of truth stays
 * the flat-block shape: every save, autosave and preview
 * posts exactly {id, name, blocks} and the sent mail is
 * rendered by TemplateBuilder::renderEmail(), never by
 * this file. The preview pane below the canvas shows the
 * exact server-rendered email, refreshed as you type.
 *
 * Reorder: drag the handle on desktop; up/down buttons
 * always work, including touch (drag-and-drop is not
 * reliable on touch screens, so the buttons are the
 * honest fallback there, not a hidden gesture).
 */
(function () {
    'use strict';

    var BLOCK_TYPES = ['header', 'text', 'button', 'image', 'divider', 'footer'];
    var DEFAULT_COLOR = '#0b6b3a';
    var MAX_BLOCKS = 50;

    function escHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(value) {
        return escHtml(value).replace(/'/g, '&#039;');
    }

    function deepCopy(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function timeString(timestamp) {
        try {
            return new Date(timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    function defaultBlock(type) {
        var base = { type: type, align: 'left' };
        if (type === 'header') {
            base.text = '';
            base.subtitle = '';
            base.background = DEFAULT_COLOR;
        }
        if (type === 'text') {
            base.content = '';
        }
        if (type === 'button') {
            base.label = '';
            base.url = '';
            base.background = DEFAULT_COLOR;
        }
        if (type === 'image') {
            base.attachment_id = 0;
            base.url = '';
            base.alt = '';
            base.link = '';
            base.width = 600;
        }
        if (type === 'divider') {
            base.style = 'solid';
            base.color = '#dddddd';
        }
        if (type === 'footer') {
            base.text = '';
        }
        return base;
    }

    /* Normalize edited text HTML to the tight allowlist
     * TemplateBuilder uses (p, br, b, strong, em, a with
     * href/title). Contenteditable produces divs on Enter
     * and arbitrary markup on paste; this converts divs to
     * paragraphs and unwraps everything else, so the editor
     * never shows formatting the sent mail would strip. */
    function normalizeTextHtml(html) {
        var holder = document.createElement('div');
        holder.innerHTML = String(html || '');
        var allowed = { P: true, BR: true, B: true, STRONG: true, EM: true, A: true };
        function clean(node) {
            var child = node.firstChild;
            while (child) {
                var next = child.nextSibling;
                if (child.nodeType === 1) {
                    var tag = child.tagName;
                    if (tag === 'DIV') {
                        var p = document.createElement('p');
                        while (child.firstChild) {
                            p.appendChild(child.firstChild);
                        }
                        node.replaceChild(p, child);
                        clean(p);
                    } else if (allowed[tag]) {
                        if (tag === 'A') {
                            var href = child.getAttribute('href') || '';
                            var title = child.getAttribute('title') || '';
                            while (child.attributes.length > 0) {
                                child.removeAttribute(child.attributes[0].name);
                            }
                            if (href !== '') {
                                child.setAttribute('href', href);
                            }
                            if (title !== '') {
                                child.setAttribute('title', title);
                            }
                        } else {
                            while (child.attributes.length > 0) {
                                child.removeAttribute(child.attributes[0].name);
                            }
                        }
                        clean(child);
                    } else if (tag === 'BR') {
                        // Kept as-is.
                    } else {
                        while (child.firstChild) {
                            node.insertBefore(child.firstChild, child);
                        }
                        node.removeChild(child);
                    }
                }
                child = next;
            }
        }
        clean(holder);
        /* Drop empty paragraphs (contenteditable leaves
         * <p><br></p> behind when a paragraph is cleared). */
        holder.querySelectorAll('p').forEach(function (p) {
            if (p.textContent.trim() === '') {
                p.remove();
            }
        });
        return holder.innerHTML;
    }

    function plainText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function init(root) {
        var configEl = root.querySelector('.td-email-builder-config');
        var config = {};
        try {
            config = JSON.parse(configEl ? configEl.textContent : '{}');
        } catch (e) {
            config = {};
        }
        var ajaxUrl = config.ajaxUrl || '';
        var nonce = config.nonce || '';
        var siteName = config.siteName || '';
        var siteUrl = config.siteUrl || '';
        var strings = config.strings || {};
        var emptyCanvasText = strings.emptyCanvas || 'No blocks yet. Add one from the palette above.';
        var isFormBuilder = config.context === 'form-builder';

        var messageEl = root.querySelector('.td-eb-message');
        var restoreEl = root.querySelector('.td-eb-restore');
        var restoreTextEl = root.querySelector('.td-eb-restore-text');
        var canvasEl = root.querySelector('.td-eb-canvas');
        var inspectorEl = root.querySelector('.td-eb-inspector');
        var previewFrame = root.querySelector('.td-eb-preview-frame');
        var nameInput = config.nameSelector ? document.querySelector(config.nameSelector) : null;
        var idInput = config.idSelector ? document.querySelector(config.idSelector) : null;
        var formEl = config.formSelector ? document.querySelector(config.formSelector) : null;

        var state = {
            id: '',
            name: '',
            blocks: [],
            selected: -1,
            dirty: false,
        };
        var autosaveTimer = null;
        var autosaveController = null;
        var lastSavedJson = '';
        var previewTimer = null;
        var draggedIndex = -1;
        var mediaFrame = null;
        var pendingAutosave = null;

        function showMessage(text, kind) {
            if (!messageEl) {
                return;
            }
            messageEl.textContent = text;
            messageEl.classList.toggle('is-error', kind === 'error');
            messageEl.classList.toggle('is-ok', kind === 'ok');
        }

        function post(action, fields, signal) {
            var body = new FormData();
            body.append('action', action);
            body.append('_ajax_nonce', nonce);
            Object.keys(fields).forEach(function (key) {
                body.append(key, fields[key]);
            });
            var options = { method: 'POST', body: body, credentials: 'same-origin' };
            if (signal) {
                options.signal = signal;
            }
            return fetch(ajaxUrl, options)
                .then(function (response) { return response.json(); });
        }

        function readName() {
            if (nameInput) {
                state.name = nameInput.value;
            }
            return state.name;
        }

        function readId() {
            if (idInput) {
                state.id = idInput.value;
            }
            return state.id;
        }

        function writeId(id) {
            state.id = id;
            if (idInput) {
                idInput.value = id;
            }
        }

        function writeName(name) {
            state.name = name;
            if (nameInput) {
                nameInput.value = name;
            }
        }

        /* Read the current blocks from the canvas DOM, so
         * in-progress typing is never lost between renders.
         * Plain-text fields are stripped to text; text blocks
         * are normalized to the allowlist. The result is also
         * written back to state.blocks: every canvas rebuild
         * (palette-add, move, delete, drag, align, media
         * select) renders from state, so typed text must live
         * there, not only in the DOM. */
        function serializeBlocks() {
            var blocks = deepCopy(state.blocks);
            canvasEl.querySelectorAll('.td-eb-block').forEach(function (blockEl) {
                var index = parseInt(blockEl.dataset.index || '', 10);
                if (isNaN(index) || !blocks[index]) {
                    return;
                }
                var block = blocks[index];
                blockEl.querySelectorAll('[data-field]').forEach(function (fieldEl) {
                    var key = fieldEl.dataset.field || '';
                    if (!key) {
                        return;
                    }
                    if (block.type === 'text' && key === 'content') {
                        block.content = normalizeTextHtml(fieldEl.innerHTML);
                    } else {
                        block[key] = plainText(fieldEl.textContent);
                    }
                });
            });
            state.blocks = blocks;
            return deepCopy(blocks);
        }

        function hasUsableBlock(blocks) {
            return blocks.some(function (block) {
                if (block.type === 'header' || block.type === 'footer') {
                    return plainText(block.text) !== '';
                }
                if (block.type === 'text') {
                    return normalizeTextHtml(block.content || '').replace(/<[^>]+>/g, '').trim() !== '';
                }
                if (block.type === 'button') {
                    return plainText(block.label) !== '' && plainText(block.url) !== '';
                }
                if (block.type === 'image') {
                    return (block.attachment_id > 0) || plainText(block.url) !== '';
                }
                return block.type === 'divider';
            });
        }

        /* ---- Canvas rendering (mirrors renderBlock()) ---- */

        function alignValue(block) {
            return (block.align === 'center' || block.align === 'right') ? block.align : 'left';
        }

        function colorValue(block) {
            return block.background || block.color || '';
        }

        function blockContentHtml(block, index) {
            var align = alignValue(block);
            if (block.type === 'header') {
                var bg = block.background || DEFAULT_COLOR;
                return '<div class="td-eb-content" style="background-color:' + escAttr(bg)
                    + ';padding:28px 24px;text-align:' + align + ';">'
                    + '<div class="td-eb-editable" contenteditable="plaintext-only" spellcheck="false"'
                    + ' data-field="text" data-placeholder="Heading"'
                    + ' style="margin:0;font-size:22px;line-height:1.3;font-weight:700;color:#ffffff;">'
                    + escHtml(block.text || '') + '</div>'
                    + '<div class="td-eb-editable" contenteditable="plaintext-only" spellcheck="false"'
                    + ' data-field="subtitle" data-placeholder="Subtitle (optional)"'
                    + ' style="margin:6px 0 0;font-size:14px;color:#ffffff;opacity:0.85;">'
                    + escHtml(block.subtitle || '') + '</div></div>';
            }
            if (block.type === 'text') {
                return '<div class="td-eb-content td-eb-editable" contenteditable="true" spellcheck="false"'
                    + ' data-field="content" data-placeholder="Write your text…"'
                    + ' style="padding:20px 24px;text-align:' + align + ';font-size:15px;line-height:1.6;color:#222222;">'
                    + (block.content || '') + '</div>';
            }
            if (block.type === 'button') {
                var btnBg = block.background || DEFAULT_COLOR;
                return '<div class="td-eb-content" style="padding:12px 24px;text-align:' + align + ';">'
                    + '<span style="display:inline-block;background-color:' + escAttr(btnBg)
                    + ';color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;'
                    + 'padding:12px 28px;border-radius:6px;">'
                    + '<span class="td-eb-editable" contenteditable="plaintext-only" spellcheck="false"'
                    + ' data-field="label" data-placeholder="Button label">'
                    + escHtml(block.label || '') + '</span></span></div>';
            }
            if (block.type === 'image') {
                var url = block.url || '';
                var inner = '';
                if (url !== '') {
                    var img = '<img class="td-eb-image-preview" src="' + escAttr(url) + '" alt="'
                        + escAttr(block.alt || '') + '" style="max-width:100%;height:auto;display:block;border:0;" />';
                    inner = block.link ? '<span style="display:inline-block;">' + img + '</span>' : img;
                } else {
                    inner = '<div class="td-eb-image-empty"><p>No image yet.</p>'
                        + '<button type="button" class="button td-eb-pick-image">Select image</button></div>';
                }
                return '<div class="td-eb-content" style="padding:12px 24px;text-align:' + align + ';">' + inner + '</div>';
            }
            if (block.type === 'divider') {
                var style = block.style === 'dashed' ? 'dashed' : 'solid';
                var color = block.color || '#dddddd';
                return '<div class="td-eb-content" style="padding:12px 24px;">'
                    + '<hr style="border:0;border-top:1px ' + style + ' ' + escAttr(color) + ';margin:0;" /></div>';
            }
            /* footer */
            var siteHtml = '';
            if (siteName !== '') {
                siteHtml = '<p style="margin:8px 0 0;font-size:12px;color:#888888;">'
                    + '<span style="color:#888888;">' + escHtml(siteName) + '</span></p>';
            }
            return '<div class="td-eb-content" style="padding:20px 24px;text-align:' + align
                + ';font-size:13px;line-height:1.5;color:#666666;border-top:1px solid #eeeeee;">'
                + '<div class="td-eb-editable td-eb-editable--dark" contenteditable="plaintext-only" spellcheck="false"'
                + ' data-field="text" data-placeholder="Footer text">'
                + escHtml(block.text || '') + '</div>' + siteHtml + '</div>';
        }

        function blockToolbarHtml(block, index) {
            var align = alignValue(block);
            var alignButtons = ['left', 'center', 'right'].map(function (value) {
                var label = value.charAt(0).toUpperCase() + value.slice(1);
                return '<button type="button" class="td-eb-block-tool td-eb-align-btn'
                    + (align === value ? ' is-active' : '') + '" data-align="' + value
                    + '" aria-label="Align ' + value + '">' + label + '</button>';
            }).join('');
            var formatBar = '';
            if (block.type === 'text') {
                formatBar = '<div class="td-eb-formatbar">'
                    + '<button type="button" class="td-eb-block-tool" data-format="bold" aria-label="Bold"><b>B</b></button>'
                    + '<button type="button" class="td-eb-block-tool" data-format="italic" aria-label="Italic"><i>I</i></button>'
                    + '<button type="button" class="td-eb-block-tool" data-format="link" aria-label="Add link">Link</button>'
                    + '<input type="url" class="td-eb-link-input" placeholder="https://…" spellcheck="false" hidden />'
                    + '<button type="button" class="td-eb-block-tool td-eb-link-apply" hidden>Apply link</button>'
                    + '</div>';
            }
            return '<div class="td-eb-block-toolbar">'
                + '<span class="td-eb-block-drag" draggable="true" title="Drag to reorder">&#8942;&#8942;</span>'
                + '<span class="td-eb-block-type">' + escHtml(block.type) + '</span>'
                + alignButtons
                + '<button type="button" class="td-eb-block-tool" data-move="-1" aria-label="Move up">&uarr;</button>'
                + '<button type="button" class="td-eb-block-tool" data-move="1" aria-label="Move down">&darr;</button>'
                + '<button type="button" class="td-eb-block-tool is-danger" data-delete="1" aria-label="Delete block">&times;</button>'
                + '</div>' + formatBar;
        }

        function renderCanvas() {
            canvasEl.innerHTML = '';
            if (state.blocks.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'td-eb-empty';
                empty.textContent = emptyCanvasText;
                canvasEl.appendChild(empty);
            }
            state.blocks.forEach(function (block, index) {
                var wrapper = document.createElement('div');
                wrapper.className = 'td-eb-block' + (state.selected === index ? ' is-selected' : '');
                wrapper.dataset.index = String(index);
                wrapper.innerHTML = blockToolbarHtml(block, index) + blockContentHtml(block, index);
                canvasEl.appendChild(wrapper);
            });
            renderInspector();
        }

        /* ---- Inspector (settings of the selected block) ---- */

        function fieldRow(label, inputHtml) {
            return '<label class="td-eb-field"><span>' + escHtml(label) + '</span>' + inputHtml + '</label>';
        }

        function renderInspector() {
            if (!inspectorEl) {
                return;
            }
            var block = state.blocks[state.selected];
            if (!block) {
                inspectorEl.innerHTML = '<h3>Block settings</h3>'
                    + '<p class="td-eb-inspector-desc">Select a block in the email to edit its settings.</p>';
                return;
            }
            var html = '<h3>Block settings</h3><p class="td-eb-inspector-desc">'
                + escHtml(block.type.charAt(0).toUpperCase() + block.type.slice(1))
                + ' block</p>';
            if (block.type === 'header' || block.type === 'button') {
                html += fieldRow('Background', '<input type="color" data-inspect="background" value="'
                    + escAttr(block.background || DEFAULT_COLOR) + '" />');
            }
            if (block.type === 'button') {
                html += fieldRow('Button URL', '<input type="url" data-inspect="url" value="'
                    + escAttr(block.url || '') + '" placeholder="https://…" spellcheck="false" />');
            }
            if (block.type === 'image') {
                html += '<div class="td-eb-field-row">'
                    + fieldRow('Image URL', '<input type="url" data-inspect="url" value="'
                    + escAttr(block.url || '') + '" placeholder="https://…" spellcheck="false" />')
                    + '<button type="button" class="button td-eb-pick-image">Media library</button></div>';
                html += fieldRow('Alt text', '<input type="text" data-inspect="alt" value="'
                    + escAttr(block.alt || '') + '" />');
                html += fieldRow('Link URL (optional)', '<input type="url" data-inspect="link" value="'
                    + escAttr(block.link || '') + '" placeholder="https://…" spellcheck="false" />');
                html += fieldRow('Width (px, max 1200)', '<input type="number" data-inspect="width" min="1" max="1200" value="'
                    + escAttr(String(block.width || 600)) + '" />');
            }
            if (block.type === 'divider') {
                html += fieldRow('Style', '<select data-inspect="style">'
                    + '<option value="solid"' + (block.style !== 'dashed' ? ' selected' : '') + '>Solid</option>'
                    + '<option value="dashed"' + (block.style === 'dashed' ? ' selected' : '') + '>Dashed</option></select>');
                html += fieldRow('Colour', '<input type="color" data-inspect="color" value="'
                    + escAttr(block.color || '#dddddd') + '" />');
            }
            html += '<div class="td-eb-field"><span>Alignment</span><span class="td-eb-align">'
                + ['left', 'center', 'right'].map(function (value) {
                    return '<button type="button" data-inspect-align="' + value + '"'
                        + (alignValue(block) === value ? ' class="is-active"' : '') + '>'
                        + value.charAt(0).toUpperCase() + value.slice(1) + '</button>';
                }).join('') + '</span></div>';
            if (block.type === 'text') {
                html += '<p class="td-eb-inspector-desc">Text supports bold, italic and links. '
                    + 'Use {{field_id}} placeholders in notification mail — they are replaced '
                    + 'with the submitted values when the mail is sent.</p>';
            }
            inspectorEl.innerHTML = html;
        }

        /* Patch one block's rendered styles after an inspector
         * change, without rebuilding the canvas (keeps focus
         * and the caret where they are). */
        function patchBlock(index) {
            var wrapper = canvasEl.querySelector('.td-eb-block[data-index="' + index + '"]');
            var block = state.blocks[index];
            if (!wrapper || !block) {
                return;
            }
            var content = wrapper.querySelector('.td-eb-content');
            if (!content) {
                return;
            }
            content.style.textAlign = alignValue(block);
            if (block.type === 'header' || block.type === 'button') {
                var bg = block.background || DEFAULT_COLOR;
                if (block.type === 'header') {
                    content.style.backgroundColor = bg;
                } else {
                    var pill = content.querySelector('span[style]');
                    if (pill) {
                        pill.style.backgroundColor = bg;
                    }
                }
            }
            if (block.type === 'divider') {
                var hr = content.querySelector('hr');
                if (hr) {
                    hr.style.borderTop = '1px ' + (block.style === 'dashed' ? 'dashed' : 'solid')
                        + ' ' + (block.color || '#dddddd');
                }
            }
            if (block.type === 'image') {
                var img = content.querySelector('img');
                if (img && block.url) {
                    img.src = block.url;
                    img.alt = block.alt || '';
                    img.width = block.width || 600;
                } else if (!block.url) {
                    renderCanvas();
                } else {
                    /* The URL went from empty to set, but the
                     * canvas still shows the "No image yet"
                     * placeholder: swap it for the image in
                     * place. A full renderCanvas() here would
                     * rebuild the inspector too and drop the
                     * focus from the URL field mid-typing. */
                    var empty = content.querySelector('.td-eb-image-empty');
                    var markup = '<img class="td-eb-image-preview" src="' + escAttr(block.url)
                        + '" alt="' + escAttr(block.alt || '')
                        + '" style="max-width:100%;height:auto;display:block;border:0;" />';
                    if (block.link) {
                        markup = '<span style="display:inline-block;">' + markup + '</span>';
                    }
                    if (empty) {
                        empty.outerHTML = markup;
                    } else {
                        content.innerHTML = markup;
                    }
                    var freshImg = content.querySelector('img');
                    if (freshImg) {
                        freshImg.width = block.width || 600;
                    }
                }
            }
        }

        /* ---- Preview + autosave (debounced) ---- */

        function schedulePreview() {
            if (previewTimer) {
                window.clearTimeout(previewTimer);
            }
            previewTimer = window.setTimeout(updatePreview, 800);
        }

        function updatePreview() {
            if (!previewFrame) {
                return;
            }
            var blocks = serializeBlocks();
            if (!hasUsableBlock(blocks)) {
                previewFrame.removeAttribute('srcdoc');
                return;
            }
            post('traydigita_email_preview', { blocks: JSON.stringify(blocks) }).then(function (result) {
                if (result && result.success) {
                    previewFrame.srcdoc = result.data.html;
                }
            });
        }

        function scheduleAutosave() {
            state.dirty = true;
            if (autosaveTimer) {
                window.clearTimeout(autosaveTimer);
            }
            autosaveTimer = window.setTimeout(runAutosave, 1500);
        }

        /* The content an autosave would store, as one string:
         * the comparison is against the last saved draft, so
         * the autosave never rewrites a row with content the
         * draft already holds. */
        function savedContentJson(name, blocks) {
            return JSON.stringify({ name: name, blocks: blocks });
        }

        /* Drop a pending autosave: the timer, and an autosave
         * request already in flight (aborted, so its response
         * can never overwrite the message a save just showed). */
        function cancelAutosave() {
            if (autosaveTimer) {
                window.clearTimeout(autosaveTimer);
                autosaveTimer = null;
            }
            if (autosaveController) {
                autosaveController.abort();
                autosaveController = null;
            }
        }

        function runAutosave() {
            autosaveTimer = null;
            var blocks = serializeBlocks();
            var name = readName().trim();
            var id = readId();
            if (id === '' && name === '' && !hasUsableBlock(blocks)) {
                return;
            }
            /* The draft already holds exactly this content (a
             * save just ran, or the template was just loaded):
             * writing it again as an autosave would only bump
             * the row's timestamp, and the restore check treats
             * a newer timestamp as unsaved changes, so the
             * banner would offer to restore identical content. */
            var contentJson = savedContentJson(name, blocks);
            if (contentJson === lastSavedJson) {
                return;
            }
            autosaveController = new AbortController();
            var controller = autosaveController;
            post('traydigita_email_autosave', {
                template: JSON.stringify({ id: id, name: name, blocks: blocks }),
            }, controller.signal).then(function (result) {
                if (autosaveController === controller) {
                    autosaveController = null;
                }
                if (result && result.success && result.data) {
                    if (result.data.id) {
                        writeId(result.data.id);
                    }
                    state.dirty = false;
                    /* A save may have landed while this request
                     * was in flight: the saved draft now holds
                     * this content, so the save's message stands
                     * and the autosave one must not overwrite it. */
                    if (contentJson !== lastSavedJson) {
                        showMessage('Autosaved ' + timeString(result.data.updated_at) + '.', 'ok');
                    }
                }
            }).catch(function (error) {
                if (autosaveController === controller) {
                    autosaveController = null;
                }
                /* Only the abort from cancelAutosave() is
                 * swallowed: anything else still rejects, as
                 * it did before (autosave failures stay silent
                 * in the UI, they never surface as errors). */
                if (!error || error.name !== 'AbortError') {
                    throw error;
                }
            });
        }

        /* ---- Save / load ---- */

        function save(status) {
            var blocks = serializeBlocks();
            var name = readName().trim();
            var id = readId();
            if (name === '') {
                showMessage('Please enter a template name.', 'error');
                return;
            }
            if (!hasUsableBlock(blocks)) {
                showMessage('Add at least one filled block before saving.', 'error');
                return;
            }
            /* The save supersedes any autosave of this same
             * content: drop its timer, and abort it if it is
             * already in flight, so no autosave message can
             * land after the save's message. */
            cancelAutosave();
            post('traydigita_email_save_template', {
                template: JSON.stringify({ id: id, name: name, blocks: blocks }),
                status: status,
            }).then(function (result) {
                if (result && result.success) {
                    if (status === 'draft') {
                        if (result.data && result.data.template && result.data.template.id) {
                            writeId(result.data.template.id);
                        }
                        state.dirty = false;
                        lastSavedJson = savedContentJson(name, blocks);
                        showMessage('Draft saved.', 'ok');
                        return;
                    }
                    window.location.reload();
                    return;
                }
                showMessage((result && result.data && result.data.message) || 'Error', 'error');
            });
        }

        function loadTemplateData(template) {
            writeId(template.id || '');
            writeName(template.name || '');
            state.blocks = deepCopy(template.blocks || []);
            state.selected = -1;
            state.dirty = false;
            lastSavedJson = savedContentJson(state.name, state.blocks);
            hideRestore();
            renderCanvas();
            updatePreview();
            checkAutosave();
        }

        function loadTemplate(id) {
            var templates = config.templates || {};
            var template = templates[id];
            if (!template) {
                return;
            }
            loadTemplateData(template);
            showMessage('');
        }

        function hideRestore() {
            if (restoreEl) {
                restoreEl.hidden = true;
            }
            pendingAutosave = null;
        }

        function checkAutosave() {
            var id = readId();
            if (id === '' || !restoreEl) {
                return;
            }
            post('traydigita_email_get_autosave', { template_id: id }).then(function (result) {
                if (!result || !result.success || !result.data || !result.data.autosave) {
                    return;
                }
                if (!result.data.is_newer) {
                    return;
                }
                pendingAutosave = result.data.autosave;
                if (restoreTextEl) {
                    restoreTextEl.textContent = 'You have unsaved changes from '
                        + timeString(result.data.autosave.updated_at)
                        + ' that are newer than the saved template.';
                }
                restoreEl.hidden = false;
            });
        }

        function restoreAutosave() {
            if (!pendingAutosave) {
                hideRestore();
                return;
            }
            state.blocks = deepCopy(pendingAutosave.template.blocks || []);
            writeName(pendingAutosave.template.name || state.name);
            state.selected = -1;
            state.dirty = true;
            hideRestore();
            renderCanvas();
            schedulePreview();
            scheduleAutosave();
            showMessage('Unsaved changes restored. Save to keep them.', 'ok');
        }

        function discardAutosave() {
            var id = readId();
            if (id === '') {
                hideRestore();
                return;
            }
            post('traydigita_email_delete_autosave', { template_id: id }).then(function () {
                hideRestore();
                showMessage('Unsaved changes discarded.', 'ok');
            });
        }

        /* ---- Events ---- */

        /* Palette: add a block. */
        root.querySelectorAll('.td-eb-palette-item').forEach(function (button) {
            button.addEventListener('click', function () {
                if (state.blocks.length >= MAX_BLOCKS) {
                    showMessage('A template holds at most ' + MAX_BLOCKS + ' blocks.', 'error');
                    return;
                }
                /* Keep typed text: the rebuild below renders
                 * from state.blocks, so sync the DOM first. */
                serializeBlocks();
                state.blocks.push(defaultBlock(button.dataset.blockType));
                state.selected = state.blocks.length - 1;
                state.dirty = true;
                renderCanvas();
                schedulePreview();
                scheduleAutosave();
            });
        });

        /* Canvas clicks: select, toolbar actions, format bar. */
        canvasEl.addEventListener('click', function (event) {
            var blockEl = event.target.closest('.td-eb-block');
            if (!blockEl) {
                return;
            }
            var index = parseInt(blockEl.dataset.index || '', 10);
            if (isNaN(index) || !state.blocks[index]) {
                return;
            }
            var pickBtn = event.target.closest('.td-eb-pick-image');
            if (pickBtn) {
                openMedia(index);
                return;
            }
            var toolBtn = event.target.closest('button[data-move], button[data-delete], button[data-align], button[data-format]');
            if (toolBtn) {
                event.preventDefault();
                /* Keep typed text: the rebuild below renders
                 * from state.blocks, so sync the DOM first. */
                serializeBlocks();
                if (toolBtn.dataset.delete) {
                    state.blocks.splice(index, 1);
                    state.selected = -1;
                    state.dirty = true;
                    renderCanvas();
                    schedulePreview();
                    scheduleAutosave();
                    return;
                }
                var move = parseInt(toolBtn.dataset.move || '0', 10);
                if (move !== 0) {
                    var next = index + move;
                    if (next >= 0 && next < state.blocks.length) {
                        var moved = state.blocks.splice(index, 1)[0];
                        state.blocks.splice(next, 0, moved);
                        state.selected = next;
                        state.dirty = true;
                        renderCanvas();
                        schedulePreview();
                        scheduleAutosave();
                    }
                    return;
                }
                if (toolBtn.dataset.align) {
                    state.blocks[index].align = toolBtn.dataset.align;
                    state.dirty = true;
                    renderCanvas();
                    schedulePreview();
                    scheduleAutosave();
                    return;
                }
                if (toolBtn.dataset.format) {
                    applyFormat(toolBtn, index);
                    return;
                }
            }
            if (state.selected !== index) {
                state.selected = index;
                canvasEl.querySelectorAll('.td-eb-block').forEach(function (el) {
                    el.classList.toggle('is-selected', parseInt(el.dataset.index || '', 10) === index);
                });
                renderInspector();
            }
        });

        /* Link apply button inside a text format bar. */
        canvasEl.addEventListener('click', function (event) {
            var applyBtn = event.target.closest('.td-eb-link-apply');
            if (!applyBtn) {
                return;
            }
            var blockEl = event.target.closest('.td-eb-block');
            var input = blockEl ? blockEl.querySelector('.td-eb-link-input') : null;
            if (!blockEl || !input) {
                return;
            }
            var url = input.value.trim();
            if (url !== '') {
                document.execCommand('createLink', false, url);
            }
            input.hidden = true;
            applyBtn.hidden = true;
            input.value = '';
            var contentField = blockEl.querySelector('[data-field="content"]');
            if (contentField) {
                syncFieldFromDom(contentField);
            }
            onTextEdited();
        });

        function applyFormat(button, index) {
            var blockEl = canvasEl.querySelector('.td-eb-block[data-index="' + index + '"]');
            var editable = blockEl ? blockEl.querySelector('[data-field="content"]') : null;
            if (!editable) {
                return;
            }
            editable.focus();
            var format = button.dataset.format;
            if (format === 'bold') {
                document.execCommand('bold', false, null);
            } else if (format === 'italic') {
                document.execCommand('italic', false, null);
            } else if (format === 'link') {
                var input = blockEl.querySelector('.td-eb-link-input');
                var applyBtn = blockEl.querySelector('.td-eb-link-apply');
                if (input && applyBtn) {
                    input.hidden = false;
                    applyBtn.hidden = false;
                    input.focus();
                }
                return;
            }
            syncFieldFromDom(editable);
            onTextEdited();
        }

        /* Write one editable field's current DOM value back
         * into state.blocks, using the same rules as
         * serializeBlocks(). Typing updates state live, so a
         * canvas rebuild can never wipe in-progress text. */
        function syncFieldFromDom(fieldEl) {
            var blockEl = fieldEl.closest('.td-eb-block');
            if (!blockEl) {
                return;
            }
            var index = parseInt(blockEl.dataset.index || '', 10);
            var block = state.blocks[index];
            if (isNaN(index) || !block) {
                return;
            }
            var key = fieldEl.dataset.field || '';
            if (!key) {
                return;
            }
            if (block.type === 'text' && key === 'content') {
                block.content = normalizeTextHtml(fieldEl.innerHTML);
            } else {
                block[key] = plainText(fieldEl.textContent);
            }
        }

        function onTextEdited() {
            state.dirty = true;
            schedulePreview();
            scheduleAutosave();
        }

        /* Typing inside any editable field. */
        canvasEl.addEventListener('input', function (event) {
            var field = event.target.closest('[data-field]');
            if (field) {
                syncFieldFromDom(field);
                onTextEdited();
            }
        });

        /* Focus leaving an editable field: same sync, as a
         * backstop for edits that do not fire input (format
         * commands, IME commits, text dropped in). */
        canvasEl.addEventListener('focusout', function (event) {
            var field = event.target.closest ? event.target.closest('[data-field]') : null;
            if (field) {
                syncFieldFromDom(field);
            }
        });

        /* Paste as plain text: the allowlist has no spans,
         * fonts or pasted styles, so pasted formatting is
         * dropped at the door instead of vanishing on save. */
        canvasEl.addEventListener('paste', function (event) {
            var field = event.target.closest('[data-field]');
            if (!field) {
                return;
            }
            event.preventDefault();
            var text = (event.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
            syncFieldFromDom(field);
            onTextEdited();
        });

        /* Drag-and-drop reorder (desktop pointer only). */
        canvasEl.addEventListener('dragstart', function (event) {
            var handle = event.target.closest('.td-eb-block-drag');
            var blockEl = event.target.closest('.td-eb-block');
            if (!handle || !blockEl) {
                return;
            }
            draggedIndex = parseInt(blockEl.dataset.index || '', 10);
            blockEl.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            try {
                event.dataTransfer.setData('text/plain', String(draggedIndex));
            } catch (e) {
                /* Some browsers restrict setData; the index is kept locally. */
            }
        });
        canvasEl.addEventListener('dragend', function () {
            draggedIndex = -1;
            canvasEl.querySelectorAll('.td-eb-block').forEach(function (el) {
                el.classList.remove('is-dragging', 'is-drop-target');
            });
        });
        canvasEl.addEventListener('dragover', function (event) {
            var blockEl = event.target.closest('.td-eb-block');
            if (!blockEl || draggedIndex < 0) {
                return;
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            canvasEl.querySelectorAll('.td-eb-block').forEach(function (el) {
                el.classList.toggle('is-drop-target', el === blockEl);
            });
        });
        canvasEl.addEventListener('drop', function (event) {
            var blockEl = event.target.closest('.td-eb-block');
            if (!blockEl || draggedIndex < 0) {
                return;
            }
            event.preventDefault();
            var targetIndex = parseInt(blockEl.dataset.index || '', 10);
            if (!isNaN(targetIndex) && targetIndex !== draggedIndex) {
                /* Keep typed text: the rebuild below renders
                 * from state.blocks, so sync the DOM first. */
                serializeBlocks();
                var moved = state.blocks.splice(draggedIndex, 1)[0];
                state.blocks.splice(targetIndex, 0, moved);
                state.selected = targetIndex;
                state.dirty = true;
                renderCanvas();
                schedulePreview();
                scheduleAutosave();
            }
            draggedIndex = -1;
        });

        /* Inspector changes. */
        if (inspectorEl) {
            inspectorEl.addEventListener('input', function (event) {
                var input = event.target.closest('[data-inspect]');
                var block = state.blocks[state.selected];
                if (!input || !block) {
                    return;
                }
                var key = input.dataset.inspect;
                if (key === 'width') {
                    block.width = Math.max(1, Math.min(1200, parseInt(input.value || '600', 10) || 600));
                } else {
                    block[key] = input.value;
                }
                patchBlock(state.selected);
                onTextEdited();
            });
            inspectorEl.addEventListener('change', function (event) {
                var input = event.target.closest('[data-inspect]');
                var block = state.blocks[state.selected];
                if (!input || !block) {
                    return;
                }
                block[input.dataset.inspect] = input.value;
                patchBlock(state.selected);
                onTextEdited();
            });
            inspectorEl.addEventListener('click', function (event) {
                var alignBtn = event.target.closest('[data-inspect-align]');
                var pickBtn = event.target.closest('.td-eb-pick-image');
                var block = state.blocks[state.selected];
                if (alignBtn && block) {
                    /* Keep typed text: the rebuild below
                     * renders from state.blocks. serializeBlocks()
                     * replaces state.blocks with a fresh copy, so
                     * re-read the block afterwards. */
                    serializeBlocks();
                    state.blocks[state.selected].align = alignBtn.dataset.inspectAlign;
                    state.dirty = true;
                    renderCanvas();
                    schedulePreview();
                    scheduleAutosave();
                    return;
                }
                if (pickBtn) {
                    openMedia(state.selected);
                }
            });
        }

        /* Media library picker (wp.media). */
        function openMedia(index) {
            if (isNaN(index) || !state.blocks[index] || state.blocks[index].type !== 'image') {
                return;
            }
            if (typeof window.wp === 'undefined' || !window.wp.media) {
                showMessage('The media library is unavailable. Paste an image URL in the block settings instead.', 'error');
                return;
            }
            if (!mediaFrame) {
                mediaFrame = window.wp.media({
                    title: 'Select image',
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' },
                });
            }
            mediaFrame.off('select');
            mediaFrame.on('select', function () {
                var attachment = mediaFrame.state().get('selection').first().toJSON();
                var block = state.blocks[index];
                if (!block) {
                    return;
                }
                /* Keep typed text: the rebuild below renders
                 * from state.blocks, so sync the DOM first.
                 * serializeBlocks() replaces state.blocks with
                 * a fresh copy, so re-read the block after. */
                serializeBlocks();
                block = state.blocks[index];
                if (!block) {
                    return;
                }
                block.attachment_id = attachment.id || 0;
                var sizes = attachment.sizes || {};
                block.url = (sizes.large && sizes.large.url) || attachment.url || '';
                state.dirty = true;
                renderCanvas();
                schedulePreview();
                scheduleAutosave();
            });
            mediaFrame.open();
        }

        /* Actions. */
        var saveDraftBtn = root.querySelector('.td-eb-save-draft');
        if (saveDraftBtn) {
            saveDraftBtn.addEventListener('click', function () { save('draft'); });
        }
        var savePublishBtn = root.querySelector('.td-eb-save-publish');
        if (savePublishBtn) {
            savePublishBtn.addEventListener('click', function () { save('published'); });
        }
        var newBtn = root.querySelector('.td-eb-new');
        if (newBtn) {
            newBtn.addEventListener('click', function () {
                cancelAutosave();
                writeId('');
                writeName('');
                state.blocks = [];
                state.selected = -1;
                state.dirty = false;
                lastSavedJson = '';
                hideRestore();
                renderCanvas();
                if (previewFrame) {
                    previewFrame.removeAttribute('srcdoc');
                }
                showMessage('');
            });
        }
        var restoreAccept = root.querySelector('.td-eb-restore-accept');
        if (restoreAccept) {
            restoreAccept.addEventListener('click', restoreAutosave);
        }
        var restoreDiscard = root.querySelector('.td-eb-restore-discard');
        if (restoreDiscard) {
            restoreDiscard.addEventListener('click', discardAutosave);
        }

        /* Newsletter template list: Edit loads the template. */
        if (!isFormBuilder && config.templates) {
            document.querySelectorAll('.email-template-load').forEach(function (button) {
                button.addEventListener('click', function () {
                    loadTemplate(button.dataset.templateId);
                });
            });
        }

        /* Name input edits feed the autosave. */
        if (nameInput) {
            nameInput.addEventListener('input', function () {
                state.name = nameInput.value;
                scheduleAutosave();
            });
        }

        /* FormBuilder submit publishes (the shell save is
         * bypassed for this form: the editor saves through
         * the Email AJAX endpoints, which keep block HTML
         * intact — the generic save would strip it). */
        if (formEl) {
            formEl.addEventListener('submit', function (event) {
                event.preventDefault();
                save('published');
            });
        }

        /* Initial state. */
        if (config.initial) {
            loadTemplateData(config.initial);
        } else {
            renderCanvas();
        }
    }

    document.querySelectorAll('.td-email-builder').forEach(init);
})();
