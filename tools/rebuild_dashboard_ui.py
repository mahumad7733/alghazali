from pathlib import Path
import re

path = Path('/home/ubuntu/bus-booking-system/infinityfree/assets/js/app.js')
text = path.read_text()

text = text.replace(
"function dashboardMetricCard(title,value,icon,current,previous,unit='',details=''){const delta=dashboardChange(current,previous);const trendClass=delta>0?'trend-up':delta<0?'trend-down':'trend-flat';return `<article class=\"stat-card dashboard-smart-card ${trendClass}\"><div class=\"stat-icon\">${icon}</div><div class=\"stat-label\">${title}</div><div class=\"stat-value\">${value}${unit}</div><div class=\"stat-compare\"><span>الفترة الحالية: <b>${dashboardNumber(current)}</b></span><span>الفترة السابقة: <b>${dashboardNumber(previous)}</b></span><strong>${dashboardPercent(delta)} عن السابقة</strong></div>${details?`<div class=\"stat-details\">${details}</div>`:''}</article>`;}",
"function dashboardMetricCard(title,value,icon,current,previous,unit='',details='',compare=true){const delta=dashboardChange(current,previous);const trendClass=delta>0?'trend-up':delta<0?'trend-down':'trend-flat';return `<article class=\"stat-card dashboard-smart-card ${trendClass}\"><div class=\"stat-icon\">${icon}</div><div class=\"stat-label\">${title}</div><div class=\"stat-value\">${value}${unit}</div>${compare?`<div class=\"stat-compare\"><span>الفترة الحالية: <b>${dashboardNumber(current)}</b></span><span>الفترة السابقة: <b>${dashboardNumber(previous)}</b></span><strong>${dashboardPercent(delta)} عن السابقة</strong></div>`:''}${details?`<div class=\"stat-details\">${details}</div>`:''}</article>`;}",
1
)

