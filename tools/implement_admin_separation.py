from pathlib import Path
import re

ROOT = Path('/home/ubuntu/bus-booking-system/infinityfree')

# Add fixed-page wrappers and permission entries without changing existing pages.
layout = ROOT / 'admin' / '_layout.php'
text = layout.read_text()
text = text.replace("'countries' => ['title' => 'الدول', 'page' => 'countries', 'permissions' => ['manage_countries'], 'any' => false],", "'countries' => ['title' => 'الدول', 'page' => 'countries', 'permissions' => ['manage_countries'], 'any' => false],\n        'currencies' => ['title' => 'العملات', 'page' => 'currencies', 'permissions' => ['manage_settings'], 'any' => false],\n        'exchange_rates' => ['title' => 'أسعار الصرف', 'page' => 'exchange_rates', 'permissions' => ['manage_settings'], 'any' => false],")
text = text.replace("'agent_balances' => ['title' => 'أرصدة الوكلاء', 'page' => 'wallet', 'permissions' => ['manage_payments'], 'any' => false],", "'agent_balances' => ['title' => 'أرصدة الوكلاء', 'page' => 'wallet', 'permissions' => ['manage_payments'], 'any' => false],\n        'agent_finance' => ['title' => 'إعدادات الوكلاء المالية', 'page' => 'agent_finance', 'permissions' => ['manage_agents'], 'any' => false],\n        'agent_credit' => ['title' => 'شحن رصيد الوكيل', 'page' => 'agent_credit', 'permissions' => ['manage_agents'], 'any' => false],")
layout.write_text(text)

for filename, key in {
    'currencies.php': 'currencies',
    'exchange-rates.php': 'exchange_rates',
    'agent-finance.php': 'agent_finance',
    'agent-credit.php': 'agent_credit',
}.items():
    path = ROOT / 'admin' / filename
    if not path.exists():
        path.write_text(f"<?php declare(strict_types=1); require __DIR__ . '/_layout.php'; renderAdminPage(requireAdminPage('{key}'));\n")

# Extend the existing admin catalog API with read/status operations for reference pages.
api = ROOT / 'api' / 'v1' / 'index.php'
text = api.read_text()
needle = "if (preg_match('#^admin/references/(countries|cities|stations|currencies|exchange-rates)$#', $route, $matches) === 1 && $method === 'POST') {"
insert = """if (preg_match('#^admin/references/(currencies|exchange-rates)$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم جلب البيانات المرجعية.', ['items' => $adminOps->references($actor, $matches[1])]);
}

if (preg_match('#^admin/references/(currencies|exchange-rates)/(\\d+)/status$#', $route, $matches) === 1 && $method === 'PUT') {
    Security::assertCsrf();
    $actor = $auth->requirePermissions(['manage_settings']);
    Response::success('تم تحديث حالة البيانات المرجعية.', ['item' => $adminOps->updateReferenceStatus($actor, $matches[1], (int) $matches[2], Security::jsonInput())]);
}

"""
if insert not in text:
    text = text.replace(needle, insert + needle, 1)
api.write_text(text)

