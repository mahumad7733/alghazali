(() => {
  'use strict';
  const core = window.BusAdminCore;
  if (!core) return;

  const routeTypeLabel = (value) => value === 'tourist' ? 'سياحي' : 'عادي';
  const routeStatusLabel = (value) => value === 'active' ? 'نشط' : 'غير نشط';
  const subrouteLabel = (item) => `${item.origin_city_name} ← ${item.destination_city_name}`;
  const optionText = (item) => `${subrouteLabel(item)} · ${item.currency_code} · ${core.money(item.amount, item.currency_symbol || '')}`;

  function visibleSubroutes(ctx, companyId, selectedIds = []) {
    return ctx.operations.subroutes.filter((item) => item.status === 'active' || selectedIds.includes(Number(item.id))).filter((item) => !item.company_id || Number(item.company_id) === Number(companyId));
  }

  function companyOptions(ctx, selected) {
    const items = ctx.operations.companies;
    return items.map((item) => `<option value="${item.id}" ${Number(item.id) === Number(selected) ? 'selected' : ''}>${core.esc(item.trade_name)}</option>`).join('');
  }

  function chipsMarkup(items, selectedIds) {
    const selected = items.filter((item) => selectedIds.includes(Number(item.id)));
    return selected.length ? selected.map((item) => `<span class="main-route-chip" data-chip-id="${item.id}">${core.esc(subrouteLabel(item))}<button type="button" aria-label="إزالة ${core.esc(subrouteLabel(item))}" data-remove-subroute="${item.id}">×</button></span>`).join('') : '<small class="muted">لم يتم اختيار أي مسار فرعي بعد.</small>';
  }

  function syncSubroutePicker(form, ctx) {
    const companyId = Number(form.querySelector('[name="company_id"]')?.value || 0);
    const select = form.querySelector('[name="subroute_ids"]');
    const selectedIds = [...select.selectedOptions].map((option) => Number(option.value));
    const available = visibleSubroutes(ctx, companyId, selectedIds);
    select.innerHTML = available.map((item) => `<option value="${item.id}" ${selectedIds.includes(Number(item.id)) ? 'selected' : ''}>${core.esc(optionText(item))}</option>`).join('');
    form.querySelector('[data-selected-subroutes]').innerHTML = chipsMarkup(available, selectedIds);
    form.querySelectorAll('[data-remove-subroute]').forEach((button) => button.addEventListener('click', () => {
      const option = select.querySelector(`option[value="${button.dataset.removeSubroute}"]`);
      if (option) option.selected = false;
      syncSubroutePicker(form, ctx);
    }));
  }

  function routeForm(ctx, route = null) {
    const linked = route ? ctx.operations.route_subroute_links.filter((link) => Number(link.route_id) === Number(route.id)).map((link) => Number(link.subroute_id)) : [];
    const selectedCompany = route?.company_id || ctx.operations.companies[0]?.id || '';
    const title = route ? `تعديل المسار ${core.esc(route.code)}` : 'إضافة مسار رئيسي';
    const buttonText = route ? 'حفظ التعديلات' : 'حفظ المسار';
    const backdrop = core.modal(`<h2>${title}</h2><p class="muted">يُنشأ رمز المسار تلقائيًا من النظام. اختر مسارًا فرعيًا واحدًا أو أكثر من المسارات الموجودة فقط.</p><form id="main-route-form" class="form-grid"><div class="field wide"><label>اسم المسار الرئيسي</label><input name="name_ar" value="${core.esc(route?.name_ar || '')}" placeholder="مثال: صنعاء - عدن" required></div><div class="field"><label>الشركة</label><select name="company_id" required>${companyOptions(ctx, selectedCompany)}</select></div><div class="field"><label>نوع المسار</label><select name="route_type" required><option value="normal" ${route?.route_type !== 'tourist' ? 'selected' : ''}>عادي</option><option value="tourist" ${route?.route_type === 'tourist' ? 'selected' : ''}>سياحي</option></select></div><div class="field wide"><label>المسارات الفرعية</label><select name="subroute_ids" multiple required size="7" class="main-route-multiselect"></select><small class="muted">يمكن اختيار أكثر من مسار. للمحافظة على تسلسل الرحلة، يجب أن تكون المقاطع المختارة متصلة بين المدن.</small></div><div class="field wide"><label>المسارات المختارة</label><div class="main-route-chips" data-selected-subroutes></div></div><div class="field"><label>الحالة</label><select name="status" required><option value="active" ${route?.status !== 'inactive' ? 'selected' : ''}>نشط</option><option value="inactive" ${route?.status === 'inactive' ? 'selected' : ''}>غير نشط</option></select></div><div class="field"><label>&nbsp;</label><div class="modal-actions"><button class="btn btn-outline" type="button" data-cancel-main-route>إلغاء</button><button class="btn btn-primary" type="submit">${buttonText}</button></div></div></form>`,'main-route-dialog');
    const form = core.$('#main-route-form', backdrop);
    const select = form.querySelector('[name="subroute_ids"]');
    const initialItems = visibleSubroutes(ctx, selectedCompany, linked);
    select.innerHTML = initialItems.map((item) => `<option value="${item.id}" ${linked.includes(Number(item.id)) ? 'selected' : ''}>${core.esc(optionText(item))}</option>`).join('');
    syncSubroutePicker(form, ctx);
    form.querySelector('[name="company_id"]').addEventListener('change', () => syncSubroutePicker(form, ctx));
    select.addEventListener('change', () => syncSubroutePicker(form, ctx));
    core.$('[data-cancel-main-route]', backdrop)?.addEventListener('click', core.closeModal);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const payload = Object.fromEntries(new FormData(form));
      payload.subroute_ids = [...select.selectedOptions].map((option) => Number(option.value));
      try {
        await core.api(route ? `admin/routes/${route.id}` : 'admin/routes', { method: route ? 'PUT' : 'POST', body: JSON.stringify(payload) });
        core.closeModal();
        core.showToast(route ? 'تم حفظ تعديلات المسار الرئيسي.' : 'تم إنشاء المسار الرئيسي برمز تلقائي.','success');
        renderRoutes(core.$('#dash-page'));
      } catch (error) { core.showToast(error.message,'error'); }
    });
  }

  function routeLinksModal(ctx, route) {
    const links = ctx.operations.route_subroute_links.filter((item) => Number(item.route_id) === Number(route.id));
    core.modal(`<h2>المسارات الفرعية المرتبطة</h2><p class="muted">${core.esc(route.code)} — ${core.esc(route.name_ar)}</p><ol class="main-route-links">${links.length ? links.map((link) => `<li>${core.esc(link.origin_city_name)} ← ${core.esc(link.destination_city_name)}</li>`).join('') : '<li>لا توجد مسارات فرعية مرتبطة.</li>'}</ol>`,'main-route-links-dialog');
  }

  function filteredRoutes(host, ctx) {
    const query = String(host.querySelector('[name="route_query"]')?.value || '').trim().toLowerCase();
    const company = String(host.querySelector('[name="route_company"]')?.value || '');
    const type = String(host.querySelector('[name="route_type_filter"]')?.value || '');
    const status = String(host.querySelector('[name="route_status_filter"]')?.value || '');
    return ctx.operations.routes.filter((route) => (!query || String(route.code).toLowerCase().includes(query) || String(route.name_ar).toLowerCase().includes(query)) && (!company || Number(route.company_id) === Number(company)) && (!type || route.route_type === type) && (!status || route.status === status));
  }

  function rowsMarkup(host, ctx) {
    const routes = filteredRoutes(host, ctx);
    return routes.length ? routes.map((route, index) => `<tr><td>${index + 1}</td><td><b>${core.esc(route.code)}</b></td><td>${core.esc(route.name_ar)}</td><td>${core.esc(route.company_name)}</td><td>${routeTypeLabel(route.route_type)}</td><td><button class="route-link-count" data-route-links="${route.id}">${Number(route.subroute_count)} مسارات فرعية</button></td><td>${core.status(route.status)}</td><td>${core.dateTime(route.created_at)}</td><td><div class="route-actions"><button class="btn btn-outline btn-sm" title="عرض المسارات المرتبطة" data-route-links="${route.id}">👁</button><button class="btn btn-outline btn-sm" title="تعديل" data-route-edit="${route.id}">✏️</button><details class="route-more"><summary title="مزيد من الإجراءات">⋮</summary><div><button data-route-toggle="${route.id}">${route.status === 'active' ? 'تعطيل' : 'تفعيل'}</button><button class="danger" data-route-delete="${route.id}">حذف</button></div></details></div></td></tr>`).join('') : `<tr><td colspan="9">${core.empty('لا توجد مسارات مطابقة للفلاتر.')}</td></tr>`;
  }

  function bindActions(host, ctx) {
    const findRoute = (id) => ctx.operations.routes.find((route) => Number(route.id) === Number(id));
    host.querySelectorAll('[data-route-links]').forEach((button) => button.addEventListener('click', () => routeLinksModal(ctx, findRoute(button.dataset.routeLinks))));
    host.querySelectorAll('[data-route-edit]').forEach((button) => button.addEventListener('click', () => routeForm(ctx, findRoute(button.dataset.routeEdit))));
    host.querySelectorAll('[data-route-toggle]').forEach((button) => button.addEventListener('click', async () => { const route = findRoute(button.dataset.routeToggle); if (!route) return; try { await core.api(`admin/routes/${route.id}/status`, { method:'PUT', body:JSON.stringify({status:route.status === 'active' ? 'inactive' : 'active'}) }); core.showToast(`تم ${route.status === 'active' ? 'تعطيل' : 'تفعيل'} المسار.`,'success'); renderRoutes(host); } catch (error) { core.showToast(error.message,'error'); } }));
    host.querySelectorAll('[data-route-delete]').forEach((button) => button.addEventListener('click', () => { const route = findRoute(button.dataset.routeDelete); if (!route) return; const backdrop = core.modal(`<h3>تأكيد حذف المسار</h3><p>سيُحذف المسار <strong>${core.esc(route.name_ar)}</strong> فقط إذا لم يرتبط برحلات أو حجوزات. عند وجود ارتباط، عطّله بدلًا من حذفه.</p><div class="modal-actions"><button class="btn btn-outline" data-close-route-delete>إلغاء</button><button class="btn btn-danger" data-confirm-route-delete>حذف نهائي</button></div>`,'entity-delete-dialog'); core.$('[data-close-route-delete]',backdrop).addEventListener('click',core.closeModal);core.$('[data-confirm-route-delete]',backdrop).addEventListener('click',async()=>{try{await core.api(`admin/routes/${route.id}`,{method:'DELETE'});core.closeModal();core.showToast('تم حذف المسار غير المرتبط.','success');renderRoutes(host);}catch(error){core.showToast(error.message,'error');}}); }));
  }

  async function renderRoutes(host) {
    const ctx = await core.operationContext();
    const companies = `<option value="">جميع الشركات</option>${ctx.operations.companies.map((item) => `<option value="${item.id}">${core.esc(item.trade_name)}</option>`).join('')}`;
    host.innerHTML = `${core.dashTitle('المسارات الرئيسية','أنشئ وأدر المسارات الرئيسية باستخدام المقاطع الفرعية الموجودة في النظام.')}
      <section class="panel"><div class="panel-head"><h3>البحث والفلاتر</h3><button class="btn btn-primary" type="button" data-add-main-route>＋ إضافة مسار رئيسي</button></div><div class="panel-body"><div class="main-route-filters"><label><span>🔍</span><input name="route_query" placeholder="ابحث باسم المسار أو رمزه"></label><select name="route_company">${companies}</select><select name="route_type_filter"><option value="">جميع الأنواع</option><option value="normal">عادي</option><option value="tourist">سياحي</option></select><select name="route_status_filter"><option value="">جميع الحالات</option><option value="active">نشط</option><option value="inactive">غير نشط</option></select><button class="btn btn-outline" type="button" data-clear-route-filters>مسح الفلاتر</button></div></div></section>
      <section class="panel" style="margin-top:18px"><div class="panel-head"><h3>المسارات الرئيسية الحالية</h3></div><div class="panel-body"><div class="table-scroll"><table class="data-table main-routes-table"><thead><tr><th>#</th><th>رمز المسار</th><th>اسم المسار</th><th>الشركة</th><th>النوع</th><th>المسارات الفرعية</th><th>الحالة</th><th>تاريخ الإنشاء</th><th>الإجراءات</th></tr></thead><tbody data-main-route-rows></tbody></table></div></div></section>`;
    const redraw = () => { host.querySelector('[data-main-route-rows]').innerHTML = rowsMarkup(host, ctx); bindActions(host, ctx); };
    host.querySelectorAll('.main-route-filters input,.main-route-filters select').forEach((field) => field.addEventListener('input', redraw));
    core.$('[data-clear-route-filters]',host).addEventListener('click',()=>{host.querySelectorAll('.main-route-filters input').forEach((input)=>input.value='');host.querySelectorAll('.main-route-filters select').forEach((select)=>select.value='');redraw();});
    core.$('[data-add-main-route]',host).addEventListener('click',()=>routeForm(ctx));
    redraw();
  }

  window.BusAdminPages = { ...(window.BusAdminPages || {}), routes: renderRoutes };
})();
