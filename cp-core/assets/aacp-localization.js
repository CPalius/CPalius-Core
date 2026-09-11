/**
 * AACP locale management — Translation Explorer inline edit, tabs, filters, import.
 *
 * Same vanilla-JS pattern as aacp-api-keys.js; no-ops without data-localization-root.
 * Failed AJAX restores the cell value. Phase 3: locale count is dynamic from the server.
 */
function initAacpLocalization(root) {
    const csrfToken = root.dataset.localizationCsrf;
    const updateUrl = root.dataset.localizationUpdateUrl;
    const importUrl = root.dataset.localizationImportUrl;

    if (!csrfToken || !updateUrl) {
        return;
    }

    const rows = Array.from(root.querySelectorAll('[data-localization-row]'));
    const filterInput = root.querySelector('[data-localization-filter]');
    const groupFilter = root.querySelector('[data-localization-group-filter]');
    const incompleteFilter = root.querySelector('[data-localization-incomplete-filter]');
    const countEl = root.querySelector('[data-localization-count]');
    const tabs = Array.from(root.querySelectorAll('[data-localization-tab]'));

    const ACTIVE_TAB_CLASSES = ['border-primary-500', 'text-primary-400'];
    const IDLE_TAB_CLASSES = ['border-transparent', 'text-slate-500', 'hover:text-slate-300'];

    let activeLocale = '__all__';

    /* ------------------------------------------------------------------ */
    /* Inline editing                                                       */
    /* ------------------------------------------------------------------ */

    function flashCell(cell, ok) {
        cell.classList.remove('!bg-success-500/10', '!bg-danger-500/10');
        cell.classList.add(ok ? '!bg-success-500/10' : '!bg-danger-500/10');
        window.setTimeout(() => {
            cell.classList.remove('!bg-success-500/10', '!bg-danger-500/10');
        }, 900);
    }

    function markMissingState(cell, value) {
        const row = cell.closest('[data-localization-row]');
        const locale = cell.dataset.locale;
        const isEmpty = String(value).trim() === '';

        cell.classList.toggle('!bg-warning-500/5', isEmpty);

        if (!row) {
            return;
        }

        const missing = new Set((row.dataset.missing || '').split(' ').filter(Boolean));

        if (isEmpty) {
            missing.add(locale);
        } else {
            missing.delete(locale);
        }

        row.dataset.missing = Array.from(missing).join(' ');
        row.dataset.incomplete = missing.size > 0 ? '1' : '0';
    }

    function beginEdit(cell) {
        if (cell.querySelector('input')) {
            return;
        }

        const valueEl = cell.querySelector('[data-localization-value]');
        const originalValue = valueEl ? valueEl.textContent : '';

        const input = document.createElement('input');
        input.type = 'text';
        input.value = originalValue;
        input.className = 'form-control !w-full !bg-dark-1 !border-white/10 !text-slate-100 !py-1 !px-2 text-fs-sm';

        if (valueEl) {
            valueEl.replaceWith(input);
        }
        input.focus();
        input.select();

        function finishEdit(commit) {
            if (!input.isConnected) {
                return;
            }

            const newValue = input.value;
            const span = document.createElement('span');
            span.setAttribute('data-localization-value', '');

            if (!commit || newValue === originalValue) {
                span.textContent = originalValue;
                input.replaceWith(span);
                return;
            }

            span.textContent = newValue;
            input.replaceWith(span);
            markMissingState(cell, newValue);
            saveValue(cell, newValue, span, originalValue);
        }

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                finishEdit(true);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                finishEdit(false);
            }
        });

        input.addEventListener('blur', () => finishEdit(true));
    }

    async function saveValue(cell, value, span, originalValue) {
        const row = cell.closest('[data-localization-row]');
        const group = row ? row.dataset.group : '';
        const key = row ? row.dataset.key : '';
        const locale = cell.dataset.locale;

        try {
            const formData = new FormData();
            formData.set('_token', csrfToken);
            formData.set('group', group);
            formData.set('key', key);
            formData.set('locale', locale);
            formData.set('value', value);

            const response = await fetch(updateUrl, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' },
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            flashCell(cell, true);
            applyFilter();
        } catch (error) {
            // Fail-safe: revert cell on server rejection — no false "saved" state.
            span.textContent = originalValue;
            markMissingState(cell, originalValue);
            flashCell(cell, false);
        }
    }

    root.querySelectorAll('[data-localization-cell]').forEach((cell) => {
        cell.addEventListener('click', () => beginEdit(cell));
    });

    /* ------------------------------------------------------------------ */
    /* Tabs + filters                                                       */
    /* ------------------------------------------------------------------ */

    function setActiveTab(locale) {
        activeLocale = locale;

        tabs.forEach((tab) => {
            const isActive = tab.dataset.localizationTab === locale;
            tab.classList.remove(...ACTIVE_TAB_CLASSES, ...IDLE_TAB_CLASSES);
            tab.classList.add(...(isActive ? ACTIVE_TAB_CLASSES : IDLE_TAB_CLASSES));
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        // Dim non-active locale columns (not hidden — source locale stays visible).
        root.querySelectorAll('[data-localization-cell]').forEach((cell) => {
            const dim = locale !== '__all__' && cell.dataset.locale !== locale;
            cell.classList.toggle('opacity-40', dim);
        });

        applyFilter();
    }

    function applyFilter() {
        const term = filterInput ? filterInput.value.trim().toLowerCase() : '';
        const group = groupFilter ? groupFilter.value : '';
        const onlyIncomplete = incompleteFilter ? incompleteFilter.checked : false;

        let visibleCount = 0;

        rows.forEach((row) => {
            const haystack = `${row.dataset.group} ${row.dataset.key} ${row.textContent}`.toLowerCase();
            const missing = (row.dataset.missing || '').split(' ').filter(Boolean);

            let visible = term === '' || haystack.includes(term);

            if (visible && group !== '' && row.dataset.group !== group) {
                visible = false;
            }

            if (visible && onlyIncomplete) {
                // "All" tab: any missing locale; specific tab: missing in that locale only.
                visible = activeLocale === '__all__'
                    ? row.dataset.incomplete === '1'
                    : missing.includes(activeLocale);
            }

            row.classList.toggle('hidden', !visible);

            if (visible) {
                visibleCount += 1;
            }
        });

        if (countEl) {
            countEl.textContent = `${visibleCount} / ${rows.length}`;
        }
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => setActiveTab(tab.dataset.localizationTab));
    });

    if (filterInput) {
        filterInput.addEventListener('input', applyFilter);
    }

    if (groupFilter) {
        groupFilter.addEventListener('change', applyFilter);
    }

    if (incompleteFilter) {
        incompleteFilter.addEventListener('change', applyFilter);
    }

    // Open the tab matching ?locale=xx when present.
    const focusLocale = root.dataset.localizationFocus;
    const hasFocusTab = focusLocale && tabs.some((tab) => tab.dataset.localizationTab === focusLocale);

    setActiveTab(hasFocusTab ? focusLocale : '__all__');

    /* ------------------------------------------------------------------ */
    /* Import                                                               */
    /* ------------------------------------------------------------------ */

    const importTrigger = document.querySelector('[data-localization-import-trigger]');
    const importInput = document.querySelector('[data-localization-import-input]');

    if (importTrigger && importInput && importUrl) {
        importTrigger.addEventListener('click', () => importInput.click());

        importInput.addEventListener('change', async () => {
            const file = importInput.files && importInput.files[0];
            if (!file) {
                return;
            }

            try {
                const formData = new FormData();
                formData.set('_token', csrfToken);
                formData.set('file', file);

                const response = await fetch(importUrl, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json' },
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.error || `HTTP ${response.status}`);
                }

                window.location.reload();
            } catch (error) {
                // Fail-safe: failed import leaves the page unchanged for retry.
            } finally {
                importInput.value = '';
            }
        });
    }
}

document.querySelectorAll('[data-localization-root]').forEach(initAacpLocalization);
