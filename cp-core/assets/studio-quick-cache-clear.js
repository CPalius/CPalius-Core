/**
 * Studio header'ındaki şimşek ikonlu "Önbelleği Temizle" butonu.
 * AACPController::clearSymfonyCacheAction() ile AYNI AJAX ucunu (ve
 * AYNI 'aacp_cache_rebuild' CSRF token id'sini) kullanır — cache_rebuild
 * konsolundaki üç işlemden sadece biri, tek tıkla tetiklenir.
 *
 * CacheRebuildManager::clearSymfonyCache() zaten SATIR SATIR bir log
 * döndürür (cache.app pool + var/cache/{env} alt dizinleri, "[OK]"/"[HATA]"
 * önekli) — hem Studio hem AACP tarafında AYNI cache.app havuzu ve AYNI
 * var/cache dizini temizlendiği için bu, front-end (website) render
 * cache'i ile AACP arayüzünün paylaştığı TEK gerçek önbellek katmanıdır.
 * Önceden bu detaylı log'u kullanıcıya hiç GÖSTERMİYORDUK (sadece sabit
 * "Önbellek temizlendi." metni) — artık bir modal içinde tam olarak
 * hangi alt dizinin/havuzun temizlendiği listelenir.
 */
function initQuickCacheClear(button) {
    const url = button.dataset.quickCacheClearUrl;
    const csrfToken = button.dataset.quickCacheClearCsrf;
    const modal = document.querySelector('[data-quick-cache-clear-modal]');
    const statusEl = modal?.querySelector('[data-quick-cache-clear-modal-status]');
    const logEl = modal?.querySelector('[data-quick-cache-clear-modal-log]');

    if (!modal || !statusEl || !logEl) {
        return;
    }

    const openModal = () => modal.classList.add('open');
    const closeModal = () => modal.classList.remove('open');

    modal.querySelectorAll('[data-quick-cache-clear-modal-close]').forEach((closeBtn) => {
        closeBtn.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    function renderLog(outputText) {
        logEl.innerHTML = '';

        outputText
            .split('\n')
            .filter((line) => line.trim() !== '')
            .forEach((line) => {
                const lineEl = document.createElement('p');
                lineEl.className = 'modal-log-line ' + (line.startsWith('[HATA]') ? 'text-danger-600' : 'text-success-700');
                lineEl.textContent = line;
                logEl.appendChild(lineEl);
            });
    }

    button.addEventListener('click', async () => {
        button.disabled = true;
        statusEl.textContent = statusEl.dataset.runningText ?? statusEl.textContent;
        logEl.innerHTML = '';
        openModal();

        try {
            const formData = new FormData();
            formData.append('_token', csrfToken);

            const response = await fetch(url, { method: 'POST', body: formData });
            const data = await response.json();

            statusEl.textContent = data.success
                ? (statusEl.dataset.successText ?? 'OK')
                : (statusEl.dataset.errorText ?? 'ERROR');
            renderLog(data.output ?? '');
        } catch (error) {
            statusEl.textContent = statusEl.dataset.errorText ?? 'ERROR';
            renderLog('[HATA] ' + error.message);
        } finally {
            button.disabled = false;
        }
    });
}

document.querySelectorAll('[data-quick-cache-clear-trigger]').forEach(initQuickCacheClear);
