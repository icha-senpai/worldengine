import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const modulePath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('FISHBOUND_PLAYWRIGHT_MODULE is required');
const { chromium } = await import(pathToFileURL(modulePath).href);
const base = 'http://127.0.0.1:5182';
const output = new URL('../.local/live-state-hardening/', import.meta.url);
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
const results = [], blocked = [], diagnostics = [];
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
  return { ctx, page };
}
async function check(name, run) {
  await run(); results.push({ name, status: 'PASS' }); console.log(`PASS ${name}`);
}
async function choose(page, name, option) {
  await page.getByRole('combobox', { name, exact: true }).click();
  await page.getByRole('listbox', { name, exact: true }).getByRole('option', { name: option, exact: true }).click();
}
async function openQuest(page, name) {
  const group = page.locator('details.chain').filter({ has: page.getByText(name, { exact: true }) });
  if (await group.getAttribute('open') === null) await group.locator('summary').first().click();
  const quest = group.locator('details.quest').filter({ has: page.getByText(name, { exact: true }) });
  if (await quest.getAttribute('open') === null) await quest.locator('summary').first().click();
  return quest;
}
async function review(quest) {
  await quest.getByRole('button', { name: /^(Claim reward|Review delivery)$/ }).click();
  const dialog = quest.page().getByRole('dialog');
  await dialog.waitFor({ state: 'visible' });
  return dialog;
}
async function chooseFirstFish(page) {
  await page.getByRole('combobox', { name: 'Fish to hand over', exact: true }).click();
  await page.getByRole('listbox', { name: 'Fish to hand over', exact: true }).getByRole('option').nth(1).click();
}

