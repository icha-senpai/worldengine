import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const modulePath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('FISHBOUND_PLAYWRIGHT_MODULE is required');
const { chromium } = await import(pathToFileURL(modulePath).href);
const fixture = 'http://127.0.0.1:5182';
const output = new URL('../.local/ui-ux-pass/phase2/', import.meta.url);
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
const results = [], diagnostics = [], blocked = [], failed = [];

async function context(base, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, hasTouch: viewport.width === 375, ignoreHTTPSErrors: true });
  await ctx.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith(`${base}/`) || url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
    blocked.push(url);
    return route.abort();
  });
  const page = await ctx.newPage();
  page.on('pageerror', error => diagnostics.push(error.stack || error.message));
  page.on('console', message => { if (message.type() === 'error') diagnostics.push(message.text()); });
  page.on('requestfailed', request => failed.push(`${request.url()}: ${request.failure()?.errorText}`));
  return { ctx, page };
}
async function check(name, fn) {
  await fn(); results.push({ name, status: 'PASS' }); console.log(`PASS ${name}`);
}
async function inViewport(locator, viewport) {
  const box = await locator.boundingBox();
  assert(box && box.y >= 0 && box.y + box.height <= viewport.height, `not in first viewport: ${JSON.stringify(box)}`);
  return box;
}
async function noOverflow(page) {
  const size = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  assert(size.scroll <= size.width + 1, `horizontal overflow: ${JSON.stringify(size)}`);
}

