import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';

const chromePort = 9229;
const chromeProfile = '/tmp/bus-booking-mobile-admin-profile';
const screenshotDir = '/home/ubuntu/bus-booking-platform/infinityfree/testing/screenshots';
const targetUrl = process.env.TARGET_URL || 'https://8088-i8r12pwgwxmp6xsn2w8si-a19b658c.us4.manus.computer/admin.php';
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitForDebugEndpoint() {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try { const response = await fetch(`http://127.0.0.1:${chromePort}/json/version`); if (response.ok) return response.json(); } catch {}
    await delay(150);
  }
  throw new Error('تعذر بدء متصفح اختبار لوحة الإدارة.');
}

class CdpClient {
  constructor(url) {
    this.socket = new WebSocket(url); this.nextId = 1; this.pending = new Map();
    this.open = new Promise((resolve, reject) => { this.socket.addEventListener('open', resolve, { once: true }); this.socket.addEventListener('error', reject, { once: true }); });
    this.socket.addEventListener('message', (event) => { const message = JSON.parse(event.data); const pending = this.pending.get(message.id); if (!pending) return; this.pending.delete(message.id); if (message.error) pending.reject(new Error(message.error.message)); else pending.resolve(message.result); });
  }
  async command(method, params = {}) { await this.open; const id = this.nextId++; const response = new Promise((resolve, reject) => this.pending.set(id, { resolve, reject })); this.socket.send(JSON.stringify({ id, method, params })); return response; }
  close() { this.socket.close(); }
}

const chrome = spawn('chromium', ['--headless=new', '--no-sandbox', '--disable-gpu', '--ignore-certificate-errors', `--remote-debugging-port=${chromePort}`, `--user-data-dir=${chromeProfile}`, 'about:blank'], { stdio: 'ignore' });

