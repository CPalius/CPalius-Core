import Chart from 'chart.js/auto';

/**
 * AACP Genel Bakış: eskiden ayrı bir sayfa olan "Sistem Monitörü" ile
 * birleştirilmiş, Chart.js destekli canlı komuta merkezi.
 *
 * data-aacp-dashboard-* attribute'ları ile bağlanır, bulunamazsa sessizce
 * hiçbir şey yapmaz. AACP çekirdeğin kurtarma konsolu olduğu için burada
 * Stimulus/Turbo kullanılmaz — sadece core importmap girişi (app.js) ve
 * Chart.js üzerinden yüklenen düz vanilla JS.
 */
const PRIMARY = '#458EFF';
const WARNING = '#FACC15';
const DANGER = '#EF4444';
const TRACK = 'rgba(255, 255, 255, 0.08)';
const HISTORY_LENGTH = 30;
const PALETTE = ['#458EFF', '#22C55E', '#FACC15', '#F87171', '#A78BFA', '#94A3B8'];

function colorForPercent(pct) {
    if (pct > 85) {
        return DANGER;
    }
    if (pct > 60) {
        return WARNING;
    }
    return PRIMARY;
}

function createDoughnut(canvas) {
    const max = parseFloat(canvas.dataset.chartMax || '100');

    return new Chart(canvas, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [0, max],
                backgroundColor: [PRIMARY, TRACK],
                borderWidth: 0,
                circumference: 270,
                rotation: 225,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '78%',
            animation: { duration: 400 },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
            },
        },
    });
}

function updateDoughnut(chart, value, max) {
    if (!chart || value === null || value === undefined) {
        return;
    }
    const clamped = Math.max(0, Math.min(max, value));
    chart.data.datasets[0].data = [clamped, max - clamped];
    chart.data.datasets[0].backgroundColor[0] = colorForPercent((clamped / max) * 100);
    chart.update('none');
}

function createHistoryChart(canvas) {
    return new Chart(canvas, {
        type: 'line',
        data: {
            labels: new Array(HISTORY_LENGTH).fill(''),
            datasets: [{
                data: new Array(HISTORY_LENGTH).fill(null),
                borderColor: PRIMARY,
                backgroundColor: 'rgba(69, 142, 255, 0.12)',
                borderWidth: 2,
                pointRadius: 0,
                tension: 0.35,
                fill: true,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: { display: false },
                y: { display: false, beginAtZero: true },
            },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
            },
        },
    });
}

function pushHistory(chart, value) {
    if (!chart || value === null || value === undefined) {
        return;
    }
    const data = chart.data.datasets[0].data;
    data.push(value);
    data.shift();
    chart.update('none');
}

/**
 * İçerik/Altyapı/Modül bölümlerindeki yatay mini bar chart'lar — statik
 * veri (data-chart-labels/data-chart-values, sunucu-taraflı bir kez
 * render edilir), polling'e dahil DEĞİLDİR (bkz. AACPController::
 * buildContentReport() docblock'u — nadiren değişen sayılar için 4
 * saniyede bir sorgu atmanın maliyeti yok).
 */
function createBarChart(canvas) {
    const labels = (canvas.dataset.chartLabels || '').split(',').filter(Boolean);
    const values = (canvas.dataset.chartValues || '').split(',').map(Number);

    return new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                borderRadius: 4,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 300 },
            scales: {
                x: { display: false },
                y: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 10 } } },
            },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true },
            },
        },
    });
}

/**
 * createDoughnut()'un aksine tek bir "yüzde" değil, birden fazla
 * kategoriyi (ör. duruma göre içerik sayısı) gösteren, statik/tek
 * seferlik bir doughnut — polling'e dahil değildir.
 */
function createStaticDoughnut(canvas) {
    const labels = (canvas.dataset.chartLabels || '').split(',').filter(Boolean);
    const values = (canvas.dataset.chartValues || '').split(',').map(Number);

    return new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                borderWidth: 0,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            animation: { duration: 300 },
            plugins: {
                legend: { display: true, position: 'bottom', labels: { color: '#94a3b8', font: { size: 10 }, boxWidth: 8 } },
                tooltip: { enabled: true },
            },
        },
    });
}

