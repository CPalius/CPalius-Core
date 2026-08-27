/**
 * AACP "Modül Eklentileri" sayfası — tek tıkla AJAX aktif/pasif toggle.
 *
 * aacp-system.js ile aynı desen (bilinçli olarak framework'süz/vanilla
 * JS — AACP'nin "sıfır bağımlılık" ilkesi): data-plugin-toggle-root
 * bulunamazsa sessizce hiçbir şey yapmaz.
 *
 * Sayfa YENİDEN YÜKLENMEZ: fetch yanıtından dönen yeni "active" durumuna
 * göre satırdaki rozet/buton metni/rengi DOM üzerinde anında güncellenir.
 */
function initAacpPluginToggle(root) {
    const csrfToken = root.dataset.pluginToggleCsrf;
    if (!csrfToken) {
        return;
    }

    function applyState(button, statusEl, active) {
        button.dataset.pluginActive = active ? '1' : '0';
        button.textContent = active ? 'Pasif Et' : 'Aktif Et';
        button.classList.toggle('text-slate-400', active);
        button.classList.toggle('hover:text-red-300', active);
        button.classList.toggle('text-cp-accent', !active);
        button.classList.toggle('hover:text-emerald-300', !active);

        if (statusEl) {
            statusEl.textContent = active ? 'Aktif' : 'Pasif';
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
            // Fail-safe: AJAX isteği başarısız olursa buton eski durumunu
            // korur, sayfa çökmez — kullanıcı tekrar deneyebilir.
        } finally {
            button.disabled = false;
        }
    }

    root.querySelectorAll('[data-plugin-toggle]').forEach((button) => {
        button.addEventListener('click', () => toggle(button));
    });
}

document.querySelectorAll('[data-plugin-toggle-root]').forEach(initAacpPluginToggle);