start = text.index('  async function dashboardHomeAdvanced(host) {')
end = text.index('  function drawDashboardCharts(overview) {', start)
new_fn = r'''  async function dashboardHomeAdvanced(host) {
    const readFilters = () => Object.fromEntries([...host.querySelectorAll('.dashboard-filters [name]')].filter((input) => !input.hidden && input.value).map((input) => [input.name, input.value]));
    const serviceLabel = {website:'حجز حافلة',admin:'حجز حافلة',agent:'حجز حافلة',android:'حجز حافلة',iphone:'حجز حافلة',api:'حجز حافلة'};
    const render = async () => {
      const filters = readFilters();
      host.innerHTML = empty('يتم تحميل بيانات لوحة التحكم…');
      const query = new URLSearchParams(filters).toString();
      const response = await api(`dashboard/overview${query ? `?${query}` : ''}`);
      const overview = response.overview || {};
      const metrics = overview.metrics || {};
      const finance = Boolean(overview.can_view_financials);
      const salesByCurrency = overview.sales_by_currency || [];
      const singleCurrency = salesByCurrency.length === 1;
      const salesValue = finance ? (singleCurrency ? money(salesByCurrency[0].current_sales, salesByCurrency[0].currency_symbol) : salesByCurrency.length > 1 ? 'متعدد العملات' : dashboardNumber(metrics.sales)) : '—';
      const salesDetail = finance ? (salesByCurrency.length ? salesByCurrency.map(item => `<span>${money(item.current_sales,item.currency_symbol)}</span>`).join(' · ') : 'لا توجد مبيعات في الفترة') : 'غير متاح حسب الصلاحية';
      const profitDetail = finance ? (singleCurrency ? `الربح: <b>${money(metrics.profit,salesByCurrency[0].currency_symbol)}</b>` : 'الربح يعرض منفصلًا حسب العملة في التقارير') : 'غير متاح حسب الصلاحية';
      const cards = [
        dashboardMetricCard('الحجوزات', dashboardNumber(metrics.bookings), '▣', metrics.bookings, metrics.previous_bookings, '', `اليوم: <b>${dashboardNumber(metrics.today_bookings)}</b> · المؤكدة: <b>${dashboardNumber(metrics.confirmed)}</b> · المعلقة: <b>${dashboardNumber(metrics.pending)}</b>`),
        dashboardMetricCard('المبيعات والأرباح', salesValue, '◉', singleCurrency ? metrics.sales : 0, singleCurrency ? metrics.previous_sales : 0, '', `${profitDetail}<br><span>عمليات البيع: <b>${dashboardNumber(metrics.sales_count)}</b></span><br><span>${salesDetail}</span>`, singleCurrency),
        dashboardMetricCard('الرحلات', dashboardNumber(metrics.trips), '◷', metrics.trips, metrics.previous_trips || metrics.completed_trips, '', `المفتوحة: <b>${dashboardNumber(metrics.open_trips)}</b> · المكتملة: <b>${dashboardNumber(metrics.completed_trips)}</b> · القادمة: <b>${dashboardNumber(metrics.upcoming_trips || metrics.open_trips)}</b>`),
        dashboardMetricCard('الإلغاءات', dashboardNumber(metrics.cancelled), '×', metrics.cancelled, metrics.previous_cancelled, '', `نسبة الإلغاء: <b>${metrics.bookings ? dashboardNumber((metrics.cancelled / metrics.bookings) * 100) : 0}%</b><br>المسترد: <b>${finance ? dashboardNumber(metrics.refunded) : '—'}</b>`),
        dashboardMetricCard('المقاعد والإشغال', `${dashboardNumber(metrics.occupancy)}%`, '🪑', metrics.booked_seats, metrics.total_seats, '', `المحجوز: <b>${dashboardNumber(metrics.booked_seats)}</b> · المتاح: <b>${dashboardNumber(metrics.available_seats)}</b> · الإجمالي: <b>${dashboardNumber(metrics.total_seats)}</b>`),
        dashboardMetricCard('الوكلاء والشركات', dashboardNumber(metrics.total_agents), '♙', metrics.total_agents, metrics.active_agents, '', `النشطون: <b>${dashboardNumber(metrics.active_agents)}</b> · غير النشطين: <b>${dashboardNumber(metrics.inactive_agents)}</b><br>الشركات: <b>${dashboardNumber(metrics.total_companies)}</b> · حجوزات الوكلاء: <b>${dashboardNumber(metrics.agent_bookings)}</b>`),
        dashboardMetricCard('العملاء', dashboardNumber(metrics.customers), '♧', metrics.customers, 0, '', `عملاء لديهم حجوزات في الفترة المحددة<br>إجمالي الحجوزات: <b>${dashboardNumber(metrics.bookings)}</b>`),
        dashboardMetricCard('الإيرادات / الخدمات', finance ? (singleCurrency ? money(metrics.sales,salesByCurrency[0].currency_symbol) : 'حسب العملة') : '—', '◈', finance && singleCurrency ? metrics.sales : 0, 0, '', `حجوزات الحافلات: <b>${dashboardNumber((overview.service_breakdown||[])[0]?.total || metrics.bookings)}</b><br>المبيعات تُعرض حسب العملة دون خلط`, finance && singleCurrency),
      ].join('');
      const latestRows = dashboardTableRows(overview.latest_bookings, 'bookings', finance);
      const upcomingRows = dashboardTableRows(overview.upcoming_trips, 'trips', finance);
      const companyRows = (overview.top_companies || []).map((item) => { const occupancy = Number(item.total_seats || 0) ? (Number(item.booked_seats || 0) / Number(item.total_seats) * 100) : 0; return `<tr><td>${esc(item.company_name)}</td><td>${dashboardNumber(item.trips)}</td><td>${dashboardNumber(item.bookings)}</td><td>${finance ? dashboardNumber(item.sales) : '—'}</td><td>${dashboardNumber(occupancy)}%</td></tr>`; }).join('') || `<tr><td colspan="5">${empty('لا توجد بيانات كافية للعرض.')}</td></tr>`;
      const agentRows = (overview.top_agents || []).map((item) => `<tr><td>${esc(item.agent_name)}</td><td>${dashboardNumber(item.bookings)}</td><td>${finance ? dashboardNumber(item.sales) : 'متاح حسب الصلاحية'}</td><td>${finance ? dashboardNumber(item.commission) : '—'}</td><td>${finance ? dashboardNumber(item.balance) : '—'}</td><td>${finance ? dashboardNumber(item.debt) : '—'}</td></tr>`).join('') || `<tr><td colspan="6">${empty('لا توجد بيانات كافية للعرض.')}</td></tr>`;
      const alerts = (overview.alerts || []).map((item) => `<div class="dashboard-alert ${esc(item.type)}"><b>${esc(item.message)}</b><small>${dashboardDate(item.departure_at)}</small></div>`).join('') || empty('لا توجد تنبيهات تشغيلية حاليًا.');
      const seatPercent = Math.min(100, Number(metrics.occupancy || 0));
      const chartControls = `<div class="dashboard-chart-controls"><button type="button" data-dashboard-period="today">اليوم</button><button type="button" data-dashboard-period="this_week">الأسبوع</button><button type="button" data-dashboard-period="this_month">الشهر</button></div>`;
      const sections = [
        dashboardMiniTable('آخر العمليات', ['رقم العملية','العميل','الخدمة','المبلغ','الحالة','التاريخ','المستخدم'], latestRows, '<button class="linkish" data-dash="bookings">عرض الكل</button>'),
        dashboardMiniTable('الرحلات القادمة', ['الشركة','المسار','الموعد','الباص','المقاعد','المتاح','الحالة'], upcomingRows, '<button class="linkish" data-dash="trips">عرض الكل</button>'),
      ].join('');
      const rankings = [dashboardMiniTable('أفضل شركات النقل', ['الشركة','الرحلات','الحجوزات',finance?'المبيعات':'—','الإشغال'], companyRows), dashboardMiniTable('أفضل الوكلاء', ['الوكيل','الحجوزات',finance?'المبيعات':'—',finance?'العمولة':'—',finance?'الرصيد':'—',finance?'الدين':'—'], agentRows)].join('');
      host.innerHTML = `${dashTitle(role === 'agent' ? 'نظرة عامة على حسابك' : 'لوحة التحكم الرئيسية', 'لوحة ERP للحجوزات والتشغيل والماليات، مبنية على البيانات الحالية وبنطاق صلاحياتك.')}${dashboardFilterMarkup(overview.filters || {})}<div class="stats-grid dashboard-smart-grid">${cards}</div><div class="dashboard-chart-grid">${dashboardChartCard('المبيعات والحجوزات حسب الفترة','dashboard-booking-chart',chartControls)}${dashboardChartCard('الحجوزات حسب نوع الخدمة','dashboard-service-chart')}${dashboardChartCard('حالة الرحلات','dashboard-trip-chart')}${dashboardChartCard('إشغال المقاعد','dashboard-seat-chart')}${dashboardChartCard('المبيعات حسب الشركة','dashboard-company-chart')}${dashboardChartCard('الحجوزات حسب الوكيل','dashboard-agent-chart')}</div><div class="dashboard-lower-grid"><section class="panel seat-status-panel"><div class="panel-head"><h3>إشغال المقاعد</h3><b>${dashboardNumber(metrics.occupancy)}%</b></div><div class="panel-body"><div class="progress" role="progressbar" aria-valuenow="${metrics.occupancy}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:${seatPercent}%"></div></div><div class="seat-status-values"><span>الإجمالي <b>${dashboardNumber(metrics.total_seats)}</b></span><span>المحجوز <b>${dashboardNumber(metrics.booked_seats)}</b></span><span>المتاح <b>${dashboardNumber(metrics.available_seats)}</b></span></div></div></section><section class="panel alerts-panel"><div class="panel-head"><h3>التنبيهات المهمة</h3><span class="status ${overview.alerts?.length ? 'pending' : 'active'}">${dashboardNumber(overview.alerts?.length)} تنبيه</span></div><div class="panel-body"><div class="dashboard-alert-list">${alerts}</div></div></section></div>${sections}<div class="dashboard-ranking-grid">${rankings}</div>`;
      const period = host.querySelector('[name="period"]');
      const customDates = [...host.querySelectorAll('[name="start_date"], [name="end_date"]')];
      period?.addEventListener('change', (event) => customDates.forEach((input) => { input.hidden = event.target.value !== 'custom'; }));
      host.querySelector('[data-dashboard-refresh]')?.addEventListener('click', render);
      host.querySelectorAll('[data-dashboard-period]').forEach((button) => button.addEventListener('click', () => { if (period) period.value = button.dataset.dashboardPeriod; render(); }));
      host.querySelectorAll('[data-dash]').forEach((button) => button.addEventListener('click', () => loadDashboardPage(button.dataset.dash)));
      drawDashboardCharts(overview);
    };
    try { await render(); } catch (error) { host.innerHTML = `${dashTitle('لوحة التحكم', 'تعذر تحميل البيانات الحالية.')}<section class="panel"><div class="panel-body">${empty(error.message)}</div></section>`; showToast(error.message, 'error'); }
  }
'''
text = text[:start] + new_fn + text[end:]

