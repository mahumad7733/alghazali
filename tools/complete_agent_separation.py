from pathlib import Path

ROOT = Path('/home/ubuntu/bus-booking-system/infinityfree')

layout = ROOT / 'admin' / '_layout.php'
text = layout.read_text()
needle = "        'agent_credit' => ['title' => 'شحن رصيد الوكيل', 'page' => 'agent_credit', 'permissions' => ['manage_agents'], 'any' => false],"
replacement = needle + "\n        'agent_transactions' => ['title' => 'كشف حساب الوكيل', 'page' => 'agent_transactions', 'permissions' => ['manage_agents'], 'any' => false],"
if "'agent_transactions'" not in text:
    text = text.replace(needle, replacement, 1)
layout.write_text(text)

wrapper = ROOT / 'admin' / 'agent-transactions.php'
if not wrapper.exists():
    wrapper.write_text("<?php declare(strict_types=1); require __DIR__ . '/_layout.php'; renderAdminPage(requireAdminPage('agent_transactions'));\n")

service = ROOT / 'includes' / 'AgentService.php'
text = service.read_text()
needle = "    /** @return array<string, mixed> */\n    public function creditWallet(array $actor, int $agentId, array $input): array"
method = r'''    /** @return list<array<string, mixed>> */
    public function transactionsForAdmin(array $actor, int $agentId): array
    {
        if (!in_array('manage_agents', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية عرض كشف حساب الوكيل.', 'FORBIDDEN', 403);
        }
        $agent = $this->one($this->database->pdo(), 'SELECT id, company_id FROM agents WHERE id = :id', ['id' => $agentId]);
        if ($agent === null) { Response::error('الوكيل المطلوب غير موجود.', 'NOT_FOUND', 404); }
        if (!in_array('super_admin', $actor['roles'], true) && (int) ($actor['company_id'] ?? 0) !== (int) $agent['company_id']) {
            Response::error('لا يمكن عرض حساب وكيل تابع لشركة أخرى.', 'FORBIDDEN', 403);
        }
        return $this->transactions($agentId);
    }

'''
if "public function transactionsForAdmin" not in text:
    text = text.replace(needle, method + needle, 1)
# Add commission fields to the existing financial update validation and persistence.
text = text.replace("$status = (string) ($input['status'] ?? '');\n        if ($currencyId", "$status = (string) ($input['status'] ?? '');\n        $commissionType = (string) ($input['commission_type'] ?? 'percentage');\n        $commissionValue = filter_var($input['commission_value'] ?? 0, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);\n        if ($currencyId", 1)
text = text.replace("!in_array($status, ['active', 'financially_blocked', 'suspended'], true)) {", "!in_array($status, ['active', 'financially_blocked', 'suspended'], true) || !in_array($commissionType, ['percentage', 'fixed'], true) || $commissionValue === false) {", 1)
text = text.replace("$this->database->transaction(function (PDO $pdo) use ($actor, $agentId, $currencyId, $creditLimit, $minimumBalance, $creditEnabled, $blockAtMinimum, $status): array {", "$this->database->transaction(function (PDO $pdo) use ($actor, $agentId, $currencyId, $creditLimit, $minimumBalance, $creditEnabled, $blockAtMinimum, $status, $commissionType, $commissionValue): array {", 1)
text = text.replace("$pdo->prepare('UPDATE agents SET status = :status, credit_enabled = :credit_enabled, block_at_minimum_balance = :block_at_minimum_balance WHERE id = :id')->execute(['status' => $status, 'credit_enabled' => $creditEnabled ? 1 : 0, 'block_at_minimum_balance' => $blockAtMinimum ? 1 : 0, 'id' => $agentId]);", "$pdo->prepare('UPDATE agents SET status = :status, credit_enabled = :credit_enabled, block_at_minimum_balance = :block_at_minimum_balance, commission_type = :commission_type, commission_value = :commission_value WHERE id = :id')->execute(['status' => $status, 'credit_enabled' => $creditEnabled ? 1 : 0, 'block_at_minimum_balance' => $blockAtMinimum ? 1 : 0, 'commission_type' => $commissionType, 'commission_value' => $commissionValue, 'id' => $agentId]);", 1)
service.write_text(text)

api = ROOT / 'api' / 'v1' / 'index.php'
text = api.read_text()
needle = "if ($route === 'agent/transactions' && $method === 'GET') {"
route = """if (preg_match('#^admin/agents/(\\d+)/transactions$#', $route, $matches) === 1 && $method === 'GET') {
    $actor = $auth->requirePermissions(['manage_agents']);
    Response::success('تم جلب كشف حساب الوكيل.', ['items' => $agents->transactionsForAdmin($actor, (int) $matches[1])]);
}

"""
if "transactionsForAdmin" not in text:
    text = text.replace(needle, route + needle, 1)
