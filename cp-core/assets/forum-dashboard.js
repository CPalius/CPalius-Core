import Chart from 'chart.js/auto';

/**
 * Studio > Forum dashboard grafikleri — AACP dashboard'undaki aynı
 * Chart.js/auto deseni (bkz. aacp-dashboard.js): data-forum-chart-*
 * attribute'larından JSON okunur, canvas bulunamazsa sessizce hiçbir şey
 * yapılmaz. Node.js bağımlılığı yok, sadece core importmap girişi.
 */
const PALETTE = ['#4A7C9B', '#4AADE4', '#C8A86E', '#8B9DAF', '#3A6A87', '#a371f7', '#27AE60'];
const TRACK = '#E4E7EC';

document.addEventListener('DOMContentLoaded', function () {
    initModerationGauge();
    initSectionPieChart();
    initSectionBarChart();
});

function readJson(el, attr, fallback) {
    try {
        return JSON.parse(el.getAttribute(attr) || 'null') ?? fallback;
    } catch (e) {
        return fallback;
    }
}

// ---- Moderasyon sağlığı — açık rapor oranını gösteren yarım daire gauge ----
function initModerationGauge() {
    const canvas = document.getElementById('forum-chart-moderation-gauge');
    if (!canvas) return;

    const open = parseInt(canvas.dataset.open || '0', 10);
    const total = parseInt(canvas.dataset.total || '0', 10);
    const remaining = Math.max(0, total - open);
    const pct = total > 0 ? (open / total) * 100 : 0;

    let color = '#27AE60';
    if (pct > 50) color = '#C0392B';
    else if (pct > 15) color = '#C8A86E';

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Açık', 'Kapalı'],
            datasets: [{
                data: total > 0 ? [open, remaining] : [0, 1],
                backgroundColor: total > 0 ? [color, TRACK] : [TRACK, TRACK],
                borderWidth: 0,
                circumference: 270,
                rotation: 225,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%',
            plugins: {
                legend: { display: false },
                tooltip: { enabled: total > 0 },
            },
        },
        plugins: [{
            id: 'centerText',
            afterDraw(chart) {
                const { ctx, chartArea } = chart;
                if (!chartArea) return;
                const x = (chartArea.left + chartArea.right) / 2;
                const y = (chartArea.top + chartArea.bottom) / 2 + 10;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.fillStyle = '#2C3E50';
                ctx.font = '700 22px "Roboto Condensed", Arial, sans-serif';
                ctx.fillText(String(open), x, y);
                ctx.font = '600 10px "Roboto", Arial, sans-serif';
                ctx.fillStyle = '#8B9DAF';
                ctx.fillText('AÇIK RAPOR', x, y + 16);
                ctx.restore();
            },
        }],
    });
}

// ---- Bölümlere göre mesaj dağılımı (pasta) ----
function initSectionPieChart() {
    const canvas = document.getElementById('forum-chart-section-pie');
    if (!canvas) return;

    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: PALETTE,
                borderWidth: 2,
                borderColor: '#fff',
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '55%',
            plugins: {
                legend: {
                    position: 'right',
                    labels: { boxWidth: 10, font: { size: 11 } },
                },
            },
        },
    });
}

// ---- Bölümlere göre konu sayısı (çubuk) ----
function initSectionBarChart() {
    const canvas = document.getElementById('forum-chart-section-bar');
    if (!canvas) return;

    const labels = readJson(canvas, 'data-labels', []);
    const values = readJson(canvas, 'data-values', []);
    if (!labels.length) return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Konu',
                data: values,
                backgroundColor: '#4AADE4',
                borderRadius: 4,
                maxBarThickness: 36,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
        },
    });
}
