import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

const schema = read('database/schema.sql');
const migration = read('database/route_management_migration.sql');
const scheduleMigration = read('database/subroute_schedule_migration.sql');
const service = read('includes/AdminService.php');
const api = read('api/v1/index.php');
const app = read('assets/js/app.js');
const subroutesUi = read('assets/js/admin-subroutes.js');

for (const source of [schema, migration]) {
  assert.match(source, /CREATE TABLE IF NOT EXISTS route_subroutes/);
  assert.match(source, /CREATE TABLE IF NOT EXISTS route_subroute_links/);
}

for (const method of ['createCity', 'updateCityStatus', 'createSubroute', 'orderSubroutes']) {
  assert.match(service, new RegExp(`function ${method}`));
}
assert.match(service, /INSERT INTO route_subroute_links/);
assert.match(service, /INSERT INTO segment_prices/);
assert.match(service, /المسارات الفرعية المختارة العملة نفسها/);
assert.match(service, /combinedAmount \+=/);
assert.match(service, /for \(\$destinationIndex = \$originIndex \+ 1/);
assert.match(service, /المسارات الفرعية المختارة غير متصلة/);
for (const field of ['origin_arrival_time', 'origin_departure_time', 'destination_arrival_time', 'destination_departure_time']) {
  assert.match(schema, new RegExp(field));
  assert.match(scheduleMigration, new RegExp(field));
  assert.match(service, new RegExp(field));
}
assert.match(schema, /company_id BIGINT UNSIGNED NULL/);
assert.match(service, /sr\.company_id IS NULL OR sr\.company_id = :company_id/);
assert.match(app, /مقطع مشترك/);
assert.match(subroutesUi, /timeField\('destination_arrival_time', 'وقت الحضور'\)/);
assert.match(subroutesUi, /timeField\('origin_departure_time', 'وقت المغادرة'\)/);
assert.match(app, /function timeField\(name,label,value=''\)/);
assert.match(app, /name="origin_departure_time"/);
assert.match(app, /name="destination_arrival_time"/);
assert.doesNotMatch(subroutesUi, /time12Field\('destination_arrival'/);
assert.doesNotMatch(subroutesUi, /time12Field\('origin_departure'/);
assert.doesNotMatch(subroutesUi, /optionalCompanyField/);
assert.match(api, /admin\/cities/);
assert.match(api, /admin\/subroutes/);
assert.match(app, /city-page-create/);
assert.match(app, /subroute-page-create/);
assert.match(app, /name="subroute_ids" multiple/);
assert.match(app, /تم إنشاء المسار الرئيسي وربط مقاطعه/);

console.log('اختبار إدارة المدن والمسارات الفرعية: ناجح');
