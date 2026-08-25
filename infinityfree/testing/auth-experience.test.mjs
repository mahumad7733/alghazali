import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const root = resolve(new URL('.', import.meta.url).pathname, '..');
const [script, css, customer] = await Promise.all([
  readFile(resolve(root, 'assets/js/app.js'), 'utf8'),
  readFile(resolve(root, 'assets/css/app.css'), 'utf8'),
  readFile(resolve(root, 'customer.php'), 'utf8'),
]);

assert.match(script, /auth-shell/);
assert.match(script, /ensureMinimalAuthStyle/);
assert.match(script, /auth-visual/);
assert.match(script, /data-action="toggle-password"/);
assert.match(script, /البريد الإلكتروني أو اسم المستخدم/);
assert.match(script, /autocomplete="\$\{isLogin \? 'current-password' : 'new-password'\}"/);
assert.match(css, /\.modal\.auth-modal/);
assert.match(css, /\.auth-registration-grid/);
assert.match(css, /@media \(max-width:700px\)/);
assert.match(customer, /assets\/css\/app\.css\?v=/);
assert.match(customer, /assets\/js\/app\.js\?v=/);
assert.match(css, /بديل هادئ/);

console.log('اختبار بنية تجربة المصادقة: ناجح');