try {
  await waitForDebugEndpoint();
  const targetResponse = await fetch(`http://127.0.0.1:${chromePort}/json/new?${encodeURIComponent(targetUrl)}`, { method: 'PUT' });
  const target = await targetResponse.json();
  const client = new CdpClient(target.webSocketDebuggerUrl);
  await client.command('Page.enable');
  await client.command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
  await client.command('Page.navigate', { url: targetUrl });
  await delay(1600);
  await client.command('Runtime.evaluate', { expression: `(() => { document.querySelector('#gate-login')?.click(); })()` });
  await delay(1000);
  const login = await client.command('Runtime.evaluate', { expression: `fetch('/api/v1/index.php?route=auth%2Flogin',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({email:'محمد',password:'738155861'})}).then(async(response)=>({status:response.status,payload:await response.json()}))`, awaitPromise: true, returnByValue: true });
  assert.equal(login.result.value.status, 200);
  assert.equal(login.result.value.payload.success, true);
  await client.command('Runtime.evaluate', { expression: 'location.reload()' });
  for (let attempt = 0; attempt < 12; attempt += 1) {
    await delay(500);
    const ready = await client.command('Runtime.evaluate', { expression: `(() => document.querySelectorAll('.stat-card').length >= 4)()`, returnByValue: true });
    if (ready.result.value) break;
  }
  const state = await client.command('Runtime.evaluate', { expression: `(() => { const shell = document.querySelector('#template-dashboard'); const sidebar = document.querySelector('#sidebar'); const grid = document.querySelector('.stats-grid'); const topbar = document.querySelector('.template-topbar'); return { shell: Boolean(shell), sidebar: Boolean(sidebar), topbar: Boolean(topbar), title: document.querySelector('#template-page-title')?.textContent?.trim(), stats: document.querySelectorAll('.stat-card').length, columns: grid ? getComputedStyle(grid).gridTemplateColumns.trim().split(/\\s+/).length : 0, viewport: document.documentElement.clientWidth, documentWidth: document.documentElement.scrollWidth, topbarWidth: Math.ceil(topbar?.getBoundingClientRect().width || 0), diagnostic: document.body.innerText.slice(0, 300) }; })()`, returnByValue: true });
  const value = state.result.value;
  if (!value.shell) console.log(`تشخيص فشل اختبار الهاتف: ${JSON.stringify(value)}`);
  assert.equal(value.shell, true);
  assert.equal(value.sidebar, true);
  assert.equal(value.topbar, true);
  assert.equal(value.title, 'لوحة التحكم');
  assert.ok(value.stats >= 4, `عدد بطاقات الإحصاءات أقل من الحد المطلوب: ${value.stats}`);
  assert.equal(value.columns, 1);
  assert.ok(value.documentWidth <= value.viewport + 1, `يوجد تجاوز أفقي: ${value.documentWidth}/${value.viewport}`);
  assert.ok(value.topbarWidth <= value.viewport + 1, `الترويسة أعرض من الهاتف: ${value.topbarWidth}/${value.viewport}`);
  await client.command('Runtime.evaluate', { expression: `(() => document.querySelector('#menu-toggle')?.click())()` });
  await delay(300);
  const menuState = await client.command('Runtime.evaluate', { expression: `(() => { const sidebar = document.querySelector('#sidebar'); const overlay = document.querySelector('#template-overlay'); const rect = sidebar?.getBoundingClientRect(); return { open: sidebar?.classList.contains('open'), overlay: overlay?.classList.contains('open'), width: Math.ceil(rect?.width || 0), right: Math.ceil(rect?.right || 0), viewport: document.documentElement.clientWidth }; })()`, returnByValue: true });
  const menu = menuState.result.value;
  assert.equal(menu.open, true);
  assert.equal(menu.overlay, true);
  assert.ok(menu.width <= 304, `القائمة أعرض من الحد المحدد: ${menu.width}`);
  assert.ok(Math.abs(menu.right - menu.viewport) <= 2, `القائمة لا تحاذي حافة الهاتف: ${menu.right}/${menu.viewport}`);
  await mkdir(screenshotDir, { recursive: true });
  const menuShot = await client.command('Page.captureScreenshot', { format: 'png', fromSurface: true });
  await writeFile(`${screenshotDir}/admin-template-mobile-menu.png`, Buffer.from(menuShot.data, 'base64'));
  await client.command('Runtime.evaluate', { expression: `(() => document.querySelector('#menu-toggle')?.click())()` });
  await delay(300);
  const closed = await client.command('Runtime.evaluate', { expression: `(() => ({ open: document.querySelector('#sidebar')?.classList.contains('open'), overlay: document.querySelector('#template-overlay')?.classList.contains('open') }))()`, returnByValue: true });
  assert.equal(closed.result.value.open, false);
  assert.equal(closed.result.value.overlay, false);
  if (process.env.OPERATION_PAGES === '1') {
    const pages = [
      ['companies', 'إضافة شركة نقل'],
      ['routes', 'إضافة مسار رئيسي'],
      ['route_stops', 'إضافة محطة لمسار'],
      ['buses', 'إضافة باص'],
      ['trips', 'إضافة رحلة'],
    ];
    for (const [page, expected] of pages) {
      await client.command('Runtime.evaluate', { expression: `(() => document.querySelector('[data-dash="${page}"]')?.click())()` });
      for (let attempt = 0; attempt < 12; attempt += 1) {
        await delay(350);
        const ready = await client.command('Runtime.evaluate', { expression: `(() => document.querySelector('#dash-page')?.innerText.includes('${expected}'))()`, returnByValue: true });
        if (ready.result.value) break;
      }
      const pageState = await client.command('Runtime.evaluate', { expression: `(() => ({ title: document.querySelector('#template-page-title')?.textContent?.trim(), hasExpected: document.querySelector('#dash-page')?.innerText.includes('${expected}'), width: document.documentElement.scrollWidth, viewport: document.documentElement.clientWidth }))()`, returnByValue: true });
      assert.equal(pageState.result.value.hasExpected, true, `لم يظهر نموذج قسم ${page}`);
      assert.ok(pageState.result.value.width <= pageState.result.value.viewport + 1, `تجاوز أفقي في قسم ${page}`);
    }
  }
  const contentShot = await client.command('Page.captureScreenshot', { format: 'png', fromSurface: true });
  await writeFile(`${screenshotDir}/admin-template-mobile.png`, Buffer.from(contentShot.data, 'base64'));
  client.close();
  console.log('اختبار لوحة الإدارة المرجعية على هاتف 390×844: ناجح');
} finally { chrome.kill('SIGTERM'); }
