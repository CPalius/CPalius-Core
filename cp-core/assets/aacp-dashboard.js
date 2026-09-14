import Chart from 'chart.js/auto';

/**
 * AACP command desk: health poll, live telemetry feed, and threat charts.
 */

const SEV_CLASS = {
    info: 'aacp-sev-info',
    warning: 'aacp-sev-warning',
    critical: 'aacp-sev-critical',
    threat: 'aacp-sev-threat',
};

const VECTOR_LABELS = {
    sqli_attempt: 'SQLi',
    xss_attempt: 'XSS',
    scanner_detected: 'Scanner',
    path_traversal: 'LFI',
    login_attempt: 'Login',
};

const VECTOR_COLORS = ['#F87171', '#FACC15', '#FB923C', '#A78BFA', '#64748B'];

function initAacpDashboard(root) {
    const url = root.dataset.aacpDashboardUrl;
    const interval = parseInt(root.dataset.aacpDashboardInterval || '4000', 10);
    if (!url) {
        return;
    }

    const statusEl = root.querySelector('[data-aacp-dashboard-target="status"]');
    const dotEl = root.querySelector('[data-aacp-dashboard-target="dot"]');
    const pulseEl = root.querySelector('[data-aacp-dashboard-target="pulse"]');
    const uptimeEl = root.querySelector('[data-aacp-dashboard-target="uptime"]');
    const cronEl = root.querySelector('[data-aacp-dashboard-target="cronLastRun"]');
    const phpEl = root.querySelector('[data-aacp-dashboard-target="phpVersion"]');
    const barEl = root.querySelector('[data-aacp-opcache-bar]');

    const setField = (target, field, value) => {
        const el = root.querySelector(`[data-aacp-dashboard-target="${target}"][data-field="${field}"]`)
            || root.querySelector(`[data-aacp-dashboard-target="${target}"] [data-field="${field}"]`);
        if (el) {
            el.textContent = value;
        }
    };

    const setLive = (isLive) => {
        if (!statusEl) {
            return;
        }
        statusEl.classList.toggle('is-down', !isLive);
        [dotEl, pulseEl].forEach((el) => {
            if (el) {
                el.classList.toggle('is-down', !isLive);
            }
        });
    };

    async function refresh() {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const data = await response.json();

            if (phpEl && data.phpVersion) {
                phpEl.textContent = data.phpVersion;
            }
            if (uptimeEl && data.uptime && data.uptime.label) {
                uptimeEl.textContent = data.uptime.label;
            }
            if (cronEl && data.cron && data.cron.lastRunLabel) {
                cronEl.textContent = data.cron.lastRunLabel;
            }
            if (data.memory) {
                setField('memory', 'phpUsageMiB', `${data.memory.phpUsageMiB} MiB`);
                setField('memory', 'limit', data.memory.limit);
            }
            if (data.opcache) {
                const hit = data.opcache.hitRate;
                setField('opcache', 'hitRate', hit === null || hit === undefined ? '—' : `${hit}%`);
                if (barEl) {
                    barEl.style.width = `${Math.max(0, Math.min(100, hit || 0))}%`;
                }
            }
            if (data.queue) {
                setField('queue', 'pending', data.queue.pending === null || data.queue.pending === undefined ? '—' : String(data.queue.pending));
            }
            if (data.load) {
                setField('load', 'label', data.load.label || '—');
            }
            if (data.requestDurationMs !== null && data.requestDurationMs !== undefined) {
                const reqEl = root.querySelector('[data-aacp-dashboard-target="requestDurationMs"]');
                if (reqEl) {
                    reqEl.textContent = `${data.requestDurationMs} ms`;
                }
            }
            if (data.database) {
                const db = data.database;
                setField('database', 'latencyMs', db.latencyMs === null || db.latencyMs === undefined ? '—' : `${db.latencyMs} ms`);
                setField('database', 'name', db.name || '—');
                setField('database', 'version', db.version || '—');
                setField('database', 'sizeLabel', db.sizeLabel || '—');
                setField('database', 'tableCount', db.tableCount === null || db.tableCount === undefined ? '—' : String(db.tableCount));
                setField('database', 'charset', db.charset || '—');
                setField('database', 'slowQueries', db.slowQueries === null || db.slowQueries === undefined ? '—' : String(db.slowQueries));
                setField('database', 'serverUptime', db.serverUptime || '—');
                let connections = '—';
                if (db.threadsConnected !== null && db.threadsConnected !== undefined) {
                    connections = db.maxConnections ? `${db.threadsConnected} / ${db.maxConnections}` : String(db.threadsConnected);
                }
                setField('database', 'connections', connections);
            }
            if (statusEl && data.statusLabel) {
                statusEl.textContent = data.statusLabel;
            }
            if (data.performance) {
                updatePerformance(root, data.performance);
            }
            setLive(true);
        } catch (error) {
            setLive(false);
        }
    }

    refresh();
    setInterval(refresh, Number.isFinite(interval) && interval > 0 ? interval : 4000);

    initCyberButtons(root);
    initTelemetry(root);
}

