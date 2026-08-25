import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';

const port = 9230;
const targetUrl = 'http://127.0.0.1:8088/testing/local-admin-fixture.html?page=home&v=dashboard-mobile';
const screenshotDir = '/home/ubuntu/bus-booking-system/infinityfree/testing/screenshots';
const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms));

async function waitEndpoint() {
  for (let i = 0; i < 40; i += 1) {
    try { const r = await fetch(`http://127.0.0.1:${port}/json/version`); if (r.ok) return r.json(); } catch {}
    await wait(150);
  }
  throw new Error('تعذر بدء Chromium للاختبار المحلي.');
}
class Cdp {
  constructor(url) { this.ws = new WebSocket(url); this.id = 0; this.pending = new Map(); this.open = new Promise((resolve, reject) => { this.ws.addEventListener('open', resolve, { once: true }); this.ws.addEventListener('error', reject, { once: true }); }); this.ws.addEventListener('message', event => { const m = JSON.parse(event.data); if (m.id && this.pending.has(m.id)) { const p = this.pending.get(m.id); this.pending.delete(m.id); m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result); } }); }
  async call(method, params = {}) { await this.open; const id = ++this.id; const result = new Promise((resolve, reject) => this.pending.set(id, { resolve, reject })); this.ws.send(JSON.stringify({ id, method, params })); return result; }
  close() { this.ws.close(); }
}
const chrome = spawn('chromium', ['--headless=new', '--no-sandbox', '--disable-gpu', '--ignore-certificate-errors', `--remote-debugging-port=${port}`, `--user-data-dir=/tmp/rihla-dashboard-mobile-profile`], { stdio: 'ignore' });
try {
  const version = await waitEndpoint();
  const tab = await (await fetch(`http://127.0.0.1:${port}/json/new?${encodeURIComponent(targetUrl)}`, { method: 'PUT' })).json();
  const client = new Cdp(tab.webSocketDebuggerUrl);
  await client.call('Page.enable');
  await client.call('Runtime.enable');
  await client.call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
  await client.call('Page.navigate', { url: targetUrl });
  await wait(1800);
  const getState = async () => {
    const evaluated = await client.call('Runtime.evaluate', { returnByValue: true, expression: `(() => { const cards=[...document.querySelectorAll('.dashboard-smart-card')]; const grid=document.querySelector('.dashboard-smart-grid'); const rects=cards.map(x=>Math.round(x.getBoundingClientRect().height)); return {width:document.documentElement.clientWidth,docWidth:document.documentElement.scrollWidth,cards:cards.length,cardColumns:grid?getComputedStyle(grid).gridTemplateColumns.trim().split(/\\s+/).length:0,heights:rects,charts:document.querySelectorAll('.dashboard-chart-wrap canvas').length,filters:document.querySelectorAll('.dashboard-filters [name="period"] option').length,bodyText:document.body.innerText.slice(0,500),theme:document.querySelector('.template-dashboard')?.dataset.theme||'light'}; })()` });
    return evaluated.result.value;
  };
  const state = await getState();
  assert.equal(state.width, 390);
  assert.ok(state.docWidth <= state.width + 1, `تجاوز أفقي: ${state.docWidth}/${state.width}`);
  assert.equal(state.cards, 8, `عدد البطاقات: ${state.cards}`);
  assert.equal(state.cardColumns, 1, `عدد أعمدة البطاقات: ${state.cardColumns}`);
  assert.ok(new Set(state.heights).size <= 2, `تفاوت غير متوقع في ارتفاع البطاقات: ${state.heights.join(',')}`);
  assert.equal(state.charts, 6, `عدد الرسوم: ${state.charts}`);
  assert.equal(state.filters, 8, `خيارات الفترة: ${state.filters}`);
  assert.ok(!state.bodyText.includes('تعذر تحميل بيانات لوحة التحكم'), 'ظهر خطأ تحميل Dashboard');
  await client.call('Runtime.evaluate', { expression: `document.querySelector('#template-theme-toggle')?.click()` });
  await wait(100);
  const dark = await getState();
  assert.equal(dark.theme, 'dark');
  assert.ok(dark.docWidth <= dark.width + 1, `تجاوز أفقي في الوضع الليلي: ${dark.docWidth}/${dark.width}`);
  await mkdir(screenshotDir, { recursive: true });
  const shot = await client.call('Page.captureScreenshot', { format: 'png', fromSurface: true });
  await writeFile(`${screenshotDir}/dashboard-erp-mobile.png`, Buffer.from(shot.data, 'base64'));
  console.log(JSON.stringify({ ...state, darkTheme: dark.theme, darkDocWidth: dark.docWidth, chromium: version.Browser }, null, 2));
  client.close();
} finally { chrome.kill('SIGTERM'); }
