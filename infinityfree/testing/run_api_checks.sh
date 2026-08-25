#!/usr/bin/env bash
set -euo pipefail

# اختبارات API الأساسية. يتطلب خادم PHP محليًا أو رابطًا يمرر في API_BASE.
API_BASE="${API_BASE:-http://127.0.0.1:8088/api/v1/index.php}"
ADMIN_EMAIL="${ADMIN_EMAIL:-company@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-Company@123}"
AGENT_EMAIL="${AGENT_EMAIL:-agent@example.com}"
AGENT_PASSWORD="${AGENT_PASSWORD:-Agent@123}"
CUSTOMER_EMAIL="${CUSTOMER_EMAIL:-customer@example.com}"
CUSTOMER_PASSWORD="${CUSTOMER_PASSWORD:-Customer@123}"

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

request() {
  local cookie_file="$1"; shift
  local route="$1"; shift
  curl -sS -b "$cookie_file" -c "$cookie_file" "$@" "${API_BASE}?route=${route}"
}

assert_contains() {
  local body="$1" expected="$2" label="$3"
  if [[ "$body" != *"$expected"* ]]; then
    printf 'فشل الاختبار: %s\n%s\n' "$label" "$body" >&2
    exit 1
  fi
  printf 'نجح: %s\n' "$label" >&2
}