api.write_text(text)

# Extend agent projection with wallet aggregate values for the agent list.
admin_service = ROOT / 'includes' / 'AdminService.php'
text = admin_service.read_text()
old = "(SELECT w.used_debt FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS used_debt, u.full_name"
new = "(SELECT w.used_debt FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS used_debt, (SELECT w.balance + GREATEST(0, w.credit_limit - w.used_debt) FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS booking_available, u.full_name"
if old in text:
    text = text.replace(old, new, 1)
admin_service.write_text(text)

app = ROOT / 'assets' / 'js' / 'app.js'
text = app.read_text()
text = text.replace("agent_credit:['شحن رصيد الوكيل','إدارة الحسابات / شحن الرصيد'],", "agent_credit:['شحن رصيد الوكيل','إدارة الحسابات / شحن الرصيد'], agent_transactions:['كشف حساب الوكيل','إدارة الحسابات / كشف الحساب'],", 1)
text = text.replace("['agent_credit','＋','شحن رصيد الوكيل'],['customers'", "['agent_credit','＋','شحن رصيد الوكيل'],['agent_transactions','↔','كشف حساب الوكيل'],['customers'", 1)
text = text.replace("agent_credit:['manage_agents'],customers", "agent_credit:['manage_agents'],agent_transactions:['manage_agents'],customers", 1)
text = text.replace("agent_finance:'agent-finance.php',agent_credit:'agent-credit.php',wallet", "agent_finance:'agent-finance.php',agent_credit:'agent-credit.php',agent_transactions:'agent-transactions.php',wallet", 1)
text = text.replace("if(state.dashboardPage==='agent_finance') return dashboardAgentFinance(host); if(state.dashboardPage==='agent_credit') return dashboardAgentCredit(host);", "if(state.dashboardPage==='agent_finance') return dashboardAgentFinance(host); if(state.dashboardPage==='agent_credit') return dashboardAgentCredit(host); if(state.dashboardPage==='agent_transactions') return dashboardAgentTransactions(host);")
# Add agent action buttons and richer columns to the existing agent table.
text = text.replace("const fields=isAgent?['full_name','username','email','phone','company_name','country_name','commission_value','agent_status']:", "const fields=isAgent?['full_name','username','email','phone','company_name','wallet_balance','used_debt','booking_available','commission_value','agent_status']:", 1)
text = text.replace("commission_value:'العمولة',agent_status:'الحالة'", "commission_value:'العمولة',wallet_balance:'الرصيد',used_debt:'الدين',booking_available:'المتاح للحجز',agent_status:'الحالة'", 1)
old_actions = "<td><div class=\"table-actions\"><button class=\"btn btn-outline btn-sm\" data-person-edit=\"${type}:${item.id}\">تعديل</button>${!isAgent&&item.user_status==='pending'?`<button class=\"btn btn-primary btn-sm\" data-person-confirm=\"${type}:${item.id}\">تأكيد الحساب</button>`:`<button class=\"btn btn-outline btn-sm\" data-person-status=\"${type}:${item.id}\">الحالة</button>`}<button class=\"btn btn-danger-outline btn-sm\" data-person-delete=\"${type}:${item.id}\">حذف</button></div></td>"
new_actions = "<td><div class=\"table-actions\"><button class=\"btn btn-outline btn-sm\" data-person-edit=\"${type}:${item.id}\">تعديل</button>${isAgent?`<a class=\"btn btn-outline btn-sm\" href=\"agent-finance.php?agent_id=${item.id}\">المالية</a><a class=\"btn btn-outline btn-sm\" href=\"agent-credit.php?agent_id=${item.id}\">شحن</a><a class=\"btn btn-outline btn-sm\" href=\"agent-transactions.php?agent_id=${item.id}\">الكشف</a>`:''}${!isAgent&&item.user_status==='pending'?`<button class=\"btn btn-primary btn-sm\" data-person-confirm=\"${type}:${item.id}\">تأكيد الحساب</button>`:`<button class=\"btn btn-outline btn-sm\" data-person-status=\"${type}:${item.id}\">الحالة</button>`}<button class=\"btn btn-danger-outline btn-sm\" data-person-delete=\"${type}:${item.id}\">حذف</button></div></td>"
if old_actions in text:
    text = text.replace(old_actions, new_actions, 1)
