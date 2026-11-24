/**
 * TrayDigita shared modal.
 *
 * ONE modal component for every popup in the admin (see
 * assets/css/traydigita-modal.css for the matching styles):
 *
 * - the module info popup, opened from the context rail "More"
 *   trigger and from the info button injected into each Modules
 *   page card: a tabbed body (Features / Changelog / Details)
 *   under a compact identity strip, with only the active panel
 *   scrolling;
 * - the Mailer provider picker and SMTP preset picker (the same
 *   picker component with different data): a searchable list
 *   with a list / grid view switcher reusing the Modules page
 *   switcher pattern.
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
    var viewportFitHandler = null;

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
     * Clear the visual-viewport sizing on the overlay.
     *
     * Restores the plain `inset: 0` sizing from the stylesheet.
     *
     * @return {void}
     */
    function clearOverlayViewportFit() {
        if (!overlay) {
            return;
        }
        overlay.style.left = '';
        overlay.style.right = '';
        overlay.style.width = '';
    }

    /**
     * Pin the overlay to the visual viewport.
     *
     * A fixed overlay is sized against the layout viewport, so
     * when the underlying page overflows horizontally (a settings
     * page that lays out wider than the phone screen) the overlay
     * is wider than what the user sees, and the dialog, close
     * button and footer buttons sit partly past the visual
     * viewport edge. window.visualViewport reports the part of
     * the page that is actually visible, so when it is narrower
     * than the layout viewport the overlay is narrowed to it
     * and shifted by its horizontal offset. When the two
     * viewports agree (desktop, and phones whose page does not
     * overflow) nothing is set and the overlay keeps its plain
     * `inset: 0` sizing. Listened to on visualViewport resize
     * and scroll, so pinch-zooming or rotating while the modal
     * is open re-fits it.
     *
     * @return {void}
     */
    function fitOverlayToVisualViewport() {
        if (!overlay || overlay.hidden) {
            return;
        }
        var viewport = window.visualViewport;
        if (!viewport) {
            return;
        }
        if (viewport.width >= window.innerWidth - 1) {
            clearOverlayViewportFit();
            return;
        }
        overlay.style.left = viewport.offsetLeft + 'px';
        overlay.style.right = 'auto';
        overlay.style.width = viewport.width + 'px';
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
        /* Reset the body to its plain variant: a previous popup
           may have added a layout class (the module info tabs),
           and the next popup must start from the shared base. */
        bodyNode.className = 'traydigita-modal-body';
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
        fitOverlayToVisualViewport();
        if (window.visualViewport) {
            viewportFitHandler = fitOverlayToVisualViewport;
            window.visualViewport.addEventListener('resize', viewportFitHandler);
            window.visualViewport.addEventListener('scroll', viewportFitHandler);
        }
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
        if (window.visualViewport && viewportFitHandler) {
            window.visualViewport.removeEventListener('resize', viewportFitHandler);
            window.visualViewport.removeEventListener('scroll', viewportFitHandler);
            viewportFitHandler = null;
        }
        clearOverlayViewportFit();
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
     * Tabbed layout: a compact identity strip (icon, name,
     * version badge, Core badge for built-in modules, author)
     * sits above an underline tab bar
     * that reuses the settings shell's tab pattern
     * (.traydigita-admin-tabs / .traydigita-admin-tab), and
     * only the active panel is shown — (1) Features, (2)
     * Changelog, (3) Details (version, author, category,
     * status, settings link). The tab headings replace the
     * old stacked section titles, so nothing is repeated.
     * A module without features() shows its short description
     * in the Features panel instead of an empty list; the
     * changelog always has at least the current-version entry
     * because the server builds that fallback. The body is a
     * flex column and only the active panel scrolls, so a long
     * changelog never pushes the footer off screen and a
     * short module never leaves a tall empty modal.
     *
     * Tabs follow the roving-tabindex pattern: the active tab
     * is tabbable, the others are reached with the arrow keys,
     * Home / End; Tab stays in the modal through the existing
     * focus trap and Esc still closes it.
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
                body.classList.add('traydigita-modal-body-tabs');

                var head = el('div', 'traydigita-modal-info-head');
                var icon = el('span', 'traydigita-modal-info-icon');
                var dashicon = el('span', 'dashicons dashicons-' + cleanIcon(info.icon));
                dashicon.setAttribute('aria-hidden', 'true');
                icon.appendChild(dashicon);
                var titles = el('div', 'traydigita-modal-info-titles');
                var nameNode = el('p', 'traydigita-modal-info-name', name);
                if (String(info.version || '') !== '') {
                    nameNode.appendChild(el(
                        'span',
                        'traydigita-modal-info-version',
                        String(info.version || '')
                    ));
                }
                // Built-in modules carry a "Core" badge in the same
                // pill style as the version badge (same class, so
                // the two can never drift apart). The label comes
                // from data-msg-core-badge on the shell: "Core" is
                // a proper name, so it is translated with its own
                // context and stays "Core" in every locale.
                // Third-party modules (info.built_in false) get no
                // badge. The extra class is only a semantic hook.
                if (info.built_in) {
                    nameNode.appendChild(el(
                        'span',
                        'traydigita-modal-info-version traydigita-modal-info-core',
                        msg('core-badge', 'Core')
                    ));
                }
                titles.appendChild(nameNode);
                titles.appendChild(el(
                    'p',
                    'traydigita-modal-info-author',
                    msg('author', 'By %s').replace('%s', String(info.author || ''))
                ));
                head.appendChild(icon);
                head.appendChild(titles);
                body.appendChild(head);

                /* --- Panels (built first, tabs point at them) --- */

                var features = Array.isArray(info.features) ? info.features : [];
                var featuresPanel = el('section', 'traydigita-modal-tab-panel');
                if (features.length > 0) {
                    var list = el('ul', 'traydigita-modal-feature-list');
                    features.forEach(function (feature) {
                        list.appendChild(el('li', '', String(feature)));
                    });
                    featuresPanel.appendChild(list);
                } else {
                    featuresPanel.appendChild(el(
                        'p',
                        'traydigita-modal-info-description',
                        String(info.description || '')
                    ));
                }

                var changelog = Array.isArray(info.changelog) ? info.changelog : [];
                var changelogPanel = el('section', 'traydigita-modal-tab-panel');
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
                    changelogPanel.appendChild(block);
                });

                var detailsPanel = el('section', 'traydigita-modal-tab-panel');
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
                var settingsRow = el('div', 'traydigita-modal-meta-row');
                settingsRow.appendChild(el('dt', '', msg('label-settings', 'Settings')));
                var settingsCell = el('dd', '');
                if (String(info.status || '') === 'disabled' && String(info.id || '') !== '') {
                    // A disabled module offers no "Open settings"
                    // here: the popup is the module's front door,
                    // and settings that do nothing must not be
                    // one click away without saying so. Enable
                    // instead, through the existing toggle
                    // endpoint — the settings stay reachable from
                    // the module list for pre-configuration.
                    var enableButton = el(
                        'button',
                        'traydigita-admin-button traydigita-admin-button-primary',
                        msg('enable-module', 'Enable module')
                    );
                    enableButton.type = 'button';
                    enableButton.addEventListener('click', function () {
                        enableModule(String(info.id), enableButton);
                    });
                    settingsCell.appendChild(enableButton);
                } else {
                    var settingsLink = document.createElement('a');
                    settingsLink.className = 'traydigita-modal-meta-link';
                    settingsLink.href = String(info.settings_url || '#');
                    settingsLink.textContent = msg('open-settings', 'Open settings');
                    settingsCell.appendChild(settingsLink);
                }
                settingsRow.appendChild(settingsCell);
                meta.appendChild(settingsRow);
                detailsPanel.appendChild(meta);

                /* --- Tab bar (settings shell underline pattern) --- */

                var tabs = [
                    {
                        id: 'features',
                        label: msg('features', 'Features'),
                        panel: featuresPanel,
                    },
                    {
                        id: 'changelog',
                        label: msg('changelog', 'Changelog'),
                        panel: changelogPanel,
                    },
                    {
                        id: 'details',
                        label: msg('details', 'Details'),
                        panel: detailsPanel,
                    },
                ];
                var tabList = el('div', 'traydigita-admin-tabs');
                tabList.setAttribute('role', 'tablist');
                tabList.setAttribute('aria-label', name);
                var tabButtons = [];
                tabs.forEach(function (tab, index) {
                    var button = el('button', 'traydigita-admin-tab', tab.label);
                    button.type = 'button';
                    button.setAttribute('role', 'tab');
                    button.id = 'traydigita-modal-tab-' + tab.id;
                    button.setAttribute('aria-controls', 'traydigita-modal-panel-' + tab.id);
                    button.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
                    button.tabIndex = index === 0 ? 0 : -1;
                    if (index === 0) {
                        button.classList.add('traydigita-admin-tab-active');
                    }
                    button.addEventListener('click', function () {
                        activateTab(index, false);
                    });
                    tab.panel.id = 'traydigita-modal-panel-' + tab.id;
                    tab.panel.setAttribute('role', 'tabpanel');
                    tab.panel.setAttribute('aria-labelledby', button.id);
                    tab.panel.hidden = index !== 0;
                    tabList.appendChild(button);
                    tabButtons.push(button);
                });

                /**
                 * Show one tab and hide the others.
                 *
                 * @param {number} index Tab index to activate.
                 * @param {boolean} focusTab Move focus to the tab.
                 * @return {void}
                 */
                function activateTab(index, focusTab) {
                    tabs.forEach(function (tab, i) {
                        var selected = i === index;
                        tabButtons[i].classList.toggle('traydigita-admin-tab-active', selected);
                        tabButtons[i].setAttribute('aria-selected', selected ? 'true' : 'false');
                        tabButtons[i].tabIndex = selected ? 0 : -1;
                        tab.panel.hidden = !selected;
                    });
                    if (focusTab) {
                        tabButtons[index].focus();
                    }
                }

                tabList.addEventListener('keydown', function (event) {
                    var current = tabButtons.indexOf(document.activeElement);
                    if (current === -1) {
                        return;
                    }
                    var next = -1;
                    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                        next = (current + 1) % tabButtons.length;
                    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                        next = (current - 1 + tabButtons.length) % tabButtons.length;
                    } else if (event.key === 'Home') {
                        next = 0;
                    } else if (event.key === 'End') {
                        next = tabButtons.length - 1;
                    }
                    if (next !== -1) {
                        event.preventDefault();
                        activateTab(next, true);
                    }
                });

                body.appendChild(tabList);
                tabs.forEach(function (tab) {
                    body.appendChild(tab.panel);
                });
            },
            footer: function (footer) {
                if (String(info.status || '') === 'disabled' && String(info.id || '') !== '') {
                    // Same rule as the Details tab's Settings row
                    // above: no "Open settings" for a disabled
                    // module — Enable instead, through the
                    // existing toggle endpoint.
                    var footerEnable = el(
                        'button',
                        'traydigita-admin-button traydigita-admin-button-primary',
                        msg('enable-module', 'Enable module')
                    );
                    footerEnable.type = 'button';
                    footerEnable.addEventListener('click', function () {
                        enableModule(String(info.id), footerEnable);
                    });
                    footer.appendChild(footerEnable);
                } else {
                    var settingsLink = el(
                        'a',
                        'traydigita-admin-button traydigita-admin-button-primary',
                        msg('open-settings', 'Open settings')
                    );
                    settingsLink.href = String(info.settings_url || '#');
                    footer.appendChild(settingsLink);
                }
                footer.appendChild(closeButton());
            },
            initialFocus: '.traydigita-modal-close',
        });
    }

    /* ------------------------------------------------------------------ */
    /* Module enable (disabled banner + info popup)                      */
    /* ------------------------------------------------------------------ */

    /**
     * Enable one module through the existing toggle endpoint,
     * then reload so the page reflects the new state.
     *
     * The same endpoint the module list toggles use
     * (traydigita_admin_toggle_module): module id, enabled=1,
     * the shell nonce from the shell root's data attributes.
     * The button is disabled while the request runs; on failure
     * it comes back carrying the error as its label, so the
     * failure is visible without a toast system.
     *
     * @param {string} moduleId Module id to enable.
     * @param {HTMLElement} button Button that triggered the enable.
     * @return {void}
     */
    function enableModule(moduleId, button) {
        var shell = document.querySelector('.traydigita-admin-shell');
        if (!shell || moduleId === '') {
            return;
        }
        var ajaxUrl = shell.getAttribute('data-ajax-url') || '';
        var nonce = shell.getAttribute('data-nonce') || '';
        if (ajaxUrl === '' || nonce === '') {
            return;
        }
        button.textContent = msg('enable-module', 'Enable module');
        button.disabled = true;
        var body = new FormData();
        body.set('action', 'traydigita_admin_toggle_module');
        body.set('module', moduleId);
        body.set('enabled', '1');
        body.set('_ajax_nonce', nonce);
        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (result && result.success) {
                    window.location.reload();
                    return;
                }
                button.disabled = false;
                var message = result && result.data
                    && typeof result.data.message === 'string'
                    && result.data.message !== ''
                    ? result.data.message
                    : msg('toggle-error', 'Could not change the module state. Please try again.');
                button.textContent = message;
            })
            .catch(function () {
                button.disabled = false;
                button.textContent = msg('toggle-error', 'Could not change the module state. Please try again.');
            });
    }

    /**
     * Wire the disabled-banner Enable button on module settings
     * pages (rendered by templates/admin/shell.php). The popup's
     * own Enable buttons are wired where they are built.
     *
     * @return {void}
     */
    function initEnableButtons() {
        var buttons = document.querySelectorAll('[data-traydigita-enable-module]');
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                enableModule(button.getAttribute('data-traydigita-enable-module') || '', button);
            });
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
                /* Toolbar: the search input plus the grid / list
                   view switcher — the same switcher the Modules
                   page uses (.traydigita-ui-view-switch with
                   grid_view / list icons and the same active
                   state), so the popup looks like it belongs to
                   this admin. List is the default view; the
                   switch only changes presentation, the search
                   filter below applies in both views. */
                var toolbar = el('div', 'traydigita-modal-picker-toolbar');
                var search = el('input', 'traydigita-modal-search');
                search.type = 'search';
                search.placeholder = picker.searchPlaceholder;
                search.setAttribute('aria-label', picker.searchPlaceholder);
                toolbar.appendChild(search);
                var viewSwitch = el('div', 'traydigita-ui-view-switch');
                viewSwitch.setAttribute('role', 'group');
                viewSwitch.setAttribute('aria-label', msg('view', 'View'));
                var list = el('ul', 'traydigita-modal-picker-list');
                list.setAttribute('role', 'listbox');
                var viewButtons = {};
                var currentView = 'list';

                /**
                 * Switch the picker between list and grid view.
                 *
                 * @param {string} view 'list' or 'cards'.
                 * @return {void}
                 */
                function setView(view) {
                    if (view !== 'cards' && view !== 'list') {
                        return;
                    }
                    currentView = view;
                    list.classList.toggle('is-grid', view === 'cards');
                    ['list', 'cards'].forEach(function (key) {
                        var active = key === view;
                        viewButtons[key].classList.toggle(
                            'traydigita-ui-view-button-active',
                            active
                        );
                        viewButtons[key].setAttribute('aria-pressed', active ? 'true' : 'false');
                    });
                }

                [
                    { key: 'list', icon: 'list', label: msg('view-list', 'List view') },
                    { key: 'cards', icon: 'grid_view', label: msg('view-cards', 'Card view') },
                ].forEach(function (view) {
                    var button = el('button', 'traydigita-ui-view-button');
                    button.type = 'button';
                    var viewIcon = el('span', 'material-icons traydigita-ui-view-button-icon');
                    viewIcon.setAttribute('aria-hidden', 'true');
                    viewIcon.textContent = view.icon;
                    button.appendChild(viewIcon);
                    button.setAttribute('aria-label', view.label);
                    button.setAttribute('aria-pressed', view.key === currentView ? 'true' : 'false');
                    button.title = view.label;
                    if (view.key === currentView) {
                        button.classList.add('traydigita-ui-view-button-active');
                    }
                    button.addEventListener('click', function () {
                        setView(view.key);
                    });
                    viewSwitch.appendChild(button);
                    viewButtons[view.key] = button;
                });
                toolbar.appendChild(viewSwitch);
                var empty = el('p', 'traydigita-modal-picker-empty', picker.emptyText);
                empty.hidden = true;
                body.appendChild(toolbar);
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
                    /* Readiness (Mailer provider picker only — the
                       preset picker carries no flags, so a missing
                       flag means ready): an unverified provider is
                       shown but cannot be picked. The server-side
                       save still refuses it, so this is a guide,
                       not the gate. */
                    var isReady = item.ready !== false;
                    var isProven = item.proven === true;
                    if (!isReady) {
                        button.disabled = true;
                        button.classList.add('is-disabled');
                        button.setAttribute('aria-disabled', 'true');
                    }
                    var logo = document.createElement('img');
                    logo.className = 'traydigita-modal-picker-logo';
                    logo.src = String(item.logo || '');
                    logo.alt = '';
                    logo.setAttribute('aria-hidden', 'true');
                    logo.loading = 'lazy';
                    var text = el('span', 'traydigita-modal-picker-text');
                    var label = String(item.label || item.id);
                    text.appendChild(el('span', 'traydigita-modal-picker-name', label));
                    if (!isReady) {
                        text.appendChild(el('span', 'traydigita-modal-picker-badge', msg('picker-unverified', 'Unverified')));
                    } else if (isProven) {
                        text.appendChild(el('span', 'traydigita-modal-picker-badge traydigita-modal-picker-badge-verified', msg('picker-verified-live', 'Verified live')));
                    }
                    var meta = String(item.meta || item.description || '');
                    if (meta !== '') {
                        text.appendChild(el('span', 'traydigita-modal-picker-description', meta));
                    }
                    /* The tile tooltip always starts with the full
                       name: grid view clamps the name to one line
                       with an ellipsis, so a long name (e.g.
                       "Mailchimp Transactional") stays
                       readable on hover, and the description
                       follows it when there is one. */
                    button.title = meta !== '' ? label + ' — ' + meta : label;
                    if (!isReady) {
                        button.title += ' — ' + msg('picker-unverified', 'Unverified');
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
                        if (!rowButtons[i].button.parentNode.hidden && !rowButtons[i].button.disabled) {
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
                                if (!rowButtons[i].button.parentNode.hidden && !rowButtons[i].button.disabled) {
                                    next = rowButtons[i].button;
                                    break;
                                }
                            }
                        } else if (event.key === 'ArrowUp') {
                            for (var j = index - 1; j >= 0; j--) {
                                if (!rowButtons[j].button.parentNode.hidden && !rowButtons[j].button.disabled) {
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
    /* Public API                                                           */
    /* ------------------------------------------------------------------ */

    /* One public opener for popups that are not a picker or a module
       info card (for example the Mailer DKIM DNS check): the same
       overlay, header / body / footer, focus trap and close
       behaviour as every other popup, so no new modal style is
       ever invented. `config` is the same object `openModal`
       takes: `title`, `build(body)`, optional `footer(footer)`,
       `trigger`, `onClose`, `initialFocus`. */
    window.TrayDigitaModal = {
        open: openModal,
    };

    /* ------------------------------------------------------------------ */
    /* Boot                                                                */
    /* ------------------------------------------------------------------ */

    function init() {
        initRail();
        initCards();
        initPickers();
        initEnableButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
