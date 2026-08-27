/**
 * AACP "Dil Yönetimi" sayfası — Translation Explorer tıkla-düzenle akışı,
 * client-side arama filtresi ve içe aktarma (import) tetikleyicisi.
 *
 * aacp-api-keys.js ile aynı desen (framework'süz/vanilla JS, "sıfır
 * bağımlılık" ilkesi): data-localization-root bulunamazsa sessizce hiçbir
 * şey yapmaz. Bir AJAX isteği başarısız olursa hücre eski değerine geri
 * döner, sayfa asla çökmez.
 */
function initAacpLocalization(root) {
    const csrfToken = root.dataset.localizationCsrf;
    const updateUrl = root.dataset.localizationUpdateUrl;
    const importUrl = root.dataset.localizationImportUrl;

    if (!csrfToken || !updateUrl) {
        return;
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }

    function flashCell(cell, ok) {
        cell.classList.remove('!bg-success-500/10', '!bg-danger-500/10');
        cell.classList.add(ok ? '!bg-success-500/10' : '!bg-danger-500/10');
        window.setTimeout(() => {
            cell.classList.remove('!bg-success-500/10', '!bg-danger-500/10');
        }, 900);
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
        } catch (error) {
            span.textContent = originalValue;
            flashCell(cell, false);
        }
    }

    root.querySelectorAll('[data-localization-cell]').forEach((cell) => {
        cell.addEventListener('click', () => beginEdit(cell));
    });

    const filterInput = document.querySelector('[data-localization-filter]');
    const countEl = document.querySelector('[data-localization-count]');
    const rows = Array.from(root.querySelectorAll('[data-localization-row]'));

    function applyFilter() {
        const term = filterInput ? filterInput.value.trim().toLowerCase() : '';
        let visibleCount = 0;

        rows.forEach((row) => {
            const haystack = `${row.dataset.group} ${row.dataset.key} ${row.textContent}`.toLowerCase();
            const visible = term === '' || haystack.includes(term);
            row.classList.toggle('hidden', !visible);
            if (visible) {
                visibleCount += 1;
            }
        });

        if (countEl) {
            countEl.textContent = `${visibleCount} / ${rows.length}`;
        }
    }

    if (filterInput) {
        filterInput.addEventListener('input', applyFilter);
        applyFilter();
    }

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
                // Fail-safe: içe aktarma başarısız olursa sayfa mevcut
                // hâliyle kalır, kullanıcı tekrar deneyebilir.
            } finally {
                importInput.value = '';
            }
        });
    }
}

document.querySelectorAll('[data-localization-root]').forEach(initAacpLocalization);
