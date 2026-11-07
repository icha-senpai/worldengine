import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const modulePath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
if (!modulePath) throw new Error('FISHBOUND_PLAYWRIGHT_MODULE is required');
const { chromium } = await import(pathToFileURL(modulePath).href);
const base = 'http://127.0.0.1:5182';
const output = new URL('../.local/tideforge-language/', import.meta.url);
const renameOutput = new URL('../.local/gear-renaming/', import.meta.url);
const layoutOutput = new URL('../.local/tideforge-gear-layout/', import.meta.url);
const basicArtOutput = new URL('../.local/tideforge-basic-art/', import.meta.url);
const retireOutput = new URL('../.local/retire-raid-workshop/', import.meta.url);
const outputSuffix = process.env.FISHBOUND_TIDEFORGE_RESULT_SUFFIX ?? '';
await mkdir(output, { recursive: true });
await mkdir(renameOutput, { recursive: true });
await mkdir(layoutOutput, { recursive: true });
await mkdir(basicArtOutput, { recursive: true });
await mkdir(retireOutput, { recursive: true });
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
    await page.evaluate(() => window.tideforgeFixture.showUnknownLanguage());
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
    await page.evaluate(() => window.tideforgeFixture.clearFishProtections());
    assert.match(await page.locator('#tideforge-panel-processing .fish label').first().innerText(), /Catch 1.*Ready to fillet/s);
    await page.locator('#tideforge-panel-processing .fish input').first().check();
    await page.getByRole('button', { name: 'Preview processing' }).click();
    await dialog.waitFor({ state: 'visible' });
    assert.match(await dialog.innerText(), /Catch 1/);
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
    await page.getByRole('region', { name: 'Gear' }).waitFor({ state: 'visible' });
    assert.match(await page.getByRole('region', { name: 'Gear' }).innerText(), /Base equipment and bait stats/);
    await page.locator('#tab-tideforge').click();
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
  if (!outputSuffix) await page.screenshot({ path: new URL('desktop-gear.png', output).pathname.slice(1) });
  await check('all seven Tideforge sections', async () => {
    for (const id of ['gear', 'forge', 'processing', 'trader', 'vault', 'codex', 'reliquary']) {
      await tab(page, id);
      await assertPlayerLanguage(page.locator(`#tideforge-panel-${id}`));
      if (id === 'trader') {
        const trader = page.locator('#tideforge-panel-trader');
        await assertInfoButtons(trader);
        assert.equal(await trader.getByRole('button', { name: 'About secondary water', exact: true }).count(), 1);
      }
    }
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
    for (let index = 0; index < 5; index++) {
      await page.getByRole('button', { name: 'Select eligible on this page' }).click();
      if (index < 4) await page.getByRole('navigation', { name: 'Processing fish pages' }).getByRole('button', { name: 'Next page' }).click();
    }
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
    await page.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
    assert.equal(await page.getByRole('dialog').isVisible(), false);
    await tab(page, 'processing');
    assert.equal(await page.getByRole('checkbox', { name: 'Include rank maxima and perfect catches' }).isChecked(), false);
    await page.getByText('0/50 selected.', { exact: false }).waitFor({ state: 'visible' });
    await tab(page, 'forge');
    assert.match(await page.getByRole('combobox', { name: 'Forge gear' }).innerText(), /Choose gear/);
  });
  await check('known-active core failure protects originals; absent legacy feature remains usable', async () => {
    await page.evaluate(() => window.tideforgeFixture.setGearUnavailable(true));
    await page.locator('#tab-inventory').click();
    await page.getByText('Equipment details are unavailable. Reconnect before upgrading.').waitFor({ state: 'visible' });
    assert.equal(await page.getByRole('button', { name: 'Upgrade gear' }).first().isDisabled(), true);
    await page.evaluate(() => window.tideforgeFixture.setGearUnavailable(false));
    assert.equal(await page.getByText('Equipment details are unavailable. Reconnect before upgrading.').count(), 0);
    await page.evaluate(() => window.tideforgeFixture.setGearReady());
    await page.locator('#tab-tideforge').click();
  });
  await check('inactive and unavailable feature states remain local', async () => {
    await page.evaluate(() => window.tideforgeFixture.setAvailability('inactive'));
    await page.getByText('Tideforge is not active on this server yet.').waitFor({ state: 'visible' });
    await page.evaluate(() => window.tideforgeFixture.setAvailability('unavailable'));
    await page.getByText('Tideforge is unavailable. Refresh the page or try again later.').waitFor({ state: 'visible' });
    await assertPlayerLanguage(page.locator('.tideforge'));
    await page.getByRole('button', { name: 'Retry Tideforge' }).click();
    await page.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  });
  await desktop.ctx.close();

  const namedDesktop = await context({ width: 1440, height: 900 });
  const named = namedDesktop.page;
  await named.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await named.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  const secondCard = named.locator('#tideforge-panel-gear .card').nth(1);
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
    await named.locator('#tab-inventory').click();
    const rod = named.locator('article[aria-label="Equipped rod"]');
    await rod.getByRole('heading', { name: 'Old Faithful' }).waitFor({ state: 'visible' });
    assert.match(await rod.innerText(), /River Rod · Item 2/);
    assert.match(await named.locator('article[aria-label="Equipped boat"]').innerText(), /Boat Friend.*River Boat/s);
    assert.match(await named.locator('article[aria-label="Equipped net"]').innerText(), /Net Friend.*Twig Net/s);
    await rod.screenshot({ path: new URL('desktop-equipped-name.png', renameOutput).pathname.slice(1) });
    await named.locator('#tab-tideforge').click();
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
  await check('Tackle Box retains three sections and refining lives in Processing', async () => {
    const gear = tacklePage.getByRole('region', { name: 'Gear', exact: true });
    await gear.waitFor({ state: 'visible' });
    const snapshot = () => tacklePage.evaluate(() => JSON.stringify({
      gear: window.tideforgeFixture.gearInstances, items: window.tideforgeFixture.items,
    }, (_key, value) => typeof value === 'bigint' ? value.toString() : value));
    const before = await snapshot();
    const tabs = tacklePage.getByRole('tablist', { name: 'Tackle box sections' });
    assert.deepEqual(await tabs.getByRole('tab').allTextContents(), ['Gear / Bait', 'Mats', 'Fish']);
    assert.equal(await tacklePage.getByRole('tab', { name: 'Raid Workshop' }).count(), 0);
    assert.equal(await tacklePage.getByRole('heading', { name: 'Raid Workshop' }).count(), 0);
    assert.equal(await tacklePage.locator('#tackle-panel-workshop').count(), 0);
    const reinforcement = gear.getByRole('region', { name: 'River Rod rod reinforcement' });
    await reinforcement.getByRole('button', { name: 'Reinforce rod' }).waitFor({ state: 'visible' });
    await tacklePage.screenshot({ path: new URL('tackle.png', retireOutput).pathname.slice(1) });
    await tabs.getByRole('tab', { name: 'Mats', exact: true }).click();
    await tacklePage.getByRole('region', { name: 'Junk and materials' }).waitFor({ state: 'visible' });
    const fish = tabs.getByRole('tab', { name: 'Fish', exact: true });
    await fish.click();
    await tacklePage.locator('#tackle-panel-fish').getByRole('button', { name: 'Select all', exact: true }).waitFor({ state: 'visible' });
    await tacklePage.locator('#tackle-panel-fish').getByRole('button', { name: /Preview sale/ }).waitFor({ state: 'visible' });
    await fish.focus(); await fish.press('ArrowRight');
    assert.equal(await tabs.getByRole('tab', { name: 'Gear / Bait', exact: true }).getAttribute('aria-selected'), 'true');
    assert.equal(await tabs.getByRole('tab', { name: 'Gear / Bait', exact: true }).evaluate(node => document.activeElement === node), true);
    await gear.waitFor({ state: 'visible' });
    await tacklePage.locator('#tab-tideforge').click();
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

  const vaultDesktop = await context({ width: 1440, height: 1600 });
  const vaultPage = vaultDesktop.page;
  await vaultPage.goto(`${base}/#tideforge`, { waitUntil: 'networkidle' });
  await vaultPage.locator('#tideforge-tab-gear').waitFor({ state: 'visible' });
  await vaultPage.evaluate(() => {
    window.tideforgeFixture.items = [
      { key: 800n, quantity: 11n }, { key: 801n, quantity: 22n },
      { key: 1000n, quantity: 33n }, { key: 1100n, quantity: 44n },
    ];
  });
  await check('Basic materials reuse F-grade art while quantities remain separate', async () => {
    await tab(vaultPage, 'vault');
    const rows = vaultPage.locator('#tideforge-panel-vault .rows').first();
    for (const [basicLabel, basicQuantity, gradeLabel, gradeQuantity, expected] of [
      ['Basic fillets', '11', 'F-grade fillets', '33', '/raid-materials/fillets-f.png'],
      ['Basic scales', '22', 'F-grade scales', '44', '/raid-materials/scales-f.png'],
    ]) {
      const basic = rows.locator('p').filter({ hasText: new RegExp(`^\\s*${basicLabel}\\s+${basicQuantity}\\s*$`) });
      const graded = rows.locator('p').filter({ hasText: new RegExp(`^\\s*${gradeLabel}\\s+${gradeQuantity}\\s*$`) });
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
      ['/rods/river.png', 'River Rod'], ['/rods/river.png', 'River Rod'],
      ['/boats/river.png', 'River Boat'], ['/nets/twig.png', 'Twig Net'],
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
    await first.getByRole('button', { name: 'Rename' }).click();
    await first.getByRole('textbox', { name: 'Name for River Rod · Item 1' }).fill('X'.repeat(32));
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
    for (const id of ['gear', 'forge', 'processing', 'trader', 'vault', 'codex', 'reliquary']) {
      await tab(small, id); await noOverflow(small); await assertPlayerLanguage(small.locator(`#tideforge-panel-${id}`));
    }
    await tab(small, 'processing');
    await small.getByRole('button', { name: 'Select eligible on this page' }).click();
    await noOverflow(small);
    await small.locator('#tideforge-panel-processing').scrollIntoViewIfNeeded();
    if (!outputSuffix) await small.screenshot({ path: new URL('mobile-processing.png', output).pathname.slice(1) });
    await small.getByRole('button', { name: 'Dark mode' }).click();
    for (const id of ['gear', 'forge', 'processing', 'trader', 'vault', 'codex', 'reliquary']) {
      await tab(small, id); await noOverflow(small);
    }
    await tab(small, 'processing');
    await small.getByRole('button', { name: 'Open navigation' }).click();
    await noOverflow(small);
    await small.locator('#mobile-navigation').getByRole('button', { name: 'Tackle box' }).click();
    assert.equal(new URL(small.url()).hash, '#inventory');
    await small.getByRole('button', { name: 'Open navigation' }).click();
    await small.locator('#mobile-navigation').getByRole('button', { name: 'Tideforge' }).click();
    assert.equal(new URL(small.url()).hash, '#tideforge');
    await small.locator('#tideforge-panel-processing').waitFor({ state: 'visible' });
    await noOverflow(small);
  });
  await check('mobile long names and editing fit light and dark themes', async () => {
    const theme = small.getByRole('button', { name: 'Dark mode' });
    assert.equal(await theme.getAttribute('aria-pressed'), 'true');
    await theme.click();
    assert.equal(await theme.getAttribute('aria-pressed'), 'false');
    await tab(small, 'gear');
    const card = small.locator('#tideforge-panel-gear .card').nth(1);
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
