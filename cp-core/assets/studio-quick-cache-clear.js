/**
 * Studio header cache-clear button. Uses the same AJAX endpoint and CSRF
 * id as AACP cache rebuild (aacp_cache_rebuild).
 *
 * Log lines from CacheRebuildManager are prefixed [OK] / [ERR] (locale-neutral);
 * the sentence after the prefix is already translated server-side.
 */
function isErrorLogLine(line) {
    return line.startsWith('[ERR]') || line.startsWith('[HATA]');
}

function interpolate(template, vars) {
    return Object.keys(vars).reduce(
        (text, key) => text.replaceAll('{' + key + '}', String(vars[key])),
        template,
    );
}

async function parseJsonBody(response) {
    const text = await response.text();
    try {
        return { data: JSON.parse(text) };
    } catch {
        return { status: response.status };
    }
}

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
                lineEl.className = 'modal-log-line ' + (isErrorLogLine(line) ? 'text-danger-600' : 'text-success-700');
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

            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' },
            });
            const parsed = await parseJsonBody(response);

            if (!parsed.data) {
                statusEl.textContent = statusEl.dataset.errorText ?? 'ERROR';
                renderLog('[ERR] ' + interpolate(statusEl.dataset.notJson || 'HTTP {status}', { status: parsed.status }));
                return;
            }

            const data = parsed.data;
            statusEl.textContent = data.success
                ? (statusEl.dataset.successText ?? 'OK')
                : (statusEl.dataset.errorText ?? 'ERROR');
            renderLog(data.output ?? '');
        } catch (error) {
            statusEl.textContent = statusEl.dataset.errorText ?? 'ERROR';
            const template = statusEl.dataset.requestFailed || '{error}';
            renderLog('[ERR] ' + interpolate(template, { error: error.message }));
        } finally {
            button.disabled = false;
        }
    });
}

document.querySelectorAll('[data-quick-cache-clear-trigger]').forEach(initQuickCacheClear);
