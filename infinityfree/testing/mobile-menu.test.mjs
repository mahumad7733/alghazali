import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(new URL('.', import.meta.url).pathname, '..');
const app = readFileSync(resolve(root, 'assets/js/app.js'), 'utf8');
const template = readFileSync(resolve(root, 'assets/js/public-template.js'), 'utf8');
const navigation = app.slice(app.indexOf('function installPublicTemplateNavigation'), app.indexOf('function customerHeader'));

assert.match(template, /const toggle = ctx\.\$\('#public-nav-toggle'\)/);
assert.match(template, /drawer\?\.classList\.toggle\('open', open\)/);
assert.match(template, /toggle\?\.setAttribute\('aria-expanded', String\(open\)\)/);
assert.match(template, /public-mobile-drawer/);
assert.match(template, /public-mobile-user-icon/);
assert.match(template, /public-mobile-account-button/);
assert.match(template, /data-action="open-account-drawer"/);
assert.match(template, /publicMobileEscapeBound/);
assert.match(template, /event\.stopPropagation\(\)/);
assert.match(template, /data-public-page="about"/);
assert.match(template, /data-public-page="contact"/);
assert.match(template, /data-public-page="privacy"/);
assert.doesNotMatch(navigation, /public-nav-toggle/);
assert.doesNotMatch(navigation, /classList\.toggle\('open'\)/);

console.log('mobile-menu.test.mjs: menu listener is single and non-duplicated');
