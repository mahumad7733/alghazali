import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve(new URL('.', import.meta.url).pathname, '..');
const script = await readFile(resolve(root, 'assets/js/app.js'), 'utf8');

assert.match(script, /ensureDashboardTemplateStyle/);
assert.match(script, /template-sidebar/);
assert.match(script, /template-topbar/);
assert.match(script, /template-theme-toggle/);
assert.match(script, /dashboard-quick-search/);
assert.match(script, /dashboardPageMeta/);
assert.match(script, /إدارة التشغيل/);
assert.match(script, /إدارة المتقدمة/);

console.log('اختبار هيكل لوحة الإدارة المرجعية: ناجح');
