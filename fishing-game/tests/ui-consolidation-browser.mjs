import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import catalog from '../content/trader.json' with { type: 'json' };

const modulePath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('FISHBOUND_PLAYWRIGHT_MODULE is required');
const { chromium } = await import(pathToFileURL(modulePath).href);
const base = 'http://127.0.0.1:5182';
const output = new URL('../.local/ui-ux-pass/phase3/', import.meta.url);
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
const results = [], diagnostics = [], blocked = [];
async function context(viewport = { width: 1440, height: 1000 }) {
  const ctx = await browser.newContext({ viewport, hasTouch: viewport.width === 375 });
  await ctx.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith(`${base}/`) || url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
    blocked.push(url); return route.abort();
  });
  const page = await ctx.newPage();
  page.on('pageerror', error => diagnostics.push(error.stack || error.message));
  page.on('console', message => { if (message.type() === 'error') diagnostics.push(message.text()); });
  return { ctx, page };
}
async function check(name, fn) { await fn(); results.push({ name, status: 'PASS' }); console.log(`PASS ${name}`); }
async function choose(page, name, option) {
  await page.getByRole('combobox', { name, exact: true }).click();
  await page.getByRole('listbox', { name, exact: true }).getByRole('option', { name: option, exact: true }).click();
}
async function noOverflow(page) {
  const size = await page.evaluate(() => [document.documentElement.clientWidth, document.documentElement.scrollWidth]);
  assert(size[1] <= size[0] + 1, `horizontal overflow ${size}`);
}
async function validTabPanels(page, name) {
  const tabs = page.getByRole('tablist', { name, exact: true }).getByRole('tab');
  for (let index = 0; index < await tabs.count(); index++) {
    const tab = tabs.nth(index), id = await tab.getAttribute('aria-controls');
    assert(id);
    const panel = page.locator(`#${id}`);
    assert.equal(await panel.count(), 1);
    assert.equal(await panel.getAttribute('role'), 'tabpanel');
    assert.equal(await panel.getAttribute('aria-labelledby'), await tab.getAttribute('id'));
  }
}
async function setListing(page) {
  const listing = catalog.listings.find(row => row.listingId === 20);
  await page.evaluate(({ listing, version }) => {
    window.tideforgeFixture.listings = [{ ...listing, priceCoins: BigInt(listing.priceCoins), catalogVersion: version }];
  }, { listing, version: catalog.version });
}