# Add safe backend methods using the existing currencies/exchange_rates tables.
service = ROOT / 'includes' / 'AdminService.php'
text = service.read_text()
needle = "    /** @return array<string, mixed> */\n    public function assignUserRole(array $actor, int $userId, array $input): array"
methods = r'''    /** @return list<array<string, mixed>> */
    public function references(array $actor, string $type): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_settings', $actor['permissions'], true)) {
            Response::error('إدارة البيانات المرجعية متاحة للمخولين فقط.', 'FORBIDDEN', 403);
        }
        $pdo = $this->database->pdo();
        if ($type === 'currencies') {
            return $this->allPdo($pdo, 'SELECT id, code, name_ar, symbol_ar, decimal_places, is_active, created_at FROM currencies ORDER BY code', []);
        }
        if ($type === 'exchange-rates') {
            return $this->allPdo($pdo, 'SELECT er.id, er.base_currency_id, er.quote_currency_id, er.rate, er.effective_at, er.expires_at, er.is_active, er.created_at, bc.code AS base_code, qc.code AS quote_code FROM exchange_rates er INNER JOIN currencies bc ON bc.id = er.base_currency_id INNER JOIN currencies qc ON qc.id = er.quote_currency_id ORDER BY er.effective_at DESC, er.id DESC LIMIT 200', []);
        }
        Response::error('نوع البيانات المرجعية غير مدعوم.', 'VALIDATION_ERROR', 422);
    }

    /** @return array<string, mixed> */
    public function updateReferenceStatus(array $actor, string $type, int $id, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_settings', $actor['permissions'], true)) {
            Response::error('تعديل البيانات المرجعية متاح للمخولين فقط.', 'FORBIDDEN', 403);
        }
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true)) { Response::error('الحالة غير صالحة.', 'VALIDATION_ERROR', 422); }
        $table = $type === 'currencies' ? 'currencies' : ($type === 'exchange-rates' ? 'exchange_rates' : '');
        if ($table === '') { Response::error('نوع البيانات المرجعية غير مدعوم.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        $exists = $this->one($pdo, "SELECT id FROM {$table} WHERE id = :id", ['id' => $id]);
        if ($exists === null) { Response::error('السجل المرجعي غير موجود.', 'NOT_FOUND', 404); }
        if ($table === 'currencies' && $status === 'inactive') {
            $used = (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM companies WHERE base_currency_id = :id', ['id' => $id])['total'];
            if ($used > 0) { Response::error('لا يمكن إيقاف عملة مستخدمة في شركات قائمة.', 'DEPENDENCY_EXISTS', 409); }
        }
        $pdo->prepare("UPDATE {$table} SET is_active = :is_active WHERE id = :id")->execute(['is_active' => $status === 'active' ? 1 : 0, 'id' => $id]);
        $this->audit->log((int) $actor['id'], null, 'reference_status_updated', $type, $id, null, ['status' => $status]);
        return ['id' => $id, 'type' => $type, 'status' => $status];
    }

'''
if methods not in text:
    text = text.replace(needle, methods + needle, 1)

old_select = "SELECT a.id, a.user_id, a.company_id, a.country_id, a.latitude, a.longitude, u.username, a.commission_type, a.commission_value, a.status AS agent_status, a.credit_enabled, a.block_at_minimum_balance, u.full_name"
new_select = "SELECT a.id, a.user_id, a.company_id, a.country_id, a.latitude, a.longitude, u.username, a.commission_type, a.commission_value, a.status AS agent_status, a.credit_enabled, a.block_at_minimum_balance, (SELECT w.currency_id FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS wallet_currency_id, (SELECT w.credit_limit FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS credit_limit, (SELECT w.minimum_balance FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS minimum_balance, (SELECT w.balance FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS wallet_balance, (SELECT w.used_debt FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS used_debt, u.full_name"
if old_select in text:
    text = text.replace(old_select, new_select, 1)
service.write_text(text)

