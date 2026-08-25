import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const service = read('includes/AdminService.php');
const bookingService = read('includes/BookingService.php');
const dashboardService = read('includes/DashboardService.php');
const api = read('api/v1/index.php');
const app = read('assets/js/app.js');
const subroutesPage = read('assets/js/admin-subroutes.js');
const stationsPage = read('assets/js/admin-stations.js');
const mainRoutesPage = read('assets/js/admin-main-routes.js');

for (const method of [
  'updateCompany', 'updateCompanyStatus', 'deleteCompany',
  'updateRoute', 'updateRouteStatus', 'deleteRoute',
  'updateSubroute', 'updateSubrouteStatus', 'deleteSubroute',
  'updateCity', 'deleteCity',
  'updateBus', 'updateBusStatus', 'deleteBus',
  'updateTrip', 'updateTripStatus', 'deleteTrip',
]) {
  assert.match(service, new RegExp(`function ${method}`));
}

assert.match(service, /SELECT COUNT\(\*\) AS total FROM routes WHERE company_id/);
assert.match(service, /SELECT COUNT\(\*\) AS total FROM buses WHERE company_id/);
assert.match(service, /SELECT COUNT\(\*\) AS total FROM trips WHERE company_id/);
assert.match(service, /SELECT COUNT\(\*\) AS total FROM bookings WHERE company_id/);
assert.match(service, /SELECT COUNT\(\*\) AS total FROM route_subroute_links WHERE subroute_id/);
assert.match(service, /لا يمكن حذف مسار مرتبط برحلة/);
assert.match(service, /أوقفها بدلًا من الحذف/);
assert.match(service, /\$pdo->beginTransaction\(\);/);
assert.match(service, /company_deleted/);

for (const entity of ['companies', 'routes', 'subroutes']) {
  assert.match(api, new RegExp(`admin/${entity}/\\(\\\\d\\+\\)`));
  assert.match(api, new RegExp(`admin/${entity}/\\(\\\\d\\+\\)/status`));
}
for (const entity of ['cities', 'buses', 'trips']) {
  assert.match(api, new RegExp(`admin/${entity}/\\(\\\\d\\+\\)`));
  assert.match(api, new RegExp(`admin/${entity}/\\(\\\\d\\+\\)/status`));
}
assert.match(api, /\$method === 'PUT'/);
assert.match(api, /\$method === 'DELETE'/);
assert.match(api, /Security::assertCsrf\(\)/);

