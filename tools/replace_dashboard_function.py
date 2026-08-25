from pathlib import Path
import re

path = Path('/home/ubuntu/bus-booking-system/infinityfree/assets/js/app.js')
text = path.read_text()
replacement = r'''  async function dashboardHomeAdvanced(host) {
    const readFilters = () => Object.fromEntries([...host.querySelectorAll('.dashboard-filters [name]')].filter((input) => !input.hidden && input.value).map((input) => [input.name, input.value]));
    const render = async () => {
      host.innerHTML = empty('يتم تحميل بيانات لوحة التحكم…');
      const query = new URLSearchParams(readFilters()).toString();
      const response = await api(`dashboard/overview${query ? `?${query}` : ''}`);
      const overview = response.overview || {};
      const metrics = overview.metrics || {};
      const finance = Boolean(overview.can_view_financials);
      const cards = [
        dashboardMetricCard('الحجوزات', dashboardNumber(metrics.bookings), '▣', metrics.bookings, metrics.previous_bookings),
        finance ? dashboardMetricCard('المبيعات', dashboardNumber(metrics.sales), '◉', metrics.sales, metrics.previous_sales, ' ر.ي') : '',
        dashboardMetricCard('الرحلات', dashboardNumber(metrics.trips), '◷', metrics.trips, metrics.completed_trips),
        dashboardMetricCard('المقاعد والإشغال', `${dashboardNumber(metrics.occupancy)}%`, '🪑', metrics.booked_seats, metrics.total_seats),
        dashboardMetricCard('الإلغاءات', dashboardNumber(metrics.cancelled), '×', metrics.cancelled, 0),
        dashboardMetricCard('بانتظار الإجراء', dashboardNumber(metrics.pending), '◷', metrics.pending, 0),
      ].join('');
      const latestRows = dashboardTableRows(overview.latest_bookings, 'bookings', finance);
      const upcomingRows = dashboardTableRows(overview.upcoming_trips, 'trips', finance);
      const companyRows = (overview.top_companies || []).map((item) => `<tr><td>${esc(item.company_name)}</td><td>${dashboardNumber(item.bookings)}</td><td>${finance ? dashboardNumber(item.sales) : dashboardNumber(item.trips)}</td></tr>`).join('') || `<tr><td colspan="3">${empty('لا توجد بيانات كافية للعرض.')}</td></tr>`;
      const agentRows = (overview.top_agents || []).map((item) => `<tr><td>${esc(item.agent_name)}</td><td>${dashboardNumber(item.bookings)}</td><td>${finance ? dashboardNumber(item.sales) : 'متاح حسب الصلاحية'}</td></tr>`).join('') || `<tr><td colspan="3">${empty('لا توجد بيانات كافية للعرض.')}</td></tr>`;
      const alerts = (overview.alerts || []).map((item) => `<div class="dashboard-alert ${esc(item.type)}"><b>${esc(item.message)}</b><small>${dashboardDate(item.departure_at)}</small></div>`).join('') || empty('لا توجد تنبيهات تشغيلية حاليًا.');
      const seatPercent = Math.min(100, Number(metrics.occupancy || 0));
      const sections = [
        dashboardMiniTable('آخر الحجوزات', ['رقم الحجز', 'العميل', 'الشركة', 'الرحلة', 'التاريخ', 'السعر', 'الحالة', ''], latestRows, '<button class="linkish" data-dash="bookings">عرض جميع الحجوزات</button>'),
        dashboardMiniTable('الرحلات القادمة', ['الشركة', 'المسار', 'الموعد', 'الباص', 'المقاعد', 'المتاح', 'الحالة'], upcomingRows, '<button class="linkish" data-dash="trips">عرض جميع الرحلات</button>'),
      ].join('');
      const rankings = [
        dashboardMiniTable('أفضل شركات النقل', ['الشركة', 'الحجوزات', finance ? 'المبيعات' : 'الرحلات'], companyRows),
        dashboardMiniTable('أفضل الوكلاء', ['الوكيل', 'الحجوزات', finance ? 'المبيعات' : 'الحالة'], agentRows),
      ].join('');
      host.innerHTML = `${dashTitle(role === 'agent' ? 'نظرة عامة على حسابك' : 'نظرة عامة على المنصة', 'لوحة تشغيل موحدة تعتمد على البيانات الحقيقية، مع مقارنة تلقائية بالفترة السابقة.')}${dashboardFilterMarkup(overview.filters || {})}<div class="stats-grid dashboard-smart-grid">${cards}</div><div class="dashboard-chart-grid">${dashboardChartCard('الحجوزات والمبيعات حسب اليوم', 'dashboard-booking-chart')}${dashboardChartCard('حالة الرحلات في الفترة', 'dashboard-trip-chart')}</div><div class="dashboard-lower-grid"><section class="panel seat-status-panel"><div class="panel-head"><h3>حالة المقاعد</h3><b>${dashboardNumber(metrics.occupancy)}%</b></div><div class="panel-body"><div class="progress" role="progressbar" aria-valuenow="${metrics.occupancy}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:${seatPercent}%"></div></div><div class="seat-status-values"><span>الإجمالي <b>${dashboardNumber(metrics.total_seats)}</b></span><span>المحجوز <b>${dashboardNumber(metrics.booked_seats)}</b></span><span>المتاح <b>${dashboardNumber(metrics.available_seats)}</b></span></div></div></section><section class="panel alerts-panel"><div class="panel-head"><h3>التنبيهات المهمة</h3><span class="status ${overview.alerts?.length ? 'pending' : 'active'}">${dashboardNumber(overview.alerts?.length)} تنبيه</span></div><div class="panel-body"><div class="dashboard-alert-list">${alerts}</div></div></section></div>${sections}<div class="dashboard-ranking-grid">${rankings}</div>`;
      const period = host.querySelector('[name="period"]');
      const customDates = [...host.querySelectorAll('[name="start_date"], [name="end_date"]')];
      period?.addEventListener('change', (event) => customDates.forEach((input) => { input.hidden = event.target.value !== 'custom'; }));
      host.querySelector('[data-dashboard-refresh]')?.addEventListener('click', render);
      host.querySelectorAll('[data-dash]').forEach((button) => button.addEventListener('click', () => loadDashboardPage(button.dataset.dash)));
      drawDashboardCharts(overview);
    };
    try { await render(); } catch (error) { host.innerHTML = `${dashTitle('لوحة التحكم', 'تعذر تحميل البيانات الحالية.')}<section class="panel"><div class="panel-body">${empty(error.message)}</div></section>`; showToast(error.message, 'error'); }
  }
'''
pattern = r"  async function dashboardHomeAdvanced\(host\)\{.*?\n  \}\n  function drawDashboardCharts"
new_text, count = re.subn(pattern, replacement + '  function drawDashboardCharts', text, count=1, flags=re.S)
if count != 1:
    raise SystemExit(f'replacement count={count}')
path.write_text(new_text)
print('dashboard function replaced')
