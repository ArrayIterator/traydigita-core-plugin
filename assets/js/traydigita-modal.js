/**
 * TrayDigita shared modal.
 *
 * ONE modal component for every popup in the admin (see
 * assets/css/traydigita-modal.css for the matching styles):
 *
 * - the module info popup, opened from the context rail "More"
 *   trigger and from the info button injected into each Modules
 *   page card;
 * - the Mailer provider picker and SMTP preset picker (the same
 *   picker component with different data).
 *
 * One overlay, one card, one header / body / footer structure,
 * one focus trap, one close behaviour — the popups differ only
 * in body content. All data is server-rendered JSON embedded on
 * the page (no new AJAX or REST endpoint). All user-visible
 * strings come from data-msg-* attributes on the admin shell
 * (or on the picker button); the English fallbacks below are
 * only used when an attribute is missing.
 *
 * Plain JavaScript, no build step: enqueued by
 * AssetLoader::enqueueAdminEntry() on TrayDigita pages.
 */
(function () {
    'use strict';

    /**
     * Read a translatable string from the shell root.
     *
     * @param {string} key data-msg-* attribute name without the prefix.
     * @param {string} fallback English text used when the attribute is missing.
     * @return {string}
     */
    function msg(key, fallback) {
        var shell = document.querySelector('.traydigita-admin-shell');
        if (shell) {
            var value = shell.getAttribute('data-msg-' + key);
            if (value) {
                return value;
            }
        }
        return fallback;
    }

    /**
     * Create an element with an optional class and text content.
     *
     * Text is always set through textContent, never innerHTML, so
     * server data can never inject markup.
     *
     * @param {string} tag Tag name.
     * @param {string} className Class list, or ''.
     * @param {string} text Text content, or ''.
     * @return {HTMLElement}
     */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    /**
     * Parse JSON from a data attribute, returning null on failure.
     *
     * @param {Element} node Element carrying the attribute.
     * @param {string} attr Attribute name.
     * @return {any}
     */
    function readJson(node, attr) {
        var raw = node.getAttribute(attr);
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Modal core                                                          */
    /* ------------------------------------------------------------------ */

    var overlay = null;
    var dialog = null;
    var titleNode = null;
    var bodyNode = null;
    var footerNode = null;
    var trigger = null;
    var previousOverflow = '';
    var onCloseCallback = null;

    /**
     * Build the singleton overlay + dialog shell.
     *
     * @return {void}
     */
    function ensureShell() {
        if (overlay) {
            return;
        }
        overlay = el('div', 'traydigita-modal-overlay');
        overlay.hidden = true;
        dialog = el('div', 'traydigita-modal');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        var header = el('div', 'traydigita-modal-header');
        titleNode = el('h2', 'traydigita-modal-title');
        titleNode.id = 'traydigita-modal-title';
        dialog.setAttribute('aria-labelledby', 'traydigita-modal-title');
        var closeButton = el('button', 'traydigita-modal-close');
        closeButton.type = 'button';
        closeButton.setAttribute('aria-label', msg('close', 'Close'));
        var closeIcon = el('span', 'material-icons');
        closeIcon.setAttribute('aria-hidden', 'true');
        closeIcon.textContent = 'close';
        closeButton.appendChild(closeIcon);
        closeButton.addEventListener('click', closeModal);
        header.appendChild(titleNode);
        header.appendChild(closeButton);
        bodyNode = el('div', 'traydigita-modal-body');
        footerNode = el('div', 'traydigita-modal-footer');
        dialog.appendChild(header);
        dialog.appendChild(bodyNode);
        dialog.appendChild(footerNode);
        overlay.appendChild(dialog);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeModal();
            }
        });
        document.addEventListener('keydown', onKeyDown);
        document.body.appendChild(overlay);
    }

    /**
     * Focusable elements inside the dialog, in tab order.
     *
     * @return {Array<HTMLElement>}
     */
    function focusable() {
        var nodes = dialog.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        var list = [];
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (!node.disabled && node.offsetParent !== null) {
                list.push(node);
            }
        }
        return list;
    }

    /**
     * Global key handler: Esc closes, Tab is trapped in the dialog.
     *
     * @param {KeyboardEvent} event Key event.
     * @return {void}
     */
    function onKeyDown(event) {
        if (!overlay || overlay.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        var items = focusable();
        if (items.length === 0) {
            return;
        }
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    /**
     * Open the modal with fresh content.
     *
     * @param {Object} config {title, build(body), footer(footer), initialFocus, onClose, trigger}
     * @return {void}
     */
    function openModal(config) {
        ensureShell();
        trigger = config.trigger || document.activeElement;
        onCloseCallback = config.onClose || null;
        titleNode.textContent = config.title || '';
        while (bodyNode.firstChild) {
            bodyNode.removeChild(bodyNode.firstChild);
        }
        while (footerNode.firstChild) {
            footerNode.removeChild(footerNode.firstChild);
        }
        config.build(bodyNode);
        if (config.footer) {
            footerNode.hidden = false;
            config.footer(footerNode);
        } else {
            footerNode.hidden = true;
        }
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        overlay.hidden = false;
        var focusTarget = null;
        if (config.initialFocus) {
            focusTarget = dialog.querySelector(config.initialFocus);
        }
        if (!focusTarget) {
            var items = focusable();
            focusTarget = items.length > 0 ? items[0] : dialog;
        }
        /* preventScroll: focusing the picker search input must not
           scroll the overlay — on a narrow viewport that scroll
           would push the modal header out of view. Focus still
           lands in the dialog, so keyboard users can type at once. */
        focusTarget.focus({ preventScroll: true });
    }

    /**
     * Close the modal and return focus to the trigger.
     *
     * @return {void}
     */
    function closeModal() {
        if (!overlay || overlay.hidden) {
            return;
        }
        overlay.hidden = true;
        document.body.style.overflow = previousOverflow;
        var callback = onCloseCallback;
        onCloseCallback = null;
        if (callback) {
            callback();
        }
        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus();
        }
        trigger = null;
    }

    /**
     * Close button for modal footers.
     *
     * @return {HTMLButtonElement}
     */
    function closeButton() {
        var button = el('button', 'traydigita-admin-button', msg('close', 'Close'));
        button.type = 'button';
        button.addEventListener('click', closeModal);
        return button;
    }

    /* ------------------------------------------------------------------ */
    /* Module info popup                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Clean a dashicon slug: letters, digits and dashes only.
     *
     * @param {string} icon Raw icon slug.
     * @return {string}
     */
    function cleanIcon(icon) {
        var clean = String(icon || '').replace(/[^a-z0-9-]/g, '');
        return clean === '' ? 'admin-generic' : clean;
    }

    /**
     * Build one meta row (Version / Author / Category / Status).
     *
     * @param {HTMLElement} list Parent dl element.
     * @param {string} label Row label.
     * @param {string} value Row value.
     * @return {void}
     */
    function metaRow(list, label, value) {
        var row = el('div', 'traydigita-modal-meta-row');
        row.appendChild(el('dt', '', label));
        row.appendChild(el('dd', '', value));
        list.appendChild(row);
    }

    /**
     * Open the module info popup for one module.
     *
     * Sections, in order: (1) title in the header, (2) icon,
     * (3) features, (4) changelog, (5) version and other info
     * (author, category, status, settings link). A module
     * without features() shows its short description in the
     * Features section instead of an empty list; the changelog
     * always has at least the current-version entry because the
     * server builds that fallback.
     *
     * @param {Object} info Module info data (ModuleRegistry::moduleInfoData()).
     * @param {HTMLElement} from Trigger element to return focus to.
     * @return {void}
     */
    function openModuleInfo(info, from) {
        if (!info || typeof info !== 'object') {
            return;
        }
        var name = String(info.name || '');
        openModal({
            title: name,
            trigger: from,
            build: function (body) {
                var head = el('div', 'traydigita-modal-info-head');
                var icon = el('span', 'traydigita-modal-info-icon');
                var dashicon = el('span', 'dashicons dashicons-' + cleanIcon(info.icon));
                dashicon.setAttribute('aria-hidden', 'true');
                icon.appendChild(dashicon);
                var titles = el('div', '');
                titles.appendChild(el('p', 'traydigita-modal-info-name', name));
                titles.appendChild(el(
                    'p',
                    'traydigita-modal-info-author',
                    msg('author', 'By %s').replace('%s', String(info.author || ''))
                ));
                head.appendChild(icon);
                head.appendChild(titles);
                body.appendChild(head);

                var features = Array.isArray(info.features) ? info.features : [];
                var featuresSection = el('section', 'traydigita-modal-section');
                featuresSection.appendChild(el(
                    'h3',
                    'traydigita-modal-section-title',
                    msg('features', 'Features')
                ));
                if (features.length > 0) {
                    var list = el('ul', 'traydigita-modal-feature-list');
                    features.forEach(function (feature) {
                        list.appendChild(el('li', '', String(feature)));
                    });
                    featuresSection.appendChild(list);
                } else {
                    featuresSection.appendChild(el(
                        'p',
                        'traydigita-modal-info-description',
                        String(info.description || '')
                    ));
                }
                body.appendChild(featuresSection);

                var changelog = Array.isArray(info.changelog) ? info.changelog : [];
                var changelogSection = el('section', 'traydigita-modal-section');
                changelogSection.appendChild(el(
                    'h3',
                    'traydigita-modal-section-title',
                    msg('changelog', 'Changelog')
                ));
                changelog.forEach(function (entry) {
                    if (!entry || typeof entry !== 'object') {
                        return;
                    }
                    var block = el('div', 'traydigita-modal-changelog-entry');
                    block.appendChild(el(
                        'h4',
                        'traydigita-modal-changelog-version',
                        msg('version', 'Version %s').replace('%s', String(entry.version || ''))
                    ));
                    var changes = Array.isArray(entry.changes) ? entry.changes : [];
                    if (changes.length > 0) {
                        var changeList = el('ul', 'traydigita-modal-changelog-list');
                        changes.forEach(function (change) {
                            changeList.appendChild(el('li', '', String(change)));
                        });
                        block.appendChild(changeList);
                    }
                    changelogSection.appendChild(block);
                });
                body.appendChild(changelogSection);

                var details = el('section', 'traydigita-modal-section');
                details.appendChild(el(
                    'h3',
                    'traydigita-modal-section-title',
                    msg('details', 'Details')
                ));
                var meta = el('dl', 'traydigita-modal-meta');
                metaRow(meta, msg('label-version', 'Version'), String(info.version || ''));
                metaRow(meta, msg('label-author', 'Author'), String(info.author || ''));
                metaRow(
                    meta,
                    msg('label-category', 'Category'),
                    String(info.category_label || info.category || '')
                );
                metaRow(
                    meta,
                    msg('label-status', 'Status'),
                    String(info.statusLabel || info.status || '')
                );
                details.appendChild(meta);
                body.appendChild(details);
            },
            footer: function (footer) {
                var settingsLink = el(
                    'a',
                    'traydigita-admin-button traydigita-admin-button-primary',
                    msg('open-settings', 'Open settings')
                );
                settingsLink.href = String(info.settings_url || '#');
                footer.appendChild(settingsLink);
                footer.appendChild(closeButton());
            },
            initialFocus: '.traydigita-modal-close',
        });
    }

    /* ------------------------------------------------------------------ */
    /* Context rail: clamp + "More" trigger                                */
    /* ------------------------------------------------------------------ */

    /**
     * Show the rail "More" button only when the clamped
     * description actually overflows; otherwise the rail stays
     * exactly as it was (no broken "more" on short text).
     *
     * @return {void}
     */
    function initRail() {
        var description = document.querySelector('.traydigita-admin-rail-description');
        var more = document.querySelector('[data-traydigita-rail-more]');
        var rail = document.querySelector('.traydigita-admin-rail');
        if (!description || !more || !rail) {
            return;
        }
        description.classList.add('is-clamped');
        var info = readJson(rail, 'data-traydigita-module-info');
        if (!info) {
            return;
        }
        var check = function () {
            if (description.scrollHeight > description.clientHeight + 1) {
                more.hidden = false;
                more.classList.add('is-visible');
            } else {
                more.hidden = true;
                more.classList.remove('is-visible');
            }
        };
        more.addEventListener('click', function () {
            openModuleInfo(info, more);
        });
        check();
        window.addEventListener('load', check);
        window.addEventListener('resize', check);
    }

    /* ------------------------------------------------------------------ */
    /* Modules page: info button on every card                             */
    /* ------------------------------------------------------------------ */

    var moduleInfoById = null;

    /**
     * Module info data by id, parsed once from the list JSON.
     *
     * @return {Object|null}
     */
    function moduleInfoMap() {
        if (moduleInfoById !== null) {
            return moduleInfoById;
        }
        moduleInfoById = {};
        var list = document.querySelector('[data-traydigita-module-list]');
        if (!list) {
            return moduleInfoById;
        }
        var modules = readJson(list, 'data-modules');
        if (!Array.isArray(modules)) {
            return moduleInfoById;
        }
        modules.forEach(function (entry) {
            if (entry && typeof entry === 'object' && entry.id && entry.info) {
                moduleInfoById[String(entry.id)] = entry.info;
            }
        });
        return moduleInfoById;
    }

    /**
     * Inject an info button into every rendered module card that
     * does not have one yet. The list is re-rendered by the admin
     * bundle on filter / search / pagination, so this runs on
     * every DOM change inside the list container.
     *
     * @return {void}
     */
    function injectCardButtons() {
        var list = document.querySelector('[data-traydigita-module-list]');
        if (!list) {
            return;
        }
        var map = moduleInfoMap();
        var cards = list.querySelectorAll('[data-module-id]');
        cards.forEach(function (card) {
            if (card.querySelector('.traydigita-admin-card-info')) {
                return;
            }
            var id = card.getAttribute('data-module-id');
            var info = id ? map[id] : null;
            if (!info) {
                return;
            }
            var foot = card.querySelector('.traydigita-admin-card-foot, .traydigita-admin-row-actions');
            if (!foot) {
                return;
            }
            var button = el(
                'button',
                'traydigita-admin-button traydigita-admin-button-small traydigita-admin-card-info',
                msg('module-info', 'Module info')
            );
            button.type = 'button';
            button.addEventListener('click', function () {
                openModuleInfo(info, button);
            });
            foot.appendChild(button);
        });
    }

    /**
     * Watch the module list for re-renders and inject buttons.
     *
     * @return {void}
     */
    function initCards() {
        var list = document.querySelector('[data-traydigita-module-list]');
        if (!list) {
            return;
        }
        injectCardButtons();
        document.addEventListener('traydigita:admin-ui:ready', injectCardButtons);
        if (typeof MutationObserver !== 'undefined') {
            var scheduled = false;
            var observer = new MutationObserver(function () {
                if (scheduled) {
                    return;
                }
                scheduled = true;
                window.requestAnimationFrame(function () {
                    scheduled = false;
                    injectCardButtons();
                });
            });
            observer.observe(list, { childList: true, subtree: true });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Picker (Mailer provider + SMTP preset: same component)              */
    /* ------------------------------------------------------------------ */

    /**
     * Open a searchable picker popup for one field.
     *
     * @param {Object} picker {title, searchPlaceholder, emptyText, items, value, onSelect}
     * @param {HTMLElement} from Trigger element to return focus to.
     * @return {void}
     */
    function openPicker(picker, from) {
        var items = Array.isArray(picker.items) ? picker.items : [];
        var selectedId = String(picker.value || '');
        openModal({
            title: picker.title,
            trigger: from,
            initialFocus: '.traydigita-modal-search',
            build: function (body) {
                var search = el('input', 'traydigita-modal-search');
                search.type = 'search';
                search.placeholder = picker.searchPlaceholder;
                search.setAttribute('aria-label', picker.searchPlaceholder);
                var list = el('ul', 'traydigita-modal-picker-list');
                list.setAttribute('role', 'listbox');
                var empty = el('p', 'traydigita-modal-picker-empty', picker.emptyText);
                empty.hidden = true;
                body.appendChild(search);
                body.appendChild(list);
                body.appendChild(empty);

                var rowButtons = [];
                items.forEach(function (item) {
                    var row = el('li', '');
                    var button = el('button', 'traydigita-modal-picker-item');
                    button.type = 'button';
                    button.setAttribute('role', 'option');
                    button.setAttribute('aria-selected', String(item.id) === selectedId ? 'true' : 'false');
                    if (String(item.id) === selectedId) {
                        button.classList.add('is-selected');
                    }
                    var logo = document.createElement('img');
                    logo.className = 'traydigita-modal-picker-logo';
                    logo.src = String(item.logo || '');
                    logo.alt = '';
                    logo.setAttribute('aria-hidden', 'true');
                    logo.loading = 'lazy';
                    var text = el('span', 'traydigita-modal-picker-text');
                    text.appendChild(el('span', 'traydigita-modal-picker-name', String(item.label || item.id)));
                    var meta = String(item.meta || item.description || '');
                    if (meta !== '') {
                        text.appendChild(el('span', 'traydigita-modal-picker-description', meta));
                    }
                    var check = el('span', 'material-icons traydigita-modal-picker-check');
                    check.setAttribute('aria-hidden', 'true');
                    check.textContent = 'check';
                    button.appendChild(logo);
                    button.appendChild(text);
                    button.appendChild(check);
                    button.addEventListener('click', function () {
                        picker.onSelect(String(item.id));
                        closeModal();
                    });
                    row.appendChild(button);
                    list.appendChild(row);
                    rowButtons.push({
                        button: button,
                        haystack: (String(item.label || '') + ' ' + meta + ' ' + String(item.description || '')).toLowerCase(),
                    });
                });

                var applyFilter = function () {
                    var query = search.value.trim().toLowerCase();
                    var visible = 0;
                    rowButtons.forEach(function (entry) {
                        var show = query === '' || entry.haystack.indexOf(query) !== -1;
                        entry.button.parentNode.hidden = !show;
                        if (show) {
                            visible++;
                        }
                    });
                    empty.hidden = visible !== 0;
                };
                search.addEventListener('input', applyFilter);

                search.addEventListener('keydown', function (event) {
                    if (event.key !== 'ArrowDown') {
                        return;
                    }
                    event.preventDefault();
                    for (var i = 0; i < rowButtons.length; i++) {
                        if (!rowButtons[i].button.parentNode.hidden) {
                            rowButtons[i].button.focus();
                            return;
                        }
                    }
                });
                rowButtons.forEach(function (entry, index) {
                    entry.button.addEventListener('keydown', function (event) {
                        var next = null;
                        if (event.key === 'ArrowDown') {
                            for (var i = index + 1; i < rowButtons.length; i++) {
                                if (!rowButtons[i].button.parentNode.hidden) {
                                    next = rowButtons[i].button;
                                    break;
                                }
                            }
                        } else if (event.key === 'ArrowUp') {
                            for (var j = index - 1; j >= 0; j--) {
                                if (!rowButtons[j].button.parentNode.hidden) {
                                    next = rowButtons[j].button;
                                    break;
                                }
                            }
                            if (!next) {
                                next = search;
                            }
                        }
                        if (next) {
                            event.preventDefault();
                            next.focus();
                        }
                    });
                });
            },
            footer: function (footer) {
                footer.appendChild(closeButton());
            },
        });
    }

    /**
     * Wire every picker field button on the page.
     *
     * Each button carries its items as JSON (logo URL, label,
     * meta line, description) and points at the hidden input
     * that holds the real setting value, so picking an option
     * sets exactly the value the old <select> set and the
     * existing save flow is untouched.
     *
     * @return {void}
     */
    function initPickers() {
        var buttons = document.querySelectorAll('[data-traydigita-picker]');
        buttons.forEach(function (button) {
            var inputId = button.getAttribute('data-picker-input') || '';
            var input = inputId !== '' ? document.getElementById(inputId) : null;
            if (!input) {
                return;
            }
            var items = readJson(button, 'data-picker-items');
            if (!Array.isArray(items) || items.length === 0) {
                return;
            }
            var labelNode = button.querySelector('.traydigita-mailer-picker-label');
            var logoNode = button.querySelector('.traydigita-modal-picker-logo');
            button.addEventListener('click', function () {
                openPicker({
                    title: button.getAttribute('data-picker-title') || '',
                    searchPlaceholder: button.getAttribute('data-picker-search') || '',
                    emptyText: button.getAttribute('data-picker-empty') || '',
                    items: items,
                    value: input.value,
                    onSelect: function (id) {
                        var item = null;
                        items.forEach(function (candidate) {
                            if (String(candidate.id) === id) {
                                item = candidate;
                            }
                        });
                        input.value = id;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        if (item) {
                            if (labelNode) {
                                labelNode.textContent = String(item.label || id);
                            }
                            if (logoNode && item.logo) {
                                logoNode.src = String(item.logo);
                            }
                        }
                    },
                }, button);
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Boot                                                                */
    /* ------------------------------------------------------------------ */

    function init() {
        initRail();
        initCards();
        initPickers();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