function initAacpDashboard(root) {
    const url = root.dataset.aacpDashboardUrl;
    const interval = parseInt(root.dataset.aacpDashboardInterval || '4000', 10);

    if (!url) {
        return;
    }

    const statusEl = root.querySelector('[data-aacp-dashboard-target="status"]');
    const dotEl = root.querySelector('[data-aacp-dashboard-target="dot"]');
    const pulseEl = root.querySelector('[data-aacp-dashboard-target="pulse"]');
    const updatedAtEl = root.querySelector('[data-aacp-dashboard-target="updatedAt"]');

    const setField = (target, field, value) => {
        const el = root.querySelector(`[data-aacp-dashboard-target="${target}"] [data-field="${field}"]`);
        if (el) {
            el.textContent = value;
        }
    };

    const charts = {};
    let staticChartIndex = 0;
    root.querySelectorAll('[data-aacp-chart]').forEach((canvas) => {
        const key = canvas.dataset.aacpChart;
        if (key.endsWith('-history')) {
            charts[key] = createHistoryChart(canvas);
        } else if (key === 'bar') {
            charts[`bar-${staticChartIndex++}`] = createBarChart(canvas);
        } else if (key === 'doughnut-static') {
            charts[`doughnut-static-${staticChartIndex++}`] = createStaticDoughnut(canvas);
        } else {
            charts[key] = createDoughnut(canvas);
        }
    });

    /**
     * Kritik Uyarı Şeridi'ni canlı tutar — Twig'deki aynı seviye/renk
     * eşlemesini (level==='danger' → border-danger-500/60 bg-danger-500/10
     * text-danger-200, 'warning' için warning-* karşılıkları) tekrarlar.
     * Liste boşsa container boşaltılır, doluysa yeniden basılır.
     */
    const alertsContainer = document.querySelector('[data-aacp-critical-alerts]');
    const renderCriticalAlerts = (alerts) => {
        if (!alertsContainer) {
            return;
        }
        if (!Array.isArray(alerts) || alerts.length === 0) {
            alertsContainer.innerHTML = '';
            return;
        }

        alertsContainer.innerHTML = alerts.map((alert) => {
            const isDanger = alert.level === 'danger';
            const borderBg = isDanger ? 'border-danger-500/60 bg-danger-500/10' : 'border-warning-500/60 bg-warning-500/10';
            const iconColor = isDanger ? 'text-danger-400' : 'text-warning-400';
            const textColor = isDanger ? 'text-danger-200' : 'text-warning-200';
            const message = alert.message || alert.messageKey || '';

            return `<div class="flex items-center gap-3 rounded-lg border ${borderBg} px-sp-sm py-sp-xs">
                <span class="h-5 w-5 shrink-0 ${iconColor}">⚠</span>
                <p class="text-fs-sm font-medium ${textColor}">${message}</p>
            </div>`;
        }).join('');
    };

    const setLive = (isLive) => {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = isLive ? 'canlı' : 'bağlantı hatası';
        statusEl.classList.toggle('text-primary-400', isLive);
        statusEl.classList.toggle('text-danger-400', !isLive);
        [dotEl, pulseEl].forEach((el) => {
            if (!el) {
                return;
            }
            el.classList.toggle('bg-primary-500', isLive);
            el.classList.toggle('bg-primary-400', isLive);
            el.classList.toggle('bg-danger-500', !isLive);
            el.classList.toggle('bg-danger-400', !isLive);
        });
    };

    async function refresh() {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const data = await response.json();

            if (data.load && data.load.available) {
                setField('load', 'one', data.load.one);
                setField('load', 'five', data.load.five);
                setField('load', 'fifteen', data.load.fifteen);
                updateDoughnut(charts.load, data.load.one, 4);
                pushHistory(charts['load-history'], data.load.one);
            }

            setField('memory', 'phpUsageMiB', `${data.memory.phpUsageMiB} MiB`);
            setField('memory', 'phpPeakMiB', `${data.memory.phpPeakMiB} MiB`);
            setField('memory', 'limit', data.memory.limit);
            if (data.memory.memoryUsagePercent !== null && data.memory.memoryUsagePercent !== undefined) {
                setField('memory', 'memoryUsagePercent', `${data.memory.memoryUsagePercent}%`);
                updateDoughnut(charts.memory, data.memory.memoryUsagePercent, 100);
            }

            if (data.opcache.enabled) {
                setField('opcache', 'hitRate', `%${data.opcache.hitRate}`);
                setField('opcache', 'usedMemoryMiB', `${data.opcache.usedMemoryMiB} MiB`);
                setField('opcache', 'freeMemoryMiB', `${data.opcache.freeMemoryMiB} MiB`);
                setField('opcache', 'numCachedScripts', data.opcache.numCachedScripts);
                updateDoughnut(charts.opcache, data.opcache.hitRate, 100);
            }

            if (data.database.connected) {
                setField('database', 'platform', data.database.platform);
            } else {
                setField('database', 'error', data.database.error);
            }

            if (updatedAtEl) {
                updatedAtEl.textContent = data.generatedAt;
            }
            renderCriticalAlerts(data.criticalAlerts);
            setLive(true);
        } catch (error) {
            setLive(false);
        }
    }

    refresh();
    setInterval(refresh, Number.isFinite(interval) && interval > 0 ? interval : 4000);
}

