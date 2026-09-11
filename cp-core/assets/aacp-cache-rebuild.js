/**
 * AACP cache rebuild console: three AJAX actions dump CacheRebuildManager
 * output into the terminal panel. Copy is supplied via data-i18n-* attributes.
 */
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

function initCacheRebuildConsole(root) {
    const csrfToken = root.dataset.cacheRebuildCsrf;
    const urls = {
        clear: root.dataset.cacheRebuildClearUrl,
        opcache: root.dataset.cacheRebuildOpcacheUrl,
        assets: root.dataset.cacheRebuildAssetsUrl,
    };
    const locale = document.documentElement.lang || undefined;

    const logEl = root.querySelector('[data-cache-rebuild-log]');
    const buttons = Array.from(root.querySelectorAll('[data-cache-rebuild-trigger]'));

    function appendLog(line, isError) {
        if (!logEl) {
            return;
        }
        const timestamp = new Date().toLocaleTimeString(locale);
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
        appendLog(interpolate(root.dataset.i18nStarted || '{action}', { action }), false);

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
                appendLog(interpolate(root.dataset.i18nNotJson || 'HTTP {status}', { status: parsed.status }), true);
                return;
            }

            const data = parsed.data;
            const fallback = data.success
                ? (root.dataset.i18nDone || '')
                : (root.dataset.i18nFailed || '');
            appendLog(data.output || fallback, !data.success);
        } catch (error) {
            appendLog(interpolate(root.dataset.i18nRequestFailed || '{error}', { error: error.message }), true);
        } finally {
            buttons.forEach((b) => { b.disabled = false; });
        }
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => runAction(button));
    });
}

document.querySelectorAll('[data-cache-rebuild-root]').forEach(initCacheRebuildConsole);
