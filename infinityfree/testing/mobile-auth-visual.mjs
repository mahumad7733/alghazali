import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';

const chromePort = 9228;
const chromeProfile = '/tmp/bus-booking-mobile-auth-profile';
const screenshotDir = '/home/ubuntu/bus-booking-platform/infinityfree/testing/screenshots';
const targetUrl = 'https://rihla.kesug.com/';
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitForDebugEndpoint() {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${chromePort}/json/version`);
      if (response.ok) return response.json();
    } catch {}
    await delay(150);
  }
  throw new Error('تعذر بدء متصفح اختبار الجوال.');
}

class CdpClient {
  constructor(url) {
    this.socket = new WebSocket(url);
    this.nextId = 1;
    this.pending = new Map();
    this.open = new Promise((resolve, reject) => {
      this.socket.addEventListener('open', resolve, { once: true });
      this.socket.addEventListener('error', reject, { once: true });
    });
    this.socket.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);
      const pending = this.pending.get(message.id);
      if (!pending) return;
      this.pending.delete(message.id);
      if (message.error) pending.reject(new Error(message.error.message));
      else pending.resolve(message.result);
    });
  }

  async command(method, params = {}) {
    await this.open;
    const id = this.nextId++;
    const response = new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
    this.socket.send(JSON.stringify({ id, method, params }));
    return response;
  }

  close() { this.socket.close(); }
}

const chrome = spawn('chromium', [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--ignore-certificate-errors',
  `--remote-debugging-port=${chromePort}`, `--user-data-dir=${chromeProfile}`, 'about:blank',
], { stdio: 'ignore' });

try {
  await waitForDebugEndpoint();
  const targetResponse = await fetch(`http://127.0.0.1:${chromePort}/json/new?${encodeURIComponent(targetUrl)}`, { method: 'PUT' });
  const target = await targetResponse.json();
  const client = new CdpClient(target.webSocketDebuggerUrl);

  await client.command('Page.enable');
  await client.command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
  await client.command('Page.navigate', { url: targetUrl });
  await delay(3600);
  await client.command('Runtime.evaluate', { expression: `(() => document.querySelector('[data-action="open-account-drawer"]')?.click())()` });
  await delay(250);
  await client.command('Runtime.evaluate', { expression: `(() => document.querySelector('[data-drawer-action="login"]')?.click())()` });
  await delay(700);

  const loginState = await client.command('Runtime.evaluate', { expression: `(() => {
    const modal = document.querySelector('.auth-modal');
    const shell = document.querySelector('.auth-shell');
    const password = document.querySelector('#auth-password');
    const toggle = document.querySelector('[data-action="toggle-password"]');
    toggle?.click();
    return { modal: Boolean(modal), mobile: matchMedia('(max-width: 700px)').matches, columns: shell ? getComputedStyle(shell).gridTemplateColumns : '', passwordType: password?.type, loginButton: document.querySelector('.auth-submit .btn')?.textContent?.trim() };
  })()` , returnByValue: true });
  const login = loginState.result.value;
  assert.equal(login.modal, true);
  assert.equal(login.mobile, true);
  assert.equal(login.passwordType, 'text');
  assert.match(login.loginButton, /دخول إلى حسابي/);
  assert.equal(login.columns.trim().split(/\s+/).length, 1);

  await mkdir(screenshotDir, { recursive: true });
  const loginShot = await client.command('Page.captureScreenshot', { format: 'png', fromSurface: true });
  await writeFile(`${screenshotDir}/auth-login-mobile.png`, Buffer.from(loginShot.data, 'base64'));

  await client.command('Runtime.evaluate', { expression: `(() => document.querySelector('#auth-switch')?.click())()` });
  await delay(650);
  const registerState = await client.command('Runtime.evaluate', { expression: `(() => ({ mode: document.querySelector('.auth-shell')?.dataset.authMode, registrationFields: document.querySelectorAll('.auth-registration-grid .field').length, submit: document.querySelector('.auth-submit .btn')?.textContent?.trim() }))()` , returnByValue: true });
  const register = registerState.result.value;
  assert.equal(register.mode, 'register');
  assert.equal(register.registrationFields, 4);
  assert.match(register.submit, /إنشاء الحساب والمتابعة/);

  const registerShot = await client.command('Page.captureScreenshot', { format: 'png', fromSurface: true });
  await writeFile(`${screenshotDir}/auth-register-mobile.png`, Buffer.from(registerShot.data, 'base64'));
  client.close();
  console.log('اختبار واجهة المصادقة على هاتف 390×844: ناجح');
} finally {
  chrome.kill('SIGTERM');
}
