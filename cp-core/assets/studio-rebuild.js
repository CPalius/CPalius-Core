/**
 * Batched rebuild runner for Studio and AACP.
 * Click handlers live on document so the script can load from head or late.
 * Copy is supplied via data-i18n-*.
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

function setBusy(root, busy) {
    root.dataset.cpRebuildBusy = busy ? '1' : '0';
    root.querySelectorAll('[data-rebuild-run], [data-rebuild-all]').forEach((button) => {
        button.disabled = busy;
    });
}

async function runJob(root, row) {
    const url = row.dataset.rebuildUrl;
    if (!url) {
        throw new Error(root.dataset.i18nFailed || '');
    }

    const wrap = row.querySelector('[data-rebuild-progress-wrap]');
    const bar = row.querySelector('[data-rebuild-bar]');
    const status = row.querySelector('[data-rebuild-status]');
    const startTotal = Number(row.dataset.rebuildTotal || 0);

    wrap?.classList.remove('hidden');
    if (status) {
        status.classList.remove('text-red-600');
        status.textContent = interpolate(root.dataset.i18nProgress || '{percent}%', {
            percent: 0,
            done: 0,
            total: startTotal,
        });
    }
    if (bar) {
        bar.style.width = '0%';
    }

    let offset = 0;

    while (true) {
        const formData = new FormData();
        formData.append('_token', root.dataset.cpRebuildCsrf || '');
        formData.append('offset', String(offset));

        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const parsed = await parseJsonBody(response);
        if (!parsed.data) {
            throw new Error(interpolate(root.dataset.i18nNotJson || 'HTTP {status}', {
                status: parsed.status,
            }));
        }

        const data = parsed.data;
        if (!data.ok) {
            throw new Error(data.error || (root.dataset.i18nFailed || ''));
        }

        offset = Number(data.offset || 0);
        const total = Number(data.total || 0);
        const percent = Number(data.percent || 0);

        if (bar) {
            bar.style.width = percent + '%';
        }
        if (status) {
            status.textContent = interpolate(root.dataset.i18nProgress || '{percent}%', {
                percent,
                done: offset,
                total,
            });
        }

        if (data.done) {
            if (status) {
                status.textContent = root.dataset.i18nDone || '';
            }
            if (bar) {
                bar.style.width = '100%';
            }
            return;
        }
    }
}

function showRowError(root, row, error) {
    const status = row.querySelector('[data-rebuild-status]');
    const wrap = row.querySelector('[data-rebuild-progress-wrap]');
    wrap?.classList.remove('hidden');
    if (status) {
        status.classList.add('text-red-600');
        status.textContent = interpolate(
            root.dataset.i18nRequestFailed || '{error}',
            { error: error.message || (root.dataset.i18nFailed || '') },
        );
    }
}

function bindRebuildClicks() {
    if (window.__cpRebuildBound) {
        return;
    }
    window.__cpRebuildBound = true;

    document.addEventListener('click', async (event) => {
        const runBtn = event.target.closest('[data-rebuild-run]');
        const allBtn = event.target.closest('[data-rebuild-all]');
        if (!runBtn && !allBtn) {
            return;
        }

        const root = (runBtn || allBtn).closest('[data-cp-rebuild]');
        if (!root || root.dataset.cpRebuildBusy === '1') {
            return;
        }

        event.preventDefault();
        setBusy(root, true);

        try {
            if (allBtn) {
                const rows = Array.from(root.querySelectorAll('[data-rebuild-job]'));
                for (const row of rows) {
                    await runJob(root, row);
                }
                return;
            }

            const row = runBtn.closest('[data-rebuild-job]');
            if (row) {
                await runJob(root, row);
            }
        } catch (error) {
            if (runBtn) {
                const row = runBtn.closest('[data-rebuild-job]');
                if (row) {
                    showRowError(root, row, error);
                }
            } else if (allBtn) {
                allBtn.title = error.message || (root.dataset.i18nFailed || '');
            }
        } finally {
            setBusy(root, false);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindRebuildClicks);
} else {
    bindRebuildClicks();
}
