import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve, sep } from 'node:path';
import { pathToFileURL } from 'node:url';

const modulePath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('FISHBOUND_PLAYWRIGHT_MODULE is required');
const { chromium } = await import(pathToFileURL(modulePath).href);
const base = 'http://127.0.0.1:5182';
const artifactRoot = process.env.FISHBOUND_TIDEFORGE_ARTIFACT_ROOT
  ? pathToFileURL(resolve(process.env.FISHBOUND_TIDEFORGE_ARTIFACT_ROOT) + sep) : null;
const artifactDirectory = name => artifactRoot ? new URL(`${name}/`, artifactRoot) : new URL(`../.local/${name}/`, import.meta.url);
const output = artifactDirectory('tideforge-language');
const renameOutput = artifactDirectory('gear-renaming');
const layoutOutput = artifactDirectory('tideforge-gear-layout');
const basicArtOutput = artifactDirectory('tideforge-basic-art');
const retireOutput = artifactDirectory('retire-raid-workshop');
const processingOutput = artifactDirectory('tideforge-processing-ui');
const tackleOutput = artifactDirectory('tackle-gear-ui');
const orderOutput = artifactDirectory('tideforge-gear-order');
const outputSuffix = process.env.FISHBOUND_TIDEFORGE_RESULT_SUFFIX ?? '';
await mkdir(output, { recursive: true });
await mkdir(renameOutput, { recursive: true });
await mkdir(layoutOutput, { recursive: true });
await mkdir(basicArtOutput, { recursive: true });
await mkdir(retireOutput, { recursive: true });
await mkdir(processingOutput, { recursive: true });
await mkdir(tackleOutput, { recursive: true });
await mkdir(orderOutput, { recursive: true });
const results = [], diagnostics = [], blocked = [];
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
async function context(viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, hasTouch: viewport.width === 375 });
  await ctx.route('**/*', route => {
    const url = route.request().url();
    if (url.startsWith(`${base}/`) || url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
    blocked.push(url);
    return route.abort();
  });
  const page = await ctx.newPage();
  page.on('pageerror', error => diagnostics.push(`pageerror: ${error.stack || error.message}`));
  page.on('console', message => { if (message.type() === 'error') diagnostics.push(`console: ${message.text()}`); });
  return { ctx, page };
}
async function check(name, fn) {
  await fn();
  results.push({ name, status: 'PASS' });
  console.log(`PASS ${name}`);
}
async function choose(page, label, option) {
  await page.getByRole('combobox', { name: label, exact: true }).click();
  await assertPlayerLanguage(page.getByRole('listbox', { name: label, exact: true }));
  await page.getByRole('listbox', { name: label, exact: true }).getByRole('option', { name: option, exact: true }).click();
}
async function tab(page, id) {
  if (id === 'trader') {
    if (!await page.locator('#shop-tab-additional').isVisible()) await page.getByRole('button', { name: 'Visit shop', exact: true }).click();
    await page.locator('#shop-tab-additional').click();
    await page.locator('#shop-panel-additional').waitFor({ state: 'visible' });
    return;
  }
  if (!await page.locator(`#tideforge-tab-${id}`).isVisible()) {
    if (await page.locator('#tab-tideforge').isVisible()) await page.locator('#tab-tideforge').click();
    else {
      await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
      await page.locator('#mobile-navigation').getByRole('button', { name: 'Tideforge', exact: true }).click();
    }
  }
  await page.locator(`#tideforge-tab-${id}`).click();
  await page.locator(`#tideforge-panel-${id}`).waitFor({ state: 'visible' });
}
async function noOverflow(page) {
  const size = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  assert(size.scroll <= size.width + 1, `horizontal overflow: ${JSON.stringify(size)}`);
}
async function assertInfoButtons(locator, minSize = 32) {
  await locator.page().mouse.move(0, 0);
  const buttons = await locator.locator('.info-button:visible').evaluateAll(nodes => nodes.map(node => {
    const style = getComputedStyle(node);
    const rect = node.getBoundingClientRect();
    return {
      label: node.getAttribute('aria-label'), description: node.getAttribute('aria-description'),
      text: node.textContent, svg: node.querySelectorAll('svg').length,
      decorative: node.querySelectorAll('svg[aria-hidden="true"][focusable="false"]').length,
      circles: node.querySelectorAll('svg circle').length,
      borders: [style.borderTopWidth, style.borderRightWidth, style.borderBottomWidth, style.borderLeftWidth],
      background: style.backgroundColor, width: rect.width, height: rect.height,
    };
  }));
  assert(buttons.length > 0, 'expected visible info buttons');
  for (const button of buttons) {
    assert(button.label?.trim(), 'info button needs an accessible label');
    assert(button.description?.trim(), 'info button needs an accessible description');
    assert.doesNotMatch(button.text, /ⓘ/);
    assert.equal(button.svg, 1); assert.equal(button.decorative, 1); assert.equal(button.circles, 1);
    assert.deepEqual(button.borders, ['0px', '0px', '0px', '0px']);
    assert.equal(button.background, 'rgba(0, 0, 0, 0)');
    assert(button.width >= minSize && button.height >= minSize, `info button too small: ${JSON.stringify(button)}`);
  }
}
async function captureInfo(page, button, name) {
  if (outputSuffix) return;
  const box = await button.boundingBox();
  const viewport = page.viewportSize();
  assert(box && viewport);
  const x = Math.max(0, box.x - 90), y = Math.max(0, box.y - 45);
  await page.screenshot({ path: new URL(name, output).pathname.slice(1), clip: {
    x, y, width: Math.min(viewport.width - x, box.width + 180), height: Math.min(viewport.height - y, box.height + 90),
  } });
}
async function assertPlayerLanguage(locator) {
  const text = await locator.evaluate(node => {
    const visible = el => !!(el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden');
    return [node.innerText, ...[node, ...node.querySelectorAll('*')]
      .filter(visible).flatMap(el => [el.getAttribute('aria-label') ?? '', el.getAttribute('aria-description') ?? '', el.getAttribute('title') ?? ''])].join('\n');
  });
  assertPlayerText(text);
}
function assertPlayerText(text) {
  assert.doesNotMatch(text, /\b(?:protected original|family mastery|booked gear|native signature|instance_id|definition_id|nonce|receipt|revision|[A-Z]+_[A-Z_]+|(?:normal|raid)_(?:fillets|scales)_[a-z]+|normal_(?:fillets|scales)|copy\s+\d+|originals?\s+or\s+copies)\b/i);
}

try {
  const desktop = await context({ width: 1440, height: 900 });
  const page = desktop.page;
  await page.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await page.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  await check('Gear info buttons are accessible single-circle icons', async () => {
    const gear = page.locator('#tideforge-panel-gear');
    await assertInfoButtons(gear);
    for (const name of ['About collection goals', 'About mastery', 'About Fortune', 'About Echoed Line']) {
      assert(await gear.getByRole('button', { name, exact: true }).count() > 0, name);
    }
  });
  await check('protected help and consistent duplicate gear labels', async () => {
    await page.getByRole('heading', { name: 'River Rod · Item 1', exact: true }).waitFor({ state: 'visible' });
    await page.getByRole('heading', { name: 'River Rod · Item 2', exact: true }).waitFor({ state: 'visible' });
    const protectionHelp = page.getByRole('button', { name: 'Why River Rod · Item 1 is protected' });
    await protectionHelp.scrollIntoViewIfNeeded();
    await page.waitForTimeout(150);
    await protectionHelp.focus();
    const tooltip = page.getByRole('tooltip');
    await tooltip.waitFor({ state: 'visible' });
    const tooltipText = await protectionHelp.getAttribute('aria-description');
    assert.match(tooltipText, /kept safe.*cannot be dismantled/);
    assertPlayerText(tooltipText);
    await tab(page, 'forge');
    await choose(page, 'Forge gear', 'River Rod · Item 1');
    assert.match(await page.getByRole('combobox', { name: 'Forge gear' }).innerText(), /River Rod · Item 1/);
    const forge = page.locator('#tideforge-panel-forge');
    await assertInfoButtons(forge);
    assert.equal(await forge.getByRole('button', { name: 'About mastery', exact: true }).count(), 1);
    assert.equal(await forge.getByRole('button', { name: 'About Fortune', exact: true }).count(), 1);
    await tab(page, 'gear');
  });
  await check('unknown labels and actual error translation stay player-safe', async () => {
    await page.evaluate(() => {
      const game = window.tideforgeFixture;
      game.showUnknownLanguage();
      game.tideforgeVault = game.tideforgeVault.map(row => ({ ...row, playerId: game.player.playerId }));
    });
    await page.getByText('Unknown affix').first().waitFor({ state: 'visible' });
    await page.getByText('Unknown signature').first().waitFor({ state: 'visible' });
    await page.getByRole('alert').getByText('This action could not be completed. Check your gear and materials, then try again.').waitFor({ state: 'visible' });
    await assertPlayerLanguage(page.locator('.tideforge'));
    await tab(page, 'vault');
    await page.locator('#tideforge-panel-vault').getByText('Unknown affix').waitFor({ state: 'visible' });
    await assertPlayerLanguage(page.locator('#tideforge-panel-vault'));
    await page.evaluate(() => window.tideforgeFixture.clearUnknownLanguage());
    await tab(page, 'gear');
  });
  await check('removal and fish previews use clear targets and safe resource names', async () => {
    await page.evaluate(() => {
      window.tideforgeFixture.gearLoadout = { ...window.tideforgeFixture.gearLoadout, rodInstanceId: 100n };
      window.tideforgeFixture.unknownPreview = true;
    });
    const card = page.locator('#tideforge-panel-gear .card').filter({ has: page.getByRole('heading', { name: 'River Rod · Item 2', exact: true }) });
    await card.getByRole('button', { name: 'Dismantle' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor({ state: 'visible' });
    await assertInfoButtons(dialog);
    assert.equal(await dialog.getByRole('button', { name: 'About affix strength', exact: true }).count(), 1);
    assert.match(await dialog.innerText(), /River Rod · Item 2/);
    assert.match(await dialog.innerText(), /This item will be permanently removed/);
    assert.match(await dialog.innerText(), /Past upgrade costs are not refunded/);
    assert.match(await dialog.innerText(), /Unknown affix/);
    assert.match(await dialog.innerText(), /Unknown signature/);
    assert.match(await dialog.innerText(), /Unknown water/);
    assert.match(await dialog.innerText(), /Unknown material/);
    await assertPlayerLanguage(dialog);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await page.evaluate(() => { window.tideforgeFixture.unknownPreview = false; window.tideforgeFixture.tideforgeLibrary = []; });
    await tab(page, 'reliquary');
    assert.match(await page.locator('#tideforge-panel-reliquary').innerText(), /permanently removes the selected item/);
    await choose(page, 'Gear to extract from', 'River Rod · Item 2 · Echoed Line');
    await page.getByRole('button', { name: 'Preview extraction' }).click();
    await dialog.waitFor({ state: 'visible' });
    assert.match(await dialog.innerText(), /River Rod · Item 2/);
    assert.match(await dialog.innerText(), /This item will be permanently removed/);
    assert.match(await dialog.innerText(), /Learn signature: Echoed Line/);
    await assertPlayerLanguage(dialog);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await page.evaluate(() => {
      window.tideforgeFixture.gearLoadout = { ...window.tideforgeFixture.gearLoadout, rodInstanceId: 101n };
      window.tideforgeFixture.tideforgeLibrary = [{ playerId: 9n, signatureId: 'echoed_line' }];
    });
    await tab(page, 'processing');
    await page.evaluate(() => window.tideforgeFixture.showFishProtections());
    const fish = page.locator('#tideforge-panel-processing .fish label');
    assert.match(await fish.nth(1).innerText(), /Favorite/);
    assert.match(await fish.nth(2).innerText(), /Bonus fish: protected/);
    assert.match(await fish.nth(3).innerText(), /Protected: fish details unavailable/);
    assert.match(await fish.nth(4).innerText(), /Rank maximum or perfect catch/);
    assert.equal(await fish.nth(1).locator('input').isDisabled(), true);
    assert.equal(await fish.nth(2).locator('input').isDisabled(), true);
    assert.equal(await fish.nth(3).locator('input').isDisabled(), true);
    assert.equal(await fish.nth(4).locator('input').isDisabled(), true);
    await page.getByRole('checkbox', { name: 'Include rank maxima and perfect catches' }).check();
    assert.equal(await fish.nth(4).locator('input').isDisabled(), false);
    assert.equal(await fish.nth(1).locator('input').isDisabled(), true);
    assert.equal(await fish.nth(3).locator('input').isDisabled(), true);
    await page.getByRole('checkbox', { name: 'Include rank maxima and perfect catches' }).uncheck();
    await page.evaluate(() => window.tideforgeFixture.clearFishProtections());
    const speciesName = await page.evaluate(() => window.tideforgeFixture.species[0].name);
    const firstFish = await page.locator('#tideforge-panel-processing .fish label').first().innerText();
    assert(firstFish.includes(speciesName));
    assert.match(firstFish, /Ready to fillet/);
    assert.doesNotMatch(firstFish, /\bCatch\s+\d+|#\d+/);
    await page.locator('#tideforge-panel-processing .fish input').first().check();
    await page.getByRole('button', { name: 'Preview processing' }).click();
    await dialog.waitFor({ state: 'visible' });
    assert((await dialog.innerText()).includes(speciesName));
    assert.match(await dialog.innerText(), /\bF\b.*\d+\.\d+ in.*oz/s);
    assert.doesNotMatch(await dialog.innerText(), /\bCatch\s+\d+|#\d+/);
    assert.match(await dialog.innerText(), /Basic fillets/);
    assert.match(await dialog.innerText(), /Sale value you give up/);
    await assertPlayerLanguage(dialog);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await page.locator('#tideforge-panel-processing .fish input').first().uncheck();
    await tab(page, 'gear');
  });
  await check('desktop navigation, retained search, Tackle Box, keyboard tabs', async () => {
    await page.getByRole('searchbox', { name: 'Find Tideforge gear' }).fill('River');
    await page.locator('#tab-inventory').click();
    await page.getByRole('button', { name: 'Select all', exact: true }).waitFor({ state: 'visible' });
    await page.getByRole('button', { name: 'Manage gear & bait', exact: true }).click();
    await page.getByText('Equipped gear, upgrades & bait', { exact: true }).click();
    assert.match(await page.getByRole('region', { name: 'Gear' }).innerText(), /Base equipment and bait stats/);
    assert.equal(await page.getByRole('searchbox', { name: 'Find Tideforge gear' }).inputValue(), 'River');
    await page.locator('#tideforge-tab-gear').focus();
    await page.keyboard.press('ArrowRight');
    assert.equal(await page.locator('#tideforge-tab-forge').getAttribute('aria-selected'), 'true');
    await tab(page, 'gear');
    const goalHelp = page.getByRole('button', { name: 'About collection goals' });
    await goalHelp.focus();
    await page.getByRole('tooltip').waitFor({ state: 'visible' });
    assertPlayerText(await goalHelp.getAttribute('aria-description'));
    await goalHelp.hover();
    await captureInfo(page, goalHelp, 'info-desktop-focus.png');
    await noOverflow(page);
  });
  await check('focused help survives auto-scroll but closes offscreen and after Escape or hover scroll', async () => {
    const goalHelp = page.getByRole('button', { name: 'About collection goals' });
    const tooltip = page.getByRole('tooltip');
    const isOutsideViewport = () => goalHelp.evaluate(el => {
      const rect = el.getBoundingClientRect();
      return rect.bottom <= 0 || rect.top >= window.innerHeight || rect.right <= 0 || rect.left >= window.innerWidth;
    });

    await goalHelp.evaluate(el => el.blur());
    await page.mouse.move(0, 0);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(150);
    assert.equal(await isOutsideViewport(), true, 'focus starts with the anchor outside the viewport');
    await goalHelp.focus();
    await page.waitForTimeout(150);
    assert.equal(await goalHelp.evaluate(el => document.activeElement === el), true);
    await tooltip.waitFor({ state: 'visible' });

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(150);
    assert.equal(await isOutsideViewport(), true, 'scroll moves the focused anchor outside the viewport');
    await tooltip.waitFor({ state: 'hidden' });

    await goalHelp.evaluate(el => el.blur());
    await goalHelp.scrollIntoViewIfNeeded();
    await page.waitForTimeout(150);
    await goalHelp.focus();
    await tooltip.waitFor({ state: 'visible' });
    await goalHelp.press('Escape');
    await tooltip.waitFor({ state: 'hidden' });
    await page.evaluate(() => window.dispatchEvent(new Event('scroll')));
    assert.equal(await tooltip.isVisible(), false);

    await goalHelp.evaluate(el => el.blur());
    await page.mouse.move(0, 0);
    await goalHelp.hover();
    await tooltip.waitFor({ state: 'visible' });
    await page.evaluate(() => window.dispatchEvent(new Event('scroll')));
    await tooltip.waitFor({ state: 'hidden' });
  });
  if (!outputSuffix) await page.screenshot({ path: new URL('desktop-gear.png', output).pathname.slice(1) });
  await check('all Tideforge sections and both gear shop offers', async () => {
    for (const id of ['gear', 'forge', 'processing', 'vault', 'codex', 'reliquary']) {
      await tab(page, id);
      await assertPlayerLanguage(page.locator(`#tideforge-panel-${id}`));
    }
    await tab(page, 'trader');
    const trader = page.locator('#shop-panel-additional');
    await assertPlayerLanguage(trader); await assertInfoButtons(trader);
    assert.equal(await trader.getByRole('button', { name: 'About secondary water', exact: true }).count(), 1);
    await page.locator('#shop-tab-wandering').click();
    await assertPlayerLanguage(page.locator('#shop-panel-wandering'));
  });
  await page.getByRole('button', { name: 'Dark mode' }).click();
  await tab(page, 'forge');
  await choose(page, 'Forge gear', 'River Rod · Item 1');
  if (!outputSuffix) await page.screenshot({ path: new URL('desktop-forge-dark.png', output).pathname.slice(1) });
  await check('fully refined and tier-ten Forge actions are blocked with explanations', async () => {
    await page.evaluate(() => window.tideforgeFixture.setOriginalRoll(10, 10000));
    await choose(page, 'Forge action', 'Improve strength');
    await page.getByText('This affix strength is already fully improved.').waitFor({ state: 'visible' });
    assert.equal(await page.getByRole('button', { name: 'Preview Forge action' }).isDisabled(), true);
    await choose(page, 'Forge action', 'Upgrade tier');
    await page.getByText('This affix is already at tier 10; there is no higher tier.').waitFor({ state: 'visible' });
    assert.equal(await page.getByRole('button', { name: 'Preview Forge action' }).isDisabled(), true);
    await page.evaluate(() => window.tideforgeFixture.setOriginalRoll(2, 5000));
  });
  await check('purchase preview target, costs, cancel, Escape and focus', async () => {
    await tab(page, 'trader');
    await choose(page, 'Gear model', 'River Rod');
    await choose(page, 'Secondary water', 'Whispering River');
    const trigger = page.getByRole('button', { name: 'Preview gear purchase' });
    await trigger.click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor({ state: 'visible' });
    const text = await dialog.innerText();
    await assertPlayerLanguage(dialog);
    assert.match(text, /River Rod/); assert.match(text, /Whispering River/);
    assert.match(text, /Costs/); assert.match(text, /500 coins/); assert.match(text, /Receive/);
    assert.equal(await dialog.getByRole('button', { name: 'Cancel' }).evaluate(el => document.activeElement === el), true);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(await trigger.evaluate(el => document.activeElement === el), true);
    await trigger.click(); await dialog.waitFor({ state: 'visible' });
    await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'hidden' });
  });
  await check('expired quote disables confirmation without committing', async () => {
    await page.evaluate(() => { window.tideforgeFixture.quoteLifetimeMs = 2000; });
    const commits = await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'commit').length);
    await page.getByRole('button', { name: 'Preview gear purchase' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor({ state: 'visible' });
    await dialog.getByText('This preview expired. Open a new preview.').waitFor({ state: 'visible', timeout: 5000 });
    await assertPlayerLanguage(dialog);
    assert.equal(await dialog.getByRole('button', { name: 'Confirm', exact: true }).isDisabled(), true);
    assert.equal(await page.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'commit').length), commits);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await page.evaluate(() => { window.tideforgeFixture.quoteLifetimeMs = 120000; });
  });
  await check('stale water and target previews never open', async () => {
    await page.evaluate(() => { window.tideforgeFixture.delayNext = true; });
    await page.getByRole('button', { name: 'Preview gear purchase' }).click();
    await choose(page, 'Secondary water', 'Hollow Marsh');
    await page.evaluate(() => window.tideforgeFixture.releasePreview());
    await page.waitForTimeout(100);
    assert.equal(await page.getByRole('dialog').isVisible(), false);
    await page.evaluate(() => { window.tideforgeFixture.delayNext = true; });
    await page.getByRole('button', { name: 'Preview gear purchase' }).click();
    await choose(page, 'Gear model', 'Choose gear');
    await page.evaluate(() => window.tideforgeFixture.releasePreview());
    await page.waitForTimeout(100);
    assert.equal(await page.getByRole('dialog').isVisible(), false);
  });
  await check('uncertain action checks the original transaction safely', async () => {
    await choose(page, 'Gear model', 'River Rod');
    await choose(page, 'Secondary water', 'Whispering River');
    await page.evaluate(() => { window.tideforgeFixture.unknownNext = true; window.tideforgeFixture.unknownPreview = true; });
    await page.getByRole('button', { name: 'Preview gear purchase' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Confirm', exact: true }).click();
    await page.getByText('We have not confirmed the last action yet. Check again safely; you will not pay twice.').waitFor({ state: 'visible' });
    await page.evaluate(() => { window.tideforgeFixture.tideforgeError = 'Action confirmed. Updating your gear and materials…'; });
    await page.getByText('Action confirmed. Updating your gear and materials…').waitFor({ state: 'visible' });
    assert.equal(await page.getByText('We have not confirmed the last action yet.', { exact: false }).count(), 0);
    await page.evaluate(() => { window.tideforgeFixture.tideforgeError = ''; });
    await page.evaluate(() => { window.tideforgeFixture.ready = false; });
    assert.equal(await page.getByRole('button', { name: 'Check last action' }).isDisabled(), true);
    await page.evaluate(() => { window.tideforgeFixture.ready = true; });
    await page.getByRole('button', { name: 'Check last action' }).click();
    const calls = await page.evaluate(() => window.tideforgeFixture.calls);
    const commit = calls.findLast(row => row.action === 'commit');
    const retry = calls.findLast(row => row.action === 'retry');
    assert(commit && retry && commit.nonce === retry.nonce);
    await page.getByText('Gear acquired', { exact: true }).waitFor({ state: 'visible' });
    await page.getByText('Unknown affix', { exact: false }).first().waitFor({ state: 'visible' });
    await page.getByText('Unknown signature', { exact: false }).first().waitFor({ state: 'visible' });
    await page.getByText('Received 10 Unknown material.').waitFor({ state: 'visible' });
    await assertPlayerLanguage(page.locator('.tideforge'));
    await page.evaluate(() => { window.tideforgeFixture.unknownPreview = false; });
  });
  await check('saved receipt closes the confirmation before a lost reply', async () => {
    await page.evaluate(() => { window.tideforgeFixture.holdCommit = true; });
    await page.getByRole('button', { name: 'Preview gear purchase' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Confirm', exact: true }).click({ noWaitAfter: true });
    await page.evaluate(() => window.tideforgeFixture.saveHeldReceipt());
    await page.getByRole('dialog').waitFor({ state: 'hidden' });
    await page.evaluate(() => window.tideforgeFixture.rejectHeldCommit());
    assert.equal(await page.getByRole('button', { name: 'Check last action' }).count(), 0);
  });
  await check('fifty selected fish clear after receipt and another batch can be selected', async () => {
    await tab(page, 'processing');
    await page.getByRole('button', { name: 'Select eligible on this page' }).click();
    await page.getByText('50/50 selected.', { exact: false }).waitFor({ state: 'visible' });
    await page.getByRole('button', { name: 'Preview processing' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Confirm', exact: true }).click();
    await page.getByText('Received 10 Basic fillets.').waitFor({ state: 'visible' });
    await page.getByText('0/50 selected.', { exact: false }).waitFor({ state: 'visible' });
    await page.getByRole('button', { name: 'Select eligible on this page' }).click();
    await page.getByText('12/50 selected.', { exact: false }).waitFor({ state: 'visible' });
  });
  await check('owner change resets private form selections and trophy permission', async () => {
    await page.getByRole('checkbox', { name: 'Include rank maxima and perfect catches' }).check();
    await tab(page, 'forge'); await choose(page, 'Forge gear', 'River Rod · Item 1');
    await tab(page, 'trader');
    await choose(page, 'Gear model', 'River Rod');
    await choose(page, 'Secondary water', 'Whispering River');
    await page.evaluate(() => { window.tideforgeFixture.delayNext = true; });
    await page.getByRole('button', { name: 'Preview gear purchase' }).click();
    await page.evaluate(() => window.tideforgeFixture.changeOwner());
    await page.evaluate(() => window.tideforgeFixture.releasePreview());
    await page.locator('#shop-tab-additional').waitFor({ state: 'visible' });
    assert.equal(await page.getByRole('dialog').isVisible(), false);
    await tab(page, 'processing');
    assert.equal(await page.getByRole('checkbox', { name: 'Include rank maxima and perfect catches' }).isChecked(), false);
    await page.getByText('0/50 selected.', { exact: false }).waitFor({ state: 'visible' });
    await tab(page, 'forge');
    assert.match(await page.getByRole('combobox', { name: 'Forge gear' }).innerText(), /Choose gear/);
  });
  await check('known-active core failure protects originals; absent legacy feature remains usable', async () => {
    await page.evaluate(() => window.tideforgeFixture.setGearUnavailable(true));
    await tab(page, 'gear');
    const loadout = page.getByText('Equipped gear, upgrades & bait', { exact: true });
    if (await loadout.locator('..').getAttribute('open') === null) await loadout.click();
    await page.getByText('Equipment details are unavailable. Reconnect before upgrading.').waitFor({ state: 'visible' });
    assert.equal(await page.getByRole('button', { name: 'Upgrade', exact: true }).first().isDisabled(), true);
    await page.evaluate(() => window.tideforgeFixture.setGearUnavailable(false));
    assert.equal(await page.getByText('Equipment details are unavailable. Reconnect before upgrading.').count(), 0);
    await page.evaluate(() => window.tideforgeFixture.setGearReady());
    await page.locator('#tab-tideforge').click();
  });
  await check('inactive and unavailable feature states remain local', async () => {
    await page.evaluate(() => window.tideforgeFixture.setAvailability('inactive'));
    await page.getByText('Tideforge is not active on this server yet.').waitFor({ state: 'visible' });
    await page.evaluate(() => window.tideforgeFixture.setAvailability('unavailable'));
    await page.getByText('Tideforge could not load. Retry Tideforge to reconnect this section; your saved gear is unchanged.').waitFor({ state: 'visible' });
    await assertPlayerLanguage(page.locator('.tideforge'));
    await page.getByRole('button', { name: 'Retry Tideforge' }).click();
    await page.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  });
  await desktop.ctx.close();

  const namedDesktop = await context({ width: 1440, height: 900 });
  const named = namedDesktop.page;
  await named.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await named.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  const secondCard = named.locator('#tideforge-panel-gear .card').filter({ hasText: 'River Rod · Item 2' });
  await check('rename is confirmed, searchable, replayable and cancel restores focus', async () => {
    const opener = secondCard.getByRole('button', { name: 'Rename' });
    await opener.click();
    let input = secondCard.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    assert.equal(await input.evaluate(el => document.activeElement === el), true);
    await input.fill('Discard me');
    await secondCard.getByRole('button', { name: 'Cancel', exact: true }).click();
    assert.equal(await opener.evaluate(el => document.activeElement === el), true);
    await opener.click(); input = secondCard.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    await input.fill('Escape draft'); await input.press('Escape');
    assert.equal(await opener.evaluate(el => document.activeElement === el), true);
    assert.equal(await named.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'rename').length), 0);
    await opener.click(); await secondCard.getByRole('textbox', { name: 'Name for River Rod · Item 2' }).fill('Old Faithful');
    await named.evaluate(() => { window.tideforgeFixture.holdNextRename = true; });
    await secondCard.getByRole('button', { name: 'Save name' }).click();
    assert.equal(await secondCard.getByRole('heading', { name: 'River Rod · Item 2' }).count(), 1);
    await named.evaluate(() => window.tideforgeFixture.releaseHeldRename());
    await secondCard.getByRole('heading', { name: 'Old Faithful', exact: true }).waitFor({ state: 'visible' });
    assert.match(await secondCard.innerText(), /River Rod · Item 2/);
    const search = named.getByRole('searchbox', { name: 'Find Tideforge gear' });
    await search.fill('faithful');
    assert.equal(await named.locator('#tideforge-panel-gear .card').count(), 1);
    await search.fill('river rod');
    assert.equal(await named.locator('#tideforge-panel-gear .card').count(), 2);
    await search.fill('');
    await named.evaluate(() => { window.tideforgeFixture.gearInstanceNames = []; window.tideforgeFixture.replayGearNames(); });
    await secondCard.getByRole('heading', { name: 'Old Faithful', exact: true }).waitFor({ state: 'visible' });
    await secondCard.getByRole('button', { name: 'Rename' }).click();
    await secondCard.screenshot({ path: new URL('desktop-name-edit.png', renameOutput).pathname.slice(1) });
    await secondCard.getByRole('button', { name: 'Cancel', exact: true }).click();
  });
  await check('named gear keeps its model in selections, confirmations and equipped headings', async () => {
    await tab(named, 'forge');
    await choose(named, 'Forge gear', 'Old Faithful · River Rod · Item 2');
    assert.match(await named.getByRole('combobox', { name: 'Forge gear' }).innerText(), /Old Faithful · River Rod · Item 2/);
    await named.evaluate(() => {
      window.tideforgeFixture.gearLoadout = { ...window.tideforgeFixture.gearLoadout, rodInstanceId: 100n };
      window.tideforgeFixture.tideforgeLibrary = [];
    });
    await tab(named, 'gear');
    await secondCard.getByRole('button', { name: 'Dismantle' }).click();
    const dialog = named.getByRole('dialog');
    await dialog.waitFor({ state: 'visible' });
    assert.match(await dialog.innerText(), /Old Faithful · River Rod · Item 2/);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await tab(named, 'reliquary');
    await choose(named, 'Gear to extract from', 'Old Faithful · River Rod · Item 2 · Echoed Line');
    await named.getByRole('button', { name: 'Preview extraction' }).click();
    await dialog.waitFor({ state: 'visible' });
    assert.match(await dialog.innerText(), /Old Faithful · River Rod · Item 2/);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await named.evaluate(async () => {
      const fixture = window.tideforgeFixture;
      fixture.gearLoadout = { ...fixture.gearLoadout, rodInstanceId: 101n };
      fixture.enableHaulNames();
      await fixture.renameGearInstance(102n, 'Boat Friend');
      await fixture.renameGearInstance(103n, 'Net Friend');
    });
    await tab(named, 'gear');
    await named.getByText('Equipped gear, upgrades & bait', { exact: true }).click();
    const rod = named.locator('article[aria-label="Equipped rod"]');
    await rod.getByRole('heading', { name: 'Old Faithful' }).waitFor({ state: 'visible' });
    assert.match(await rod.innerText(), /River Rod · Item 2/);
    assert.match(await named.locator('article[aria-label="Equipped boat"]').innerText(), /Boat Friend.*River Boat/s);
    assert.match(await named.locator('article[aria-label="Equipped net"]').innerText(), /Net Friend.*Twig Net/s);
    await rod.screenshot({ path: new URL('desktop-equipped-name.png', renameOutput).pathname.slice(1) });
    await tab(named, 'gear');
  });
  await check('rename failures retain drafts, defaults clear, and text remains literal', async () => {
    await named.evaluate(() => { window.tideforgeFixture.renameFailure = 'invalid'; });
    await secondCard.getByRole('button', { name: 'Rename' }).click();
    let input = secondCard.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    await input.fill('Rejected draft');
    await secondCard.getByRole('button', { name: 'Save name' }).click();
    await secondCard.getByRole('alert').getByText(/cannot contain/).waitFor({ state: 'visible' });
    assert.equal(await input.inputValue(), 'Rejected draft');
    assert.equal(await secondCard.getByRole('heading', { name: 'Old Faithful', exact: true }).count(), 1);
    await named.evaluate(() => { window.tideforgeFixture.renameFailure = 'unknown'; });
    await input.fill('Unconfirmed draft');
    await secondCard.getByRole('button', { name: 'Save name' }).click();
    await secondCard.getByRole('alert').getByText(/could not confirm.*Reconnect/).waitFor({ state: 'visible' });
    assert.equal(await input.inputValue(), 'Unconfirmed draft');
    assert.equal(await secondCard.getByRole('heading', { name: 'Old Faithful', exact: true }).count(), 1);
    await secondCard.getByRole('button', { name: 'Use default name' }).click();
    await secondCard.getByRole('heading', { name: 'River Rod · Item 2', exact: true }).waitFor({ state: 'visible' });
    await secondCard.getByRole('button', { name: 'Rename' }).click();
    input = secondCard.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    await input.fill('  ');
    await secondCard.getByRole('button', { name: 'Save name' }).click();
    assert.equal(await named.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'rename').at(-1).name), '');
    await secondCard.getByRole('button', { name: 'Rename' }).click();
    input = secondCard.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    await input.fill('x'.repeat(33));
    assert.equal(await secondCard.getByRole('button', { name: 'Save name' }).isDisabled(), true);
    await secondCard.getByRole('alert').getByText(/32 characters/).waitFor({ state: 'visible' });
    await input.fill('🎣'.repeat(32));
    await secondCard.getByRole('button', { name: 'Save name' }).click();
    await secondCard.getByRole('heading', { name: '🎣'.repeat(32), exact: true }).waitFor({ state: 'visible' });
    await secondCard.getByRole('button', { name: 'Rename' }).click();
    input = secondCard.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    await input.fill('<b>plain</b>');
    await secondCard.getByRole('button', { name: 'Save name' }).click();
    const literal = secondCard.getByRole('heading', { name: '<b>plain</b>', exact: true });
    await literal.waitFor({ state: 'visible' });
    assert.equal(await literal.locator('b').count(), 0);
    await named.evaluate(() => { window.tideforgeFixture.gearNamesAvailability = 'unavailable'; });
    assert.equal(await secondCard.getByRole('button', { name: 'Rename' }).isDisabled(), true);
    assert.equal(await secondCard.getByRole('button', { name: 'Favorite' }).isDisabled(), false);
    await named.getByRole('button', { name: 'Retry names' }).click();
    await secondCard.getByRole('button', { name: 'Rename' }).click();
    input = secondCard.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    await input.fill('Private draft');
    await named.evaluate(() => window.tideforgeFixture.changeOwner());
    await named.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
    assert.equal(await named.locator('input').evaluateAll(nodes => nodes.some(node => node.value === 'Private draft')), false);
    assert.equal(await named.locator('.rename-form').count(), 0);
  });
  await namedDesktop.ctx.close();

  const tackleDesktop = await context({ width: 1440, height: 1400 });
  const tacklePage = tackleDesktop.page;
  await tacklePage.goto(`${base}/#inventory`, { waitUntil: 'networkidle' });
  await check('Tackle Box keeps fish and links to canonical gear, materials and Processing', async () => {
    await tacklePage.evaluate(() => {
      const game = window.tideforgeFixture, playerId = game.player.playerId;
      const ordinary = game.species.find(row => row.countsForOrdinaryCollectionCompletion);
      game.items = [{ key: (playerId << 32n) + 1000n, playerId, item: 'raid_fillets_f', quantity: 20n }];
      game.raidBadges = [{ playerId, speciesId: ordinary.speciesId, clearedGrades: 2 }];
    });
    const snapshot = () => tacklePage.evaluate(() => JSON.stringify({
      gear: window.tideforgeFixture.gearInstances, items: window.tideforgeFixture.items,
    }, (_key, value) => typeof value === 'bigint' ? value.toString() : value));
    const before = await snapshot();
    assert.equal(await tacklePage.getByRole('tablist', { name: 'Tackle box sections' }).count(), 0);
    assert.equal(await tacklePage.getByRole('tab', { name: 'Raid Workshop' }).count(), 0);
    assert.equal(await tacklePage.getByRole('heading', { name: 'Raid Workshop' }).count(), 0);
    assert.equal(await tacklePage.locator('#tackle-panel-workshop').count(), 0);
    await tacklePage.getByRole('button', { name: 'Select all', exact: true }).waitFor({ state: 'visible' });
    await tacklePage.getByRole('button', { name: /Preview sale/ }).waitFor({ state: 'visible' });
    await tacklePage.getByRole('button', { name: 'Manage gear & bait', exact: true }).click();
    await tacklePage.getByText('Equipped gear, upgrades & bait', { exact: true }).click();
    const gear = tacklePage.getByRole('region', { name: 'Gear', exact: true });
    const reinforcement = gear.getByRole('region', { name: 'River Rod rod reinforcement' });
    await reinforcement.getByRole('button', { name: 'Reinforce', exact: true }).waitFor({ state: 'visible' });
    await tacklePage.screenshot({ path: new URL('tackle.png', retireOutput).pathname.slice(1) });
    await tacklePage.locator('#tab-inventory').click();
    await tacklePage.getByRole('button', { name: 'Open materials', exact: true }).click();
    await tacklePage.getByRole('region', { name: 'Materials', exact: true }).waitFor({ state: 'visible' });
    await tab(tacklePage, 'processing');
    await choose(tacklePage, 'Processing action', 'Refine materials');
    await tacklePage.getByRole('combobox', { name: 'From grade', exact: true }).waitFor({ state: 'visible' });
    await tacklePage.getByRole('combobox', { name: 'Destination grade', exact: true }).waitFor({ state: 'visible' });
    const preview = tacklePage.getByRole('button', { name: 'Preview processing' });
    assert.equal(await preview.isDisabled(), false);
    await tacklePage.locator('#tideforge-panel-processing').screenshot({ path: new URL('processing.png', retireOutput).pathname.slice(1) });
    await preview.click();
    const dialog = tacklePage.getByRole('dialog');
    await dialog.getByRole('heading', { name: 'Confirm Refine materials' }).waitFor({ state: 'visible' });
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    assert.equal(await snapshot(), before);
    assert.deepEqual(await tacklePage.evaluate(() => window.tideforgeFixture.calls.map(row => row.action)), ['prepare']);
  });
  await tackleDesktop.ctx.close();

  const tackleGearDesktop = await context({ width: 1440, height: 1100 });
  const tackleGearPage = tackleGearDesktop.page;
  await tackleGearPage.goto(`${base}/#tideforge/gear`, { waitUntil: 'networkidle' });
  await tackleGearPage.getByText('Equipped gear, upgrades & bait', { exact: true }).click();
  await tackleGearPage.getByRole('region', { name: 'Gear', exact: true }).waitFor({ state: 'visible' });
  await tackleGearPage.evaluate(() => window.tideforgeFixture.showTackleGear());
  await check('Tackle Box equips named instances and retains accessible action explanations', async () => {
    const gear = tackleGearPage.getByRole('region', { name: 'Gear', exact: true });
    const rod = gear.getByRole('article', { name: 'Equipped rod' });
    const boat = gear.getByRole('article', { name: 'Equipped boat' });
    const net = gear.getByRole('article', { name: 'Equipped net' });
    const upgrade = rod.getByRole('button', { name: 'Upgrade', exact: true });
    const reinforce = rod.getByRole('button', { name: 'Reinforce', exact: true });
    const originalRodPower = await rod.locator('dl dd').first().innerText();
    const originalBoatLuck = await boat.locator('dl dd').first().innerText();
    const originalNetLuck = await net.locator('dl dd').first().innerText();
    assert.equal(await gear.getByRole('button', { name: 'Upgrade', exact: true }).count(), 3);
    assert.equal(await gear.getByRole('button', { name: 'Reinforce', exact: true }).count(), 1);
    assert.doesNotMatch(await rod.innerText(), /Next:|rusted tin|scrap|Collection Focus|bonus rolls/i);
    await upgrade.scrollIntoViewIfNeeded();
    await tackleGearPage.waitForTimeout(150);
    await upgrade.hover();
    const tooltip = tackleGearPage.getByRole('tooltip');
    await tooltip.waitFor({ state: 'visible' });
    assert.match(await tooltip.innerText(), /Next quality: Epic.*Rusted tin: 100 \/ 1.*Scrap: 100 \/ 1.*Power/s);
    await tackleGearPage.mouse.move(0, 0);
    await reinforce.scrollIntoViewIfNeeded();
    await tackleGearPage.waitForTimeout(150);
    await reinforce.focus();
    await tooltip.waitFor({ state: 'visible' });
    assert.match(await tooltip.innerText(), /separate from quality.*mastered biome.*ordinary UUR.*F fillets: 100 \/ 60.*F scales: 100 \/ 40.*Coins: 50000 \/ 50/s);
    await reinforce.press('Escape');
    await tooltip.waitFor({ state: 'hidden' });
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.items = game.items.map(row => row.item === 'rusted_tin' ? { ...row, quantity: 0n } : row);
      game.raidBadges = [];
    });
    const insufficient = rod.getByRole('group', { name: 'Upgrade details' });
    await insufficient.focus();
    await tooltip.waitFor({ state: 'visible' });
    assert.match(await tooltip.innerText(), /Rusted tin: 0 \/ 1/);
    await insufficient.press('Escape');
    const locked = rod.getByRole('group', { name: 'Reinforce details' });
    await locked.focus();
    await tooltip.waitFor({ state: 'visible' });
    assert.match(await tooltip.innerText(), /Clear F on an ordinary raid boss first/);
    await locked.press('Escape');
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.items = game.items.map(row => row.item === 'rusted_tin' ? { ...row, quantity: 100n } : row);
      game.raidBadges = [{ playerId: 9n, speciesId: game.species[0].speciesId, clearedGrades: 1 }];
    });
    await noOverflow(tackleGearPage);
    await rod.screenshot({ path: new URL('desktop.png', tackleOutput).pathname.slice(1) });

    await upgrade.click();
    let dialog = tackleGearPage.getByRole('dialog');
    await dialog.getByRole('heading', { name: 'Confirm rod upgrade' }).waitFor({ state: 'visible' });
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 101n };
    });
    await dialog.waitFor({ state: 'hidden' });
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 100n };
    });
    await tackleGearPage.evaluate(() => { window.tideforgeFixture.holdNextUpgrade = true; });
    await upgrade.click();
    await tackleGearPage.waitForFunction(() => window.tideforgeFixture.busy);
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 101n };
    });
    await rod.getByRole('heading', { name: 'Rod Buddy', exact: true }).waitFor({ state: 'visible' });
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 100n };
    });
    await rod.getByRole('heading', { name: 'River Rod · Item 1', exact: true }).waitFor({ state: 'visible' });
    await tackleGearPage.evaluate(() => window.tideforgeFixture.releaseHeldUpgrade());
    await tackleGearPage.waitForFunction(() => !window.tideforgeFixture.busy);
    await tackleGearPage.waitForTimeout(100);
    assert.equal(await tackleGearPage.getByRole('dialog').isVisible(), false);
    await reinforce.click();
    dialog = tackleGearPage.getByRole('dialog');
    await dialog.getByRole('heading', { name: 'Confirm per-rod reinforcement' }).waitFor({ state: 'visible' });
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 101n };
    });
    await dialog.waitFor({ state: 'hidden' });
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 100n };
    });
    await boat.getByRole('button', { name: 'Upgrade', exact: true }).click();
    dialog = tackleGearPage.getByRole('dialog');
    await dialog.getByRole('heading', { name: 'Confirm boat upgrade' }).waitFor({ state: 'visible' });
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, boatInstanceId: 9007199254740993n };
    });
    await dialog.waitFor({ state: 'hidden' });
    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, boatInstanceId: 102n };
    });

    await tackleGearPage.getByRole('combobox', { name: 'Switch rod' }).click();
    const rodOptions = tackleGearPage.getByRole('listbox', { name: 'Switch rod' });
    assert.equal(await rodOptions.getByRole('option', { name: /Abyssal Rod · Level 99 required/ }).getAttribute('aria-disabled'), 'true');
    await rodOptions.getByRole('option', { name: 'Common Rod Buddy · River Rod · Item 2', exact: true }).click();
    await rod.getByRole('heading', { name: 'Rod Buddy', exact: true }).waitFor({ state: 'visible' });
    assert.match(await rod.locator('.quality').innerText(), /Common/);
    assert.notEqual(await rod.locator('dl dd').first().innerText(), originalRodPower);
    assert.match(await tackleGearPage.getByRole('combobox', { name: 'Switch rod' }).innerText(), /Rod Buddy · River Rod · Item 2/);
    assert.equal(await upgrade.isDisabled(), true);
    assert.equal(await reinforce.isDisabled(), true);
    const disabledHelp = rod.getByRole('group', { name: 'Upgrade details' });
    await disabledHelp.focus();
    await tooltip.waitFor({ state: 'visible' });
    assert.match(await tooltip.innerText(), /not available for this copy/);
    await disabledHelp.press('Escape');
    await tooltip.waitFor({ state: 'hidden' });
    await tackleGearPage.evaluate(() => window.tideforgeFixture.renameGearInstance(101n, 'Fresh Rod'));
    assert.match(await tackleGearPage.getByRole('combobox', { name: 'Switch rod' }).innerText(), /Fresh Rod/);

    await tackleGearPage.evaluate(() => { window.tideforgeFixture.failNextEquip = true; });
    await choose(tackleGearPage, 'Switch boat', 'Uncommon Boat Buddy · River Boat · Item 2');
    await gear.getByRole('alert').getByText('This gear is busy on a trip.').waitFor({ state: 'visible' });
    await choose(tackleGearPage, 'Switch boat', 'Uncommon Boat Buddy · River Boat · Item 2');
    await boat.getByRole('heading', { name: 'Boat Buddy', exact: true }).waitFor({ state: 'visible' });
    assert.match(await boat.locator('.quality').innerText(), /Uncommon/);
    assert.notEqual(await boat.locator('dl dd').first().innerText(), originalBoatLuck);
    assert.match(await tackleGearPage.getByRole('combobox', { name: 'Switch boat' }).innerText(), /Boat Buddy · River Boat · Item 2/);
    assert.equal(await boat.getByRole('button', { name: 'Upgrade', exact: true }).isDisabled(), true);
    await choose(tackleGearPage, 'Switch net', 'Rare Net Buddy · Twig Net · Item 2');
    await net.getByRole('heading', { name: 'Net Buddy', exact: true }).waitFor({ state: 'visible' });
    assert.match(await net.locator('.quality').innerText(), /Rare/);
    assert.notEqual(await net.locator('dl dd').first().innerText(), originalNetLuck);
    assert.match(await tackleGearPage.getByRole('combobox', { name: 'Switch net' }).innerText(), /Net Buddy · Twig Net · Item 2/);
    assert.equal(await net.getByRole('button', { name: 'Upgrade', exact: true }).isDisabled(), true);
    assert.deepEqual(await tackleGearPage.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'equip').map(row => row.instanceId)),
      ['101', '9007199254740993', '9007199254740993', '9007199254740995']);
    assert.equal(await tackleGearPage.evaluate(() => window.tideforgeFixture.calls.some(row => /commit|reinforce/i.test(row.action))), false);

    await tackleGearPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = null;
      game.ownedRods = [...game.ownedRods, { rodId: 3, upgradeLevel: 0 }];
    });
    await choose(tackleGearPage, 'Switch rod', 'Common Marsh Rod');
    await rod.getByRole('heading', { name: 'Marsh Rod', exact: true }).waitFor({ state: 'visible' });
    assert.equal(await tackleGearPage.evaluate(() => window.tideforgeFixture.calls.at(-1).action), 'legacyRod');
  });
  await tackleGearDesktop.ctx.close();

  const tackleGearMobile = await context({ width: 375, height: 812 });
  const tackleMobilePage = tackleGearMobile.page;
  await tackleMobilePage.goto(`${base}/#tideforge/gear`, { waitUntil: 'networkidle' });
  await tackleMobilePage.getByText('Equipped gear, upgrades & bait', { exact: true }).click();
  await tackleMobilePage.getByRole('region', { name: 'Gear', exact: true }).waitFor({ state: 'visible' });
  await tackleMobilePage.evaluate(() => window.tideforgeFixture.showTackleGear());
  await check('disabled Tackle Box action explanations are available by touch', async () => {
    await choose(tackleMobilePage, 'Switch rod', 'Common Rod Buddy · River Rod · Item 2');
    const rod = tackleMobilePage.getByRole('article', { name: 'Equipped rod' });
    const help = rod.getByRole('group', { name: 'Upgrade details' });
    await help.tap();
    const tooltip = tackleMobilePage.getByRole('tooltip');
    await tooltip.waitFor({ state: 'visible' });
    assert.match(await tooltip.innerText(), /not available for this copy/);
    await noOverflow(tackleMobilePage);
    await rod.screenshot({ path: new URL('mobile.png', tackleOutput).pathname.slice(1) });
  });
  await tackleGearMobile.ctx.close();

  const vaultDesktop = await context({ width: 1440, height: 1600 });
  const vaultPage = vaultDesktop.page;
  await vaultPage.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await vaultPage.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  await vaultPage.evaluate(() => {
    window.tideforgeFixture.items = [
      { key: 800n, playerId: 9n, quantity: 11n }, { key: 801n, playerId: 9n, quantity: 22n },
      { key: 1000n, playerId: 9n, quantity: 33n }, { key: 1100n, playerId: 9n, quantity: 44n },
    ];
  });
  await check('Basic materials reuse F-grade art while quantities remain separate', async () => {
    await tab(vaultPage, 'vault');
    const rows = vaultPage.locator('#tideforge-panel-vault');
    for (const [basicLabel, basicQuantity, gradeLabel, gradeQuantity, expected] of [
      ['Basic fillets', '11', 'F-grade fillets', '33', '/raid-materials/fillets-f.png'],
      ['Basic scales', '22', 'F-grade scales', '44', '/raid-materials/scales-f.png'],
    ]) {
      const basic = rows.locator('.rows p').filter({ has: vaultPage.getByText(basicLabel, { exact: true }) });
      const graded = rows.locator('.rows p').filter({ has: vaultPage.getByText(gradeLabel, { exact: true }) });
      assert.equal(await basic.count(), 1);
      assert.equal(await graded.count(), 1);
      assert.equal(await basic.locator('strong').innerText(), basicQuantity);
      assert.equal(await graded.locator('strong').innerText(), gradeQuantity);
      const image = basic.locator('img');
      assert.equal(await image.getAttribute('src'), expected);
      assert.equal(await image.getAttribute('src'), await graded.locator('img').getAttribute('src'));
      const size = await image.evaluate(async node => { await node.decode(); return [node.naturalWidth, node.naturalHeight]; });
      assert(size[0] > 0 && size[1] > 0, `${expected} did not decode`);
    }
    await rows.screenshot({ path: new URL('vault.png', basicArtOutput).pathname.slice(1) });
  });
  await vaultDesktop.ctx.close();

  const processingDesktop = await context({ width: 1440, height: 900 });
  const processingPage = processingDesktop.page;
  await processingPage.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await processingPage.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  await check('processing pages keep selection aligned and fish artwork safe', async () => {
    await tab(processingPage, 'processing');
    const panel = processingPage.locator('#tideforge-panel-processing');
    const cards = panel.locator('.fish label');
    const pages = processingPage.getByRole('navigation', { name: 'Processing fish pages' });
    assert.equal(await cards.count(), 50);
    assert.match(await pages.innerText(), /Page 1 of 2/);
    const expectedSprite = await processingPage.evaluate(() => window.tideforgeFixture.species[0].spriteAsset);
    const image = cards.first().locator('img');
    assert.equal(await image.getAttribute('src'), expectedSprite);
    assert.equal(await image.getAttribute('alt'), '');
    await image.scrollIntoViewIfNeeded();
    assert(await image.evaluate(async node => { await node.decode(); return node.naturalWidth > 0; }));
    await noOverflow(processingPage);
    await processingPage.screenshot({ path: new URL('desktop.png', processingOutput).pathname.slice(1) });
    await pages.getByRole('button', { name: 'Next page' }).click();
    assert.equal(await cards.count(), 12);
    await panel.getByRole('button', { name: 'Select eligible on this page' }).click();
    await panel.getByText('12/50 selected.', { exact: false }).waitFor({ state: 'visible' });
    await pages.getByRole('button', { name: 'Previous page' }).click();
    await panel.getByRole('button', { name: 'Select eligible on this page' }).click();
    await panel.getByText('50/50 selected.', { exact: false }).waitFor({ state: 'visible' });
    assert.equal(await panel.getByRole('button', { name: 'Select eligible on this page' }).isDisabled(), true);
    assert.equal(await cards.locator('input:not(:checked)').first().isDisabled(), true);
    assert.doesNotMatch(await panel.locator('.fish').innerText(), /\bCatch\s+\d+|#\d+/);
    await panel.getByRole('button', { name: 'Preview processing' }).click();
    const dialog = processingPage.getByRole('dialog');
    await dialog.waitFor({ state: 'visible' });
    assert.doesNotMatch(await dialog.innerText(), /\bCatch\s+\d+|#\d+/);
    const intent = await processingPage.evaluate(() => window.tideforgeFixture.calls.findLast(row => row.action === 'prepare').intent);
    assert.deepEqual(intent.catch_ids, [...Array.from({ length: 12 }, (_, index) => String(index + 51)), ...Array.from({ length: 38 }, (_, index) => String(index + 1))]);
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await panel.getByRole('button', { name: 'Clear selection' }).click();
    await panel.getByText('0/50 selected.', { exact: false }).waitFor({ state: 'visible' });
    assert.equal(await processingPage.evaluate(() => window.tideforgeFixture.calls.filter(row => row.action === 'commit').length), 0);
    await processingPage.route('**/fish/processing-broken-fixture.png', route => route.fulfill({ status: 200, contentType: 'image/png', body: 'not an image' }));
    await processingPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.species = [...game.species, { ...game.species[0], speciesId: 9997, spriteAsset: '' },
        { ...game.species[0], speciesId: 9996, spriteAsset: '/fish/processing-broken-fixture.png' }];
      game.inventory = game.inventory.map((fish, index) => index < 3 ? { ...fish, speciesId: [9998, 9997, 9996][index] } : fish);
    });
    await processingPage.waitForFunction(() => [...document.querySelectorAll('#tideforge-panel-processing .fish img')].slice(0, 3)
      .every(image => image.getAttribute('src') === '/fish/minnow.png' && image.complete && image.naturalWidth > 0));
    assert.match(await cards.first().innerText(), /Unknown fish/);
    assert.equal(await cards.first().locator('input').isDisabled(), true);
  });
  await processingDesktop.ctx.close();

  const orderDesktop = await context({ width: 1440, height: 1200 });
  const orderPage = orderDesktop.page;
  await orderPage.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await orderPage.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  await check('gear ordering stays live across pages, searches and kinds', async () => {
    await orderPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.showGearGrid();
      const original = game.gearInstances;
      const extra = Array.from({ length: 12 }, (_, index) => ({
        ...original[4], instanceId: 1000n + BigInt(index), favorite: false
      }));
      game.gearInstances = [...extra, original[0], original[5], { ...original[4], favorite: true },
        { ...original[1], favorite: true }, original[2], original[3]];
      const names = [
        [100n, 'Original Rod'], [101n, 'Equipped Favorite Rod'], [102n, 'Equipped Boat'],
        [103n, 'Equipped Net'], [104n, 'Favorite Rod'], [105n, 'Normal Rod'],
        ...extra.map((item, index) => [item.instanceId, `Regular ${String(index + 1).padStart(2, '0')}`])
      ];
      game.gearInstanceNames = names.map(([instanceId, name]) => ({ playerId: 9n, instanceId, name }));
    });
    const panel = orderPage.locator('#tideforge-panel-gear');
    const cards = panel.locator('.card');
    const pages = orderPage.getByRole('navigation', { name: 'Tideforge gear pages' });
    const headings = () => cards.locator('h3').allTextContents();
    await cards.getByRole('heading', { name: 'Equipped Favorite Rod', exact: true }).waitFor({ state: 'visible' });
    assert.match(await pages.innerText(), /Page 1 of 2/);
    assert.deepEqual(await headings(), ['Equipped Favorite Rod', 'Equipped Boat', 'Equipped Net', 'Favorite Rod',
      ...Array.from({ length: 8 }, (_, index) => `Regular ${String(index + 1).padStart(2, '0')}`)]);
    assert.match(await cards.nth(0).innerText(), /Equipped · Favorite/);
    assert.match(await cards.nth(1).innerText(), /Equipped/);
    assert.match(await cards.nth(2).innerText(), /Equipped/);
    assert.match(await cards.nth(3).innerText(), /Favorite/);
    assert.doesNotMatch(await cards.nth(3).innerText(), /Equipped/);
    await panel.locator('.cards').screenshot({ path: new URL('desktop.png', orderOutput).pathname.slice(1) });
    const url = orderPage.url();
    await orderPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearLoadout = { ...game.gearLoadout, rodInstanceId: 100n };
      game.gearInstances = game.gearInstances.map(item => item.instanceId === 103n ? { ...item, favorite: true }
        : item.instanceId === 104n ? { ...item, favorite: false } : item);
    });
    await cards.first().getByRole('heading', { name: 'Original Rod', exact: true }).waitFor({ state: 'visible' });
    assert.deepEqual((await headings()).slice(0, 4), ['Original Rod', 'Equipped Boat', 'Equipped Net', 'Equipped Favorite Rod']);
    assert.match(await cards.nth(2).innerText(), /Equipped · Favorite/);
    assert.doesNotMatch(await cards.nth(3).innerText(), /Equipped · Favorite/);
    await pages.getByRole('button', { name: 'Next page' }).click();
    assert.match(await pages.innerText(), /Page 2 of 2/);
    await orderPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearInstances = game.gearInstances.map(item => item.instanceId === 1000n ? { ...item, favorite: true } : item);
    });
    await orderPage.waitForTimeout(50);
    assert.match(await pages.innerText(), /Page 2 of 2/);
    await pages.getByRole('button', { name: 'Previous page' }).click();
    assert.deepEqual((await headings()).slice(0, 5), ['Original Rod', 'Equipped Boat', 'Equipped Net', 'Regular 01', 'Equipped Favorite Rod']);

    const search = orderPage.getByRole('searchbox', { name: 'Find Tideforge gear' });
    await choose(orderPage, 'Gear kind', 'Rods');
    await search.fill('regular');
    assert.equal(await cards.count(), 12);
    assert.equal((await headings())[0], 'Regular 01');
    await orderPage.evaluate(() => {
      const game = window.tideforgeFixture;
      game.gearInstances = game.gearInstances.map(item => item.instanceId === 1005n ? { ...item, favorite: true } : item);
    });
    await cards.nth(1).getByRole('heading', { name: 'Regular 06', exact: true }).waitFor({ state: 'visible' });
    assert.equal(await search.inputValue(), 'regular');
    assert.match(await orderPage.getByRole('combobox', { name: 'Gear kind' }).innerText(), /Rods/);
    await choose(orderPage, 'Gear kind', 'Boats');
    assert.equal(await cards.count(), 0);
    await search.fill('');
    assert.deepEqual(await headings(), ['Equipped Boat']);
    await choose(orderPage, 'Gear kind', 'Rods');
    await search.fill('favorite');
    assert.deepEqual(await headings(), ['Equipped Favorite Rod', 'Favorite Rod']);
    assert.equal(orderPage.url(), url);
  });
  await orderDesktop.ctx.close();

  const layoutDesktop = await context({ width: 1440, height: 900 });
  const layout = layoutDesktop.page;
  await layout.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await layout.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  await layout.evaluate(() => window.tideforgeFixture.showGearGrid());
  await check('gear art and desktop grid show four complete cards per row', async () => {
    const cards = layout.locator('#tideforge-panel-gear .card');
    await cards.nth(5).waitFor({ state: 'visible' });
    assert.equal(await cards.count(), 6);
    const expected = [
      ['/rods/river.png', 'River Rod'], ['/boats/river.png', 'River Boat'],
      ['/nets/twig.png', 'Twig Net'], ['/rods/river.png', 'River Rod'],
      ['/rods/marsh.png', 'Marsh Rod'], ['/rods/moonwood.png', 'Moonwood Rod'],
    ];
    for (const [index, [src, alt]] of expected.entries()) {
      const image = cards.nth(index).locator('.gear-art img');
      await image.scrollIntoViewIfNeeded();
      assert.equal(await image.getAttribute('src'), src);
      assert.equal(await image.getAttribute('alt'), alt);
      const size = await image.evaluate(async node => { await node.decode(); return [node.naturalWidth, node.naturalHeight]; });
      assert(size[0] > 0 && size[1] > 0, `${src} did not decode`);
    }
    const assertColumns = async (width, columns) => {
      await layout.setViewportSize({ width, height: 900 });
      const actual = await layout.locator('#tideforge-panel-gear .cards').evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').length);
      assert.equal(actual, columns);
      const positions = await cards.evaluateAll(nodes => nodes.map(node => ({ x: node.getBoundingClientRect().x, y: node.getBoundingClientRect().y })));
      for (let index = 1; index < columns; index++) assert.equal(positions[index].y, positions[0].y);
      assert(positions[columns].y > positions[0].y);
      await noOverflow(layout);
    };
    await assertColumns(1440, 4);
    await layout.setViewportSize({ width: 1440, height: 2200 });
    await layout.evaluate(() => window.scrollTo(0, 0));
    await layout.locator('#tideforge-panel-gear .cards').screenshot({ path: new URL('desktop.png', layoutOutput).pathname.slice(1) });
    await assertColumns(1920, 4);
    await assertColumns(1024, 2);
  });
  await layoutDesktop.ctx.close();

  const layoutMobile = await context({ width: 375, height: 812 });
  const narrow = layoutMobile.page;
  await narrow.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await narrow.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  await narrow.evaluate(() => window.tideforgeFixture.showGearGrid());
  await check('mobile gear art and long-name editing fit both themes', async () => {
    const cards = narrow.locator('#tideforge-panel-gear .card');
    await cards.nth(5).waitFor({ state: 'visible' });
    assert.equal(await narrow.locator('#tideforge-panel-gear .cards').evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').length), 1);
    const positions = await cards.evaluateAll(nodes => nodes.slice(0, 2).map(node => node.getBoundingClientRect().y));
    assert(positions[1] > positions[0]);
    const first = cards.first();
    assert.match(await first.innerText(), /Equipped/);
    await first.getByRole('button', { name: 'Rename' }).click();
    await first.getByRole('textbox', { name: 'Name for River Rod · Item 2' }).fill('X'.repeat(32));
    await first.getByRole('button', { name: 'Save name' }).click();
    await first.getByRole('heading', { name: 'X'.repeat(32), exact: true }).waitFor({ state: 'visible' });
    await first.getByRole('button', { name: 'Rename' }).click();
    await noOverflow(narrow);
    await first.screenshot({ path: new URL('mobile.png', layoutOutput).pathname.slice(1) });
    await narrow.getByRole('button', { name: 'Dark mode' }).click();
    await noOverflow(narrow);
  });
  await layoutMobile.ctx.close();

  const mobile = await context({ width: 375, height: 812 });
  const small = mobile.page;
  await small.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await small.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  await check('mobile touch opens shared tooltip and Escape closes it', async () => {
    const anchor = small.getByRole('button', { name: 'About collection goals' });
    const coarse = await small.evaluate(() => matchMedia('(pointer: coarse)').matches);
    await assertInfoButtons(small.locator('#tideforge-panel-gear'), coarse ? 44 : 32);
    await anchor.tap();
    await small.getByRole('tooltip').waitFor({ state: 'visible' });
    assertPlayerText(await anchor.getAttribute('aria-description'));
    await captureInfo(small, anchor, 'info-mobile-touch.png');
    await anchor.press('Escape');
    await small.getByRole('tooltip').waitFor({ state: 'hidden' });
  });
  await check('mobile tabs, hash navigation, light and dark layout', async () => {
    for (const id of ['gear', 'forge', 'processing', 'vault', 'codex', 'reliquary']) {
      await tab(small, id); await noOverflow(small); await assertPlayerLanguage(small.locator(`#tideforge-panel-${id}`));
    }
    await tab(small, 'trader'); await noOverflow(small); await assertPlayerLanguage(small.locator('#shop-panel-additional'));
    await small.locator('#shop-tab-wandering').click(); await noOverflow(small); await assertPlayerLanguage(small.locator('#shop-panel-wandering'));
    await tab(small, 'processing');
    const firstFish = small.locator('#tideforge-panel-processing .fish label').first();
    await firstFish.scrollIntoViewIfNeeded();
    assert(await firstFish.locator('img').evaluate(async node => { await node.decode(); return node.naturalWidth > 0; }));
    await firstFish.locator('input').check();
    await noOverflow(small);
    await small.screenshot({ path: new URL('mobile.png', processingOutput).pathname.slice(1) });
    await firstFish.locator('input').uncheck();
    await small.getByRole('button', { name: 'Select eligible on this page' }).click();
    await noOverflow(small);
    await small.locator('#tideforge-panel-processing').scrollIntoViewIfNeeded();
    if (!outputSuffix) await small.screenshot({ path: new URL('mobile-processing.png', output).pathname.slice(1) });
    await small.getByRole('button', { name: 'Dark mode' }).click();
    for (const id of ['gear', 'forge', 'processing', 'vault', 'codex', 'reliquary']) {
      await tab(small, id); await noOverflow(small);
    }
    await tab(small, 'trader'); await noOverflow(small);
    await small.locator('#shop-tab-wandering').click(); await noOverflow(small);
    await tab(small, 'processing');
    await small.getByRole('button', { name: 'Open navigation' }).click();
    await noOverflow(small);
    await small.locator('#mobile-navigation').getByRole('button', { name: 'Tackle box' }).click();
    assert.equal(new URL(small.url()).hash, '#inventory');
    await small.getByRole('button', { name: 'Open navigation' }).click();
    await small.locator('#mobile-navigation').getByRole('button', { name: 'Tideforge' }).click();
    assert.equal(new URL(small.url()).hash, '#tideforge');
    await small.locator('#tideforge-panel-gear').waitFor({ state: 'visible' });
    await noOverflow(small);
  });
  await check('mobile long names and editing fit light and dark themes', async () => {
    const theme = small.getByRole('button', { name: 'Dark mode' });
    assert.equal(await theme.getAttribute('aria-pressed'), 'true');
    await theme.click();
    assert.equal(await theme.getAttribute('aria-pressed'), 'false');
    await tab(small, 'gear');
    const card = small.locator('#tideforge-panel-gear .card').filter({ hasText: 'River Rod · Item 2' });
    await card.getByRole('button', { name: 'Rename' }).click();
    let input = card.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    await input.fill('X'.repeat(32));
    await card.getByRole('button', { name: 'Save name' }).click();
    await card.getByRole('heading', { name: 'X'.repeat(32), exact: true }).waitFor({ state: 'visible' });
    await card.getByRole('button', { name: 'Rename' }).click();
    input = card.getByRole('textbox', { name: 'Name for River Rod · Item 2' });
    await noOverflow(small);
    const coarse = await small.evaluate(() => matchMedia('(pointer: coarse)').matches);
    if (coarse) {
      assert((await input.boundingBox()).height >= 44);
      assert((await card.getByRole('button', { name: 'Save name' }).boundingBox()).height >= 44);
    }
    await card.screenshot({ path: new URL('mobile-name-edit.png', renameOutput).pathname.slice(1) });
    await theme.click();
    assert.equal(await theme.getAttribute('aria-pressed'), 'true');
    await noOverflow(small);
  });
  await mobile.ctx.close();
  assert.deepEqual(blocked, [], 'fixture attempted an external request');
  assert.deepEqual(diagnostics, [], 'browser console or page errors');
} catch (error) {
  results.push({ name: 'browser suite', status: 'FAIL', error: error.stack || String(error) });
  console.error(error);
  process.exitCode = 1;
} finally {
  await writeFile(new URL(`results${outputSuffix}.json`, output), JSON.stringify({ label: 'FRONTEND FIXTURE ONLY', base, results, blocked, diagnostics }, null, 2));
  await writeFile(new URL(`console${outputSuffix}.log`, output), [...diagnostics, ...blocked.map(url => `blocked: ${url}`)].join('\n') + '\n');
  await browser.close();
}
