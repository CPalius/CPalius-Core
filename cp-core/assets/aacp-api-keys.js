/**
 * AACP "API Yönetimi" sayfası — üret/pasif et/sil AJAX akışları.
 *
 * aacp-plugins.js ile aynı desen (bilinçli olarak framework'süz/vanilla
 * JS — AACP'nin "sıfır bağımlılık" ilkesi): data-api-keys-root
 * bulunamazsa sessizce hiçbir şey yapmaz.
 *
 * Üretilen düz metin anahtar SADECE bu akışın belleğinde (bir kerelik
 * modal/uyarı içinde) görünür; sayfa yenilendiğinde bir daha ASLA geri
 * getirilemez (bkz. ApiKeyService docblock'u) — bu yüzden kullanıcıya
 * kopyalaması için ayrı bir "tek seferlik anahtar" kutusu gösterilir.
 */
function initAacpApiKeys(root) {
    const csrfToken = root.dataset.apiKeysCsrf;
    if (!csrfToken) {
        return;
    }

    const revealBox = root.querySelector('[data-api-key-reveal]');
    const revealValue = root.querySelector('[data-api-key-reveal-value]');
    const createForm = root.querySelector('[data-api-key-create-form]');
    const tableWrapper = root.querySelector('[data-api-keys-table]');
    const tableBody = tableWrapper ? tableWrapper.querySelector('tbody') : null;

    function showRevealedKey(rawKey) {
        if (!revealBox || !revealValue) {
            return;
        }
        revealValue.textContent = rawKey;
        revealBox.classList.remove('hidden');
    }

    function appendRow(apiKey) {
        if (!tableBody) {
            return;
        }

        const emptyRow = tableBody.querySelector('td[colspan]');
        if (emptyRow) {
            emptyRow.closest('tr').remove();
        }

        const tr = document.createElement('tr');
        tr.dataset.apiKeyRow = apiKey.id;
        tr.innerHTML = `
            <td class="px-sp-sm py-2.5 text-slate-200">${escapeHtml(apiKey.label)}</td>
            <td class="px-sp-sm py-2.5 font-mono text-fs-xs text-slate-500">••••${escapeHtml(apiKey.lastFourChars)}</td>
            <td class="px-sp-sm py-2.5 text-fs-xs text-slate-500">${escapeHtml(new Date(apiKey.createdAt).toLocaleString('tr-TR'))}</td>
            <td class="px-sp-sm py-2.5">
                <span data-api-key-status class="rounded-sm px-2 py-0.5 text-fs-xs font-medium bg-emerald-500/10 text-emerald-300">Aktif</span>
            </td>
            <td class="px-sp-sm py-2.5 text-right">
                <div class="flex items-center justify-end gap-3">
                    <button type="button" data-api-key-toggle data-api-key-id="${escapeHtml(apiKey.id)}" data-api-key-active="1" class="text-fs-xs font-medium text-slate-400 hover:text-red-300">Pasif Et</button>
                    <button type="button" data-api-key-delete data-api-key-id="${escapeHtml(apiKey.id)}" class="text-fs-xs font-medium text-slate-400 hover:text-red-300">Sil</button>
                </div>
            </td>
        `;
        tableBody.prepend(tr);
        bindRowActions(tr);
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }

    async function createKey(event) {
        event.preventDefault();

        const labelInput = createForm.querySelector('[name="label"]');
        const label = labelInput ? labelInput.value.trim() : '';
        if (!label) {
            return;
        }

        const submitButton = createForm.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }

        try {
            const formData = new FormData();
            formData.set('_token', csrfToken);
            formData.set('label', label);

            const response = await fetch('/aacp/api-keys/create', {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' },
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            showRevealedKey(data.key);
            appendRow(data.apiKey);
            if (labelInput) {
                labelInput.value = '';
            }
        } catch (error) {
            // Fail-safe: AJAX isteği başarısız olursa form eski durumunu
            // korur, sayfa çökmez — kullanıcı tekrar deneyebilir.
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    }

    async function toggleKey(button) {
        const id = button.dataset.apiKeyId;
        if (!id || button.disabled) {
            return;
        }

        const row = button.closest('[data-api-key-row]');
        const statusEl = row ? row.querySelector('[data-api-key-status]') : null;

        button.disabled = true;

        try {
            const formData = new FormData();
            formData.set('_token', csrfToken);

            const response = await fetch(`/aacp/api-keys/${encodeURIComponent(id)}/toggle`, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' },
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            const active = data.apiKey.active;
            button.dataset.apiKeyActive = active ? '1' : '0';
            button.textContent = active ? 'Pasif Et' : 'Aktif Et';
            button.classList.toggle('text-cp-accent', !active);
            button.classList.toggle('hover:text-emerald-300', !active);

            if (statusEl) {
                statusEl.textContent = active ? 'Aktif' : 'Pasif';
                statusEl.classList.toggle('bg-emerald-500/10', active);
                statusEl.classList.toggle('text-emerald-300', active);
                statusEl.classList.toggle('bg-slate-500/10', !active);
                statusEl.classList.toggle('text-slate-400', !active);
            }
        } catch (error) {
            // Fail-safe: sessizce eski durum korunur.
        } finally {
            button.disabled = false;
        }
    }

    async function deleteKey(button) {
        const id = button.dataset.apiKeyId;
        if (!id || button.disabled) {
            return;
        }

        if (!confirm('Bu API anahtarını kalıcı olarak silmek istediğinize emin misiniz?')) {
            return;
        }

        const row = button.closest('[data-api-key-row]');
        button.disabled = true;

        try {
            const formData = new FormData();
            formData.set('_token', csrfToken);

            const response = await fetch(`/aacp/api-keys/${encodeURIComponent(id)}/delete`, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' },
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }

            if (row) {
                row.remove();
            }
        } catch (error) {
            // Fail-safe: sessizce eski durum korunur.
        } finally {
            button.disabled = false;
        }
    }

    function bindRowActions(scope) {
        scope.querySelectorAll('[data-api-key-toggle]').forEach((button) => {
            button.addEventListener('click', () => toggleKey(button));
        });
        scope.querySelectorAll('[data-api-key-delete]').forEach((button) => {
            button.addEventListener('click', () => deleteKey(button));
        });
    }

    if (createForm) {
        createForm.addEventListener('submit', createKey);
    }

    bindRowActions(root);
}

document.querySelectorAll('[data-api-keys-root]').forEach(initAacpApiKeys);