try {
  await check('quest previews invalidate on week, cycle, fish and account changes', async () => {
    const { ctx, page } = await context();
    try {
      await page.goto(`${base}/#quests`, { waitUntil: 'networkidle' });
      await page.locator('#quest-tab-quests').waitFor({ state: 'visible' });
      await page.evaluate(() => window.tideforgeFixture.showQuestState());
      await page.locator('#quest-tab-quests').click();
      let quest = await openQuest(page, 'Fish for the Crew');
      let dialog = await review(quest);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.quests = game.quests.map(row => row.questId === 301 ? { ...row, week: row.week + 1n } : row); });
      await dialog.waitFor({ state: 'hidden' });
      await page.getByRole('status').filter({ hasText: 'This quest changed. Review it again.' }).waitFor({ state: 'visible' });
      dialog = await review(quest); await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      quest = await openQuest(page, 'Another Basketful');
      dialog = await review(quest);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.quests = game.quests.map(row => row.questId === 1101 ? { ...row, cycle: row.cycle + 1n } : row); });
      await dialog.waitFor({ state: 'hidden' });
      dialog = await review(quest); await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      quest = await openQuest(page, 'Something to Compare');
      await chooseFirstFish(page); dialog = await review(quest);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.inventory = game.inventory.map(fish => fish.catchId === 1n ? { ...fish, favorite: true } : fish); });
      await dialog.waitFor({ state: 'hidden' });
      await chooseFirstFish(page); dialog = await review(quest);
      await page.evaluate(() => { const game = window.tideforgeFixture; game.inventory = game.inventory.filter(fish => fish.catchId !== 2n); });
      await dialog.waitFor({ state: 'hidden' });
      await chooseFirstFish(page); dialog = await review(quest);
      await page.evaluate(() => window.tideforgeFixture.changeOwner());
      await dialog.waitFor({ state: 'hidden' });
      await chooseFirstFish(page); dialog = await review(quest);
      await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'claimQuest').length), 0);
      quest = await openQuest(page, 'Fish for the Crew');
      dialog = await review(quest);
      await dialog.getByRole('button', { name: 'Confirm reward', exact: true }).click();
      await dialog.waitFor({ state: 'hidden' });
      assert.equal(await page.getByText('This quest changed. Review it again.', { exact: true }).count(), 0);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'claimQuest').length), 1);
      await page.screenshot({ path: new URL('quest.png', output).pathname.slice(1) });
    } finally { await ctx.close(); }
  });

  await check('server time gates previews and ignores browser wall clock skew', async () => {
    const { ctx, page } = await context();
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    try {
      await page.addInitScript(() => {
        const original = Date.now.bind(Date);
        window.setWallClockOffset = offset => { Date.now = () => original() + offset; };
        window.setWallClockOffset(6 * 60 * 60 * 1000);
      });
      await page.route('**/api/time', async route => { await gate; try { await route.continue(); } catch {} });
      await page.goto(`${base}/#tideforge`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Visit shop', exact: true }).click();
      await page.locator('#shop-tab-additional').click();
      await choose(page, 'Gear model', 'River Rod');
      await choose(page, 'Secondary water', 'Whispering River');
      const trigger = page.getByRole('button', { name: 'Preview gear purchase' });
      await trigger.click();
      await page.getByText('Syncing server time…', { exact: true }).waitFor({ state: 'visible' });
      assert.equal(await page.getByRole('dialog').isVisible(), false);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'prepare').length), 0);
      release();
      await page.waitForFunction(() => window.tideforgeFixture.serverNowMs !== null);
      await page.getByText('Syncing server time…', { exact: true }).waitFor({ state: 'hidden' });
      assert.equal(await page.evaluate(() => window.tideforgeFixture.tideforgeError), '');
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'prepare').length), 0);
      assert.match(await page.getByRole('combobox', { name: 'Gear model', exact: true }).innerText(), /River Rod/);
      assert.match(await page.getByRole('combobox', { name: 'Secondary water', exact: true }).innerText(), /Whispering River/);
      assert.equal(await page.evaluate(() => {
        window.tideforgeFixture.tideforgeError = 'Another error';
        document.dispatchEvent(new Event('freeze'));
        return window.tideforgeFixture.serverNowMs;
      }), null);
      await page.evaluate(() => document.dispatchEvent(new Event('resume')));
      await page.waitForFunction(() => window.tideforgeFixture.serverNowMs !== null);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.tideforgeError), 'Another error');
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'prepare').length), 0);
      await page.evaluate(() => { window.tideforgeFixture.tideforgeError = ''; });
      await trigger.click();
      const dialog = page.getByRole('dialog');
      await dialog.waitFor({ state: 'visible' });
      assert.equal(await dialog.getByRole('button', { name: 'Confirm', exact: true }).isDisabled(), false);
      await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      await page.evaluate(() => { window.setWallClockOffset(-6 * 60 * 60 * 1000); window.tideforgeFixture.quoteLifetimeMs = 2000; });
      await trigger.click();
      await dialog.waitFor({ state: 'visible' });
      assert.equal(await dialog.getByRole('button', { name: 'Confirm', exact: true }).isDisabled(), false);
      await dialog.getByText('This preview expired. Open a new preview.').waitFor({ state: 'visible', timeout: 5000 });
      assert.equal(await dialog.getByRole('button', { name: 'Confirm', exact: true }).isDisabled(), true);
      assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'commit').length), 0);
      await page.screenshot({ path: new URL('clock.png', output).pathname.slice(1) });
    } finally { release(); await ctx.close(); }
  });

  for (const failures of [1, 2]) {
    await check(`lazy panel recovers after ${failures} failure${failures === 1 ? '' : 's'} without navigation`, async () => {
      const { ctx, page } = await context();
      try {
        await page.goto(`${base}/lazy.html?failures=${failures}`, { waitUntil: 'networkidle' });
        let navigations = 0;
        page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations++; });
        const originalUrl = page.url();
        await page.getByRole('textbox', { name: 'Other section input' }).fill('kept draft');
        await page.getByRole('alert').getByRole('heading', { name: 'We hit a snag loading this section.' }).waitFor({ state: 'visible' });
        assert.equal(await page.getByRole('button', { name: 'Reload page' }).count(), 0);
        await page.getByRole('button', { name: 'Retry section' }).evaluate(button => { button.click(); button.click(); });
        if (failures === 2) {
          await page.getByRole('button', { name: 'Reload page' }).waitFor({ state: 'visible' });
          await page.getByRole('button', { name: 'Retry section' }).evaluate(button => { button.click(); button.click(); });
        }
        await page.getByRole('status').getByText('Loading...').waitFor({ state: 'visible' });
        assert.equal(await page.evaluate(() => window.lazyFixture.calls()), failures + 1);
        await page.evaluate(() => window.lazyFixture.release());
        await page.getByText('Section loaded successfully.').waitFor({ state: 'visible' });
        assert.equal(await page.getByRole('textbox', { name: 'Other section input' }).inputValue(), 'kept draft');
        assert.equal(page.url(), originalUrl);
        assert.equal(navigations, 0);
      } finally { await ctx.close(); }
    });
  }
  assert.deepEqual(blocked, []);
  assert.deepEqual(diagnostics, []);
} catch (error) {
  results.push({ name: 'live state browser suite', status: 'FAIL', error: error.stack || String(error) });
  console.error(error); process.exitCode = 1;
} finally {
  await writeFile(new URL('browser-results.json', output), JSON.stringify({ results, blocked, diagnostics }, null, 2));
  await browser.close();
}
