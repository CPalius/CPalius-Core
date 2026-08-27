import Chart from 'chart.js/auto';

/**
 * Studio Genel Bakış — Chart.js destekli görsel komuta merkezi.
 * Tüm grafik türleri (gauge, doughnut, pie, bar, line, radar, polar)
 * data-studio-chart attribute'u ile bağlanır. Widget/section görünürlüğü
 * AACP dashboard ile aynı kalıcılık desenini kullanır (User::data).
 */
const PALETTE = ['#487FFF', '#4AADE4', '#22C55E', '#FACC15', '#F87171', '#A78BFA', '#64748B'];
const PRIMARY = '#487FFF';
const TRACK = '#E4E7EC';

document.addEventListener('DOMContentLoaded', () => {
    initCharts();
    initWidgetSettings();
    initSectionCollapse();
});

function readJson(el, attr, fallback) {
    try {
        return JSON.parse(el.getAttribute(attr) || 'null') ?? fallback;
    } catch (e) {
        return fallback;
    }
}

function centerTextPlugin(center, sub) {
    return {
        id: 'centerText',
        afterDraw(chart) {
            const { ctx, chartArea } = chart;
            if (!chartArea || !center) return;
            const x = (chartArea.left + chartArea.right) / 2;
            const y = (chartArea.top + chartArea.bottom) / 2 + 6;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.fillStyle = '#111827';
                ctx.font = '700 20px "Roboto Condensed", Arial, sans-serif';
            ctx.fillText(String(center), x, y);
            if (sub) {
                ctx.font = '600 10px "Roboto", Arial, sans-serif';
                ctx.fillStyle = '#64748B';
                ctx.fillText(String(sub).toUpperCase(), x, y + 18);
            }
            ctx.restore();
        },
    };
}

function initCharts() {
    document.querySelectorAll('[data-studio-chart]').forEach((canvas) => {
        const type = canvas.dataset.studioChart;
        switch (type) {
            case 'gauge':
                initGauge(canvas);
                break;
            case 'doughnut-center':
                initDoughnutCenter(canvas);
                break;
            case 'pie':
                initPie(canvas);
                break;
            case 'bar-h':
                initBarHorizontal(canvas);
                break;
            case 'bar-v':
                initBarVertical(canvas);
                break;
            case 'line-area':
                initLineArea(canvas);
                break;
            case 'line-multi':
                initLineMulti(canvas);
                break;
            case 'radar':
                initRadar(canvas);
                break;
            case 'polar':
                initPolar(canvas);
                break;
        }
    });
}

function initGauge(canvas) {
    const open = canvas.dataset.open;
    const total = canvas.dataset.total;

    if (open !== undefined && total !== undefined) {
        const openVal = parseInt(open, 10);
        const totalVal = parseInt(total, 10);
        const remaining = Math.max(0, totalVal - openVal);
        const pct = totalVal > 0 ? (openVal / totalVal) * 100 : 0;
        let color = '#22C55E';
        if (pct > 50) color = '#EF4444';
        else if (pct > 15) color = '#FACC15';

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: totalVal > 0 ? [openVal, remaining] : [0, 1],
                    backgroundColor: totalVal > 0 ? [color, TRACK] : [TRACK, TRACK],
                    borderWidth: 0,
                    circumference: 270,
                    rotation: 225,
                }],
            },
            options: gaugeOptions(),
            plugins: [centerTextPlugin(canvas.dataset.center, canvas.dataset.centerLabel)],
        });
        return;
    }

    const value = parseFloat(canvas.dataset.value || '0');
    const max = parseFloat(canvas.dataset.max || '100');
    const clamped = Math.max(0, Math.min(max, value));
    const remaining = max - clamped;
    const pct = max > 0 ? (clamped / max) * 100 : 0;
    let color = PRIMARY;
    if (pct > 85) color = '#EF4444';
    else if (pct > 60) color = '#FACC15';

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            datasets: [{
                data: [clamped, remaining],
                backgroundColor: [color, TRACK],
                borderWidth: 0,
                circumference: 270,
                rotation: 225,
            }],
        },
        options: gaugeOptions(),
        plugins: [centerTextPlugin(canvas.dataset.center, canvas.dataset.centerLabel)],
    });
}

function gaugeOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '78%',
        plugins: { legend: { display: false }, tooltip: { enabled: true } },
    };
}

function initDoughnutCenter(canvas) {
    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                borderWidth: 2,
                borderColor: '#fff',
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            layout: { padding: { top: 4, bottom: 4 } },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 10 }, color: '#64748B', padding: 8 } },
            },
        },
        plugins: [centerTextPlugin(canvas.dataset.center, canvas.dataset.centerLabel)],
    });
}

function initPie(canvas) {
    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'pie',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                borderWidth: 2,
                borderColor: '#fff',
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 4, bottom: 4 } },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 10 }, color: '#64748B', padding: 6 } },
            },
        },
    });
}

function initBarHorizontal(canvas) {
    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                borderRadius: 6,
                maxBarThickness: 32,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { display: false, beginAtZero: true },
                y: { grid: { display: false }, ticks: { color: '#64748B', font: { size: 11 } } },
            },
            plugins: { legend: { display: false } },
        },
    });
}