# Adapt the latest bookings row to the requested operation columns.
old = "function dashboardTableRows(items,kind,financial){if(!items?.length)return `<tr><td colspan=\"9\">${empty('لا توجد بيانات كافية للعرض.')}</td></tr>`;return items.map(item=>kind==='bookings'?`<tr><td>${esc(item.booking_number)}</td><td>${esc(item.customer_name)}</td><td>${esc(item.agent_name||'مباشر')}</td><td>${esc(item.company_name)}</td><td>${esc(item.trip_number)}</td><td>${dashboardDate(item.created_at)}</td><td>${financial?money(item.total_amount,item.currency_symbol):'—'}</td><td>${status(item.status)}</td><td><button class=\"btn btn-outline btn-sm\" data-booking-detail=\"${item.id}\">عرض</button></td></tr>`:`<tr><td>${esc(item.company_name)}</td><td>${esc(item.route_name)}</td><td>${dashboardDate(item.departure_at)}</td><td>${esc(item.bus_number)}</td><td>${dashboardNumber(item.total_seats)}</td><td>${dashboardNumber(item.available_seats)}</td><td>${status(item.status)}</td></tr>`).join('');}"
new = "function dashboardTableRows(items,kind,financial){if(!items?.length)return `<tr><td colspan=\"${kind==='bookings'?7:7}\">${empty('لا توجد بيانات كافية للعرض.')}</td></tr>`;return items.map(item=>kind==='bookings'?`<tr><td>${esc(item.booking_number)}</td><td>${esc(item.customer_name)}</td><td>${esc({website:'حجز حافلة',admin:'حجز حافلة',agent:'حجز حافلة',android:'حجز حافلة',iphone:'حجز حافلة',api:'حجز حافلة'}[item.source]||'حجز حافلة')}</td><td>${financial?money(item.total_amount,item.currency_symbol):'—'}</td><td>${status(item.status)}</td><td>${dashboardDate(item.created_at)}</td><td>${esc(item.created_by_name||item.agent_name||'النظام')}</td></tr>`:`<tr><td>${esc(item.company_name)}</td><td>${esc(item.route_name)}</td><td>${dashboardDate(item.departure_at)}</td><td>${esc(item.bus_number)}</td><td>${dashboardNumber(item.total_seats)}</td><td>${dashboardNumber(item.available_seats)}</td><td>${status(item.status)}</td></tr>`).join('');}"
if old not in text:
    raise SystemExit('dashboardTableRows target not found')
text = text.replace(old, new, 1)
path.write_text(text)
