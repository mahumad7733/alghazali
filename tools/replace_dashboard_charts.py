from pathlib import Path
import re

path = Path('/home/ubuntu/bus-booking-system/infinityfree/assets/js/app.js')
text = path.read_text()
replacement = r'''  function drawDashboardCharts(overview) {
    if (!window.Chart) return;
    const series = overview.series || {};
    const daily = series.bookings_sales || [];
    const labels = daily.map((item) => item.label);
    const bookings = daily.map((item) => Number(item.bookings || 0));
    const sales = daily.map((item) => Number(item.sales || 0));
    const trips = series.trips || [];
    const tripLabels = trips.map((item) => ({ open: 'مفتوحة', completed: 'مكتملة', cancelled: 'ملغاة', scheduled: 'مجدولة', expired: 'منتهية' }[item.label] || item.label));
    const tripValues = trips.map((item) => Number(item.total || 0));
    const companies = overview.top_companies || [];
    const agents = overview.top_agents || [];
    const seatMetrics = overview.metrics || {};
    const make = (id, config) => { const canvas = document.getElementById(id); if (!canvas) return; const emptyNode = document.getElementById(`${id}-empty`); if (!config.data.labels.length || config.data.datasets.every((dataset) => dataset.data.every((value) => Number(value || 0) === 0))) { canvas.hidden = true; if (emptyNode) emptyNode.hidden = false; return; } new Chart(canvas, config); };
    const styles = getComputedStyle(document.querySelector('.template-dashboard')); const text = styles.getPropertyValue('--dash-muted') || '#64748b'; const grid = styles.getPropertyValue('--dash-border') || '#e2e8f0';
    const axis = { ticks: { color: text }, grid: { color: grid } };
    make('dashboard-booking-chart', { type: 'line', data: { labels, datasets: [{ label: 'الحجوزات', data: bookings, borderColor: '#3b82f6', backgroundColor: 'rgb(59 130 246/.15)', fill: true, tension: .35 }, { label: 'المبيعات', data: sales, borderColor: '#d89b35', backgroundColor: 'transparent', tension: .35, yAxisID: 'sales' }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: text } } }, scales: { x: axis, y: { ...axis, beginAtZero: true }, sales: { ...axis, position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } } } } });
    make('dashboard-trip-chart', { type: 'doughnut', data: { labels: tripLabels, datasets: [{ data: tripValues, backgroundColor: ['#3b82f6', '#10b981', '#ef4444', '#f59e0b', '#64748b'] }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: text } } } } });
    make('dashboard-seat-chart', { type: 'doughnut', data: { labels: ['محجوز', 'متاح'], datasets: [{ data: [Number(seatMetrics.booked_seats || 0), Number(seatMetrics.available_seats || 0)], backgroundColor: ['#3b82f6', '#cbd5e1'] }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: text } } } } });
    make('dashboard-company-chart', { type: 'bar', data: { labels: companies.map((item) => item.company_name), datasets: [{ label: 'المبيعات', data: companies.map((item) => Number(item.sales || 0)), backgroundColor: '#d89b35', borderRadius: 7 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: text } } }, scales: { x: axis, y: { ...axis, beginAtZero: true } } } });
    make('dashboard-agent-chart', { type: 'bar', data: { labels: agents.map((item) => item.agent_name), datasets: [{ label: 'الحجوزات', data: agents.map((item) => Number(item.bookings || 0)), backgroundColor: '#3b82f6', borderRadius: 7 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: text } } }, scales: { x: { ...axis, beginAtZero: true }, y: axis } } });
  }
'''
pattern = r"  function drawDashboardCharts\(overview\)\{.*?\}\n  function stats"
new_text, count = re.subn(pattern, replacement + '  function stats', text, count=1, flags=re.S)
if count != 1:
    raise SystemExit(f'replacement count={count}')
path.write_text(new_text)
print('dashboard charts replaced')
