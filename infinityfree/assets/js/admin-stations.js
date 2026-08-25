(() => {
  'use strict';
  const core = window.BusAdminCore;
  if (!core) return;

  async function renderStations(host) {
    const ctx = await core.operationContext();
    host.innerHTML = `${core.dashTitle('محطات التشغيل','محطات تُنشأ تلقائيًا عند تكوين مسار رئيسي من المقاطع الفرعية المتصلة.')}
      <section class="panel"><div class="panel-head"><h3>محطات التشغيل المنشأة تلقائيًا</h3><span class="status active">${ctx.operations.route_stops.length} محطة / توقف</span></div><div class="panel-body"><p class="muted" style="margin-top:0">تُحفظ هذه المحطات تلقائيًا مع المسار الرئيسي لحماية تسلسل الرحلة. يمكن مراجعة المسار والمدينة وترتيب التوقف من هذه الشاشة المستقلة.</p>${core.operationsTable(ctx.operations.route_stops,['route_name','station_name','city_name','stop_order','departure_offset_minutes'])}</div></section>`;
  }

  window.BusAdminPages = { ...(window.BusAdminPages || {}), stations: renderStations };
})();