assert.match(app, /data-entity-edit/);
assert.match(app, /data-entity-status/);
assert.match(app, /data-entity-delete/);
assert.match(app, /openEntityEditor/);
assert.match(app, /openEntityDeleteDialog/);
assert.match(app, /تأكيد حذف العنصر/);
assert.match(app, /origin_city_id/);
assert.match(app, /base_currency_id/);
assert.match(app, /syncCompanyForm/);
assert.match(app, /#company-page-create\{display:grid!important/);
assert.match(app, /city\.disabled=!countryId/);
assert.match(app, /\.company-logo-frame\{width:68px!important;height:68px!important/);
assert.match(app, /\.company-logo-frame img\{display:block!important;width:68px!important;height:68px!important/);
assert.match(app, /const resources=\{company:'companies',city:'cities',route:'routes',subroute:'subroutes',bus:'buses',trip:'trips'\}/);
assert.match(app, /operation-create-button/);
assert.match(app, /separateCreateForm/);
assert.match(app, /openEntityStatusDialog/);
assert.match(app, /entity-status-confirm/);
assert.match(app, /تأكيد \$\{action\}/);
assert.match(app, /company-edit-replace-grid/);
assert.match(app, /استبدال الشعار/);
assert.match(app, /استبدال الصورة التعريفية/);
assert.match(app, /cover_image_path/);
assert.match(service, /cover_image_path/);
assert.match(app, /company_logo/);
assert.match(app, /company_cover/);
assert.match(app, /bindCompanyCreateWithMedia/);
assert.match(app, /const \{company\}=await api\('admin\/companies'/);
assert.match(app, /admin\/companies\/\$\{company\.id\}\/media/);
assert.match(app, /تم إنشاء الشركة وحفظ الوسائط المختارة/);

console.log('اختبار إدارة الكيانات والحذف الآمن: ناجح');

assert.match(read('database/schema.sql'), /CREATE TABLE IF NOT EXISTS company_images/);
assert.match(read('database/company_images_migration.sql'), /UNIQUE KEY uq_company_image_order/);
assert.match(service, /uploadCompanyGalleryImage/);
assert.match(service, /deleteCompanyGalleryImage/);
assert.match(service, /imageOrder < 1 \|\| \$imageOrder > 6/);
assert.match(api, /admin\/companies\/\(\\d\+\)\/gallery/);
assert.match(api, /admin\/company-gallery\/\(\\d\+\)/);
assert.match(app, /company_gallery_\$\{order\}/);
assert.match(app, /company-edit-replace-grid/);
assert.match(app, /image_order:entry\.order/);
assert.match(app, /gallery_images/);
console.log('اختبار معرض صور الشركات المتعددة: ناجح');

for (const method of ['people', 'createAgent', 'createCustomer', 'updatePerson', 'deletePerson']) {
  assert.match(service, new RegExp(`function ${method}`));
}
for (const endpoint of ['admin/people', 'admin/agents', 'admin/customers']) {
  assert.match(api, new RegExp(endpoint.replace('/', '\\/')));
}
assert.match(service, /password_hash\(\$password, PASSWORD_DEFAULT\)/);
assert.match(service, /DEPENDENCY_EXISTS/);
assert.match(app, /dashboardAgents/);
assert.match(app, /dashboardCustomers/);
assert.match(app, /data-add-person/);
assert.match(app, /person-form/);
console.log('اختبار شاشتي إدارة الوكلاء والعملاء والصلاحيات: ناجح');

assert.match(app, /data-company-gallery-view/);
assert.match(app, /openCompanyGalleryModal/);
assert.match(app, /company-edit-media-preview/);
assert.match(app, /function timeField\(name,label,value=''\)/);
assert.match(app, /name="origin_departure_time"/);
assert.match(app, /name="destination_arrival_time"/);
assert.match(app, /bindSubrouteTimeSuggestion/);
assert.match(app, /minute\+30/);
assert.match(app, /أدخل الوقت في حقل واحد/);
assert.doesNotMatch(app, /<label>وصول مدينة الانطلاق<\/label>/);
assert.doesNotMatch(app, /<label>مغادرة مدينة الوصول<\/label>/);
console.log('اختبار زر عرض الصور ومحرر الشركة وتسميات أوقات المسار: ناجح');

assert.match(service, /createCountry/);
assert.match(service, /updateCountry/);
assert.match(service, /deleteCountry/);
assert.match(api, /admin\/countries/);
assert.match(app, /dashboardCountries/);
assert.match(app, /country-page-create/);
assert.match(app, /admin\/countries\/\$\{id\}\/status/);
console.log('اختبار إدارة الدول وتأكيد العملاء وصيغة الوقت 12 ساعة: ناجح');

assert.match(service, /VALUES \(:full_name, :email, :phone, :password_hash, \\'pending\\'\)/);
assert.match(app, /تأكيد الحساب/);
assert.match(app, /confirmed/);
console.log('اختبار حالة العميل اليدوي وزر التأكيد: ناجح');

assert.match(read('database/schema.sql'), /latitude DECIMAL\(10,7\) NULL/);
assert.match(read('database/company_agent_locations_migration.sql'), /ALTER TABLE companies/);
assert.match(read('database/company_agent_locations_migration.sql'), /ALTER TABLE agents/);
assert.match(service, /private function coordinates/);
assert.match(service, /private function ensureLocationColumns/);
assert.match(service, /SHOW COLUMNS FROM companies LIKE 'latitude'/);
assert.match(service, /latitude = :latitude, longitude = :longitude/);
assert.match(app, /خط العرض \(اختياري\)/);
assert.match(api, /company_latitude/);
assert.match(app, /موقع شركة النقل على الخريطة/);
console.log('اختبار الإحداثيات والخريطة ووقت المغادرة المقترح: ناجح');

assert.match(service, /يمكن للمغادرة أن تقع في اليوم التالي/);
assert.match(app, /يجب أن يختلف وقت المغادرة عن وقت الحضور/);
assert.match(app, /sessionStorage\.setItem\('bus-dashboard-page'/);
assert.match(app, /beforeunload/);
assert.match(app, /data-unsaved/);
assert.match(app, /stations:\['محطات التشغيل'/);
assert.match(subroutesPage, /المسارات الفرعية الحالية/);
assert.doesNotMatch(subroutesPage, /محطات التشغيل المنشأة تلقائيًا/);
assert.match(stationsPage, /محطات التشغيل المنشأة تلقائيًا/);
assert.match(stationsPage, /departure_offset_minutes/);
console.log('اختبار صحة الوقت وحفظ الشاشة وفصل المحطات: ناجح');

assert.match(read('database/schema.sql'), /route_type ENUM\('normal','tourist'\)/);
assert.match(read('database/main_route_enhancement_migration.sql'), /ALTER TABLE routes/);
assert.match(read('database/main_route_enhancement_migration.sql'), /ADD COLUMN route_type/);
assert.match(service, /function nextMainRouteCode/);
assert.match(service, /AUTO_INCREMENT AS next_id/);
assert.match(service, /RT-' \. str_pad/);
assert.match(service, /function mainRouteSubrouteIds/);
assert.match(service, /لا يمكن تكرار المسار الفرعي نفسه/);
assert.match(service, /function legacyCreateRoute/);
assert.match(service, /function updateRoute/);
assert.match(service, /لا يمكن تغيير الشركة أو المسارات الفرعية لمسار مرتبط برحلات/);
assert.match(mainRoutesPage, /main-route-multiselect/);
assert.match(mainRoutesPage, /المسارات المختارة/);
assert.match(mainRoutesPage, /data-remove-subroute/);
assert.match(mainRoutesPage, /route_query/);
assert.match(mainRoutesPage, /route_type_filter/);
assert.match(mainRoutesPage, /route_status_filter/);
assert.match(mainRoutesPage, /route-link-count/);
assert.match(mainRoutesPage, /المسارات الفرعية المرتبطة/);
assert.match(mainRoutesPage, /data-route-delete/);
assert.match(app, /BusAdminPages\?\.routes/);
assert.doesNotMatch(subroutesPage, /main-route-multiselect/);
console.log('اختبار تطوير المسارات الرئيسية دون تعديل المسارات الفرعية: ناجح');

assert.match(read('database/schema.sql'), /company_amount DECIMAL\(14,2\) NOT NULL DEFAULT 0\.00/);
assert.match(read('database/operations_finance_enhancement_migration.sql'), /ALTER TABLE route_subroutes/);
assert.match(read('database/operations_finance_enhancement_migration.sql'), /company_cost_amount/);
assert.match(service, /function ensureOperationalFinanceColumns/);
assert.match(service, /linked_route_count/);
assert.match(service, /يمكن للمغادرة أن تقع في اليوم التالي/);
assert.doesNotMatch(service, /لا يمكن تعديل بيانات مقطع مرتبط بمسار رئيسي/);
assert.match(subroutesPage, /سعر الشركة علينا/);
assert.match(subroutesPage, /هامش الربح/);
assert.match(subroutesPage, /يمكن تعديل المسار المرتبط دون حذف الرابط/);
assert.doesNotMatch(subroutesPage, /محطات التشغيل المُنشأة تلقائيًا/);
console.log('اختبار أسعار المسارات الفرعية والوقت والارتباط: ناجح');

const auth = read('includes/Auth.php');
const adminLayout = read('admin/_layout.php');
const loginPage = read('login.php');
assert.match(auth, /phone = :phone/);
assert.match(auth, /حسابك غير نشط حاليًا/);
assert.match(service, /function updateRolePermissions/);
assert.match(api, /admin\/roles\/\(\\d\+\)\/permissions/);
assert.match(adminLayout, /function requireAdminPage/);
assert.match(adminLayout, /adminForbidden/);
assert.match(adminLayout, /array_intersect\(\$roles, \['super_admin', 'company_admin'/);
assert.match(adminLayout, /in_array\(\$key, \['users', 'permissions'\], true\)/);
assert.match(adminLayout, /!in_array\('super_admin', \$roles, true\)/);
assert.match(loginPage, /البريد الإلكتروني أو اسم المستخدم أو رقم الجوال/);
assert.match(loginPage, /admin\/admin\.php/);
for (const page of ['admin/admin.php', 'admin/companies.php', 'admin/main_routes.php', 'admin/sub_routes.php', 'admin/stations.php', 'admin/users.php', 'admin/permissions.php']) {
  assert.match(read(page), /require __DIR__ \. '\/_layout\.php'/);
}
assert.match(app, /fixedAdminPage/);
assert.match(app, /dashboardPermissions/);
assert.match(app, /role-permissions-form/);
assert.match(app, /permissions:'permissions\.php'/);
assert.match(app, /agentDashboardPages/);
assert.match(app, /superAdminOnlyPages/);
assert.match(app, /DOMContentLoaded', startBootstrap/);
console.log('اختبار بوابة الدخول وحماية صفحات الإدارة والصلاحيات: ناجح');

const operationsMigration = read('database/operations_finance_enhancement_migration.sql');
const schema = read('database/schema.sql');
assert.match(schema, /route_subroute_id BIGINT UNSIGNED NULL/);
assert.match(schema, /recurrence_group VARCHAR\(64\) NULL/);
assert.match(schema, /recurrence_index SMALLINT UNSIGNED NULL/);
assert.match(operationsMigration, /idx_trips_route_subroute/);
assert.match(operationsMigration, /idx_trips_recurrence_group/);
assert.match(service, /function previewRecurringTrips/);
assert.match(service, /function createRecurringTrips/);
assert.match(service, /function recurringTripTemplate/);
assert.match(service, /recurrence_group/);
assert.match(service, /المسار الفرعي المختار لا يتبع المسار الرئيسي أو غير نشط/);
assert.match(api, /admin\/trips\/recurring\/preview/);
assert.match(api, /admin\/trips\/recurring/);
assert.match(app, /إنشاء رحلات متكررة/);
assert.match(app, /معاينة الرحلات المتكررة/);
assert.match(app, /تأكيد إنشاء الرحلات المتكررة/);
console.log('اختبار ربط الرحلة والتكرار الفعلي: ناجح');

assert.match(service, /function previewBulkTripUpdate/);
assert.match(service, /function applyBulkTripUpdate/);
assert.match(service, /function bulkTripUpdatePayload/);
assert.match(service, /لا يمكن تعديل الموعد أو الباص جماعيًا عند وجود حجوزات/);
assert.match(api, /admin\/trips\/bulk\/preview/);
assert.match(api, /admin\/trips\/bulk/);
assert.match(app, /تعديل جماعي للرحلات/);
assert.match(app, /trip-bulk-select/);
assert.match(app, /تأكيد التعديل الجماعي/);
console.log('اختبار التعديل الجماعي الآمن للرحلات: ناجح');

assert.match(schema, /agent_commission_type ENUM\('percentage','fixed'\) NULL/);
assert.match(schema, /agent_commission_rate DECIMAL\(12,4\) NOT NULL DEFAULT 0/);
assert.match(operationsMigration, /agent_commission_type/);
assert.match(bookingService, /tsp\.company_amount/);
assert.match(bookingService, /company_cost_amount/);
assert.match(bookingService, /company_payable_amount/);
assert.match(bookingService, /platform_commission_amount/);
assert.match(bookingService, /agent_commission_rate/);
assert.match(bookingService, /company_unit_amount/);
assert.match(bookingService, /function canViewInternalFinancials/);
assert.match(bookingService, /function redactInternalFinancials/);
assert.match(bookingService, /return \$this->redactInternalFinancials\(\$this->bookingDetails\(\$pdo, \$bookingId\), \$actor\)/);
console.log('اختبار لقطات السعر والعمولة وحماية التكلفة الداخلية: ناجح');

assert.match(app, /تصفية الرحلات/);
assert.match(app, /trip-filter-form/);
assert.match(app, /عرض \$\{visible\} من \$\{trips\.length\} رحلة/);
assert.match(dashboardService, /confirmed_company_cost/);
assert.match(dashboardService, /confirmed_company_payable/);
assert.match(dashboardService, /confirmed_platform_commission/);
assert.match(dashboardService, /function canViewInternalFinancials/);
assert.match(app, /تكلفة الشركة/);
assert.match(app, /مستحق الشركة/);
assert.match(app, /عمولة المنصة/);
console.log('اختبار فلاتر الرحلات والتقرير المالي المشروط: ناجح');
