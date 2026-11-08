import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const { chromium } = await import(pathToFileURL(process.env.FISHBOUND_PLAYWRIGHT_MODULE).href);
const base = 'http://127.0.0.1:5182', output = new URL('../.local/ui-ux-pass/phase4/', import.meta.url);
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
const results = [], diagnostics = [], blocked = [], failed = [];
async function context() {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await ctx.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith(`${base}/`) || url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
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
async function blockedBy(button, text) {
  assert.equal(await button.isDisabled(), true);
  const id = await button.getAttribute('aria-describedby'); assert(id);
  assert.match(await button.page().locator(`#${id}`).innerText(), text);
}
async function enabled(button) { await button.page().waitForFunction(el => !el.disabled, await button.elementHandle()); }
try {
  await check('conversion and multigrade refining show totals and react to owner balances and clears', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#tideforge/processing`, { waitUntil: 'networkidle' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture, id = game.player.playerId;
        game.items = [{ key: (id << 32n) + 800n, playerId: id, item: 'normal_fillets', quantity: 30n },
          { key: (id << 32n) + 1000n, playerId: id, item: 'raid_fillets_f', quantity: 39n }];
        game.raidBadges = [{ playerId: 999n, speciesId: game.species[0].speciesId, clearedGrades: 10 }];
      });
      await choose(page, 'Processing action', 'Make F-grade materials');
      await page.getByRole('spinbutton', { name: 'Processing batches' }).fill('3');
      const button = page.getByRole('button', { name: 'Preview processing' });
      await blockedBy(button, /regular-fish raid clear at F grade/);
      let guide = page.getByRole('region', { name: 'Cost guide' });
      assert.match(await guide.innerText(), /Basic fillets: 30 required · 30 owned/);
      assert.match(await guide.innerText(), /3 F-grade fillets/);
      assert.match(await guide.innerText(), /Coins: 0 required/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.raidBadges = [...game.raidBadges, { playerId: game.player.playerId, speciesId: game.species[0].speciesId, clearedGrades: 4 }];
      });
      await enabled(button);
      await choose(page, 'Processing action', 'Refine materials');
      await choose(page, 'Destination grade', 'C');
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.raidBadges = [{ playerId: game.player.playerId, speciesId: game.species[0].speciesId, clearedGrades: 2 }];
      });
      await blockedBy(button, /regular-fish raid clear at C grade/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.raidBadges = game.raidBadges.map(row => ({ ...row, clearedGrades: 4 }));
      });
      await page.getByRole('spinbutton', { name: 'Processing batches' }).fill('1');
      await blockedBy(button, /cannot reach that grade/i);
      await page.getByRole('spinbutton', { name: 'Processing batches' }).fill('2');
      await blockedBy(button, /Need 1 more F-grade fillets/);
      guide = page.getByRole('region', { name: 'Cost guide' });
      const text = await guide.innerText();
      assert.match(text, /F-grade fillets: 40 required · 39 owned/);
      assert.match(text, /16 D-grade fillets/); assert.match(text, /18 C-grade fillets/);
      assert.match(text, /Coins: 3 required/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.items = game.items.map(row => row.item === 'raid_fillets_f' ? { ...row, quantity: 40n } : row);
        game.player = { ...game.player, coins: 2n };
      });
      await blockedBy(button, /Need 1 more coins/);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.player = { ...game.player, coins: 3n }; });
      await enabled(button);
      await page.screenshot({ path: new URL('cost-processing.png', output).pathname.slice(1) });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('Forge add, tier upgrade and effective strength use current balances', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#tideforge/forge`, { waitUntil: 'networkidle' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture, id = game.player.playerId;
        game.gearFamilies = game.gearFamilies.map(row => ({ ...row, masteryXp: 1000000n }));
        game.raidBadges = [{ playerId: id, speciesId: game.species[0].speciesId, clearedGrades: 4 }];
        game.items = [{ key: (id << 32n) + 1000n, playerId: id, item: 'raid_fillets_f', quantity: 14n },
          { key: (id << 32n) + 1100n, playerId: id, item: 'raid_scales_f', quantity: 10n }];
        game.tideforgeVault = [];
      });
      await choose(page, 'Forge gear', 'River Rod · Item 1');
      await choose(page, 'Discovered affix', 'Secondary Affinity'); await choose(page, 'Secondary water', 'Whispering River');
      const button = page.getByRole('button', { name: 'Preview Forge action' });
      await blockedBy(button, /Need 1 more F-grade fillets/);
      let text = await page.getByRole('region', { name: 'Cost guide' }).innerText();
      assert.match(text, /F-grade fillets: 15 required · 14 owned/);
      assert.match(text, /F-grade scales: 10 required · 10 owned/);
      assert.match(text, /Secondary Affinity shards: 1 required · 0 owned/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.items = game.items.map(row => row.item === 'raid_fillets_f' ? { ...row, quantity: 15n } : row);
        game.tideforgeVault = [{ key: 'secondary', playerId: game.player.playerId, affixId: 'secondary_affinity', count: 1n }];
      });
      await enabled(button);
      await choose(page, 'Forge action', 'Upgrade tier');
      await page.getByRole('spinbutton', { name: 'Target tier' }).fill('4');
      await blockedBy(button, /Need 36 more C-grade fillets/);
      text = await page.getByRole('region', { name: 'Cost guide' }).innerText();
      for (const value of ['C-grade fillets: 36', 'C-grade scales: 24', 'B-grade fillets: 44', 'B-grade scales: 30']) assert(text.includes(value), value);
      assert.doesNotMatch(text, /shards/i);
      await page.evaluate(() => {
        const game = window.tideforgeFixture, id = game.player.playerId;
        game.items = [...game.items, ...[[1002, 'raid_fillets_c', 36n], [1102, 'raid_scales_c', 24n], [1003, 'raid_fillets_b', 44n], [1103, 'raid_scales_b', 30n]]
          .map(([slot, item, quantity]) => ({ key: (id << 32n) + BigInt(slot), playerId: id, item, quantity }))];
      });
      await enabled(button);
      await choose(page, 'Forge action', 'Improve strength');
      await page.evaluate(() => { const game = window.tideforgeFixture; game.player = { ...game.player, coins: 100n }; });
      await blockedBy(button, /Need 25 more coins/);
      text = await page.getByRole('region', { name: 'Cost guide' }).innerText();
      assert.match(text, /Coins: 125 required/); assert.match(text, /Next effective strength: 60%/);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.player = { ...game.player, coins: 125n }; });
      await enabled(button);
      await page.screenshot({ path: new URL('cost-forge.png', output).pathname.slice(1) });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('haul appraiser skips ineffective steps and costs 750 coins', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#tideforge/forge`, { waitUntil: 'networkidle' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.showGearGrid();
        game.tideforgeGear = game.tideforgeGear.map(row => row.instanceId === 102n
          ? { ...row, affixesJson: JSON.stringify([{ slot: 0, id: 'haul_appraiser', tier: 1, finishBp: 0, secondaryBiomeId: null }]) } : row);
        game.player = { ...game.player, coins: 749n };
      });
      await choose(page, 'Forge gear', 'River Boat');
      await choose(page, 'Forge action', 'Improve strength');
      const button = page.getByRole('button', { name: 'Preview Forge action' });
      await blockedBy(button, /Need 1 more coins/);
      const guide = await page.getByRole('region', { name: 'Cost guide' }).innerText();
      assert.match(guide, /Coins: 750 required/); assert.match(guide, /Next effective strength: 60%/);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.player = { ...game.player, coins: 750n }; });
      await enabled(button);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('core materials and confirmed wallet remain visible through optional detail failures', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#tideforge/materials`, { waitUntil: 'networkidle' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture, id = game.player.playerId;
        game.items = [{ key: (id << 32n) + 800n, playerId: id, item: 'normal_fillets', quantity: 30n }];
        game.tideforgeVault = [{ key: 'fortune', playerId: id, affixId: 'fortune', count: 2n }];
        game.tideforgeDetailsUnavailable = true;
      });
      const materials = page.getByRole('region', { name: 'Materials', exact: true });
      await materials.getByText('Affix shard details are unavailable.').waitFor({ state: 'visible' });
      assert.match(await materials.innerText(), /Basic fillets\s*30/);
      assert.match(await materials.innerText(), /Dredge Marks:\s*20/);
      assert.doesNotMatch(await materials.innerText(), /No recovered shards yet/);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.tideforgeDetailsUnavailable = false; game.tideforgeLoading = true; });
      await materials.getByText('Loading affix shards…').waitFor({ state: 'visible' });
      assert.match(await materials.innerText(), /Basic fillets\s*30/);
      assert.match(await materials.innerText(), /Dredge Marks:\s*20/);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.tideforgeLoading = false; game.tideforgeAvailability = 'inactive'; });
      await materials.getByText('Affix shards are not enabled on this server.').waitFor({ state: 'visible' });
      assert.match(await materials.innerText(), /Basic fillets\s*30/);
      assert.match(await materials.innerText(), /Dredge Marks:\s*Unavailable/);
      await page.evaluate(() => { window.tideforgeFixture.tideforgeAvailability = 'unavailable'; });
      await materials.getByText('Affix shards are unavailable.').waitFor({ state: 'visible' });
      await materials.getByRole('button', { name: 'Retry Tideforge' }).waitFor({ state: 'visible' });
      await page.evaluate(() => { window.tideforgeFixture.tideforgeAvailability = 'active'; });
      await materials.getByText('Fortune shards').waitFor({ state: 'visible' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('trader shortages and extraction protections update without previewing', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#trader/additional`, { waitUntil: 'networkidle' });
      await choose(page, 'Gear model', 'River Rod'); await choose(page, 'Secondary water', 'Whispering River');
      await page.evaluate(() => { const game = window.tideforgeFixture; game.player = { ...game.player, coins: 400n }; });
      await blockedBy(page.getByRole('button', { name: 'Preview gear purchase' }), /Need 100 more coins \(400 \/ 500\)/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture, now = performance.now();
        game.serverClock.synchronize(Date.UTC(2026, 10, 7), now, now); game.clockRevision++;
        game.tideforgeWallet = { ...game.tideforgeWallet, marks: 0n };
      });
      await page.locator('#shop-tab-wandering').click();
      await blockedBy(page.getByRole('button', { name: 'Preview trader offer' }), /Need 1 more Dredge Marks \(0 \/ 1\)/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 100n };
        game.tideforgeLibrary = [];
      });
      await page.locator('#tab-tideforge').click();
      await page.locator('#tideforge-tab-reliquary').click();
      const button = page.getByRole('button', { name: 'Preview extraction' });
      await blockedBy(button, /Choose eligible gear/);
      await choose(page, 'Gear to extract from', 'River Rod · Item 2 · Echoed Line');
      await enabled(button);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.gearInstances = game.gearInstances.map(row => row.instanceId === 101n ? { ...row, favorite: true } : row);
      });
      await blockedBy(button, /No new signatures|no longer eligible/);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('mobile workshop and shop keep accessible titles without horizontal overflow', async () => {
    const { ctx, page } = await context();
    try {
      await page.setViewportSize({ width: 375, height: 812 });
      await page.goto(`${base}/#tideforge/materials`, { waitUntil: 'networkidle' });
      const title = page.getByRole('heading', { name: 'Tideforge', level: 2, exact: true });
      assert.equal(await title.count(), 1);
      const box = await title.boundingBox(); assert(box && box.width <= 1 && box.height <= 1);
      async function noOverflow() {
        const [width, scroll] = await page.evaluate(() => [document.documentElement.clientWidth, document.documentElement.scrollWidth]);
        assert(scroll <= width + 1, `horizontal overflow: ${width}/${scroll}`);
      }
      await noOverflow();
      await page.evaluate(() => { location.hash = '#trader/camp'; });
      await page.locator('#shop-panel-camp').waitFor({ state: 'visible' });
      const shop = page.getByRole('heading', { name: 'Shop', level: 2, exact: true });
      const shopBox = await shop.boundingBox(); assert(shopBox && shopBox.width <= 1 && shopBox.height <= 1);
      await noOverflow(); await page.screenshot({ path: new URL('mobile-shop.png', output).pathname.slice(1) });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });
  assert.deepEqual(blocked, []); assert.deepEqual(failed, []); assert.deepEqual(diagnostics, []);
} catch (error) {
  results.push({ name: 'browser suite', status: 'FAIL', error: error.stack || String(error) });
  console.error(error); process.exitCode = 1;
} finally {
  await writeFile(new URL('cost-browser-results.json', output), JSON.stringify({ results, diagnostics, blocked, failed }, null, 2));
  await browser.close();
}
