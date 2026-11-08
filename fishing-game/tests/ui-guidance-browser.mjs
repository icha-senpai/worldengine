import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const modulePath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('FISHBOUND_PLAYWRIGHT_MODULE is required');
const { chromium } = await import(pathToFileURL(modulePath).href);
const base = 'http://127.0.0.1:5182';
const output = new URL('../.local/ui-ux-pass/phase1/', import.meta.url);
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
const results = [], diagnostics = [], blocked = [];

async function context(viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, hasTouch: viewport.width === 375 });
  await ctx.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith(`${base}/`) || url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
    blocked.push(url);
    return route.abort();
  });
  const page = await ctx.newPage();
  page.on('pageerror', error => diagnostics.push(error.stack || error.message));
  page.on('console', message => { if (message.type() === 'error') diagnostics.push(message.text()); });
  await page.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await page.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  return { ctx, page };
}

async function check(name, fn) {
  await fn();
  results.push({ name, status: 'PASS' });
  console.log(`PASS ${name}`);
}

async function tab(page, id) {
  await page.locator(`#tideforge-tab-${id}`).click();
  await page.locator(`#tideforge-panel-${id}`).waitFor({ state: 'visible' });
}

async function choose(page, label, option) {
  await page.getByRole('combobox', { name: label, exact: true }).click();
  await page.getByRole('listbox', { name: label, exact: true }).getByRole('option', { name: option, exact: true }).click();
}

async function reason(button, expected) {
  assert.equal(await button.isDisabled(), true);
  const id = await button.getAttribute('aria-describedby');
  assert(id, 'disabled action must reference a visible explanation');
  const node = button.page().locator(`#${id}`);
  await node.waitFor({ state: 'visible' });
  assert.match(await node.innerText(), expected);
  assert.notEqual(await node.getAttribute('role'), 'alert');
}

