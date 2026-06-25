document.addEventListener('DOMContentLoaded', () => {
    const data = window.__AM_REPORT_DATA__;
    const canvas = document.getElementById('am-chart');
    if (!data || !canvas || !data.perMinute || data.perMinute.length === 0 || typeof Chart === 'undefined') {
        return;
    }

    const colorFor = (percent) => (percent >= 70 ? '#2e9e44' : percent >= 40 ? '#c9a227' : '#9a9a9a');
    const axisColor = getComputedStyle(document.body).color || '#666';
    const gridColor = 'rgba(128, 128, 128, 0.15)';

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.perMinute.map((p) => p.label),
            datasets: [{
                label: 'Вовлечённость, %',
                data: data.perMinute.map((p) => p.engagementPercent),
                backgroundColor: data.perMinute.map((p) => colorFor(p.engagementPercent)),
                borderRadius: 6,
                maxBarThickness: 56,
            }],
        },
        options: {
            scales: {
                y: { beginAtZero: true, max: 100, grid: { color: gridColor }, ticks: { color: axisColor } },
                x: { grid: { display: false }, ticks: { color: axisColor } },
            },
            plugins: { legend: { display: false } },
        },
    });
});
