import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const modulePath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('FISHBOUND_PLAYWRIGHT_MODULE is required');
const { chromium } = await import(pathToFileURL(modulePath).href);
const base = 'http://127.0.0.1:5182';
const output = new URL('../.local/wandering-trader-ui/', import.meta.url);
const midnight = Date.UTC(2026, 10, 7), hour = 3_600_000;
const diagnostics = [], blocked = [];
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
async function choose(page, name, option) {
  await page.getByRole('combobox', { name, exact: true }).click();
  await page.getByRole('listbox', { name, exact: true }).getByRole('option', { name: option, exact: true }).click();
}
async function clock(page, value) {
  await page.evaluate(value => {
    const game = window.tideforgeFixture;
    if (value === null) game.serverClock.reset();
    else { const now = performance.now(); game.serverClock.synchronize(value, now, now); }
    game.clockRevision++;
  }, value);
}
async function scenario(timezoneId, viewport, expectedOpen, expectedClose, image) {
  const context = await browser.newContext({ timezoneId, locale: 'en-US', viewport, hasTouch: viewport.width === 375 });
  try {
    await context.route('**/*', route => {
      const url = route.request().url();
      if (url.endsWith('/api/time')) return route.fulfill({ json: { serverTimeMs: midnight }, headers: { 'cache-control': 'no-store' } });
      if (url.startsWith(`${base}/`) || url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
      blocked.push(url); return route.abort();
    });
    await context.addInitScript(() => { const now = Date.now.bind(Date); Date.now = () => now() + 12 * 3_600_000; });
    const page = await context.newPage();
    page.on('pageerror', error => diagnostics.push(error.message));
    page.on('console', message => { if (message.type() === 'error') diagnostics.push(message.text()); });
    await page.goto(`${base}/#trader/additional`, { waitUntil: 'networkidle' });
    await page.locator('#shop-tab-additional').waitFor({ state: 'visible' });
    await choose(page, 'Gear model', 'River Rod');
    await choose(page, 'Secondary water', 'Whispering River');
    await clock(page, midnight);
    const campAvailable = async () => {
      await page.locator('#shop-tab-additional').click();
      assert.equal(await page.getByRole('button', { name: 'Preview gear purchase' }).isDisabled(), false);
      await page.locator('#shop-tab-wandering').click();
    };
    await campAvailable();
    const card = page.locator('#shop-panel-wandering article').filter({ has: page.getByRole('heading', { name: 'Wandering trader' }) });
    const visits = card.getByRole('list', { name: 'Trader visit times' });
    assert.equal(await visits.locator('li').count(), 4);
    const first = visits.locator('li').first().locator('time');
    assert.equal(await first.nth(0).getAttribute('datetime'), '2026-11-07T00:00:00.000Z');
    assert.equal(await first.nth(1).getAttribute('datetime'), '2026-11-07T02:00:00.000Z');
    assert.match(await first.nth(0).innerText(), expectedOpen);
    assert.match(await first.nth(1).innerText(), expectedClose);
    await card.getByText('Here now', { exact: false }).waitFor({ state: 'visible' });
    const trader = card.getByRole('button', { name: 'Preview trader offer' });
    assert.equal(await trader.isDisabled(), false);
    const size = await page.evaluate(() => [document.documentElement.clientWidth, document.documentElement.scrollWidth]);
    assert(size[1] <= size[0] + 1);
    await card.screenshot({ path: new URL(image, output).pathname.slice(1) });
    const url = page.url();
    await clock(page, midnight + 2 * hour);
    await card.getByText('Returns', { exact: false }).waitFor({ state: 'visible' });
    assert.equal(await card.locator('p[role=status] time').getAttribute('datetime'), '2026-11-07T06:00:00.000Z');
    assert.equal(await trader.isDisabled(), true);
    await campAvailable();
    assert.match(await page.getByRole('combobox', { name: 'Gear model' }).innerText(), /River Rod/);
    assert.match(await page.getByRole('combobox', { name: 'Secondary water' }).innerText(), /Whispering River/);
    assert.equal(page.url(), url);
    await clock(page, midnight + 6 * hour);
    await card.getByText('Here now', { exact: false }).waitFor({ state: 'visible' });
    assert.equal(await trader.isDisabled(), false);
    await clock(page, null);
    await card.getByText('Syncing server time…').waitFor({ state: 'visible' });
    assert.equal(await trader.isDisabled(), true);
    await campAvailable();
    assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    console.log(`PASS ${timezoneId} ${viewport.width}px`);
  } finally { await context.close(); }
}
try {
  await scenario('America/Los_Angeles', { width: 1440, height: 1000 }, /Fri, Nov 6.*4:00 PM PST/, /Fri, Nov 6.*6:00 PM PST/, 'desktop.png');
  await scenario('Asia/Kolkata', { width: 375, height: 812 }, /Sat, Nov 7.*5:30 AM GMT\+5:30/, /Sat, Nov 7.*7:30 AM GMT\+5:30/, 'mobile.png');
  assert.deepEqual(diagnostics, []); assert.deepEqual(blocked, []);
  console.log('WANDERING_BROWSER=PASS');
} catch (error) { console.error(error.stack || String(error)); process.exitCode = 1; }
finally { await browser.close(); }
