/**
 * AACP "Önbellek ve Yeniden Derleme" konsolu: üç bağımsız AJAX butonu
 * (Symfony cache / OPcache / Tailwind assets), CacheRebuildManager'ın
 * JSON sonucunu neon-yeşil bir terminal panelinde canlı basar.
 *
 * Bilinçli olarak vanilla JS (bkz. aacp-system.js ile aynı "sıfır
 * bağımlılık" ruhu) — AACP hiçbir ek UX paketine bağımlı olmamalı.
 */
function initCacheRebuildConsole(root) {
    const csrfToken = root.dataset.cacheRebuildCsrf;
    const urls = {
        clear: root.dataset.cacheRebuildClearUrl,
        opcache: root.dataset.cacheRebuildOpcacheUrl,
        assets: root.dataset.cacheRebuildAssetsUrl,
    };

    const logEl = root.querySelector('[data-cache-rebuild-log]');
    const buttons = Array.from(root.querySelectorAll('[data-cache-rebuild-trigger]'));

    function appendLog(line, isError) {
        if (!logEl) {
            return;
        }
        const timestamp = new Date().toLocaleTimeString('tr-TR');
        const prefix = `[${timestamp}] `;
        const span = document.createElement('div');
        span.textContent = prefix + line;
        span.className = isError ? 'text-red-400' : 'text-cp-accent';
        logEl.appendChild(span);
        logEl.scrollTop = logEl.scrollHeight;
    }

    async function runAction(button) {
        const action = button.dataset.cacheRebuildTrigger;
        const url = urls[action];
        if (!url) {
            return;
        }

        buttons.forEach((b) => { b.disabled = true; });
        appendLog(`$ ${action} işlemi başlatıldı…`, false);

        try {
            const formData = new FormData();
            formData.append('_token', csrfToken);

            const response = await fetch(url, { method: 'POST', body: formData });
            const data = await response.json();

            appendLog(data.output || (data.success ? 'İşlem tamamlandı.' : 'İşlem başarısız oldu.'), !data.success);
        } catch (error) {
            appendLog(`İstek başarısız: ${error.message}`, true);
        } finally {
            buttons.forEach((b) => { b.disabled = false; });
        }
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => runAction(button));
    });
}

document.querySelectorAll('[data-cache-rebuild-root]').forEach(initCacheRebuildConsole);