# Frontend page routing and sidebar entries.
app = ROOT / 'assets' / 'js' / 'app.js'
text = app.read_text()
text = text.replace("countries:['الدول','إدارة المراجع / الدول'],", "countries:['الدول','إدارة المراجع / الدول'], currencies:['العملات','إدارة النظام / العملات'], exchange_rates:['أسعار الصرف','إدارة النظام / أسعار الصرف'],")
text = text.replace("agents:['الوكلاء','إدارة الحسابات / الوكلاء'],", "agents:['الوكلاء','إدارة الحسابات / الوكلاء'], agent_finance:['إعدادات الوكلاء المالية','إدارة الحسابات / الإعدادات المالية'], agent_credit:['شحن رصيد الوكيل','إدارة الحسابات / شحن الرصيد'],")
old_menu = "['countries','⊕','الدول'],['cities','⌖','المدن']"
new_menu = "['countries','⊕','الدول'],['cities','⌖','المدن'],['currencies','¤','العملات'],['exchange_rates','⇄','أسعار الصرف']"
text = text.replace(old_menu, new_menu, 1)
text = text.replace("['agents','♙','الوكلاء'],['customers','♧','العملاء']", "['agents','♙','الوكلاء'],['agent_finance','◈','إعدادات الوكلاء المالية'],['agent_credit','＋','شحن رصيد الوكيل'],['customers','♧','العملاء']", 1)
text = text.replace("buses:['manage_buses'],trips:['manage_trips'],agents:['manage_agents']", "buses:['manage_buses'],trips:['manage_trips'],currencies:['manage_settings'],exchange_rates:['manage_settings'],agents:['manage_agents'],agent_finance:['manage_agents'],agent_credit:['manage_agents']", 1)
text = text.replace("routes:'main_routes.php',route_stops:'sub_routes.php',stations:'stations.php',buses", "routes:'main_routes.php',route_stops:'sub_routes.php',stations:'stations.php',currencies:'currencies.php',exchange_rates:'exchange-rates.php',buses", 1)
text = text.replace("agents:'agents.php',wallet:'agent_balances.php',transactions", "agents:'agents.php',agent_finance:'agent-finance.php',agent_credit:'agent-credit.php',wallet:'agent_balances.php',transactions", 1)
text = text.replace("if(state.dashboardPage==='countries') return dashboardCountries(host);", "if(state.dashboardPage==='countries') return dashboardCountries(host); if(state.dashboardPage==='currencies') return dashboardCurrencies(host); if(state.dashboardPage==='exchange_rates') return dashboardExchangeRates(host);")
text = text.replace("if(state.dashboardPage==='agents') return dashboardAgents(host);", "if(state.dashboardPage==='agents') return dashboardAgents(host); if(state.dashboardPage==='agent_finance') return dashboardAgentFinance(host); if(state.dashboardPage==='agent_credit') return dashboardAgentCredit(host);")