/**
 * Widget aç/kapa paneli — bağımsız çalışır (initAacpDashboard'un
 * data-aacp-dashboard root'undan bağımsız), gear butonuna tıklayınca
 * paneli açar/kapatır, her checkbox değişikliğinde (a) ilgili
 * [data-widget-id] elementini anında gizler/gösterir, (b) fire-and-forget
 * bir AJAX çağrısıyla User::data'ya kalıcılaştırır. Başarısız olursa
 * checkbox+DOM durumu geri alınır (setLive(false) ile aynı fail-soft
 * felsefe).
 */
function initWidgetSettings() {
    const trigger = document.querySelector('[data-aacp-widget-settings-trigger]');
    const panel = document.querySelector('[data-aacp-widget-settings-panel]');
    if (!trigger || !panel) {
        return;
    }

    const closeBtn = panel.querySelector('[data-aacp-widget-settings-close]');
    const showAllBtn = panel.querySelector('[data-aacp-widget-settings-show-all]');
    const visibilityUrl = panel.dataset.aacpWidgetVisibilityUrl;
    const csrfToken = panel.dataset.csrfToken;

    trigger.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            panel.hidden = true;
        });
    }

    const setWidgetHidden = (widgetId, hidden) => {
        const el = document.querySelector(`[data-widget-id="${widgetId}"]`);
        if (el) {
            el.classList.toggle('hidden', hidden);
        }
    };

    const persistVisibility = async (widgetId, hidden) => {
        try {
            const response = await fetch(visibilityUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ widgetId, hidden: hidden ? '1' : '', _token: csrfToken }),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return true;
        } catch (error) {
            return false;
        }
    };

    panel.querySelectorAll('[data-widget-toggle]').forEach((checkbox) => {
        checkbox.addEventListener('change', async () => {
            const widgetId = checkbox.dataset.widgetToggle;
            const hidden = !checkbox.checked;

            setWidgetHidden(widgetId, hidden);

            const success = await persistVisibility(widgetId, hidden);
            if (!success) {
                checkbox.checked = !checkbox.checked;
                setWidgetHidden(widgetId, !hidden);
            }
        });
    });

    if (showAllBtn) {
        showAllBtn.addEventListener('click', async () => {
            const checkboxes = panel.querySelectorAll('[data-widget-toggle]');
            for (const checkbox of checkboxes) {
                if (!checkbox.checked) {
                    checkbox.checked = true;
                    setWidgetHidden(checkbox.dataset.widgetToggle, false);
                    await persistVisibility(checkbox.dataset.widgetToggle, false);
                }
            }
        });
    }
}

document.querySelectorAll('[data-aacp-dashboard]').forEach(initAacpDashboard);
initWidgetSettings();
