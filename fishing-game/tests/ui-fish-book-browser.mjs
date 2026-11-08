import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import catalog from '../content/species.json' with { type: 'json' };
import manifest from '../assets/manifest.json' with { type: 'json' };

const { chromium } = await import(pathToFileURL(process.env.FISHBOUND_PLAYWRIGHT_MODULE).href);
const base = 'http://127.0.0.1:5182', output = new URL('../.local/ui-ux-pass/phase4/', import.meta.url);
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
const results = [], diagnostics = [], blocked = [], failed = [];
const first = catalog.species.find(row => row.biomeId === 1 && row.countsForOrdinaryCollectionCompletion);
const multiword = catalog.species.find(row => row.key.includes('-') && row.name.includes(' ')
  && catalog.species.filter(candidate => candidate.key.includes(row.key)).length === 1);
assert(multiword);
const fixtures = catalog.species.map(row => ({ ...row, spriteAsset: manifest.fish.find(asset => asset.key === row.spriteKey)?.url ?? '/fish/minnow.png' }));
async function context(viewport = { width: 1440, height: 1000 }, live = false) {
  const ctx = await browser.newContext({ viewport, hasTouch: viewport.width === 375, ignoreHTTPSErrors: true });
  await ctx.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith(`${base}/`) || live && (url.startsWith('https://fishbound.test/') || url.startsWith('https://cdn.discordapp.com/'))
      || url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
    blocked.push(url); return route.abort();
  });
  const page = await ctx.newPage();
  page.on('pageerror', error => diagnostics.push(error.stack || error.message));
  page.on('console', message => { if (message.type() === 'error') diagnostics.push(message.text()); });
  page.on('requestfailed', request => failed.push(`${request.url()}: ${request.failure()?.errorText}`));
  return { ctx, page };
}
async function check(name, fn) { await fn(); results.push({ name, status: 'PASS' }); console.log(`PASS ${name}`); }
async function choose(page, label, option) {
  await page.getByRole('combobox', { name: label, exact: true }).click();
  await page.getByRole('listbox', { name: label, exact: true }).getByRole('option', { name: option, exact: true }).click();
}
async function populate(page) {
  await page.evaluate(rows => {
    const game = window.tideforgeFixture;
    game.species = rows.map(({ key, ...row }) => ({ ...row, typicalWeightG: BigInt(row.typicalWeightG) }));
  }, fixtures);
}
async function noOverflow(page) {
  const [width, scroll] = await page.evaluate(() => [document.documentElement.clientWidth, document.documentElement.scrollWidth]);
  assert(scroll <= width + 1, `horizontal overflow: ${width}/${scroll}`);
}
try {
  await check('collection and field guide share filters, one modal and route history', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#collection`, { waitUntil: 'networkidle' }); await populate(page);
      await page.locator('#fish-book-panel-collection').waitFor({ state: 'visible' });
      assert.equal(await page.locator('#tab-collection').count(), 0);
      await page.evaluate(id => {
        const game = window.tideforgeFixture;
        game.collection = [{ playerId: 9n, speciesId: id, count: 0n, rankCounts: [8n] },
          { playerId: 10n, speciesId: id, count: 99n, rankCounts: [99n] }];
      }, first.speciesId);
      await page.getByRole('searchbox', { name: 'Find a fish' }).fill(first.name);
      await choose(page, 'Biome', 'Meadow Pond'); await choose(page, 'Rank', 'F');
      const collection = page.locator('#fish-book-panel-collection'), card = collection.locator('article').filter({ hasText: first.name });
      await card.getByText('Waiting for your first discovery').waitFor({ state: 'visible' });
      assert.doesNotMatch(await card.innerText(), /99 caught|8 caught/);
      await page.evaluate(id => {
        const game = window.tideforgeFixture;
        game.collection = [{ playerId: 9n, speciesId: id, count: 2n, rankCounts: [2n], bestLengthMm: 100, bestWeightG: 100n }];
        game.rankMaxima = [{ playerId: 9n, speciesId: id, rarity: 'F' }];
      }, first.speciesId);
      await card.getByText(/2 caught at F/).waitFor({ state: 'visible' });
      assert.equal(await card.getByLabel('F rank maximum').count(), 1);
      await card.getByRole('button', { name: `Open ${first.name} details` }).click();
      const dialog = page.getByRole('dialog');
      await dialog.getByRole('heading', { name: first.name, exact: true }).waitFor({ state: 'visible' });
      await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'hidden' });
      await page.locator('#fish-book-tab-field-guide').click();
      assert.equal(new URL(page.url()).hash, '#compendium/field-guide');
      assert.equal(await page.getByRole('searchbox', { name: 'Find a fish' }).inputValue(), first.name);
      assert.match(await page.getByRole('combobox', { name: 'Biome' }).innerText(), /Meadow Pond/);
      assert.match(await page.getByRole('combobox', { name: 'Rank' }).innerText(), /F/);
      const guide = page.locator('#fish-book-panel-field-guide');
      await guide.getByRole('button', { name: `Open ${first.name} details` }).click();
      await dialog.waitFor({ state: 'visible' }); assert.equal(await page.locator('dialog').count(), 1);
      await page.evaluate(() => window.tideforgeFixture.changeOwner());
      await dialog.waitFor({ state: 'hidden' });
      await page.goBack(); await collection.waitFor({ state: 'visible' });
      assert.equal(await page.getByRole('searchbox', { name: 'Find a fish' }).inputValue(), first.name);
      await card.getByText('Waiting for your first discovery').waitFor({ state: 'visible' });
      await page.goForward(); await guide.waitFor({ state: 'visible' });
      await choose(page, 'Biome', 'All waters'); await choose(page, 'Rank', 'All ranks');
      await page.getByRole('searchbox', { name: 'Find a fish' }).fill(multiword.key);
      await guide.getByRole('button', { name: `Open ${multiword.name} details` }).waitFor({ state: 'visible' });
      const guideNames = await guide.locator('.fish-name').allTextContents();
      assert.deepEqual(guideNames, [multiword.name]);
      await page.locator('#fish-book-tab-collection').click();
      await collection.getByRole('button', { name: `Open ${multiword.name} details` }).waitFor({ state: 'visible' });
      assert.deepEqual(await collection.locator('.fish-details').allTextContents(), guideNames);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('equipped estimate explains connection, equipment and missing rod states and recovers', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#compendium/field-guide`, { waitUntil: 'networkidle' });
      const button = page.getByRole('button', { name: /^Equipped(?: base gear)?$/ });
      await button.waitFor({ state: 'visible' }); assert.equal(await button.isEnabled(), true);
      await button.click(); assert.equal(await button.getAttribute('aria-pressed'), 'true');
      await page.evaluate(() => { window.tideforgeFixture.ready = false; });
      const reason = page.locator('#equipped-rate-reason');
      await reason.getByText('Wait for the connection and equipment to finish loading.', { exact: true }).waitFor({ state: 'visible' });
      assert.equal(await button.isDisabled(), true); assert.equal(await button.getAttribute('aria-describedby'), 'equipped-rate-reason');
      assert.equal(await page.getByRole('button', { name: 'Baseline', exact: true }).getAttribute('aria-pressed'), 'true');
      assert.doesNotMatch(await reason.innerText(), /Link.*Discord/i);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.ready = true; game.tideforgeCoreReady = false;
      });
      await reason.getByText('Equipment details are unavailable. Retry Tideforge to load your equipped stats.').waitFor({ state: 'visible' });
      assert.equal(await button.isDisabled(), true);
      assert.equal(await button.getAttribute('aria-describedby'), 'equipped-rate-reason');
      assert.doesNotMatch(await reason.innerText(), /Link.*Discord/i);
      assert.equal(await reason.getByRole('link', { name: 'Review gear' }).getAttribute('href'), '#tideforge/gear');
      await page.evaluate(() => { window.tideforgeFixture.tideforgeCoreReady = true; });
      await page.waitForFunction(() => !document.querySelector('.rate-presets button[aria-describedby="equipped-rate-reason"]'));
      assert.equal(await button.isEnabled(), true); assert.equal(await reason.count(), 0);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.savedReviewRods = game.rods; game.rods = [];
      });
      await reason.getByText('Your equipped rod details are unavailable. Review your gear in Tideforge.').waitFor({ state: 'visible' });
      assert.equal(await button.isDisabled(), true);
      assert.equal(await reason.getByRole('link', { name: 'Review gear' }).getAttribute('href'), '#tideforge/gear');
      await page.evaluate(() => { const game = window.tideforgeFixture; game.rods = game.savedReviewRods; });
      await page.waitForFunction(() => !document.querySelector('.rate-presets button[aria-describedby="equipped-rate-reason"]'));
      assert.equal(await button.isEnabled(), true);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('Focus uses the equipped rod and reacts to discoveries independently of odds preset', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#compendium/collection`, { waitUntil: 'networkidle' }); await populate(page);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 100n };
        game.collection = game.species.filter(row => row.biomeId === 1 && row.countsForOrdinaryCollectionCompletion)
          .map(row => ({ playerId: game.player.playerId, speciesId: row.speciesId, count: 1n, rankCounts: [1n], bestLengthMm: 1, bestWeightG: 1n }));
        game.focusPreferences = [{ playerId: game.player.playerId, biomeId: 1, enabled: false }];
      });
      const focus = page.getByRole('checkbox', { name: /Collection Focus/ });
      await focus.waitFor({ state: 'visible' });
      assert.equal(await focus.isDisabled(), false);
      assert.match(await page.locator('.collection-focus').innerText(), /C rod Focus chance/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.focusPreferences = game.focusPreferences.map(row => ({ ...row, enabled: true }));
      });
      await page.waitForFunction(() => document.querySelector('#collection-focus-toggle')?.checked);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.focusPreferences = game.focusPreferences.map(row => ({ ...row, enabled: false }));
      });
      await page.waitForFunction(() => !document.querySelector('#collection-focus-toggle')?.checked);
      await page.locator('#fish-book-tab-field-guide').click();
      await page.getByRole('button', { name: /Best base gear|Best for perfects/ }).click();
      await page.locator('#fish-book-tab-collection').click();
      assert.match(await page.locator('.collection-focus').innerText(), /C rod Focus chance/);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.collection = game.collection.slice(1); });
      assert.equal(await focus.isDisabled(), true);
      assert.match(await page.locator(`#${await focus.getAttribute('aria-describedby')}`).innerText(), /Discover them all/);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('unlinked public guide and mobile accessible help remain usable', async () => {
    const { ctx, page } = await context({ width: 375, height: 812 });
    try {
      await page.goto(`${base}/#compendium`, { waitUntil: 'networkidle' });
      await page.evaluate(() => { window.tideforgeFixture.player = null; });
      await page.locator('#fish-book-panel-field-guide').waitFor({ state: 'visible' });
      const equipped = page.getByRole('button', { name: /^Equipped(?: base gear)?$/ });
      assert.equal(await equipped.isDisabled(), true);
      assert.equal(await equipped.getAttribute('aria-describedby'), 'equipped-rate-reason');
      await page.getByText('Link your Discord account to see your equipped loadout.', { exact: true }).waitFor({ state: 'visible' });
      await page.locator('#fish-book-panel-field-guide').getByRole('button', { name: `Open ${first.name} details` }).click();
      const dialog = page.getByRole('dialog');
      await dialog.waitFor({ state: 'visible' }); await noOverflow(page);
      await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'hidden' });
      const help = page.getByRole('button', { name: 'About fish ranks' });
      await help.scrollIntoViewIfNeeded(); await help.tap();
      await page.getByRole('tooltip').waitFor({ state: 'visible' });
      await page.keyboard.press('Escape'); await page.getByRole('tooltip').waitFor({ state: 'hidden' });
      await page.locator('#fish-book-tab-collection').click();
      await page.getByText('Link Discord from Camp to track your collection.').waitFor({ state: 'visible' });
      await page.locator('#fish-book-tab-collection[aria-selected="true"]').waitFor({ state: 'visible' });
      assert.equal(await page.locator('.collection-entry').count(), 0);
      await noOverflow(page); await page.screenshot({ path: new URL('mobile-fish-book-settled.png', output).pathname.slice(1), animations: 'disabled' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('mobile Shop selected tab is captured after transitions settle', async () => {
    const { ctx, page } = await context({ width: 375, height: 812 });
    try {
      await page.goto(`${base}/#trader/camp`, { waitUntil: 'networkidle' });
      await page.locator('#shop-panel-camp').waitFor({ state: 'visible' });
      await page.locator('#shop-tab-camp[aria-selected="true"]').waitFor({ state: 'visible' });
      await noOverflow(page);
      await page.screenshot({ path: new URL('mobile-shop-settled.png', output).pathname.slice(1), animations: 'disabled' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('changing book views closes an open details dialog', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#compendium/field-guide`, { waitUntil: 'networkidle' });
      await page.locator('#fish-book-panel-field-guide').getByRole('button', { name: `Open ${first.name} details` }).click();
      const dialog = page.getByRole('dialog'); await dialog.waitFor({ state: 'visible' });
      await page.evaluate(() => { location.hash = '#compendium/collection'; });
      await dialog.waitFor({ state: 'hidden' });
      await page.locator('#fish-book-panel-collection').waitFor({ state: 'visible' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('private-dev catalog route uses the public Fish Book host', async () => {
    const { ctx, page } = await context({ width: 1440, height: 1000 }, true);
    try {
      const response = await page.goto('https://fishbound.test/catalog', { waitUntil: 'networkidle' });
      assert.equal(response.status(), 200);
      await page.locator('nav .connection.live').waitFor({ timeout: 20000 });
      await page.locator('#fish-book-panel-field-guide').waitFor({ state: 'visible' });
      assert.equal(await page.getByRole('tablist', { name: 'Fish Book sections' }).count(), 1);
      assert.equal(await page.locator('#tab-collection').count(), 0);
      await page.screenshot({ path: new URL('review-fix-live-catalog.png', output).pathname.slice(1), animations: 'disabled' });
    } finally { await ctx.close(); }
  });
  assert.deepEqual(blocked, []); assert.deepEqual(failed, []); assert.deepEqual(diagnostics, []);
} catch (error) {
  results.push({ name: 'browser suite', status: 'FAIL', error: error.stack || String(error) });
  console.error(error); process.exitCode = 1;
} finally {
  await writeFile(new URL('review-fix-browser-results.json', output), JSON.stringify({ results, diagnostics, blocked, failed }, null, 2));
  await browser.close();
}
