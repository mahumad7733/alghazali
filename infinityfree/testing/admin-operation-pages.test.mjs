import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve(new URL('.', import.meta.url).pathname, '..');
const script = await readFile(resolve(root, 'assets/js/app.js'), 'utf8');
const service = await readFile(resolve(root, 'includes/AdminService.php'), 'utf8');

for (const page of ['companies', 'routes', 'route_stops', 'buses', 'trips']) {
  assert.match(script, new RegExp(`dashboard${page.split('_').map((part) => part[0].toUpperCase() + part.slice(1)).join('')}`));
  assert.ok(script.includes(`['${page}'`), `عنصر قائمة ${page} غير موجود`);
}
assert.match(script, /المسارات الرئيسية/);
assert.match(script, /المسارات الفرعية/);
assert.match(script, /إضافة شركة نقل/);
assert.match(script, /إضافة محطة لمسار/);
assert.match(script, /إضافة رحلة/);
assert.match(service, /'route_stops'/);
assert.match(service, /'route_segments'/);
assert.match(service, /'stations'/);

console.log('اختبار صفحات التشغيل المنفصلة: ناجح');
