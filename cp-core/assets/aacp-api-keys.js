/**
 * AACP API management — create/deactivate/delete AJAX flows.
 *
 * Same vanilla-JS pattern as aacp-plugins.js; no-ops without data-api-keys-root.
 * Raw key is shown once in memory only (see ApiKeyService docblock).
 */
function initAacpApiKeys(root) {
    const csrfToken = root.dataset.apiKeysCsrf;
    if (!csrfToken) {
        return;
    }

    const i18n = {
        active: root.dataset.i18nActive || 'Active',
        inactive: root.dataset.i18nInactive || 'Inactive',
        deactivate: root.dataset.i18nDeactivate || 'Deactivate',
        activate: root.dataset.i18nActivate || 'Activate',
        delete: root.dataset.i18nDelete || 'Delete',
        deleteConfirm: root.dataset.i18nDeleteConfirm || 'Permanently delete this API key?',
        locale: root.dataset.locale || 'en',
    };

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

        const capabilities = Array.isArray(apiKey.capabilities) ? apiKey.capabilities : [];
        const scopeBadges = capabilities
            .map((cap) => `<span class="badge badge-neutral font-mono text-fs-xs">${escapeHtml(cap)}</span>`)
            .join(' ');
        const tenantBadge = apiKey.tenantId
            ? `<span class="badge badge-info text-fs-xs">tenant: ${escapeHtml(apiKey.tenantId)}</span>`
            : '';
        const expiresBadge = apiKey.expiresAt
            ? `<span class="badge badge-warning text-fs-xs">${escapeHtml(new Date(apiKey.expiresAt).toLocaleDateString(i18n.locale))}</span>`
            : '';

        tr.innerHTML = `
            <td class="px-sp-sm py-2.5 text-slate-200">
                ${escapeHtml(apiKey.label)}
                <div class="mt-1 flex flex-wrap items-center gap-1">${scopeBadges}${tenantBadge}${expiresBadge}</div>
            </td>
            <td class="px-sp-sm py-2.5 font-mono text-fs-xs text-slate-500">••••${escapeHtml(apiKey.lastFourChars)}</td>
            <td class="px-sp-sm py-2.5 text-fs-xs text-slate-500">${escapeHtml(new Date(apiKey.createdAt).toLocaleString(i18n.locale))}</td>
            <td class="px-sp-sm py-2.5">
                <span data-api-key-status class="rounded-sm px-2 py-0.5 text-fs-xs font-medium bg-emerald-500/10 text-emerald-300">${escapeHtml(i18n.active)}</span>
            </td>
            <td class="px-sp-sm py-2.5 text-right">
                <div class="flex items-center justify-end gap-3">
                    <button type="button" data-api-key-toggle data-api-key-id="${escapeHtml(apiKey.id)}" data-api-key-active="1" class="text-fs-xs font-medium text-slate-400 hover:text-red-300">${escapeHtml(i18n.deactivate)}</button>
                    <button type="button" data-api-key-delete data-api-key-id="${escapeHtml(apiKey.id)}" class="text-fs-xs font-medium text-slate-400 hover:text-red-300">${escapeHtml(i18n.delete)}</button>
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

        const fieldValue = (name) => {
            const el = createForm.querySelector(`[name="${name}"]`);
            return el ? el.value.trim() : '';
        };

        const submitButton = createForm.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }

        try {
            const formData = new FormData();
            formData.set('_token', csrfToken);
            formData.set('label', label);
            formData.set('capabilities', fieldValue('capabilities'));
            formData.set('tenant_id', fieldValue('tenant_id'));
            formData.set('ip_allowlist', fieldValue('ip_allowlist'));
            formData.set('expires_at', fieldValue('expires_at'));

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
            createForm.reset();
        } catch (error) {
            // Fail-safe: failed AJAX keeps form state; user can retry.
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
            button.textContent = active ? i18n.deactivate : i18n.activate;
            button.classList.toggle('text-cp-accent', !active);
            button.classList.toggle('hover:text-emerald-300', !active);

            if (statusEl) {
                statusEl.textContent = active ? i18n.active : i18n.inactive;
                statusEl.classList.toggle('bg-emerald-500/10', active);
                statusEl.classList.toggle('text-emerald-300', active);
                statusEl.classList.toggle('bg-slate-500/10', !active);
                statusEl.classList.toggle('text-slate-400', !active);
            }
        } catch (error) {
            // Fail-safe: silently keep previous state.
        } finally {
            button.disabled = false;
        }
    }

    async function deleteKey(button) {
        const id = button.dataset.apiKeyId;
        if (!id || button.disabled) {
            return;
        }

        if (!confirm(i18n.deleteConfirm)) {
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
            // Fail-safe: silently keep previous state.
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
