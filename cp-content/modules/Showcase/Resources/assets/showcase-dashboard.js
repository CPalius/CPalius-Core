import Chart from 'chart.js/auto';

/**
 * Charts for the Showcase admin overview.
 *
 * Same shape as forum-dashboard.js: read the numbers off data-* attributes that
 * Twig already rendered, and no-op when a canvas is absent. Nothing here fetches
 * — the page has the data, the chart just draws it.
 */
const PALETTE = ['#27AE60', '#C8A86E', '#8B9DAF', '#C0392B', '#4A7C9B', '#a371f7', '#4AADE4'];
const ACCENT = '#4A7C9B';
const TRACK = '#E4E7EC';

document.addEventListener('DOMContentLoaded', function () {
    initStatusChart();
    initTypeChart();
    initActivityChart();
});

function readJson(el, attr, fallback) {
    try {
        const parsed = JSON.parse(el.getAttribute(attr) || 'null');
        return parsed === null ? fallback : parsed;
    } catch (e) {
        return fallback;
    }
}

function hasAnyValue(values) {
    return values.some(function (v) {
        return Number(v) > 0;
    });
}

// ---- Status split: where the catalogue currently sits --------------------
function initStatusChart() {
    const canvas = document.getElementById('showcase-chart-status');
    if (!canvas) return;

    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    const empty = !hasAnyValue(values);

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: empty ? [''] : labels,
            datasets: [{
                // An empty catalogue draws one flat ring rather than a blank box,
                // so the card still reads as a chart that happens to be at zero.
                data: empty ? [1] : values,
                backgroundColor: empty ? [TRACK] : PALETTE,
                borderWidth: 0,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                tooltip: { enabled: !empty },
            },
        },
    });
}

// ---- Items per showcase type --------------------------------------------
function initTypeChart() {
    const canvas = document.getElementById('showcase-chart-type');
    if (!canvas) return;

    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: canvas.dataset.i18nItems || 'Items',
                data: values,
                backgroundColor: ACCENT,
                borderRadius: 4,
                maxBarThickness: 36,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: TRACK } },
                x: { grid: { display: false } },
            },
        },
    });
}

// ---- New entries per day ------------------------------------------------
function initActivityChart() {
    const canvas = document.getElementById('showcase-chart-activity');
    if (!canvas) return;

    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);

    new Chart(canvas, {
        type: 'line',
        data: {
            // Long ISO dates would crowd the axis; the tooltip keeps the full one.
            labels: labels.map(function (d) {
                return String(d).slice(5);
            }),
            datasets: [{
                label: canvas.dataset.i18nItems || 'New',
                data: values,
                borderColor: ACCENT,
                backgroundColor: 'rgba(74, 124, 155, 0.15)',
                fill: true,
                tension: 0.3,
                pointRadius: 0,
                pointHoverRadius: 4,
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: TRACK } },
                x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 10 } } },
            },
        },
    });
}