login() {
  local cookie_file="$1" email="$2" password="$3"
  local health csrf response
  health="$(curl -sS -c "$cookie_file" "${API_BASE}?route=health")"
  csrf="$(sed -n 's/.*"csrf_token":"\([^"]*\)".*/\1/p' <<< "$health")"
  response="$(request "$cookie_file" 'auth/login' -H 'Content-Type: application/json' -H "X-CSRF-Token: ${csrf}" -d "{\"email\":\"${email}\",\"password\":\"${password}\"}")"
  assert_contains "$response" '"success":true' "تسجيل الدخول: ${email}"
  sed -n 's/.*"csrf_token":"\([^"]*\)".*/\1/p' <<< "$response"
}

csrf_for() {
  local cookie_file="$1" response
  response="$(request "$cookie_file" 'auth/me')"
  assert_contains "$response" '"success":true' 'تجديد رمز CSRF للجلسة'
  sed -n 's/.*"csrf_token":"\([^"]*\)".*/\1/p' <<< "$response"
}

admin_cookie="$workdir/admin.cookies"
agent_cookie="$workdir/agent.cookies"
customer_cookie="$workdir/customer.cookies"

health="$(curl -sS -c "$workdir/health.cookies" "${API_BASE}?route=health")"
assert_contains "$health" '"service":"bus-booking-api"' 'فحص جاهزية API'

admin_csrf="$(login "$admin_cookie" "$ADMIN_EMAIL" "$ADMIN_PASSWORD")"
agent_csrf="$(login "$agent_cookie" "$AGENT_EMAIL" "$AGENT_PASSWORD")"
customer_csrf="$(login "$customer_cookie" "$CUSTOMER_EMAIL" "$CUSTOMER_PASSWORD")"
customer_csrf="$(csrf_for "$customer_cookie")"

operations="$(request "$admin_cookie" 'admin/operations')"
assert_contains "$operations" '"operations"' 'وصول مدير الشركة إلى بيانات التشغيل'

agent_operations="$(curl -sS -o "$workdir/agent-operations.json" -w '%{http_code}' -b "$agent_cookie" "${API_BASE}?route=admin/operations")"
if [[ "$agent_operations" != '403' ]]; then
  printf 'فشل الاختبار: يجب منع الوكيل من بيانات الإدارة، الحالة الفعلية %s\n' "$agent_operations" >&2
  exit 1
fi
printf 'نجح: منع الوكيل من بيانات الإدارة\n'

seat_conflict_status="$(curl -sS -o "$workdir/seat-conflict.json" -w '%{http_code}' -b "$customer_cookie" -H 'Content-Type: application/json' -H "X-CSRF-Token: ${customer_csrf}" -d '{"trip_id":1,"segment_id":2,"seats":["1A"],"passengers":[{"full_name_ar":"اختبار تعارض المقعد","phone_country_code":"+967","phone":"777888888","passport_number":"YEM-SEAT-CONFLICT-CHECK","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"}]}' "${API_BASE}?route=bookings")"
if [[ "$seat_conflict_status" != '409' ]]; then
  printf 'فشل الاختبار: كان متوقعًا رفض المقعد المحجوز 1A بحالة 409، وكانت الحالة %s\n' "$seat_conflict_status" >&2
  cat "$workdir/seat-conflict.json" >&2
  exit 1
fi
assert_contains "$(cat "$workdir/seat-conflict.json")" 'SEAT_CONFLICT' 'منع تعارض المقعد'

wallet="$(request "$agent_cookie" 'agent/wallet')"
assert_contains "$wallet" '"currency_code":"YER"' 'عرض محفظة الوكيل ضمن نطاقه'

# يتحقق التدفق التالي من خصم الرصيد أولاً ثم استخدام الائتمان عند حماية الحد الأدنى.
admin_csrf="$(csrf_for "$admin_cookie")"
request "$admin_cookie" 'admin/agents/1/financial-settings' -X PUT -H 'Content-Type: application/json' -H "X-CSRF-Token: ${admin_csrf}" -d '{"currency_id":1,"credit_limit":20000,"minimum_balance":5000,"credit_enabled":true,"block_at_minimum_balance":true,"status":"active"}' > /dev/null
agent_csrf="$(csrf_for "$agent_cookie")"
cash_booking="$(request "$agent_cookie" 'bookings' -H 'Content-Type: application/json' -H "X-CSRF-Token: ${agent_csrf}" -d '{"trip_id":1,"segment_id":2,"seats":["1B"],"passengers":[{"full_name_ar":"اختبار خصم الرصيد","phone_country_code":"+967","phone":"777700101","passport_number":"YEM-CASH-CHECK-01","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"}]}' )"
cash_booking_id="$(sed -n 's/.*"booking":{"id":\([0-9]*\).*/\1/p' <<< "$cash_booking")"
if [[ -z "$cash_booking_id" ]]; then printf 'فشل اختبار إنشاء حجز الرصيد.\n' >&2; exit 1; fi
admin_csrf="$(csrf_for "$admin_cookie")"
request "$admin_cookie" "bookings/${cash_booking_id}/confirm" -X PUT -H 'Content-Type: application/json' -H "X-CSRF-Token: ${admin_csrf}" -d '{}' > /dev/null
agent_csrf="$(csrf_for "$agent_cookie")"
credit_usage_booking="$(request "$agent_cookie" 'bookings' -H 'Content-Type: application/json' -H "X-CSRF-Token: ${agent_csrf}" -d '{"trip_id":1,"segment_id":2,"seats":["2A","2B","2C","2D"],"passengers":[{"full_name_ar":"اختبار دين 1","phone_country_code":"+967","phone":"777700201","passport_number":"YEM-DEBT-CHECK-01","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"},{"full_name_ar":"اختبار دين 2","phone_country_code":"+967","phone":"777700202","passport_number":"YEM-DEBT-CHECK-02","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"},{"full_name_ar":"اختبار دين 3","phone_country_code":"+967","phone":"777700203","passport_number":"YEM-DEBT-CHECK-03","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"},{"full_name_ar":"اختبار دين 4","phone_country_code":"+967","phone":"777700204","passport_number":"YEM-DEBT-CHECK-04","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"}]}' )"
credit_usage_booking_id="$(sed -n 's/.*"booking":{"id":\([0-9]*\).*/\1/p' <<< "$credit_usage_booking")"
if [[ -z "$credit_usage_booking_id" ]]; then printf 'فشل اختبار إنشاء حجز الائتمان الفعلي.\n' >&2; exit 1; fi
admin_csrf="$(csrf_for "$admin_cookie")"
request "$admin_cookie" "bookings/${credit_usage_booking_id}/confirm" -X PUT -H 'Content-Type: application/json' -H "X-CSRF-Token: ${admin_csrf}" -d '{}' > /dev/null
wallet_after_credit="$(request "$agent_cookie" 'agent/wallet')"
assert_contains "$wallet_after_credit" '"used_debt":"5000.00"' 'خصم الرصيد ثم استخدام الائتمان'

# بعد استهلاك الائتمان، يُتحقق من الحظر الصريح عند تعطيل الائتمان.
admin_csrf="$(csrf_for "$admin_cookie")"
request "$admin_cookie" 'admin/agents/1/financial-settings' -X PUT -H 'Content-Type: application/json' -H "X-CSRF-Token: ${admin_csrf}" -d '{"currency_id":1,"credit_limit":20000,"minimum_balance":5000,"credit_enabled":false,"block_at_minimum_balance":true,"status":"active"}' > "$workdir/credit-disabled-settings.json"
agent_csrf="$(csrf_for "$agent_cookie")"
credit_booking="$(request "$agent_cookie" 'bookings' -H 'Content-Type: application/json' -H "X-CSRF-Token: ${agent_csrf}" -d '{"trip_id":1,"segment_id":2,"seats":["3A","3B","3C","3D","4A","4B"],"passengers":[{"full_name_ar":"اختبار ائتمان 1","phone_country_code":"+967","phone":"777701001","passport_number":"YEM-CREDIT-CHECK-01","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"},{"full_name_ar":"اختبار ائتمان 2","phone_country_code":"+967","phone":"777701002","passport_number":"YEM-CREDIT-CHECK-02","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"},{"full_name_ar":"اختبار ائتمان 3","phone_country_code":"+967","phone":"777701003","passport_number":"YEM-CREDIT-CHECK-03","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"},{"full_name_ar":"اختبار ائتمان 4","phone_country_code":"+967","phone":"777701004","passport_number":"YEM-CREDIT-CHECK-04","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"},{"full_name_ar":"اختبار ائتمان 5","phone_country_code":"+967","phone":"777701005","passport_number":"YEM-CREDIT-CHECK-05","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"},{"full_name_ar":"اختبار ائتمان 6","phone_country_code":"+967","phone":"777701006","passport_number":"YEM-CREDIT-CHECK-06","birth_date":"1990-01-01","birth_place":"صنعاء","passport_issue_date":"2024-01-01","passport_issue_place":"صنعاء"}]}' )"
credit_booking_id="$(sed -n 's/.*"booking":{"id":\([0-9]*\).*/\1/p' <<< "$credit_booking")"
if [[ -z "$credit_booking_id" ]]; then printf 'فشل اختبار إنشاء حجز الائتمان.\n%s\n' "$credit_booking" >&2; exit 1; fi
admin_csrf="$(csrf_for "$admin_cookie")"
disabled_status="$(curl -sS -o "$workdir/credit-disabled-confirm.json" -w '%{http_code}' -b "$admin_cookie" -H 'Content-Type: application/json' -H "X-CSRF-Token: ${admin_csrf}" -X PUT -d '{}' "${API_BASE}?route=bookings/${credit_booking_id}/confirm")"
if [[ "$disabled_status" != '409' ]]; then printf 'فشل الاختبار: كان متوقعًا 409 عند تعطيل الائتمان.\n' >&2; exit 1; fi
assert_contains "$(cat "$workdir/credit-disabled-confirm.json")" 'CREDIT_DISABLED' 'منع الاستخدام عند تعطيل الائتمان'
admin_csrf="$(csrf_for "$admin_cookie")"
request "$admin_cookie" "bookings/${credit_booking_id}/reject" -X PUT -H 'Content-Type: application/json' -H "X-CSRF-Token: ${admin_csrf}" -d '{"reason":"تنظيف اختبار الائتمان."}' > /dev/null
admin_csrf="$(csrf_for "$admin_cookie")"
request "$admin_cookie" 'admin/agents/1/financial-settings' -X PUT -H 'Content-Type: application/json' -H "X-CSRF-Token: ${admin_csrf}" -d '{"currency_id":1,"credit_limit":20000,"minimum_balance":5000,"credit_enabled":true,"block_at_minimum_balance":true,"status":"active"}' > /dev/null

printf '\nاكتملت اختبارات API الأساسية بنجاح.\n'
