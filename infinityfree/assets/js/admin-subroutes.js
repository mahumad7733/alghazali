(() => {
  'use strict';
  const core = window.BusAdminCore;
  if (!core) return;

  const cityOptions = (catalog) => `<option value="">اختر المدينة</option>${catalog.cities.filter((item) => Number(item.is_active)).map((item) => `<option value="${item.id}">${core.esc(item.name_ar)}</option>`).join('')}`;
  const currencyOptions = (catalog) => `<option value="">اختر العملة</option>${catalog.currencies.map((item) => `<option value="${item.id}">${core.esc(item.code)} — ${core.esc(item.name_ar)}</option>`).join('')}`;
  const money = (value, symbol) => core.money(value, symbol || '');
  const time = (value) => value ? core.esc(String(value).slice(0, 5)) : '—';

  function bindProfitNote(form) {
    const sale = form.querySelector('[name="amount"]');
    const cost = form.querySelector('[name="company_amount"]');
    const output = form.querySelector('[data-subroute-profit]');
    if (!sale || !cost || !output) return;
    const render = () => {
      const margin = (Number(sale.value) || 0) - (Number(cost.value) || 0);
      output.textContent = `هامش الربح: ${margin.toLocaleString('ar-YE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      output.classList.toggle('is-loss', margin < 0);
      output.title = margin < 0 ? 'تحذير: سعر البيع أقل من سعر الشركة وقد ينتج عنه خسارة.' : '';
    };
    sale.addEventListener('input', render);
    cost.addEventListener('input', render);
    render();
  }

  function table(items, canManageFinance) {
    const rows = items.map((item) => {
      const margin = canManageFinance && item.company_amount !== null ? Number(item.amount || 0) - Number(item.company_amount || 0) : null;
      const links = Number(item.linked_route_count || 0);
      return `<tr>
        <td><b>${core.esc(item.origin_city_name)}</b> ← ${core.esc(item.destination_city_name)}</td>
        <td>${time(item.destination_arrival_time)}</td>
        <td>${time(item.origin_departure_time)}</td>
        <td>${money(item.amount, item.currency_symbol)}</td>
        <td>${core.esc(item.currency_code)}</td>
        ${canManageFinance ? `<td>${money(item.company_amount, item.currency_symbol)}</td><td class="${margin !== null && margin < 0 ? 'subroute-loss' : ''}">${money(margin, item.currency_symbol)}</td>` : ''}
        <td>${links ? `<span class="status active">مرتبط بـ ${links} مسار ${links === 1 ? 'رئيسي' : 'رئيسية'}</span>` : '<span class="muted">غير مرتبط بعد</span>'}</td>
        <td>${core.status(item.status)}</td>
      </tr>`;
    }).join('') || `<tr><td colspan="${canManageFinance ? 9 : 7}">${core.empty('لا توجد مسارات فرعية بعد.')}</td></tr>`;
    return `<div class="table-scroll"><table class="data-table subroute-table"><thead><tr><th>المسار</th><th>وقت الحضور</th><th>وقت المغادرة</th><th>سعر البيع</th><th>العملة</th>${canManageFinance ? '<th>سعر الشركة علينا</th><th>هامش الربح</th>' : ''}<th>الارتباط</th><th>الحالة</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }

  function form(ctx) {
    const financialFields = ctx.canManageFinance ? `<div class="field"><label>سعر الشركة علينا</label><input name="company_amount" type="number" min="0" step="0.01" value="0"><small>التكلفة المستحقة لشركة النقل.</small></div><div class="field"><label>هامش الربح</label><output class="subroute-profit" data-subroute-profit></output><small>يُحسب تلقائيًا ولا يُحفظ كحقل مستقل.</small></div>` : '';
    return `<form id="subroute-page-create" class="form-grid">
      <div class="field"><label>مدينة الانطلاق</label><select name="origin_city_id" required>${cityOptions(ctx.catalog)}</select></div>
      <div class="field"><label>مدينة الوصول</label><select name="destination_city_id" required>${cityOptions(ctx.catalog)}</select></div>
      <div class="field"><label>العملة</label><select name="currency_id" required>${currencyOptions(ctx.catalog)}</select></div>
      ${financialFields}
      <div class="field"><label>سعر البيع</label><input name="amount" type="number" min="0.01" step="0.01" required><small>السعر الذي يظهر للعميل.</small></div>
      <div class="field"><label>الحالة</label><select name="status"><option value="active">نشط</option><option value="inactive">غير نشط</option></select></div>
      <div class="field wide schedule-note"><b>⏱ أوقات المسار</b><small>يتم تعبئة وقت المغادرة تلقائيًا بعد 30 دقيقة ويمكن تعديله. عند تغيير وقت الحضور يُعاد حساب الاقتراح، بما في ذلك العبور إلى اليوم التالي.</small></div>
      ${core.time12Field('destination_arrival', 'وقت الحضور')}
      ${core.time12Field('origin_departure', 'وقت المغادرة')}
      <div class="field"><label>&nbsp;</label><button class="btn btn-primary" type="submit">حفظ المسار الفرعي</button></div>
    </form>`;
  }

  async function renderSubroutes(host) {
    const ctx = await core.operationContext();
    host.innerHTML = `${core.dashTitle('المسارات الفرعية', 'أدر المدن والسعر والعملة ووقت الحضور والمغادرة، مع بقاء الروابط الرئيسية الحالية محفوظة.')}
      <section class="panel"><div class="panel-head"><h3>إضافة مسار فرعي</h3><span class="status active">مقطع جديد</span></div><div class="panel-body">${form(ctx)}</div></section>
      <section class="panel" style="margin-top:18px"><div class="panel-head"><h3>المسارات الفرعية الحالية</h3><span class="muted">يمكن تعديل المسار المرتبط دون حذف الرابط.</span></div><div class="panel-body">${table(ctx.operations.subroutes, ctx.canManageFinance)}</div></section>`;
    core.separateCreateForm(host, {
      formId: 'subroute-page-create',
      title: 'إضافة مسار فرعي',
      buttonText: 'إضافة مسار فرعي',
      bind: (modalForm) => {
        core.bindSubrouteTimeSuggestion(modalForm);
        bindProfitNote(modalForm);
        core.bindSeparatedCreateForm(modalForm, 'admin/subroutes', host, renderSubroutes, core.buildSubrouteBody);
      },
    });
  }

  window.BusAdminPages = { ...(window.BusAdminPages || {}), subroutes: renderSubroutes };
})();