# Replace the mixed management screen with a users-only screen; role assignment remains here as requested.
start = text.index('  async function dashboardManagement(host){')
end = text.index('  async function submitManagedOperation', start)
users_fn = r'''  function userRoleDialog(user, catalog, host){
    const roles=catalog.roles||[], companies=catalog.companies||[];
    const layer=modal(`<h3>تعيين دور المستخدم</h3><p class="muted">المستخدم: <b>${esc(user.full_name)}</b> — ${esc(user.email||'')}</p><form id="user-role-form" class="form-grid"><div class="field"><label>الدور</label><select name="role_id" required>${roles.map(item=>`<option value="${item.id}">${esc(item.name_ar)} (${esc(item.code)})</option>`).join('')}</select></div><div class="field"><label>الشركة (اختيارية)</label><select name="company_id"><option value="">دور عام</option>${companies.map(item=>`<option value="${item.id}">${esc(item.trade_name)}</option>`).join('')}</select></div><div class="modal-actions"><button class="btn btn-primary" type="submit">حفظ الدور</button><button class="btn btn-outline" type="button" data-modal-close>إلغاء</button></div></form>`, 'user-role-modal');
    $('[data-modal-close]',layer)?.addEventListener('click',closeModal);
    $('#user-role-form',layer)?.addEventListener('submit',async(event)=>{event.preventDefault();try{const body=Object.fromEntries(new FormData(event.currentTarget));await api(`admin/users/${user.id}/roles`,{method:'POST',body:JSON.stringify(body)});closeModal();showToast('تم تعيين الدور للمستخدم.','success');dashboardManagement(host);}catch(error){showToast(error.message,'error');}});
  }
  async function dashboardManagement(host){
    const {catalog}=await api('admin/catalog'); const users=catalog.users||[];
    const rows=users.map(user=>`<tr><td>${esc(user.full_name)}</td><td dir="ltr">${esc(user.email||'—')}</td><td><span class="status active">حساب مستخدم</span></td><td><button class="btn btn-outline btn-sm" data-user-role="${user.id}">تعيين الدور</button></td></tr>`).join('')||`<tr><td colspan="4">${empty('لا توجد حسابات مستخدمين.')}</td></tr>`;
    host.innerHTML=`${dashTitle('المستخدمون','إدارة حسابات المستخدمين وتعيين أدوارهم فقط. تم نقل العمليات التشغيلية والمالية إلى صفحاتها المتخصصة.') }<section class="panel"><div class="panel-head"><div><h3>المستخدمون (${users.length})</h3><span class="muted">لا تظهر هنا نماذج الدول أو الشركات أو العملات أو الرحلات أو العمليات المالية.</span></div></div><div class="panel-body"><div class="table-scroll"><table class="data-table"><thead><tr><th>الاسم</th><th>البريد</th><th>النوع</th><th>الإجراء</th></tr></thead><tbody>${rows}</tbody></table></div></div></section>`;
    $$('[data-user-role]',host).forEach(button=>button.addEventListener('click',()=>{const user=users.find(item=>Number(item.id)===Number(button.dataset.userRole));if(user)userRoleDialog(user,catalog,host);}));
  }

  async function dashboardCurrencies(host){
    const {items}=await api('admin/references/currencies');
    const rows=items.map(item=>`<tr><td>${esc(item.code)}</td><td>${esc(item.name_ar)}</td><td>${esc(item.symbol_ar)}</td><td>${esc(item.decimal_places)}</td><td>${Number(item.is_active)?status('active'):'<span class="status inactive">موقوف</span>'}</td><td><button class="btn btn-outline btn-sm" data-reference-status="${item.id}:${Number(item.is_active)?'inactive':'active'}">${Number(item.is_active)?'إيقاف':'تفعيل'}</button></td></tr>`).join('')||`<tr><td colspan="6">${empty('لا توجد عملات.')}</td></tr>`;
    host.innerHTML=`${dashTitle('العملات','إدارة العملات المستخدمة في الشركات والأسعار والحجوزات.','<button class="btn btn-primary" data-reference-add>＋ إضافة عملة</button>')}<section class="panel"><div class="panel-head"><h3>قائمة العملات (${items.length})</h3></div><div class="panel-body"><div class="table-scroll"><table class="data-table"><thead><tr><th>الرمز</th><th>اسم العملة</th><th>رمز العرض</th><th>المنازل</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>${rows}</tbody></table></div></div></section>`;
    $('[data-reference-add]',host)?.addEventListener('click',()=>{const layer=modal(`<h3>إضافة عملة</h3><form id="currency-form" class="form-grid"><div class="field"><label>رمز العملة</label><input name="code" maxlength="3" placeholder="YER" required></div><div class="field"><label>اسم العملة</label><input name="name_ar" placeholder="الريال اليمني" required></div><div class="field"><label>رمز العرض</label><input name="symbol_ar" placeholder="﷼" required></div><div class="field"><label>المنازل العشرية</label><input name="decimal_places" type="number" min="0" max="6" value="2" required></div><div class="modal-actions"><button class="btn btn-primary" type="submit">حفظ العملة</button><button class="btn btn-outline" type="button" data-modal-close>إلغاء</button></div></form>`, 'reference-modal');$('[data-modal-close]',layer)?.addEventListener('click',closeModal);$('#currency-form',layer)?.addEventListener('submit',async(event)=>{event.preventDefault();try{await api('admin/references/currencies',{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(event.currentTarget)))});closeModal();showToast('تمت إضافة العملة.','success');dashboardCurrencies(host);}catch(error){showToast(error.message,'error');}});});
    $$('[data-reference-status]',host).forEach(button=>button.addEventListener('click',async()=>{const [id,next]=button.dataset.referenceStatus.split(':');try{await api(`admin/references/currencies/${id}/status`,{method:'PUT',body:JSON.stringify({status:next})});showToast('تم تحديث حالة العملة.','success');dashboardCurrencies(host);}catch(error){showToast(error.message,'error');}}));
  }

  async function dashboardExchangeRates(host){
    const {items}=await api('admin/references/exchange-rates'); const {catalog}=await api('admin/catalog'); const currencies=catalog.currencies||[];
    const rows=items.map(item=>`<tr><td>${esc(item.base_code)}</td><td>${esc(item.quote_code)}</td><td dir="ltr">${esc(item.rate)}</td><td>${dateTime(item.effective_at)}</td><td>${Number(item.is_active)?status('active'):'<span class="status inactive">موقوف</span>'}</td><td><button class="btn btn-outline btn-sm" data-rate-status="${item.id}:${Number(item.is_active)?'inactive':'active'}">${Number(item.is_active)?'إيقاف':'تفعيل'}</button></td></tr>`).join('')||`<tr><td colspan="6">${empty('لا توجد أسعار صرف.')}</td></tr>`;
    const options=currencies.map(item=>`<option value="${item.id}">${esc(item.code)} — ${esc(item.name_ar)}</option>`).join('');
    host.innerHTML=`${dashTitle('أسعار الصرف','إدارة أسعار التحويل بين العملات مع تاريخ التفعيل.','<button class="btn btn-primary" data-rate-add>＋ إضافة سعر صرف</button>')}<section class="panel"><div class="panel-head"><h3>أسعار الصرف (${items.length})</h3></div><div class="panel-body"><div class="table-scroll"><table class="data-table"><thead><tr><th>العملة الأساسية</th><th>عملة التسعير</th><th>السعر</th><th>تاريخ التفعيل</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>${rows}</tbody></table></div></div></section>`;
    $('[data-rate-add]',host)?.addEventListener('click',()=>{const layer=modal(`<h3>إضافة سعر صرف</h3><form id="rate-form" class="form-grid"><div class="field"><label>من العملة</label><select name="base_currency_id" required>${options}</select></div><div class="field"><label>إلى العملة</label><select name="quote_currency_id" required>${options}</select></div><div class="field"><label>سعر الصرف</label><input name="rate" type="number" min="0.00000001" step="0.00000001" required></div><div class="field"><label>تاريخ التفعيل</label><input name="effective_at" type="datetime-local" required></div><div class="field"><label>تاريخ الانتهاء (اختياري)</label><input name="expires_at" type="datetime-local"></div><div class="modal-actions"><button class="btn btn-primary" type="submit">حفظ السعر</button><button class="btn btn-outline" type="button" data-modal-close>إلغاء</button></div></form>`, 'reference-modal');$('[data-modal-close]',layer)?.addEventListener('click',closeModal);$('#rate-form',layer)?.addEventListener('submit',async(event)=>{event.preventDefault();const body=Object.fromEntries(new FormData(event.currentTarget));['effective_at','expires_at'].forEach(key=>{if(body[key])body[key]=String(body[key]).replace('T',' ')+':00';});try{await api('admin/references/exchange-rates',{method:'POST',body:JSON.stringify(body)});closeModal();showToast('تمت إضافة سعر الصرف.','success');dashboardExchangeRates(host);}catch(error){showToast(error.message,'error');}});});
    $$('[data-rate-status]',host).forEach(button=>button.addEventListener('click',async()=>{const [id,next]=button.dataset.rateStatus.split(':');try{await api(`admin/references/exchange-rates/${id}/status`,{method:'PUT',body:JSON.stringify({status:next})});showToast('تم تحديث حالة سعر الصرف.','success');dashboardExchangeRates(host);}catch(error){showToast(error.message,'error');}}));
  }

  async function dashboardAgentFinance(host){
    const {agents}=await api('admin/people'); const {catalog}=await api('admin/catalog'); const currencies=catalog.currencies||[];
    const agentOptions=(agents||[]).map(item=>`<option value="${item.id}" data-currency="${item.wallet_currency_id||''}" data-credit="${item.credit_limit||0}" data-minimum="${item.minimum_balance||0}" data-status="${item.agent_status||'active'}" data-credit-enabled="${Number(item.credit_enabled)?'true':'false'}" data-block-minimum="${Number(item.block_at_minimum_balance)?'true':'false'}">${esc(item.full_name)} — ${esc(item.company_name)}</option>`).join('');
    const currencyOptions=currencies.map(item=>`<option value="${item.id}">${esc(item.code)} — ${esc(item.name_ar)}</option>`).join('');
    host.innerHTML=`${dashTitle('إعدادات الوكلاء المالية','عدّل إعدادات وكيل محدد فقط، مع إبقاء العملية محمية بمعاملة قاعدة بيانات.')}<section class="panel"><div class="panel-head"><h3>الإعدادات المالية</h3></div><div class="panel-body"><form id="agent-finance-page-form" class="form-grid"><div class="field"><label>الوكيل</label><select name="agent_id" id="finance-agent" required>${agentOptions||'<option value="">لا يوجد وكلاء</option>'}</select></div><div class="field"><label>العملة</label><select name="currency_id" id="finance-currency" required>${currencyOptions}</select></div><div class="field"><label>حد الائتمان</label><input name="credit_limit" id="finance-credit" type="number" min="0" step="0.01" required></div><div class="field"><label>الحد الأدنى</label><input name="minimum_balance" id="finance-minimum" type="number" min="0" step="0.01" required></div><div class="field"><label>حالة الحساب</label><select name="status" id="finance-status"><option value="active">نشط</option><option value="financially_blocked">موقوف ماليًا</option><option value="suspended">موقوف</option></select></div><div class="field"><label>الائتمان</label><select name="credit_enabled" id="finance-credit-enabled"><option value="true">مفعل</option><option value="false">معطل</option></select></div><div class="field"><label>حماية الحد الأدنى</label><select name="block_at_minimum_balance" id="finance-block-minimum"><option value="true">مفعلة</option><option value="false">معطلة</option></select></div><div class="field"><label>&nbsp;</label><button class="btn btn-primary" type="submit" ${agents?.length?'':'disabled'}>حفظ الإعدادات المالية</button></div></form></div></section>`;
    const fill=()=>{const option=$('#finance-agent',host)?.selectedOptions[0];if(!option)return;$('#finance-currency',host).value=option.dataset.currency||currencies[0]?.id||'';$('#finance-credit',host).value=option.dataset.credit||0;$('#finance-minimum',host).value=option.dataset.minimum||0;$('#finance-status',host).value=option.dataset.status||'active';$('#finance-credit-enabled',host).value=option.dataset.creditEnabled||'false';$('#finance-block-minimum',host).value=option.dataset.blockMinimum||'false';};fill();$('#finance-agent',host)?.addEventListener('change',fill);$('#agent-finance-page-form',host)?.addEventListener('submit',async(event)=>{event.preventDefault();const body=Object.fromEntries(new FormData(event.currentTarget));const id=body.agent_id;delete body.agent_id;try{await api(`admin/agents/${id}/financial-settings`,{method:'PUT',body:JSON.stringify(body)});showToast('تم تحديث الإعدادات المالية للوكيل.','success');dashboardAgentFinance(host);}catch(error){showToast(error.message,'error');}});
  }

  async function dashboardAgentCredit(host){
    const {agents}=await api('admin/people'); const {catalog}=await api('admin/catalog'); const currencies=catalog.currencies||[];
    const agentOptions=(agents||[]).map(item=>`<option value="${item.id}">${esc(item.full_name)} — ${esc(item.company_name)}</option>`).join(''); const currencyOptions=currencies.map(item=>`<option value="${item.id}">${esc(item.code)} — ${esc(item.name_ar)}</option>`).join('');
    host.innerHTML=`${dashTitle('شحن رصيد الوكيل','كل عملية شحن تُسجل كحركة مالية داخل معاملة ذرية، ولا يُعدّل الرصيد مباشرة دون سجل.')}<section class="panel"><div class="panel-head"><h3>عملية شحن مستقلة</h3></div><div class="panel-body"><form id="agent-credit-page-form" class="form-grid"><div class="field"><label>الوكيل</label><select name="agent_id" required>${agentOptions||'<option value="">لا يوجد وكلاء</option>'}</select></div><div class="field"><label>العملة</label><select name="currency_id" required>${currencyOptions}</select></div><div class="field"><label>المبلغ</label><input name="amount" type="number" min="0.01" step="0.01" required></div><div class="field wide"><label>سبب الشحن</label><input name="reason" placeholder="تحويل أو تسوية مالية" maxlength="500" required></div><div class="field"><label>&nbsp;</label><button class="btn btn-primary" type="submit" ${agents?.length?'':'disabled'}>تسجيل عملية الشحن</button></div></form></div></section>`;
    $('#agent-credit-page-form',host)?.addEventListener('submit',async(event)=>{event.preventDefault();const body=Object.fromEntries(new FormData(event.currentTarget));const id=body.agent_id;delete body.agent_id;try{await api(`admin/agents/${id}/wallet/credit`,{method:'POST',body:JSON.stringify(body)});showToast('تم شحن الرصيد وتسجيل الحركة المالية.','success');event.currentTarget.reset();}catch(error){showToast(error.message,'error');}});
  }

'''
text = text[:start] + users_fn + text[end:]
app.write_text(text)