try {
  await check('unlinked fixture shows Discord and linking before the pond on the first mobile screen', async () => {
    const viewport = { width: 375, height: 812 };
    const { ctx, page } = await context(fixture, viewport);
    try {
      const response = await page.goto(`${fixture}/#camp`, { waitUntil: 'networkidle' });
      assert.equal(response.status(), 200);
      await page.evaluate(() => { window.tideforgeFixture.player = null; });
      await page.locator('.connection.live').waitFor({ timeout: 20000 });
      const discord = page.getByRole('link', { name: 'Open Discord', exact: true });
      const link = page.getByRole('button', { name: 'Link Discord account', exact: true });
      const discordBox = await inViewport(discord, viewport);
      const linkBox = await inViewport(link, viewport);
      const pondBox = await page.locator('.camp-banner .scene').boundingBox();
      assert(pondBox && discordBox.y < linkBox.y && linkBox.y + linkBox.height < pondBox.y);
      const destination = new URL(await discord.getAttribute('href'));
      assert.equal(destination.protocol, 'https:');
      assert(['discord.com', 'discord.gg'].includes(destination.hostname));
      assert.equal(destination.username, ''); assert.equal(destination.password, '');
      await page.locator('.link-reason').waitFor({ state: 'visible' });
      assert.match(await page.locator('.camp-intro').innerText(), /Open a server where Fishbound is available and use \/fish/);
      await noOverflow(page);
      await page.screenshot({ path: new URL('fixture-mobile-camp.png', output).pathname.slice(1) });
      await page.getByRole('button', { name: 'Link account', exact: true }).click();
      if (await link.isEnabled()) {
        await page.waitForFunction(() => document.activeElement?.textContent === 'Link Discord account');
      } else {
        await page.waitForFunction(() => document.activeElement?.classList.contains('link-reason'));
      }
      assert.equal(new URL(page.url()).hash, '#camp');
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('unlinked desktop disabled tabs expose keyboard help and link notice only focuses', async () => {
    const { ctx, page } = await context(fixture, { width: 1440, height: 1000 });
    try {
      await page.goto(`${fixture}/#camp`, { waitUntil: 'networkidle' });
      await page.evaluate(() => { window.tideforgeFixture.player = null; });
      const group = page.getByRole('group', { name: 'Quests: Link Discord to open this view', exact: true });
      const disabled = group.getByRole('tab', { name: 'Quests', exact: true });
      assert.equal(await disabled.isDisabled(), true);
      assert.equal(await group.getAttribute('tabindex'), '0');
      assert.match(await group.getAttribute('aria-description'), /Link Discord/);
      await page.mouse.move(0, 0); await group.focus();
      const tip = page.getByRole('tooltip');
      await tip.waitFor({ state: 'visible' }); assert.match(await tip.innerText(), /Link Discord/);
      await page.keyboard.press('Escape'); await tip.waitFor({ state: 'hidden' });
      await page.locator('#tab-camp').focus(); await page.keyboard.press('ArrowRight');
      assert.equal(await page.locator('#tab-trader').getAttribute('aria-selected'), 'true');
      await page.getByRole('button', { name: 'Link account', exact: true }).click();
      await page.waitForFunction(() => location.hash === '#camp' && document.activeElement?.classList.contains('link-reason'));
      assert.equal(await page.getByRole('button', { name: 'Link Discord account', exact: true }).isDisabled(), true);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('unlinked mobile disabled navigation has touch and keyboard help', async () => {
    const { ctx, page } = await context(fixture, { width: 375, height: 812 });
    try {
      await page.goto(`${fixture}/#camp`, { waitUntil: 'networkidle' });
      await page.evaluate(() => { window.tideforgeFixture.player = null; });
      await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
      const group = page.locator('#mobile-navigation').getByRole('group', { name: 'Tackle box: Link Discord to open this view', exact: true });
      assert.equal(await group.getAttribute('tabindex'), '0');
      assert.equal(await group.getByRole('button', { name: 'Tackle box', exact: true }).isDisabled(), true);
      await group.tap();
      const tip = page.getByRole('tooltip'); await tip.waitFor({ state: 'visible' });
      assert.match(await tip.innerText(), /Link Discord/);
      await page.keyboard.press('Escape'); await tip.waitFor({ state: 'hidden' });
      await page.getByRole('button', { name: 'Close navigation', exact: true }).focus();
      await group.focus(); await tip.waitFor({ state: 'visible' });
      await page.keyboard.press('Escape'); await tip.waitFor({ state: 'hidden' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('linked mobile opens story quests first, with compact live progress and working raid navigation', async () => {
    const viewport = { width: 375, height: 812 };
    const { ctx, page } = await context(fixture, viewport);
    let clockRequests = 0;
    page.on('request', request => { if (new URL(request.url()).pathname === '/api/time') clockRequests++; });
    try {
      await page.goto(`${fixture}/#quests`, { waitUntil: 'networkidle' });
      await page.locator('#quest-tab-quests').waitFor({ state: 'visible' });
      assert.equal(await page.locator('#quest-tab-quests').getAttribute('aria-selected'), 'true');
      assert.deepEqual(await page.locator('[aria-label="Quest sections"] [role="tab"]').allTextContents(),
        ['Quests', 'Dailies', 'Raids', 'Community Expeditions']);
      assert.equal(await page.locator('#quest-panel-quests').isVisible(), true);
      assert.match(await page.locator('#quest-panel-quests').innerText(), /quest board is being prepared/i);
      await inViewport(page.getByRole('heading', { name: 'Quests', exact: true, level: 1 }), viewport);
      await inViewport(page.locator('#quest-panel-quests h2').first(), viewport);
      assert.equal(await page.locator('.camp-banner .scene').count(), 0);
      const details = page.locator('.progress-details');
      assert.equal(await details.getAttribute('open'), null);
      assert.match(await details.locator('summary').innerText(), /Your camp.*50000 coins.*Next cast/);
      assert.equal(await page.locator('.stats').isVisible(), false);
      assert.equal(await page.locator('.stats').count(), 1);
      await noOverflow(page);
      await page.screenshot({ path: new URL('fixture-mobile-quests.png', output).pathname.slice(1) });
      const initialRequests = clockRequests;
      await details.locator('summary').click();
      await page.locator('.stats').waitFor({ state: 'visible' });
      assert.equal(await page.locator('.stats>div').first().locator('strong').innerText(), '1');
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.player = { ...game.player, coins: 23456n };
      });
      await page.getByText('23456', { exact: true }).waitFor({ state: 'visible' });
      assert.match(await details.locator('summary').innerText(), /23456 coins/);
      await details.locator('summary').click();
      await page.locator('.stats').waitFor({ state: 'hidden' });
      await page.waitForTimeout(250);
      assert.equal(clockRequests, initialRequests, 'expanding progress must not restart clock polling');
      await page.locator('#quest-tab-raids').click();
      assert.equal(await page.locator('#raid-tab-active').getAttribute('aria-selected'), 'true');
      await page.locator('#quest-tab-community').click();
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        const timestamp = { microsSinceUnixEpoch: BigInt(Date.now()) * 1000n };
        game.raidAttempts = [{ id: 1n, playerId: game.player.playerId, kind: 'community', status: 'prepared',
          preparedAt: timestamp, startsAt: timestamp, endsAt: timestamp, deploymentDeadline: timestamp, boatName: 'Fixture boat' }];
      });
      await page.getByRole('button', { name: 'View active expedition', exact: true }).click();
      assert.equal(await page.locator('#quest-tab-raids').getAttribute('aria-selected'), 'true');
      assert.equal(await page.locator('#raid-tab-active').getAttribute('aria-selected'), 'true');
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });

  await check('linked next steps react to subscribed facts and account ownership', async () => {
    const { ctx, page } = await context(fixture, { width: 1440, height: 1000 });
    try {
      await page.goto(`${fixture}/#camp`, { waitUntil: 'networkidle' });
      const steps = page.locator('.next-steps');
      await steps.getByText('Catch your first fish with /fish in Discord.').waitFor({ state: 'visible' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.collection = [{ playerId: game.player.playerId, speciesId: game.species[0].speciesId, count: 0n, rankCounts: Array(10).fill(0n) }];
      });
      await steps.getByText('Catch your first fish with /fish in Discord.').waitFor({ state: 'visible' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.collection = [{ playerId: game.player.playerId, speciesId: game.species[0].speciesId, count: 1n, rankCounts: Array(10).fill(0n) }];
      });
      await steps.getByText('Catch your first fish with /fish in Discord.').waitFor({ state: 'hidden' });
      await steps.getByText('Favorite a keeper in your tackle box.').waitFor({ state: 'visible' });
      await page.evaluate(() => {
        const game = window.tideforgeFixture;
        game.inventory = game.inventory.map((fish, index) => index ? fish : { ...fish, favorite: true });
      });
      await steps.getByText('Favorite a keeper in your tackle box.').waitFor({ state: 'hidden' });
      assert.match(await steps.innerText(), /Review your gear and bait/);
      await steps.getByRole('button', { name: 'Manage gear & bait', exact: true }).click();
      assert.equal(new URL(page.url()).hash, '#tideforge/gear');
      await page.getByText('Equipped gear, upgrades & bait', { exact: true }).waitFor({ state: 'visible' });
      await page.locator('#tab-camp').click();
      await page.evaluate(() => window.tideforgeFixture.changeOwner());
      await steps.getByText('Catch your first fish with /fish in Discord.').waitFor({ state: 'visible' });
      const signOut = page.getByRole('button', { name: 'Sign out of this browser', exact: true });
      assert.equal(await signOut.count(), 1);
      assert.match(await signOut.getAttribute('aria-description'), /Discord account and fishing progress stay saved/);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.length), 0);
    } finally { await ctx.close(); }
  });
  assert.deepEqual(blocked, [], 'unexpected external requests');
  assert.deepEqual(failed, [], 'failed requests');
  assert.deepEqual(diagnostics, [], 'browser errors');
} catch (error) {
  results.push({ name: 'browser suite', status: 'FAIL', error: error.stack || String(error) });
  console.error(error);
  process.exitCode = 1;
} finally {
  await writeFile(new URL('browser-results.json', output), JSON.stringify({ results, diagnostics, blocked, failed }, null, 2));
  await browser.close();
}