# Add commission values and URL-based selection to the finance form.
text = text.replace("data-block-minimum=\"${Number(item.block_at_minimum_balance)?'true':'false'}\">", "data-block-minimum=\"${Number(item.block_at_minimum_balance)?'true':'false'}\" data-commission-type=\"${item.commission_type||'percentage'}\" data-commission-value=\"${item.commission_value||0}\">")
text = text.replace("<div class=\"field\"><label>حماية الحد الأدنى</label><select name=\"block_at_minimum_balance\" id=\"finance-block-minimum\"><option value=\"true\">مفعلة</option><option value=\"false\">معطلة</option></select></div><div class=\"field\"><label>&nbsp;</label>", "<div class=\"field\"><label>حماية الحد الأدنى</label><select name=\"block_at_minimum_balance\" id=\"finance-block-minimum\"><option value=\"true\">مفعلة</option><option value=\"false\">معطلة</option></select></div><div class=\"field\"><label>نوع العمولة</label><select name=\"commission_type\" id=\"finance-commission-type\"><option value=\"percentage\">نسبة مئوية</option><option value=\"fixed\">مبلغ ثابت</option></select></div><div class=\"field\"><label>قيمة العمولة</label><input name=\"commission_value\" id=\"finance-commission-value\" type=\"number\" min=\"0\" step=\"0.01\" required></div><div class=\"field\"><label>&nbsp;</label>", 1)
text = text.replace("const fill=()=>{const option=$('#finance-agent',host)?.selectedOptions[0];if(!option)return;$('#finance-currency',host).value=option.dataset.currency||currencies[0]?.id||'';", "const requestedAgent=new URLSearchParams(location.search).get('agent_id');if(requestedAgent&&$('#finance-agent',host)?.querySelector(`option[value=\"${CSS.escape(requestedAgent)}\"]`))$('#finance-agent',host).value=requestedAgent;const fill=()=>{const option=$('#finance-agent',host)?.selectedOptions[0];if(!option)return;$('#finance-currency',host).value=option.dataset.currency||currencies[0]?.id||'';", 1)
text = text.replace("$('#finance-block-minimum',host).value=option.dataset.blockMinimum||'false';};", "$('#finance-block-minimum',host).value=option.dataset.blockMinimum||'false';$('#finance-commission-type',host).value=option.dataset.commissionType||'percentage';$('#finance-commission-value',host).value=option.dataset.commissionValue||0;};", 1)
# Insert admin agent transaction page before dashboardAgentCredit.
needle = "  async function dashboardAgentCredit(host){"
transactions_fn = r'''  async function dashboardAgentTransactions(host){
    const {agents}=await api('admin/people'); const requested=new URLSearchParams(location.search).get('agent_id'); const agentId=requested&&agents.some(item=>String(item.id)===String(requested))?requested:String(agents[0]?.id||'');
    const options=(agents||[]).map(item=>`<option value="${item.id}" ${String(item.id)===String(agentId)?'selected':''}>${esc(item.full_name)} — ${esc(item.company_name)}</option>`).join('');
    let items=[]; if(agentId){items=(await api(`admin/agents/${agentId}/transactions`)).items||[];}
    const rows=items.map(item=>`<tr><td>${dateTime(item.created_at)}</td><td>${esc(item.transaction_type)}</td><td>${money(item.debit_amount,item.currency_symbol)}</td><td>${money(item.credit_amount,item.currency_symbol)}</td><td>${money(item.balance_after,item.currency_symbol)}</td><td>${money(item.debt_after,item.currency_symbol)}</td><td>${esc(item.reason)}</td></tr>`).join('')||`<tr><td colspan="7">${empty('لا توجد حركات مالية لهذا الوكيل.')}</td></tr>`;
    host.innerHTML=`${dashTitle('كشف حساب الوكيل','عرض الحركات المالية المسجلة للوكيل دون تعديل مباشر على الرصيد.')}<section class="panel"><div class="panel-head"><h3>حساب الوكيل</h3></div><div class="panel-body"><form id="agent-transactions-filter" class="form-grid"><div class="field"><label>الوكيل</label><select name="agent_id" required>${options||'<option value="">لا يوجد وكلاء</option>'}</select></div><div class="field"><label>&nbsp;</label><button class="btn btn-outline" type="submit">عرض الكشف</button></div></form></div></section><section class="panel" style="margin-top:18px"><div class="panel-head"><h3>الحركات المالية (${items.length})</h3></div><div class="panel-body"><div class="table-scroll"><table class="data-table"><thead><tr><th>التاريخ</th><th>العملية</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>الدين</th><th>السبب</th></tr></thead><tbody>${rows}</tbody></table></div></div></section>`;
    $('#agent-transactions-filter',host)?.addEventListener('submit',(event)=>{event.preventDefault();const id=new FormData(event.currentTarget).get('agent_id');location.href=`agent-transactions.php?agent_id=${encodeURIComponent(id)}`;});
  }

'''
if 'async function dashboardAgentTransactions' not in text:
    text = text.replace(needle, transactions_fn + needle, 1)
app.write_text(text)