function updatePerformance(root, performance) {
    const onLabel = root.dataset.labelPerfOn || 'ON';
    const offLabel = root.dataset.labelPerfOff || 'OFF';
    const naLabel = root.dataset.labelPerfNa || 'n/a';

    ['cpalius', 'redis', 'memcached', 'varnish', 'pagespeed', 'opcache'].forEach((key) => {
        const row = performance[key];
        const card = root.querySelector(`[data-perf-backend="${key}"]`);
        if (!row || !card) {
            return;
        }
        const status = card.querySelector('[data-perf-field="status"]');
        if (status) {
            status.textContent = row.online ? onLabel : offLabel;
            status.classList.toggle('is-on', !!row.online);
            status.classList.toggle('is-off', !row.online);
        }
        const count = card.querySelector('[data-perf-field="count"]');
        if (count) {
            count.textContent = row.countUnavailable ? naLabel : (row.count === null || row.count === undefined ? '—' : String(row.count));
        }
        const size = card.querySelector('[data-perf-field="size"]');
        if (size) {
            size.textContent = row.sizeLabel || '—';
        }
        const meta = card.querySelector('[data-perf-field="meta"]');
        if (meta) {
            meta.textContent = row.meta || '';
        }
    });

    const body = root.querySelector('[data-perf-pages]');
    if (!body || !performance.cpalius || !Array.isArray(performance.cpalius.pages)) {
        return;
    }
    const pages = performance.cpalius.pages;
    if (!pages.length) {
        const emptyLabel = root.dataset.labelPerfPagesEmpty || '—';
        body.innerHTML = `<tr data-perf-pages-empty><td colspan="3" class="!text-slate-500">${escapeHtml(emptyLabel)}</td></tr>`;
        return;
    }
    body.innerHTML = pages.map((page) => `<tr><td class="!break-all">${escapeHtml(page.path || '')}</td><td>${escapeHtml(page.sizeLabel || '—')}</td><td>${escapeHtml(page.mtimeLabel || '')}</td></tr>`).join('');
}

function initCyberButtons(root) {
    const csrf = root.dataset.cacheRebuildCsrf;
    const urls = {
        cache: root.dataset.cacheRebuildClearUrl,
        opcache: root.dataset.cacheRebuildOpcacheUrl,
    };

    root.querySelectorAll('[data-aacp-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            const action = button.dataset.aacpAction;
            const url = urls[action];
            if (!url || !csrf) {
                return;
            }

            button.disabled = true;
            try {
                const formData = new FormData();
                formData.append('_token', csrf);
                const response = await fetch(url, { method: 'POST', body: formData });
                await response.json();
            } catch (error) {
                // Keep the console usable; the next poll refreshes telemetry.
            } finally {
                button.disabled = false;
            }
        });
    });
}

function readJson(el, attr, fallback) {
    try {
        return JSON.parse(el.getAttribute(attr) || 'null') ?? fallback;
    } catch (e) {
        return fallback;
    }
}

function truncateUri(uri) {
    const value = String(uri || '');
    return value.length > 48 ? `${value.slice(0, 48)}…` : value;
}

function parseRowPayload(tr) {
    const field = tr?.querySelector('[data-telemetry-json]');
    if (!field) {
        return null;
    }
    try {
        return JSON.parse(field.value || '{}');
    } catch (e) {
        return null;
    }
}

