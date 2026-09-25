import Chart from 'chart.js/auto';

function readData(canvas) {
    try {
        return JSON.parse(canvas.dataset.visitorstatsData || 'null');
    } catch {
        return null;
    }
}

function initDaily(canvas) {
    const data = readData(canvas);
    if (!data) {
        return;
    }

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: data.labels || [],
            datasets: [
                {
                    label: 'Total',
                    data: data.total || [],
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5,150,105,0.12)',
                    tension: 0.35,
                    fill: true,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#0b1120',
                    pointBorderColor: '#059669',
                    pointBorderWidth: 2,
                },
                {
                    label: 'Unique',
                    data: data.unique || [],
                    borderColor: '#6ee7b7',
                    backgroundColor: 'rgba(110,231,183,0.08)',
                    tension: 0.35,
                    fill: true,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#0b1120',
                    pointBorderColor: '#6ee7b7',
                    pointBorderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
        },
    });
}

function initMonthly(canvas) {
    const data = readData(canvas);
    if (!data) {
        return;
    }

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.labels || [],
            datasets: [
                {
                    label: 'Total',
                    data: data.total || [],
                    backgroundColor: 'rgba(5,150,105,0.7)',
                    borderRadius: 3,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
        },
    });
}

Chart.defaults.color = '#94a3b8';
Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';
Chart.defaults.font.family = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
Chart.defaults.font.size = 10;

document.querySelectorAll('[data-visitorstats-chart="daily"]').forEach(initDaily);
document.querySelectorAll('[data-visitorstats-chart="monthly"]').forEach(initMonthly);