function initBarVertical(canvas) {
    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: canvas.dataset.datasetLabel || '',
                data: values,
                backgroundColor: PRIMARY,
                borderRadius: 6,
                maxBarThickness: 48,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false }, ticks: { color: '#64748B', font: { size: 10 }, maxRotation: 45 } },
                y: { beginAtZero: true, grid: { color: TRACK }, ticks: { precision: 0, color: '#64748B' } },
            },
            plugins: { legend: { display: false } },
        },
    });
}

function initLineArea(canvas) {
    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: canvas.dataset.datasetLabel || '',
                data: values,
                borderColor: PRIMARY,
                backgroundColor: 'rgba(72, 127, 255, 0.12)',
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: PRIMARY,
                tension: 0.35,
                fill: true,
            }],
        },
        options: lineOptions(),
    });
}

function initLineMulti(canvas) {
    const labels = readJson(canvas, 'data-labels', []);
    const series = readJson(canvas, 'data-series', []);
    if (!labels.length || !series.length) return;

    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: series.map((s, i) => ({
                label: s.label,
                data: s.values,
                borderColor: PALETTE[i % PALETTE.length],
                backgroundColor: 'transparent',
                borderWidth: 2.5,
                pointRadius: 3,
                tension: 0.35,
            })),
        },
        options: {
            ...lineOptions(),
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 }, color: '#64748B' } },
            },
        },
    });
}

function lineOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { grid: { display: false }, ticks: { color: '#64748B', font: { size: 11 } } },
            y: { beginAtZero: true, grid: { color: TRACK }, ticks: { precision: 0, color: '#64748B' } },
        },
        plugins: { legend: { display: false } },
    };
}

function initRadar(canvas) {
    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'radar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: 'rgba(72, 127, 255, 0.18)',
                borderColor: PRIMARY,
                borderWidth: 2,
                pointBackgroundColor: PRIMARY,
                pointRadius: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    beginAtZero: true,
                    grid: { color: TRACK },
                    angleLines: { color: TRACK },
                    pointLabels: { font: { size: 11 }, color: '#64748B' },
                    ticks: { display: false },
                },
            },
            plugins: { legend: { display: false } },
        },
    });
}

function initPolar(canvas) {
    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'polarArea',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: labels.map((_, i) => {
                    const c = PALETTE[i % PALETTE.length];
                    return c + 'CC';
                }),
                borderWidth: 1,
                borderColor: '#fff',
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    grid: { color: TRACK },
                    ticks: { display: false },
                },
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 }, color: '#64748B' } },
            },
        },
    });
}

function initWidgetSettings() {
    const trigger = document.querySelector('[data-studio-widget-settings-trigger]');
    const panel = document.querySelector('[data-studio-widget-settings-panel]');
    if (!trigger || !panel) return;

    const closeBtn = panel.querySelector('[data-studio-widget-settings-close]');
    const showAllBtn = panel.querySelector('[data-studio-widget-settings-show-all]');
    const visibilityUrl = panel.dataset.studioWidgetVisibilityUrl;
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
        document.querySelectorAll(`[data-studio-widget-id="${widgetId}"]`).forEach((el) => {
            el.classList.toggle('hidden', hidden);
        });
    };

    const persistVisibility = async (widgetId, hidden) => {
        try {
            const response = await fetch(visibilityUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ widgetId, hidden: hidden ? '1' : '', _token: csrfToken }),
            });
            return response.ok;
        } catch (e) {
            return false;
        }
    };

    panel.querySelectorAll('[data-studio-widget-toggle]').forEach((checkbox) => {
        checkbox.addEventListener('change', async () => {
            const widgetId = checkbox.dataset.studioWidgetToggle;
            const hidden = !checkbox.checked;
            setWidgetHidden(widgetId, hidden);
            const ok = await persistVisibility(widgetId, hidden);
            if (!ok) {
                checkbox.checked = !checkbox.checked;
                setWidgetHidden(widgetId, !hidden);
            }
        });
    });

    if (showAllBtn) {
        showAllBtn.addEventListener('click', async () => {
            for (const checkbox of panel.querySelectorAll('[data-studio-widget-toggle]')) {
                if (!checkbox.checked) {
                    checkbox.checked = true;
                    setWidgetHidden(checkbox.dataset.studioWidgetToggle, false);
                    await persistVisibility(checkbox.dataset.studioWidgetToggle, false);
                }
            }
        });
    }
}

function initSectionCollapse() {
    const STORAGE_KEY = 'studio-dashboard-sections';

    let collapsed = {};
    try {
        collapsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') ?? {};
    } catch (e) {
        collapsed = {};
    }

    document.querySelectorAll('[data-studio-section-toggle]').forEach((btn) => {
        const key = btn.dataset.studioSectionToggle;
        const body = document.querySelector(`[data-studio-section-body="${key}"]`);
        const chevron = btn.querySelector('.studio-dash-chevron');
        if (!body) return;

        const apply = (isCollapsed) => {
            body.classList.toggle('studio-dash-section-body--collapsed', isCollapsed);
            btn.classList.toggle('studio-dash-section-toggle--collapsed', isCollapsed);
            if (chevron) chevron.style.transform = isCollapsed ? 'rotate(-90deg)' : '';
        };

        apply(collapsed[key] === true);

        btn.addEventListener('click', () => {
            collapsed[key] = !collapsed[key];
            apply(collapsed[key]);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(collapsed));
        });
    });
}