function createFeedRow(row, detailsLabel, securityMode) {
    const tr = document.createElement('tr');
    tr.dataset.telemetryId = String(row.id);

    const time = document.createElement('td');
    time.textContent = row.time || '';
    tr.appendChild(time);

    if (securityMode) {
        const sevTd = document.createElement('td');
        const sev = document.createElement('span');
        sev.className = `aacp-sev ${SEV_CLASS[row.severity] || 'aacp-sev-info'}`;
        sev.textContent = row.severity || '';
        sevTd.appendChild(sev);
        tr.appendChild(sevTd);
    }

    const ip = document.createElement('td');
    ip.textContent = row.ip || '';
    tr.appendChild(ip);

    const user = document.createElement('td');
    user.textContent = row.user || '—';
    tr.appendChild(user);

    const path = document.createElement('td');
    path.textContent = `${row.method || ''} ${truncateUri(row.uri)}`.trim();
    tr.appendChild(path);

    if (securityMode) {
        const score = document.createElement('td');
        score.textContent = String(row.threatScore ?? 0);
        tr.appendChild(score);
    }

    const actions = document.createElement('td');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'aacp-cyber-btn !py-0.5';
    button.dataset.telemetryOpen = '';
    button.textContent = detailsLabel;
    const payload = document.createElement('textarea');
    payload.hidden = true;
    payload.setAttribute('data-telemetry-json', '');
    payload.value = JSON.stringify(row);
    actions.append(button, payload);
    tr.appendChild(actions);

    return tr;
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function chartDefaults() {
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';
    Chart.defaults.font.family = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
    Chart.defaults.font.size = 10;
}

function initTelemetry(root) {
    const feedUrl = root.dataset.aacpTelemetryUrl;
    const detailsLabel = root.dataset.labelDetails || 'Details';
    const securityMode = root.dataset.telemetryMode === 'security';
    if (!feedUrl) {
        return;
    }

    chartDefaults();

    const body = root.querySelector('[data-telemetry-body]');
    const topList = root.querySelector('[data-telemetry-top-ips]');
    const trendCanvas = root.querySelector('[data-telemetry-chart="trend"]');
    const vectorCanvas = root.querySelector('[data-telemetry-chart="vectors"]');
    const uniqueEl = root.querySelector('[data-visitor-unique-ips]');
    const viewsEl = root.querySelector('[data-visitor-page-views]');
    const interval = parseInt(root.dataset.aacpTelemetryInterval || '3000', 10);
    // Server-rendered row budget, so the client trim and the initial
    // render cannot drift apart when one of them is changed.
    const parsedRows = parseInt(root.dataset.aacpTelemetryRows || '', 10);
    const maxRows = Number.isFinite(parsedRows) && parsedRows > 0 ? parsedRows : 10;

    let lastId = 0;
    if (body) {
        body.querySelectorAll('[data-telemetry-id]').forEach((row) => {
            lastId = Math.max(lastId, parseInt(row.getAttribute('data-telemetry-id') || '0', 10));
        });
    }

    const trendSeed = readJson(root, 'data-telemetry-trend', { labels: [], hits: [], normal: [], threats: [] });
    const vectorSeed = readJson(root, 'data-telemetry-vectors', []);

    let trendChart = null;
    let vectorChart = null;

    if (trendCanvas) {
        const datasets = securityMode
            ? [
                {
                    label: root.dataset.labelNormal || 'Normal',
                    data: trendSeed.normal || [],
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52,211,153,0.12)',
                    tension: 0.35,
                    fill: true,
                    pointRadius: 0,
                    borderWidth: 2,
                },
                {
                    label: root.dataset.labelThreat || 'Threat',
                    data: trendSeed.threats || [],
                    borderColor: '#f87171',
                    backgroundColor: 'rgba(248,113,113,0.12)',
                    tension: 0.35,
                    fill: true,
                    pointRadius: 0,
                    borderWidth: 2,
                },
            ]
            : [
                {
                    label: root.dataset.labelTraffic || 'Traffic',
                    data: trendSeed.hits || [],
                    borderColor: '#34d399',
                    backgroundColor: 'rgba(52,211,153,0.12)',
                    tension: 0.35,
                    fill: true,
                    pointRadius: 0,
                    borderWidth: 2,
                },
            ];

        trendChart = new Chart(trendCanvas, {
            type: 'line',
            data: { labels: trendSeed.labels || [], datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { boxWidth: 8, padding: 8 } } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                },
            },
        });
    }

    if (vectorCanvas && securityMode) {
        vectorChart = new Chart(vectorCanvas, {
            type: 'doughnut',
            data: vectorChartData(vectorSeed),
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 10 }, padding: 8 } } },
            },
        });
    }

    initModal(root);
    initBanButtons(root);

    async function poll() {
        try {
            const response = await fetch(`${feedUrl}?after_id=${lastId}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            if (Array.isArray(data.rows) && data.rows.length && body) {
                const empty = body.querySelector('[data-telemetry-empty]');
                if (empty) {
                    empty.remove();
                }
                const incoming = [...data.rows].sort((a, b) => a.id - b.id);
                incoming.forEach((row) => {
                    if (row.id <= lastId) {
                        return;
                    }
                    lastId = row.id;
                    body.prepend(createFeedRow(row, detailsLabel, securityMode));
                });
                while (body.querySelectorAll('tr').length > maxRows) {
                    body.lastElementChild.remove();
                }
            }
            if (data.trend && trendChart) {
                trendChart.data.labels = data.trend.labels || [];
                if (securityMode) {
                    trendChart.data.datasets[0].data = data.trend.normal || [];
                    if (trendChart.data.datasets[1]) {
                        trendChart.data.datasets[1].data = data.trend.threats || [];
                    }
                } else {
                    trendChart.data.datasets[0].data = data.trend.hits || [];
                }
                trendChart.update('none');
            }
            if (data.vectors && vectorChart) {
                const next = vectorChartData(data.vectors);
                vectorChart.data.labels = next.labels;
                vectorChart.data.datasets[0].data = next.datasets[0].data;
                vectorChart.data.datasets[0].backgroundColor = next.datasets[0].backgroundColor;
                vectorChart.update('none');
            }
            // Only the threat list survives: the visitor "top pages" and
            // "top IPs" panels were removed from the command desk, so there
            // is nothing to refresh outside security mode.
            if (securityMode && Array.isArray(data.topIps) && topList) {
                renderTopIps(topList, data.topIps, root);
            }
            if (uniqueEl && data.uniqueIps !== undefined) {
                uniqueEl.textContent = String(data.uniqueIps);
            }
            if (viewsEl && data.pageViews !== undefined) {
                viewsEl.textContent = String(data.pageViews);
            }
        } catch (error) {
            // Feed is best-effort; health poll still runs.
        }
    }

    poll();
    setInterval(poll, Number.isFinite(interval) && interval > 0 ? interval : 3000);
}

function vectorChartData(vectors) {
    const list = Array.isArray(vectors) ? vectors : [];
    const labels = list.map((item) => VECTOR_LABELS[item.eventType] || item.eventType);
    const data = list.map((item) => item.count || 0);
    const total = data.reduce((sum, n) => sum + n, 0);
    if (total === 0) {
        return {
            labels: ['—'],
            datasets: [{ data: [1], backgroundColor: ['#334155'], borderWidth: 0 }],
        };
    }

    return {
        labels,
        datasets: [{ data, backgroundColor: VECTOR_COLORS, borderWidth: 0 }],
    };
}

function renderTopIps(list, ips, root) {
    if (!ips.length) {
        list.innerHTML = `<li class="!text-slate-500">—</li>`;
        return;
    }

    const banLabel = root.dataset.labelBan || 'Ban IP';
    const bannedLabel = root.dataset.labelBanned || 'BANNED';
    list.innerHTML = ips.map((ip) => {
        const action = ip.banned
            ? `<span class="aacp-sev aacp-sev-threat">${escapeHtml(bannedLabel)}</span>`
            : `<button type="button" class="aacp-cyber-btn !py-0.5" data-ban-ip="${escapeHtml(ip.ip)}">${escapeHtml(banLabel)}</button>`;
        return `<li data-ip="${escapeHtml(ip.ip)}"><div><span class="font-mono text-slate-100">${escapeHtml(ip.ip)}</span><span class="ms-2 font-mono text-slate-500">${ip.score} · ${ip.hits}</span></div>${action}</li>`;
    }).join('');
}

function initBanButtons(root) {
    if (root.dataset.banDelegate === '1') {
        return;
    }
    root.dataset.banDelegate = '1';
    const url = root.dataset.aacpBanUrl;
    const csrf = root.dataset.aacpBanCsrf;

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-ban-ip], [data-telemetry-modal-ban]');
        if (!button || !root.contains(button)) {
            return;
        }
        if (!url || !csrf) {
            return;
        }

        const ip = button.getAttribute('data-ban-ip') || '';
        if (!ip) {
            return;
        }

        button.disabled = true;
        try {
            const formData = new FormData();
            formData.append('_token', csrf);
            formData.append('ip', ip);
            const response = await fetch(url, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                const modal = button.closest('[data-telemetry-modal]');
                if (modal) {
                    button.hidden = true;
                    const badge = modal.querySelector('[data-telemetry-modal-banned]');
                    if (badge) {
                        badge.hidden = false;
                    }
                    return;
                }
                button.replaceWith(Object.assign(document.createElement('span'), {
                    className: 'aacp-sev aacp-sev-threat',
                    textContent: root.dataset.labelBanned || 'BANNED',
                }));
            } else {
                button.disabled = false;
            }
        } catch (error) {
            button.disabled = false;
        }
    });
}

function formatDetails(details) {
    if (!details || (typeof details === 'object' && Object.keys(details).length === 0)) {
        return '—';
    }
    try {
        return JSON.stringify(details, null, 2);
    } catch (e) {
        return String(details);
    }
}

function isBannableSeverity(severity) {
    return severity === 'critical' || severity === 'threat';
}

function initModal(root) {
    const modal = root.querySelector('[data-telemetry-modal]');
    if (!modal) {
        return;
    }
    const banWrap = modal.querySelector('[data-telemetry-modal-ban-wrap]');
    const banBtn = modal.querySelector('[data-telemetry-modal-ban]');
    const close = () => {
        modal.classList.remove('is-open');
        if (banBtn) {
            banBtn.removeAttribute('data-ban-ip');
        }
    };

    const fill = (selector, value) => {
        const el = modal.querySelector(`[data-modal-field="${selector}"]`);
        if (el) {
            el.textContent = value == null || value === '' ? '—' : String(value);
        }
    };

    root.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-telemetry-open]');
        if (trigger) {
            const row = parseRowPayload(trigger.closest('tr'));
            if (!row) {
                return;
            }
            fill('time', row.time || row.createdAt);
            fill('ip', row.ip);
            fill('user', row.user);
            fill('method', row.method);
            fill('uri', row.uri);
            fill('severity', row.severity);
            fill('eventType', row.eventType);
            fill('threatScore', row.threatScore);
            fill('userAgent', row.userAgent);
            fill('details', formatDetails(row.details));
            if (banWrap && banBtn) {
                const bannedBadge = modal.querySelector('[data-telemetry-modal-banned]');
                if (isBannableSeverity(row.severity) && row.ip) {
                    banWrap.hidden = false;
                    banBtn.hidden = false;
                    banBtn.disabled = false;
                    banBtn.setAttribute('data-ban-ip', row.ip);
                    banBtn.textContent = root.dataset.labelBan || 'Ban IP';
                    if (bannedBadge) {
                        bannedBadge.hidden = true;
                    }
                } else {
                    banWrap.hidden = true;
                    banBtn.removeAttribute('data-ban-ip');
                    if (bannedBadge) {
                        bannedBadge.hidden = true;
                    }
                }
            }
            modal.classList.add('is-open');
            return;
        }
        if (event.target.closest('[data-telemetry-modal-close]') || event.target === modal) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }
    });
}

/**
 * Lets an operator fold panels away and remembers the choice per user.
 *
 * The button is injected here rather than written into each template: every
 * panel would otherwise carry the same six lines of markup, and a panel added
 * later would silently be the one without a control.
 *
 * The collapsed class is rendered by the server (User::$data), so a folded
 * panel is already folded at first paint. This function only adds the control
 * and keeps the server in step — it never decides the initial state, which is
 * why a failed or slow request cannot make panels flicker open.
 */
function initWidgetToggles(root) {
    const url = root.dataset.aacpWidgetUrl;
    const csrf = root.dataset.aacpWidgetCsrf;
    if (!url || !csrf) {
        return;
    }

    const collapseLabel = root.dataset.labelWidgetCollapse || 'Collapse';
    const expandLabel = root.dataset.labelWidgetExpand || 'Expand';

    root.querySelectorAll('[data-aacp-widget]').forEach((panel) => {
        if (panel.querySelector('[data-aacp-widget-toggle]')) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'aacp-widget-toggle';
        button.setAttribute('data-aacp-widget-toggle', '');

        const paint = () => {
            const collapsed = panel.classList.contains('is-collapsed');
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.setAttribute('aria-label', collapsed ? expandLabel : collapseLabel);
            button.title = collapsed ? expandLabel : collapseLabel;
            button.textContent = collapsed ? '+' : '−';
        };

        paint();

        button.addEventListener('click', async () => {
            // Fold first, persist after: the panel is the operator's own view
            // preference, and making them wait on a round trip to hide a box
            // would be the slowest possible way to tidy a screen.
            panel.classList.toggle('is-collapsed');
            paint();

            const body = new FormData();
            body.append('_token', csrf);
            body.append('widgetId', panel.dataset.aacpWidget);
            body.append('hidden', panel.classList.contains('is-collapsed') ? '1' : '0');

            try {
                await fetch(url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            } catch {
                // The fold already happened locally. A failed save means the
                // next page load shows the old layout, which is a far smaller
                // problem than an error banner over a cosmetic action.
            }
        });

        panel.appendChild(button);
    });
}

document.querySelectorAll('[data-aacp-dashboard]').forEach((root) => {
    initAacpDashboard(root);
    initWidgetToggles(root);
});
