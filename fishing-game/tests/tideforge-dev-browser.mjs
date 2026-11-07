import assert from 'node:assert/strict';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';

const base = 'https://fishbound.test';
const expectedIdentity = '0xc2002230cb292d622fb9716c64c8c73f84b1934e4eb6e6db546fb271ab32e488';
const prodRefresh = process.env.FISHBOUND_PROD_REFRESH === '1';
const output = new URL(prodRefresh ? '../.local/prod-refresh/browser/' : '../.local/tideforge-dev-browser/', import.meta.url);
const expectedKeptCount = prodRefresh
  ? JSON.parse(await readFile(new URL('../.local/prod-refresh/stage-before.json', import.meta.url), 'utf8')).devLoginKeptFish
  : 0;
assert(Number.isSafeInteger(expectedKeptCount) && expectedKeptCount >= 0, 'Invalid frozen kept-fish count.');
const playwrightPath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
if (!playwrightPath) throw new Error('FISHBOUND_PLAYWRIGHT_MODULE is required');
const { chromium } = await import(pathToFileURL(playwrightPath).href);
await mkdir(output, { recursive: true });

const results = {
  scope: 'REAL PRIVATE DEV; preview only',
  origin: base,
  identityMatched: false,
  sdkSocketObserved: false,
  sdkReady: false,
  loginAttempted: false,
  previewAttempted: false,
  previewCancelled: false,
  expectedKeptCount,
  displayedFishCount: null,
  checks: [],
  blockedOrigins: [],
};
const diagnostics = [];
let stage = 'browser startup';
let browser;

function safeMessage(value) {
  let text = String(value ?? '').replace(/[\r\n]+/g, ' ').slice(0, 600);
  if (/\b(?:token|proof|cookie|authorization|bearer|secret|password)\b/i.test(text)) return '[sensitive diagnostic redacted]';
  text = text.replace(/\b(?:https?|wss?):\/\/[^\s"']+/gi, url => {
    try { return new URL(url).origin; } catch { return '[url redacted]'; }
  });
  return text.replace(/\b(?:0x)?[a-zA-Z0-9_-]{40,}\b/g, '[redacted]').slice(0, 300);
}
function origin(url) {
  try { return new URL(url).origin; } catch { return '[invalid origin]'; }
}
function record(name) {
  results.checks.push({ name, status: 'PASS' });
  console.log(`PASS ${name}`);
}
function fail(message) { throw new Error(message); }

