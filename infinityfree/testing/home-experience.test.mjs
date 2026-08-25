import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const appScript = readFileSync(new URL('../assets/js/app.js', import.meta.url), 'utf8');
const stylesheet = readFileSync(new URL('../assets/css/app.css', import.meta.url), 'utf8');

assert.match(appScript, /function renderSearchLoading\(\)/);
assert.match(appScript, /function renderSearchError\(query, message\)/);
assert.match(appScript, /loadingCards\(count = 2\)/);
assert.match(appScript, /trip-card-accent/);
assert.match(appScript, /trip-journey/);
assert.match(appScript, /trip-seat-pill/);
assert.match(appScript, /data-action="retry-search"/);
assert.match(appScript, /function openAccountDrawer\(\)/);
assert.match(appScript, /function enableDrawerSwipe\(layer\)/);
assert.match(appScript, /touchstart/);
assert.match(appScript, /shouldClose = offsetX >= Math\.min\(96/);
assert.match(appScript, /data-action="open-account-drawer"/);
assert.match(appScript, /data-drawer-action="login"/);
assert.match(appScript, /data-drawer-action="register"/);
assert.match(stylesheet, /\.loading-card/);
assert.match(stylesheet, /\.trip-card-accent/);
assert.match(stylesheet, /\.search-loading/);
assert.match(stylesheet, /\.trip-seat-pill/);
assert.match(stylesheet, /\.account-drawer/);
assert.match(stylesheet, /\.account-menu-trigger/);
assert.match(stylesheet, /touch-action:pan-y/);

console.log('home-experience.test.mjs: all assertions passed');