try {
  const desktop = await context({ width: 1440, height: 1000 });
  const page = desktop.page;
  await check('Forge explains filled slots and supports a legal Change selection', async () => {
    await tab(page, 'forge');
    await choose(page, 'Forge gear', 'River Rod · Item 1');
    const button = page.getByRole('button', { name: 'Preview Forge action', exact: true });
    await reason(button, /No empty unlocked slots\. Reach mastery 10/);
    await choose(page, 'Discovered affix', 'Secondary Affinity');
    await choose(page, 'Secondary water', 'Whispering River');
    await reason(button, /No empty unlocked slots\. Reach mastery 10/);
    await page.locator('#tideforge-panel-forge').screenshot({ path: new URL('desktop-forge.png', output).pathname.slice(1) });
    await choose(page, 'Forge action', 'Change');
    await reason(button, /Choose a discovered compatible affix/);
    await choose(page, 'Discovered affix', 'Secondary Affinity');
    await choose(page, 'Secondary water', 'Whispering River');
    await page.evaluate(() => {
      const game = window.tideforgeFixture;
      game.tideforgeVault = [{ key: 'fixture-secondary', playerId: game.player.playerId, affixId: 'secondary_affinity', count: 1n }];
    });
    await page.waitForFunction(() => !document.querySelector('#tideforge-panel-forge button[aria-describedby="forge-blocked-reason"]'));
    assert.equal(await button.isDisabled(), false);
    assert.equal(await button.getAttribute('aria-describedby'), null);
    assert.equal(await page.locator('#forge-blocked-reason').count(), 0);
    assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
  });
  await check('Forge action help supports keyboard focus and Escape', async () => {
    const help = page.getByRole('button', { name: 'About Forge actions', exact: true });
    await help.scrollIntoViewIfNeeded(); await page.mouse.move(0, 0); await page.waitForTimeout(200);
    await help.focus();
    const tooltip = page.getByRole('tooltip');
    await tooltip.waitFor({ state: 'visible' });
    assert.match(await tooltip.innerText(), /Add fills an unlocked empty slot.*Change keeps the slot, tier and strength/);
    await page.keyboard.press('Escape');
    await tooltip.waitFor({ state: 'hidden' });
  });
  await check('Processing requires an ordinary F clear and responds to live badge changes', async () => {
    await tab(page, 'processing');
    await choose(page, 'Processing action', 'Make F-grade materials');
    const button = page.getByRole('button', { name: 'Preview processing', exact: true });
    await reason(button, /regular-fish raid clear at F grade is required/);
    await page.locator('#tideforge-panel-processing').screenshot({ path: new URL('desktop-processing.png', output).pathname.slice(1) });
    await page.evaluate(() => {
      const game = window.tideforgeFixture;
      const ordinary = game.species.find(row => row.countsForOrdinaryCollectionCompletion);
      game.species = [...game.species, { ...ordinary, speciesId: 987654, countsForOrdinaryCollectionCompletion: false }];
      game.raidBadges = [{ playerId: game.player.playerId, speciesId: 987654, clearedGrades: 10 }];
    });
    await reason(button, /regular-fish raid clear at F grade is required/);
    await page.evaluate(() => {
      const game = window.tideforgeFixture;
      const ordinary = game.species.find(row => row.countsForOrdinaryCollectionCompletion);
      game.items = [{ key: (game.player.playerId << 32n) + 800n, playerId: game.player.playerId, item: 'normal_fillets', quantity: 10n }];
      game.raidBadges = [{ playerId: game.player.playerId, speciesId: ordinary.speciesId, clearedGrades: 1 }];
    });
    await page.waitForFunction(() => !document.querySelector('#tideforge-panel-processing button[aria-describedby="processing-blocked-reason"]'));
    assert.equal(await button.isDisabled(), false);
    assert.equal(await button.getAttribute('aria-describedby'), null);
    await page.evaluate(() => { window.tideforgeFixture.raidBadges = []; });
    await page.waitForFunction(() => document.querySelector('#tideforge-panel-processing button[aria-describedby="processing-blocked-reason"]')?.disabled);
    await reason(button, /regular-fish raid clear at F grade is required/);
    assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
  });
  await desktop.ctx.close();

  const mobile = await context({ width: 375, height: 812 });
  const small = mobile.page;
  await check('mobile batch help supports touch and Escape', async () => {
    await tab(small, 'processing');
    await choose(small, 'Processing action', 'Make F-grade materials');
    const button = small.getByRole('button', { name: 'Preview processing', exact: true });
    await reason(button, /regular-fish raid clear at F grade is required/);
    const help = small.getByRole('button', { name: 'About Processing batches', exact: true });
    await help.scrollIntoViewIfNeeded(); await small.waitForTimeout(200); await help.tap();
    const tooltip = small.getByRole('tooltip');
    await tooltip.waitFor({ state: 'visible' });
    assert.match(await tooltip.innerText(), /one run of the selected recipe.*each grade in order/);
    await small.screenshot({ path: new URL('mobile-processing.png', output).pathname.slice(1) });
    await small.keyboard.press('Escape');
    await tooltip.waitFor({ state: 'hidden' });
    const size = await small.evaluate(() => ({ width: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
    assert(size.scroll <= size.width + 1, `horizontal overflow: ${JSON.stringify(size)}`);
    assert.equal(await small.evaluate(() => window.tideforgeFixture.calls.length), 0);
  });
  await mobile.ctx.close();
  assert.deepEqual(blocked, [], 'fixture attempted an external request');
  assert.deepEqual(diagnostics, [], 'browser console or page errors');
} catch (error) {
  results.push({ name: 'browser suite', status: 'FAIL', error: error.stack || String(error) });
  console.error(error);
  process.exitCode = 1;
} finally {
  await writeFile(new URL('browser-results.json', output), JSON.stringify({ label: 'FRONTEND FIXTURE ONLY', base, results, blocked, diagnostics }, null, 2));
  await browser.close();
}
