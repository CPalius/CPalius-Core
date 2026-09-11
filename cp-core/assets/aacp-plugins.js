/**
 * AACP module plugins page — one-click AJAX active/inactive toggle.
 *
 * Same vanilla-JS pattern as aacp-system.js; no-ops without data-plugin-toggle-root.
 * Page does not reload — badge/button text and colors update from the fetch response.
 */
function initAacpPluginToggle(root) {
    const csrfToken = root.dataset.pluginToggleCsrf;
    if (!csrfToken) {
        return;
    }

    const i18n = {
        active: root.dataset.i18nActive || 'Active',
        inactive: root.dataset.i18nInactive || 'Inactive',
        deactivate: root.dataset.i18nDeactivate || 'Deactivate',
        activate: root.dataset.i18nActivate || 'Activate',
    };

    function applyState(button, statusEl, active) {
        button.dataset.pluginActive = active ? '1' : '0';
        button.textContent = active ? i18n.deactivate : i18n.activate;
        button.classList.toggle('text-slate-400', active);
        button.classList.toggle('hover:text-red-300', active);
        button.classList.toggle('text-cp-accent', !active);
        button.classList.toggle('hover:text-emerald-300', !active);

        if (statusEl) {
            statusEl.textContent = active ? i18n.active : i18n.inactive;
            statusEl.classList.toggle('bg-emerald-500/10', active);
            statusEl.classList.toggle('text-emerald-300', active);
            statusEl.classList.toggle('bg-slate-500/10', !active);
            statusEl.classList.toggle('text-slate-400', !active);
        }
    }

    async function toggle(button) {
        const pluginName = button.dataset.pluginName;
        if (!pluginName || button.disabled) {
            return;
        }

        const row = button.closest('[data-plugin-row]');
        const statusEl = row ? row.querySelector('[data-plugin-status]') : null;

        button.disabled = true;

        try {
            const formData = new FormData();
            formData.set('_token', csrfToken);

            const response = await fetch(`/aacp/plugins/${encodeURIComponent(pluginName)}/toggle`, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' },
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || `HTTP ${response.status}`);
            }

            applyState(button, statusEl, data.active);
        } catch (error) {
            // Fail-safe: failed AJAX keeps button state; user can retry.
        } finally {
            button.disabled = false;
        }
    }

    root.querySelectorAll('[data-plugin-toggle]').forEach((button) => {
        button.addEventListener('click', () => toggle(button));
    });
}

document.querySelectorAll('[data-plugin-toggle-root]').forEach(initAacpPluginToggle);
