import Chart from 'chart.js/auto';

/**
 * Studio command desk: one thin doughnut plus the quick-create dropdown.
 */
const PALETTE = ['#487FFF', '#64748B', '#22C55E'];

document.addEventListener('DOMContentLoaded', () => {
    initDonut();
    initCreateDropdown();
    document.querySelectorAll('[data-studio-dashboard]').forEach(initWidgetToggles);
});

/**
 * Lets an editor fold panels away and remembers the choice per user.
 *
 * Same contract as the AACP command desk: the collapsed class is rendered by
 * the server (User::$data), so a folded panel is folded at first paint, and
 * this only adds the control and keeps the server in step.
 */
function initWidgetToggles(root) {
    const url = root.dataset.studioWidgetUrl;
    const csrf = root.dataset.studioWidgetCsrf;
    if (!url || !csrf) {
        return;
    }

    const collapseLabel = root.dataset.labelWidgetCollapse || 'Collapse';
    const expandLabel = root.dataset.labelWidgetExpand || 'Expand';

    root.querySelectorAll('[data-studio-widget]').forEach((panel) => {
        if (panel.querySelector('[data-studio-widget-toggle]')) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'studio-widget-toggle';
        button.setAttribute('data-studio-widget-toggle', '');

        const paint = () => {
            const collapsed = panel.classList.contains('is-collapsed');
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.setAttribute('aria-label', collapsed ? expandLabel : collapseLabel);
            button.title = collapsed ? expandLabel : collapseLabel;
            button.textContent = collapsed ? '+' : '−';
        };

        paint();

        button.addEventListener('click', async () => {
            // Fold first, persist after: this is the editor's own view
            // preference, and making them wait on a round trip to hide a box
            // would be the slowest possible way to tidy a screen.
            panel.classList.toggle('is-collapsed');
            paint();

            const body = new FormData();
            body.append('_token', csrf);
            body.append('widgetId', panel.dataset.studioWidget);
            body.append('hidden', panel.classList.contains('is-collapsed') ? '1' : '0');

            try {
                await fetch(url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            } catch {
                // The fold already happened locally; a failed save costs the
                // next page load, not this interaction.
            }
        });

        panel.appendChild(button);
    });
}

function readJson(el, attr, fallback) {
    try {
        return JSON.parse(el.getAttribute(attr) || 'null') ?? fallback;
    } catch (e) {
        return fallback;
    }
}

function initDonut() {
    document.querySelectorAll('[data-studio-chart="doughnut-center"]').forEach((canvas) => {
        const labels = readJson(canvas, 'data-labels', []);
        const values = readJson(canvas, 'data-values', []);
        const data = labels.length ? values : [1];
        const chartLabels = labels.length ? labels : ['—'];

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: chartLabels,
                datasets: [{
                    data,
                    backgroundColor: chartLabels.map((_, i) => PALETTE[i % PALETTE.length]),
                    borderWidth: 2,
                    borderColor: '#fff',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 8, font: { size: 10 }, color: '#64748B', padding: 8 },
                    },
                },
            },
            plugins: [{
                id: 'centerText',
                afterDraw(chart) {
                    const { ctx, chartArea } = chart;
                    if (!chartArea) {
                        return;
                    }
                    const x = (chartArea.left + chartArea.right) / 2;
                    const y = (chartArea.top + chartArea.bottom) / 2;
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.fillStyle = '#111827';
                    ctx.font = '700 18px "Roboto Condensed", Arial, sans-serif';
                    ctx.fillText(String(canvas.dataset.center || '0'), x, y);
                    ctx.font = '600 9px "Roboto", Arial, sans-serif';
                    ctx.fillStyle = '#64748B';
                    ctx.fillText(String(canvas.dataset.centerLabel || '').toUpperCase(), x, y + 14);
                    ctx.restore();
                },
            }],
        });
    });
}

function initCreateDropdown() {
    document.querySelectorAll('[data-studio-create]').forEach((root) => {
        const toggle = root.querySelector('[data-studio-create-toggle]');
        const menu = root.querySelector('[data-studio-create-menu]');
        if (!toggle || !menu) {
            return;
        }

        const close = () => {
            menu.hidden = true;
        };

        toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            menu.hidden = !menu.hidden;
        });

        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) {
                close();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
            }
        });
    });
}