try {
  browser = await chromium.launch({
    headless: true,
    executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe',
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, serviceWorkers: 'block' });
  await context.route('**/*', route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== base) {
      results.blockedOrigins.push(origin(request.url()));
      return route.abort();
    }
    if (url.pathname === '/api/connection-events') return route.fulfill({ status: 204 });
    const allowedPost = request.method() === 'POST'
      && ['/api/dev-login', '/v1/identity/websocket-token'].includes(url.pathname);
    if (request.method() !== 'GET' && !allowedPost) {
      results.blockedOrigins.push(url.origin);
      return route.abort();
    }
    return route.continue();
  });
  await context.routeWebSocket('**/*', socket => {
    const url = new URL(socket.url());
    if (url.origin !== 'wss://fishbound.test') {
      results.blockedOrigins.push(url.origin);
      return socket.close({ code: 1008, reason: 'External connection blocked' });
    }
    if (url.pathname.startsWith('/v1/database/')) results.sdkSocketObserved = true;
    return socket.connectToServer();
  });
  const page = await context.newPage();
  page.on('console', message => {
    if (message.type() === 'error' || message.type() === 'warning') {
      diagnostics.push(`${message.type()}: ${safeMessage(message.text())}`);
    }
  });
  page.on('pageerror', error => diagnostics.push(`pageerror: ${safeMessage(error.message)}`));
  page.on('requestfailed', request => {
    if (origin(request.url()) === base) diagnostics.push(`request failed at ${base}: ${safeMessage(request.failure()?.errorText)}`);
  });

  stage = 'database identity guard';
  const identityResponse = await page.goto(`${base}/v1/database/fishbound-dev-local/identity`, {
    waitUntil: 'domcontentloaded', timeout: 15000,
  });
  if (!identityResponse?.ok()) fail(`Identity guard returned HTTP ${identityResponse?.status() ?? 'no response'}.`);
  const actualIdentity = (await identityResponse.text()).trim().replace(/^0x/i, '').toLowerCase();
  if (actualIdentity !== expectedIdentity.slice(2)) fail('Database identity did not match the required private-dev identity.');
  results.identityMatched = true;
  record('exact private database identity');

  stage = 'private app connection';
  await page.goto(`${base}/${prodRefresh ? '#tideforge' : ''}`, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.locator('nav .connection.live').waitFor({ state: 'visible', timeout: 30000 });
  results.sdkReady = true;
  if (!results.sdkSocketObserved) fail('The game reported ready without an observed database WebSocket.');
  record('real SDK connection');

  stage = 'existing private-dev login';
  const login = page.getByRole('button', { name: 'Use icha_', exact: true });
  await login.waitFor({ state: 'visible', timeout: 15000 });
  if (await login.isDisabled()) fail('The private-dev login button is disabled.');
  results.loginAttempted = true;
  const [loginResponse] = await Promise.all([
    page.waitForResponse(response => {
      const url = new URL(response.url());
      return url.origin === base && url.pathname === '/api/dev-login' && response.request().method() === 'POST';
    }, { timeout: 30000 }),
    login.click(),
  ]);
  results.loginStatus = loginResponse.status();
  if (loginResponse.status() !== 204) fail(`Private-dev login returned HTTP ${loginResponse.status()}.`);
  await page.locator('nav .connection.live').waitFor({ state: 'visible', timeout: 30000 });
  await page.waitForFunction(() => {
    const tab = document.getElementById('tab-tideforge');
    return tab instanceof HTMLButtonElement && !tab.disabled;
  }, null, { timeout: 30000 });
  record('private-dev login and linked player');

  stage = 'real Tideforge gear';
  await page.locator('#tab-tideforge').click();
  await page.getByRole('tablist', { name: 'Tideforge sections' }).waitFor({ state: 'visible', timeout: 30000 });
  for (const id of ['gear', 'forge', 'processing', 'trader', 'vault', 'codex', 'reliquary']) {
    assert.equal(await page.locator(`#tideforge-tab-${id}`).isVisible(), true, `Missing ${id} tab.`);
  }
  assert.equal(await page.getByText('Tideforge is not active on this server yet.').isVisible(), false);
    assert.equal(await page.getByText('Tideforge is unavailable. Refresh the page or try again later.').isVisible(), false);
  const errorPanel = page.locator('.tideforge [role="alert"]');
  if (await errorPanel.count() && await errorPanel.first().isVisible()) {
    fail(`Tideforge reported an error: ${safeMessage(await errorPanel.first().innerText())}`);
  }
  const cards = page.locator('#tideforge-panel-gear .card');
  await cards.first().waitFor({ state: 'visible', timeout: 30000 });
    const original = cards.filter({ hasText: 'Protected' }).first();
  await original.waitFor({ state: 'visible' });
  const originalText = await original.innerText();
  assert.match(originalText, /Quality\s+\S+/);
  assert.match(originalText, /reinforcement|Unreinforced/i);
  await page.locator('#tideforge-tab-gear').scrollIntoViewIfNeeded();
  await page.screenshot({ path: fileURLToPath(new URL('desktop-gear.png', output)) });
  record('seven tabs and real protected-original gear');

  stage = 'real section panels';
  const markers = {
    forge: ['combobox', 'Forge gear'],
    processing: ['checkbox', 'Include rank maxima and perfect catches'],
    trader: ['heading', 'Camp copies'],
    vault: ['heading', 'Fillets & scales'],
    codex: ['searchbox', 'Find affix'],
    reliquary: ['heading', 'Extract a signature'],
  };
  for (const [id, [role, name]] of Object.entries(markers)) {
    await page.locator(`#tideforge-tab-${id}`).click();
    const panel = page.locator(`#tideforge-panel-${id}`);
    await panel.waitFor({ state: 'visible', timeout: 15000 });
    await panel.getByRole(role, { name, exact: true }).waitFor({ state: 'visible', timeout: 15000 });
    if (id === 'forge') {
      await panel.getByRole('combobox', { name: 'Forge gear' }).click();
      const choices = page.getByRole('listbox', { name: 'Forge gear' }).getByRole('option');
      if (await choices.count() < 2) fail('Forge did not show existing gear options.');
      await page.keyboard.press('Escape');
    }
    if (id === 'processing') {
      assert.equal(await panel.getByRole('checkbox', { name, exact: true }).isChecked(), false);
      const fish = panel.locator('.fish label');
      if (expectedKeptCount > 0) {
        await fish.first().waitFor({ state: 'visible', timeout: 15000 });
        assert.equal(await panel.getByText('No kept fish to process.', { exact: true }).isVisible(), false);
        results.displayedFishCount = await fish.count();
        assert(results.displayedFishCount > 0);
        record('real kept-fish inventory with trophy protection');
      } else {
        await panel.getByText('No kept fish to process.', { exact: true }).waitFor({ state: 'visible', timeout: 15000 });
        results.displayedFishCount = await fish.count();
        assert.equal(results.displayedFishCount, 0);
        record('legitimate empty inventory with trophy protection');
      }
    }
  }
  record('Forge, Processing, Trader, Vault, Codex and Reliquary panels');

  stage = 'camp-copy preview';
  await page.locator('#tideforge-tab-trader').click();
  async function chooseFirst(label) {
    await page.getByRole('combobox', { name: label, exact: true }).click();
    const options = page.getByRole('listbox', { name: label, exact: true }).getByRole('option');
    if (await options.count() < 2) fail(`${label} has no available choice.`);
    const choice = options.nth(1);
    if (await choice.isDisabled()) fail(`${label} first available choice is disabled.`);
    const text = (await choice.innerText()).trim();
    await choice.click();
    return text;
  }
  const gearName = await chooseFirst('Gear model');
  const waterName = await chooseFirst('Secondary water');
  const previewButton = page.getByRole('button', { name: 'Preview gear purchase', exact: true });
  if (await previewButton.isDisabled()) fail('The camp-copy preview is disabled.');
  results.previewAttempted = true;
  await previewButton.click();
  await page.waitForFunction(() => {
    const dialog = document.querySelector('.tideforge dialog[open]');
    const alert = document.querySelector('.tideforge [role=\"alert\"]');
    return !!dialog || !!alert && alert.getClientRects().length > 0;
  }, null, { timeout: 30000 });
  const dialog = page.getByRole('dialog');
  if (!await dialog.isVisible()) {
    const alert = page.locator('.tideforge [role=\"alert\"]').first();
    fail(`The server did not provide a preview: ${safeMessage(await alert.innerText())}`);
  }
  const targetText = await dialog.innerText();
  assert(targetText.includes(gearName), 'Preview does not name the requested gear.');
  assert(targetText.includes(waterName), 'Preview does not name the requested secondary water.');
  const costs = await dialog.evaluate(element => {
    const heading = [...element.querySelectorAll('h3')].find(item => item.textContent?.trim() === 'Costs');
    const amounts = [];
    for (let node = heading?.nextElementSibling; node && node.tagName !== 'H3'; node = node.nextElementSibling) {
      if (node.tagName === 'P') amounts.push(node.textContent?.trim() ?? '');
    }
    return amounts;
  });
  assert(costs.some(text => /coins/i.test(text)), 'Preview did not display the server coin cost.');
  results.preview = { gear: gearName, water: waterName, costs };
  await page.screenshot({ path: fileURLToPath(new URL('preview-cost.png', output)) });
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await dialog.waitFor({ state: 'hidden' });
  results.previewCancelled = true;
  record('real camp-copy quote and cancel; no confirmation');
  if (prodRefresh) {
    stage = 'mobile navigation and processing';
    await page.setViewportSize({ width: 375, height: 812 });
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    await page.locator('#mobile-navigation').getByRole('button', { name: 'Tideforge', exact: true }).click();
    await page.locator('#tideforge-tab-processing').click();
    const panel = page.locator('#tideforge-panel-processing');
    await panel.waitFor({ state: 'visible', timeout: 15000 });
    await panel.locator('.fish label').first().waitFor({ state: 'visible', timeout: 15000 });
    assert.equal(await panel.getByRole('checkbox', { name: 'Include rank maxima and perfect catches', exact: true }).isChecked(), false);
    results.mobileOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    assert.equal(results.mobileOverflow, false, 'Mobile page has horizontal overflow.');
    await page.locator('#tideforge-tab-processing').scrollIntoViewIfNeeded();
    await page.screenshot({ path: fileURLToPath(new URL('mobile-processing.png', output)) });
    record('mobile navigation and processing without horizontal overflow');
  }
  if (results.blockedOrigins.length) fail('The page attempted a request outside the permitted network or method.');
  if (diagnostics.some(message => /^(?:error|pageerror):/.test(message))) fail('The browser recorded console or page errors.');
} catch (error) {
  const message = safeMessage(error instanceof Error ? error.message : error);
  results.checks.push({ name: stage, status: 'FAIL', message });
  console.error(`FAIL ${stage}: ${message}`);
  process.exitCode = 1;
} finally {
  await browser?.close();
  await writeFile(new URL('results.json', output), `${JSON.stringify(results, null, 2)}\n`);
  await writeFile(new URL('console.log', output), `${diagnostics.join('\n')}\n`);
}
