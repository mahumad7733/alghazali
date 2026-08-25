import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const port = 9333;
const outputDir = '/tmp/bus-booking-mobile-live-flow';
mkdirSync(outputDir, { recursive: true });

const browser = spawn('/usr/bin/chromium', [
  '--headless=new', '--no-sandbox', '--disable-gpu', `--remote-debugging-port=${port}`,
  '--user-data-dir=/tmp/bus-booking-mobile-profile', 'about:blank',
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
  await cdp('Page.navigate', { url: 'https://rihla.kesug.com/?mobile-live-flow=1' });
  await waitFor(() => evaluate("document.querySelectorAll('#search-form select option').length >= 6"), 'production cities');

  const optionCount = await evaluate("document.querySelectorAll('#search-form select option').length");
  await evaluate(`(() => {
    const [origin, destination] = document.querySelectorAll('#search-form select');
    origin.value = [...origin.options].find((option) => option.textContent.trim() === 'صنعاء').value;
    destination.value = [...destination.options].find((option) => option.textContent.trim() === 'عدن').value;
    origin.dispatchEvent(new Event('change', { bubbles: true }));
    destination.dispatchEvent(new Event('change', { bubbles: true }));
    document.querySelector('#search-form').requestSubmit();
  })()`);
  await waitFor(() => evaluate("Boolean(document.querySelector('.trip-card'))"), 'production trip card');

  const result = await evaluate(`(() => {
    const card = document.querySelector('.trip-card');
    const button = document.querySelector('[data-trip]');
    const rect = card.getBoundingClientRect();
    return {
      title: document.querySelector('.trip-brand h3')?.textContent.trim(),
      price: document.querySelector('.trip-price strong')?.textContent.trim(),
      hasLoadingTransition: Boolean(document.querySelector('.trip-card')),
      cardVisible: rect.top >= 0 && rect.bottom <= window.innerHeight,
      actionHeight: Math.round(button.getBoundingClientRect().height),
      viewport: { width: window.innerWidth, height: window.innerHeight },
      route: document.querySelector('.trip-route-name')?.textContent.replace(/\s+/g, ' ').trim(),
    };
  })()`);
  const screenshot = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  writeFileSync(`${outputDir}/mobile-live-flow.png`, Buffer.from(screenshot.data, 'base64'));
  writeFileSync(`${outputDir}/result.json`, `${JSON.stringify(result, null, 2)}\n`);
  console.log(JSON.stringify(result));
  socket.close();
} finally {
  browser.kill('SIGTERM');
}
