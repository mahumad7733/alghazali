import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const port = 9334;
const outputDir = '/tmp/bus-booking-mobile-account-drawer';
mkdirSync(outputDir, { recursive: true });

const browser = spawn('/usr/bin/chromium', [
  '--headless=new', '--no-sandbox', '--disable-gpu', `--remote-debugging-port=${port}`,
  '--user-data-dir=/tmp/bus-booking-mobile-account-profile', 'about:blank',
], { stdio: 'ignore' });

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function waitFor(check, label, timeout = 20000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await check()) return;
    await delay(250);
  }
  throw new Error(`Timed out waiting for ${label}`);
}

try {
  await waitFor(async () => {
    try { return (await fetch(`http://127.0.0.1:${port}/json/list`)).ok; } catch { return false; }
  }, 'Chrome DevTools');
  const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
  const page = targets.find((target) => target.type === 'page');
  if (!page?.webSocketDebuggerUrl) throw new Error('No Chrome page target available');

  const socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  let id = 0;
  const pending = new Map();
  socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    const handler = pending.get(message.id);
    if (!handler) return;
    pending.delete(message.id);
    message.error ? handler.reject(new Error(message.error.message)) : handler.resolve(message.result);
  });
  const cdp = (method, params = {}) => new Promise((resolve, reject) => {
    const messageId = ++id;
    pending.set(messageId, { resolve, reject });
    socket.send(JSON.stringify({ id: messageId, method, params }));
  });
  const evaluate = async (expression) => (await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result.value;

  await cdp('Page.enable');
  await cdp('Runtime.enable');
  await cdp('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await cdp('Page.navigate', { url: 'https://rihla.kesug.com/?mobile-account-drawer=1' });
  await waitFor(() => evaluate("Boolean(document.querySelector('[data-action=\"open-account-drawer\"]'))"), 'account menu trigger');
  await evaluate("document.querySelector('[data-action=\"open-account-drawer\"]').click()");
  await waitFor(() => evaluate("document.querySelector('.account-drawer-layer')?.classList.contains('is-open')"), 'open account drawer');

  const result = await evaluate(`(() => {
    const drawer = document.querySelector('.account-drawer');
    const rect = drawer.getBoundingClientRect();
    return {
      triggerText: document.querySelector('[data-action="open-account-drawer"]')?.textContent.replace(/\\s+/g, ' ').trim(),
      hasLegacyLoginButton: [...document.querySelectorAll('.app-header [data-action="login"], .app-header [data-action="register"]')].length > 0,
      loginVisible: Boolean(document.querySelector('[data-drawer-action="login"]')),
      registerVisible: Boolean(document.querySelector('[data-drawer-action="register"]')),
      drawerVisible: rect.width >= 250 && rect.width <= window.innerWidth * .8 && rect.left > 0 && rect.right <= window.innerWidth,
      viewport: { width: window.innerWidth, height: window.innerHeight },
    };
  })()`);
  const openScreenshot = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  writeFileSync(`${outputDir}/mobile-account-drawer-open.png`, Buffer.from(openScreenshot.data, 'base64'));
  const drawerRect = await evaluate(`(() => { const rect = document.querySelector('.account-drawer').getBoundingClientRect(); return { x: rect.left + 50, y: rect.top + 180 }; })()`);
  await cdp('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: drawerRect.x, y: drawerRect.y, id: 1, radiusX: 1, radiusY: 1, force: 1 }] });
  await cdp('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: drawerRect.x + 160, y: drawerRect.y + 2, id: 1, radiusX: 1, radiusY: 1, force: 1 }] });
  await cdp('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await waitFor(() => evaluate("!document.querySelector('.account-drawer-layer')"), 'drawer close after swipe');
  result.closedAfterSwipe = await evaluate("!document.querySelector('.account-drawer-layer')");
  const closedScreenshot = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  writeFileSync(`${outputDir}/mobile-account-drawer-closed.png`, Buffer.from(closedScreenshot.data, 'base64'));
  writeFileSync(`${outputDir}/result.json`, `${JSON.stringify(result, null, 2)}\n`);
  if (!result.loginVisible || !result.registerVisible || result.hasLegacyLoginButton || !result.drawerVisible || !result.closedAfterSwipe) throw new Error('Account drawer did not render and close as expected');
  console.log(JSON.stringify(result));
  socket.close();
} finally {
  browser.kill('SIGTERM');
}