try {
  await check('unlinked Camp shop offers remain browsable and purchases require linking', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#trader`, { waitUntil: 'networkidle' });
      await setListing(page);
      await page.evaluate(() => { window.tideforgeFixture.player = null; });
      await page.locator('#shop-tab-camp').waitFor({ state: 'visible' });
      await validTabPanels(page, 'Shop sections');
      assert.equal(await page.locator('#shop-tab-camp').getAttribute('aria-selected'), 'true');
      const item = page.getByRole('article', { name: 'Whispering River Licence' });
      await item.waitFor({ state: 'visible' });
      assert.equal(await item.getByRole('button', { name: 'Link Discord to buy' }).isDisabled(), true);
      await page.locator('#shop-tab-additional').click();
      await page.getByText('Link Discord from Camp to access these gear offers.').waitFor({ state: 'visible' });
      await validTabPanels(page, 'Shop sections');
      assert.match(await page.locator('#shop-panel-additional').innerText(), /Link Discord/);
      assert.equal(await page.getByRole('combobox', { name: 'Gear model' }).count(), 0);
      await page.locator('#shop-tab-wandering').click();
      await page.getByText('Link Discord from Camp to access these gear offers.').waitFor({ state: 'visible' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('canonical Gear preserves grid, loadout and shortcuts including inactive fallback', async () => {
    const { ctx, page } = await context({ width: 1440, height: 1200 });
    try {
      await page.goto(`${base}/#inventory`, { waitUntil: 'networkidle' });
      await page.evaluate(() => window.tideforgeFixture.showTackleGear());
      await page.getByRole('button', { name: 'Select all', exact: true }).waitFor({ state: 'visible' });
      await page.getByRole('button', { name: 'Manage gear & bait', exact: true }).click();
      assert.equal(new URL(page.url()).hash, '#tideforge/gear');
      const summary = page.getByText('Equipped gear, upgrades & bait', { exact: true });
      assert.equal(await summary.locator('..').getAttribute('open'), null);
      const columns = await page.locator('#tideforge-panel-gear .cards').evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').length);
      assert.equal(columns, 4);
      assert.equal(await page.locator('#tideforge-panel-gear .card').count(), 7);
      await summary.click();
      const gear = page.getByRole('region', { name: 'Gear', exact: true });
      await gear.waitFor({ state: 'visible' });
      assert.equal(await page.locator('.gear[role=region]').count(), 1);
      await page.getByRole('combobox', { name: 'Switch rod' }).click();
      const options = page.getByRole('listbox', { name: 'Switch rod' });
      assert.match(await options.innerText(), /River Rod.*Item 1/s);
      assert.match(await options.innerText(), /Rod Buddy.*Item 2/s);
      await page.keyboard.press('Escape');
      await page.locator('#tab-waters').click();
      assert.equal(await page.getByRole('combobox', { name: 'Equip an owned rod' }).count(), 0);
      await page.getByRole('button', { name: 'Manage gear & bait', exact: true }).click();
      assert.equal(new URL(page.url()).hash, '#tideforge/gear');
      await page.evaluate(() => window.tideforgeFixture.setAvailability('inactive'));
      await page.getByText('Tideforge is not active on this server yet.').waitFor({ state: 'visible' });
      await gear.waitFor({ state: 'visible' });
      assert.equal(await page.locator('#tideforge-panel-gear .card').count(), 0);
      await page.evaluate(() => window.tideforgeFixture.setAvailability('unavailable'));
      await page.getByText('Tideforge could not load. Retry Tideforge to reconnect this section; your saved gear is unchanged.').waitFor({ state: 'visible' });
      await gear.waitFor({ state: 'visible' });
      await validTabPanels(page, 'Tideforge sections');
      await page.getByRole('button', { name: 'Visit shop', exact: true }).click();
      await setListing(page);
      await page.getByRole('article', { name: 'Whispering River Licence' }).waitFor({ state: 'visible' });
      await page.locator('#shop-tab-additional').click();
      await page.getByText('Tideforge could not load. Retry Tideforge to reconnect this section; your saved gear is unchanged.').waitFor({ state: 'visible' });
      await validTabPanels(page, 'Shop sections');
      assert.match(await page.locator('#shop-panel-additional').innerText(), /Retry Tideforge/);
      assert.equal(await page.getByRole('combobox', { name: 'Gear model' }).count(), 0);
      await page.locator('#shop-tab-camp').click();
      await page.getByRole('article', { name: 'Whispering River Licence' }).waitFor({ state: 'visible' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('canonical Materials includes distinct balances, unknowns and empty toggle', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#inventory`, { waitUntil: 'networkidle' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        const row = (slot, item, quantity) => ({ key: 9n * 4294967296n + BigInt(slot), playerId: 9n, item, quantity });
        game.items = [row(1, 'rusted_tin', 12n), row(2, 'scrap', 13n), row(900, 'raid_supplies', 14n),
          row(800, 'normal_fillets', 9007199254740999n), row(801, 'normal_scales', 22n),
          row(1000, 'raid_fillets_f', 33n), row(1100, 'raid_scales_f', 44n), row(2222, 'internal_secret_key', 55n)];
        game.tideforgeVault = [{ key: 'fixture', playerId: 9n, affixId: 'fortune', count: 66n }];
      });
      await page.getByRole('button', { name: 'Open materials', exact: true }).click();
      const materials = page.getByRole('region', { name: 'Materials', exact: true });
      await materials.waitFor({ state: 'visible' });
      assert.equal(await page.getByRole('region', { name: 'Materials', exact: true }).count(), 1);
      for (const [label, count] of [['Rusted tin', '12'], ['Scrap', '13'], ['Expedition Chum', '14'],
        ['Basic fillets', '9007199254740999'], ['Basic scales', '22'], ['F-grade fillets', '33'],
        ['F-grade scales', '44'], ['Unknown item', '55'], ['Fortune shards', '66']]) {
        const row = materials.locator('.rows p').filter({ has: page.getByText(label, { exact: true }) });
        assert.equal(await row.count(), 1, `${label} must appear once`);
        assert.equal(await row.locator('strong').innerText(), count);
      }
      assert.doesNotMatch(await materials.innerText(), /internal_secret_key/);
      assert.equal(await materials.getByText('D-grade fillets', { exact: true }).count(), 0);
      await materials.getByText('Show empty materials', { exact: true }).click();
      await materials.getByText('D-grade fillets', { exact: true }).waitFor({ state: 'visible' });
      assert.equal(await materials.getByText('D-grade fillets', { exact: true }).count(), 1);
      await materials.getByText('Show empty materials', { exact: true }).click();
      await materials.getByText('D-grade fillets', { exact: true }).waitFor({ state: 'hidden' });
      assert.equal(await materials.getByText('D-grade fillets', { exact: true }).count(), 0);
      await page.evaluate(() => { window.tideforgeFixture.tideforgeDetailsUnavailable = true; });
      await materials.getByText('Affix shard details are unavailable.').waitFor({ state: 'visible' });
      assert.equal(await materials.getByText('Basic fillets', { exact: true }).count(), 1);
      assert.match(await materials.innerText(), /Dredge Marks:\s*20/);
      assert.equal(await materials.getByText('Fortune shards', { exact: true }).count(), 0);
      await page.evaluate(() => { window.tideforgeFixture.tideforgeDetailsUnavailable = false; window.tideforgeFixture.ready = false; });
      await materials.getByText('Loading your materials…').waitFor({ state: 'visible' });
      assert.equal(await materials.getByText('Basic fillets', { exact: true }).count(), 0);
      await page.evaluate(() => { window.tideforgeFixture.ready = true; window.tideforgeFixture.tideforgeLoading = true; });
      await materials.getByText('Loading affix shards…', { exact: true }).waitFor({ state: 'visible' });
      assert.equal(await materials.getByText('Basic fillets', { exact: true }).count(), 1);
      assert.match(await materials.innerText(), /Dredge Marks:\s*20/);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.tideforgeLoading = false; game.tideforgeAvailability = 'inactive'; });
      await materials.getByText('Affix shards are not enabled on this server.').waitFor({ state: 'visible' });
      assert.equal(await materials.getByText('Basic fillets', { exact: true }).count(), 1);
      assert.match(await materials.innerText(), /Dredge Marks:\s*Unavailable/);
      await page.evaluate(() => { window.tideforgeFixture.tideforgeAvailability = 'unavailable'; });
      await materials.getByText('Affix shards are unavailable.').waitFor({ state: 'visible' });
      await materials.getByRole('button', { name: 'Retry Tideforge', exact: true }).waitFor({ state: 'visible' });
      await page.evaluate(() => { window.tideforgeFixture.tideforgeAvailability = 'active'; });
      await materials.getByText('Fortune shards', { exact: true }).waitFor({ state: 'visible' });
    } finally { await ctx.close(); }
  });

  await check('aliases, section history and shared offer picker work without reload', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/?angler=example#tideforge/trader`, { waitUntil: 'networkidle' });
      await page.locator('#shop-tab-additional').waitFor({ state: 'visible' });
      const purchase = page.getByRole('button', { name: 'Preview gear purchase', exact: true });
      assert.equal(await purchase.isDisabled(), true);
      assert.match(await page.locator(`#${await purchase.getAttribute('aria-describedby')}`).innerText(), /Choose an owned gear model/);
      await choose(page, 'Gear model', 'River Rod'); await choose(page, 'Secondary water', 'Whispering River');
      await page.evaluate(() => { const game = window.tideforgeFixture; game.player = { ...game.player, coins: 0n }; });
      assert.match(await page.locator(`#${await purchase.getAttribute('aria-describedby')}`).innerText(), /more coins/);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.player = { ...game.player, coins: 50000n }; });
      await page.locator('#shop-tab-wandering').click();
      assert.equal(new URL(page.url()).hash, '#trader/wandering');
      assert.equal(new URL(page.url()).search, '?angler=example');
      assert.match(await page.getByRole('combobox', { name: 'Gear model' }).innerText(), /River Rod/);
      assert.match(await page.getByRole('combobox', { name: 'Secondary water' }).innerText(), /Whispering River/);
      await page.goBack(); await page.locator('#shop-panel-additional').waitFor({ state: 'visible' });
      assert.equal(new URL(page.url()).hash, '#tideforge/trader');
      await page.goForward(); await page.locator('#shop-panel-wandering').waitFor({ state: 'visible' });
      await page.evaluate(() => { location.hash = '#tideforge/vault'; });
      await page.locator('#tideforge-panel-vault').waitFor({ state: 'visible' });
      assert.equal(await page.locator('#tideforge-tab-vault').innerText(), 'Materials');
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('onboarding counts only actual discoveries and opens canonical gear', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#camp`, { waitUntil: 'networkidle' });
      const steps = page.locator('.next-steps');
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.collection = [{ playerId: game.player.playerId, speciesId: game.species[0].speciesId, count: 0n, rankCounts: Array(10).fill(0n) }];
      });
      await steps.getByText('Catch your first fish with /fish in Discord.').waitFor({ state: 'visible' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.collection = game.collection.map(row => ({ ...row, count: 1n }));
      });
      await steps.getByText('Catch your first fish with /fish in Discord.').waitFor({ state: 'hidden' });
      await steps.getByRole('button', { name: 'Manage gear & bait', exact: true }).click();
      assert.equal(new URL(page.url()).hash, '#tideforge/gear');
      await page.getByText('Equipped gear, upgrades & bait', { exact: true }).waitFor({ state: 'visible' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('offer availability explains server time, marks, connection and pending action', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#trader/wandering`, { waitUntil: 'networkidle' });
      await choose(page, 'Gear model', 'River Rod'); await choose(page, 'Secondary water', 'Whispering River');
      const button = page.getByRole('button', { name: 'Preview trader offer', exact: true });
      async function blockedBy(text) {
        assert.equal(await button.isDisabled(), true);
        const id = await button.getAttribute('aria-describedby');
        assert(id); assert.match(await page.locator(`#${id}`).innerText(), text);
      }
      await page.evaluate(() => { const game = window.tideforgeFixture; game.serverClock.reset(); game.clockRevision++; });
      await blockedBy(/Syncing server time/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture, now = performance.now();
        game.serverClock.synchronize(Date.UTC(2026, 10, 7, 2), now, now); game.clockRevision++;
      });
      await blockedBy(/wandering trader is away/i);
      await page.evaluate(() => {
        const game = window.tideforgeFixture, now = performance.now();
        game.serverClock.synchronize(Date.UTC(2026, 10, 7), now, now); game.clockRevision++;
        game.tideforgeWallet = { ...game.tideforgeWallet, marks: 0n };
      });
      await blockedBy(/more Dredge Marks/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.tideforgeWallet = { ...game.tideforgeWallet, marks: 20n };
        game.ready = false;
      });
      await blockedBy(/connection/);
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.ready = true;
        game.pendingTideforgeCommit = { identity: game.tideforgeIdentity, playerId: game.player.playerId, quote: { nonce: 123n }, fingerprint: 'fixture' };
      });
      await blockedBy(/Check the previous action/);
      await page.getByRole('button', { name: 'Check last action' }).waitFor({ state: 'visible' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('view changes reject open and late quotes; uncertain commit stays recoverable', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#trader/camp`, { waitUntil: 'networkidle' });
      await page.evaluate(() => { location.hash = '#tideforge/processing'; });
      await page.locator('#tideforge-panel-processing').waitFor({ state: 'visible' });
      await page.locator('#tideforge-panel-processing .fish input').first().check();
      await page.getByRole('button', { name: 'Preview processing' }).click();
      await page.getByRole('dialog').waitFor({ state: 'visible' });
      await page.goBack(); await page.locator('#shop-panel-camp').waitFor({ state: 'visible' });
      assert.equal(await page.getByRole('dialog').isVisible(), false);
      await page.goForward(); await page.locator('#tideforge-panel-processing').waitFor({ state: 'visible' });
      await page.locator('#tideforge-panel-processing .fish input').first().check();
      await page.evaluate(() => { window.tideforgeFixture.delayNext = true; });
      await page.getByRole('button', { name: 'Preview processing' }).click();
      await page.goBack(); await page.locator('#shop-panel-camp').waitFor({ state: 'visible' });
      await page.evaluate(() => window.tideforgeFixture.releasePreview());
      await page.waitForTimeout(150);
      assert.equal(await page.getByRole('dialog').isVisible(), false);
      await page.locator('#shop-tab-additional').click();
      await choose(page, 'Gear model', 'River Rod'); await choose(page, 'Secondary water', 'Whispering River');
      await page.evaluate(() => { window.tideforgeFixture.unknownNext = true; });
      await page.getByRole('button', { name: 'Preview gear purchase' }).click();
      await page.getByRole('dialog').getByRole('button', { name: 'Confirm', exact: true }).click();
      await page.getByRole('button', { name: 'Check last action' }).waitFor({ state: 'visible' });
      await page.locator('#tab-tideforge').click();
      await page.getByRole('button', { name: 'Check last action' }).waitFor({ state: 'visible' });
      await page.getByRole('button', { name: 'Visit shop' }).click();
      await page.getByRole('button', { name: 'Check last action' }).waitFor({ state: 'visible' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'commit').length), 1);
      await page.evaluate(() => window.tideforgeFixture.changeOwner());
      assert.equal(await page.getByRole('dialog').isVisible(), false);
    } finally { await ctx.close(); }
  });

  await check('account changes close an open offer confirmation', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#trader/additional`, { waitUntil: 'networkidle' });
      await choose(page, 'Gear model', 'River Rod'); await choose(page, 'Secondary water', 'Whispering River');
      await page.getByRole('button', { name: 'Preview gear purchase' }).click();
      await page.getByRole('dialog').waitFor({ state: 'visible' });
      await page.evaluate(() => window.tideforgeFixture.changeOwner());
      await page.getByRole('dialog').waitFor({ state: 'hidden' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'commit').length), 0);
    } finally { await ctx.close(); }
  });

  await check('account changes close an open equipped-gear upgrade confirmation', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#tideforge/gear`, { waitUntil: 'networkidle' });
      await page.evaluate(() => window.tideforgeFixture.showTackleGear());
      await page.getByText('Equipped gear, upgrades & bait', { exact: true }).click();
      const rod = page.getByRole('article', { name: 'Equipped rod', exact: true });
      await rod.getByRole('button', { name: 'Upgrade', exact: true }).click();
      const dialog = page.getByRole('dialog');
      await dialog.getByRole('heading', { name: 'Confirm rod upgrade', exact: true }).waitFor({ state: 'visible' });
      await page.evaluate(() => window.tideforgeFixture.changeOwner());
      await dialog.waitFor({ state: 'hidden' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.some(row => /commit|reinforce/i.test(row.action))), false);
    } finally { await ctx.close(); }
  });

  await check('mobile Shop, Materials and equipped loadout fit the viewport', async () => {
    const { ctx, page } = await context({ width: 375, height: 812 });
    try {
      await page.goto(`${base}/#trader/additional`, { waitUntil: 'networkidle' });
      await page.locator('#shop-panel-additional').waitFor({ state: 'visible' });
      await noOverflow(page); await page.screenshot({ path: new URL('mobile-shop.png', output).pathname.slice(1) });
      await page.locator('#shop-tab-wandering').click(); await noOverflow(page);
      await page.locator('#shop-tab-camp').click(); await noOverflow(page);
      await page.evaluate(() => { location.hash = '#tideforge/materials'; });
      await page.locator('#tideforge-panel-vault').waitFor({ state: 'visible' });
      await noOverflow(page); await page.screenshot({ path: new URL('mobile-materials.png', output).pathname.slice(1) });
      await page.locator('#tideforge-tab-gear').click();
      await page.getByText('Equipped gear, upgrades & bait', { exact: true }).click();
      await page.getByRole('region', { name: 'Gear', exact: true }).waitFor({ state: 'visible' });
      await noOverflow(page); await page.screenshot({ path: new URL('mobile-loadout.png', output).pathname.slice(1) });
    } finally { await ctx.close(); }
  });
  assert.deepEqual(blocked, []); assert.deepEqual(diagnostics, []);
} catch (error) {
  results.push({ name: 'browser suite', status: 'FAIL', error: error.stack || String(error) });
  console.error(error); process.exitCode = 1;
} finally {
  await writeFile(new URL('browser-results.json', output), JSON.stringify({ results, diagnostics, blocked }, null, 2));
  await browser.close();
}
