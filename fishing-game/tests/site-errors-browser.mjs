import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { siteErrorCopy } from '../apps/web/src/lib/game/site-errors.ts';

const { chromium } = await import(pathToFileURL(process.env.FISHBOUND_PLAYWRIGHT_MODULE).href);
const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Users\\footb\\AppData\\Local\\ms-playwright\\chromium-1228\\chrome-win64\\chrome.exe' });
const base = 'http://127.0.0.1:5183', legacy = 'http://127.0.0.1:5182', live = 'https://fishbound.test';
const output = new URL('../.local/site-errors/', import.meta.url);
const results = [], diagnostics = [], blocked = [], failed = [], consoleErrors = [], documentErrors = [];
function classifiedConsole() {
  const expected = [], unexpected = [];
  for (const item of consoleErrors) {
    const match = item.text.match(/^Failed to load resource: the server responded with a status of (\d{3})\b/);
    let url = item.url;
    try { const parsed = new URL(url); parsed.hash = ''; url = parsed.href; } catch {}
    if (match && documentErrors.some(response => response.url === url && response.status === Number(match[1]))) {
      expected.push({ documentUrl: url, consoleUrl: item.url, status: Number(match[1]), text: item.text });
    } else unexpected.push(item);
  }
  return { expected, unexpected };
}
async function context(viewport, { theme = 'light', palette = 'dockside', javascript = true } = {}) {
  const ctx = await browser.newContext({ viewport, hasTouch: viewport.width === 375, javaScriptEnabled: javascript, ignoreHTTPSErrors: true });
  await ctx.route('**/*', route => {
    const url = route.request().url();
    if ([base, legacy, live].some(origin => url.startsWith(`${origin}/`)) || url.startsWith('data:') || url.startsWith('blob:')) return route.continue();
    blocked.push(url); return route.abort();
  });
  if (javascript) await ctx.addInitScript(({ theme, palette }) => {
    localStorage.setItem('fishbound:theme', theme);
    localStorage.setItem('fishbound:palette', palette);
  }, { theme, palette });
  const page = await ctx.newPage();
  const responses = [];
  page.on('pageerror', error => diagnostics.push(error.stack || error.message));
  page.on('console', message => {
    if (message.type() === 'error') consoleErrors.push({ text: message.text(), url: message.location().url });
  });
  page.on('requestfailed', request => failed.push(`${request.url()}: ${request.failure()?.errorText}`));
  page.on('response', response => {
    responses.push({ url: response.url(), status: response.status(), type: response.request().resourceType() });
    if (response.request().isNavigationRequest() && response.request().frame() === page.mainFrame() && response.status() >= 400) {
      documentErrors.push({ url: response.url(), status: response.status() });
    }
  });
  page.on('websocket', socket => {
    if (!socket.url().startsWith('ws://127.0.0.1:5183/') && !socket.url().startsWith('ws://127.0.0.1:5182/')
      && !socket.url().startsWith('wss://fishbound.test/')) blocked.push(`websocket: ${socket.url().split('?')[0]}`);
  });
  return { ctx, page, responses };
}
async function check(name, fn) {
  await fn(); results.push({ name, status: 'PASS' }); console.log(`PASS ${name}`);
}
async function assertLayout(page, status) {
  const copy = siteErrorCopy(status);
  await page.getByRole('heading', { name: copy.title, level: 1, exact: true }).waitFor({ state: 'visible' });
  assert.equal(await page.locator('main').count(), 1);
  assert.equal(await page.title(), `${status} · Fishbound`);
  assert.equal(await page.locator('meta[name="robots"]').getAttribute('content'), 'noindex, nofollow');
  assert.match(await page.locator('.site-error-kicker').innerText(), new RegExp(`HTTP ${status}`));
  assert.equal(await page.locator('.site-error-description').innerText(), copy.description);
  assert.doesNotMatch(await page.locator('main').innerText(), /UNSAFE_|secret-token/);
  assert.equal(await page.locator('main img[alt=""]').count(), 2);
  assert.equal(await page.getByRole('link', { name: 'Back to Camp' }).getAttribute('href'), '/#camp');
  assert.equal(await page.getByRole('link', { name: 'Open Fish Book' }).getAttribute('href'), '/catalog');
  const [width, scroll] = await page.evaluate(() => [document.documentElement.clientWidth, document.documentElement.scrollWidth]);
  assert(scroll <= width + 1, `horizontal overflow ${width}/${scroll}`);
  for (const action of await page.locator('.site-error-action').all()) {
    const box = await action.boundingBox();
    assert(box && box.height >= 44, 'error actions must have 44px targets');
  }
}
try {
  await check('expected statuses render safe copy with genuine HTTP responses', async () => {
    const { ctx, page } = await context({ width: 1440, height: 1000 });
    try {
      for (const status of [401, 403, 404, 408, 410, 429, 500, 502, 503, 504]) {
        const response = await page.goto(`${base}/${status}?token=secret-token#section`, { waitUntil: 'networkidle' });
        assert.equal(response.status(), status);
        assert.match(response.headers()['content-type'], /^text\/html/);
        if (status === 429) assert.equal(response.headers()['retry-after'], '120');
        await assertLayout(page, status);
        const retry = page.getByRole('link', { name: 'Try this page again' });
        assert.equal(await retry.count(), siteErrorCopy(status).retry ? 1 : 0);
        if (siteErrorCopy(status).retry) {
          assert.equal(await retry.getAttribute('href'), `/${status}`);
          assert.notEqual(await retry.getAttribute('data-sveltekit-reload'), null);
        }
      }
      const api = await ctx.request.get(`${base}/api-fail`, { headers: { accept: 'application/json' } });
      assert.equal(api.status(), 403); assert.match(api.headers()['content-type'], /^application\/json/);
      assert.equal((await api.json()).message, 'Fixture API error');
    } finally { await ctx.close(); }
  });

  await check('retry is manual GET, drops the query and can recover without replaying', async () => {
    const { ctx, page } = await context({ width: 1440, height: 1000 });
    try {
      const navigations = [];
      page.on('request', request => { if (request.isNavigationRequest()) navigations.push({ url: request.url(), method: request.method() }); });
      const limited = await page.goto(`${base}/429`, { waitUntil: 'networkidle' });
      assert.equal(limited.status(), 429);
      const before = navigations.length; await page.waitForTimeout(600);
      assert.equal(navigations.length, before);
      const limitedReload = page.waitForResponse(response => response.request().isNavigationRequest() && response.url() === `${base}/429`);
      await page.getByRole('link', { name: 'Try this page again' }).click();
      assert.equal((await limitedReload).status(), 429);
      assert.equal(navigations.length, before + 1);
      assert.equal(navigations.at(-1).method, 'GET');
      const failedPage = await page.goto(`${base}/recover?fail=1#section`, { waitUntil: 'networkidle' });
      assert.equal(failedPage.status(), 503);
      assert.equal(await page.getByRole('link', { name: 'Try this page again' }).getAttribute('href'), '/recover');
      await page.getByRole('link', { name: 'Try this page again' }).click();
      await page.getByRole('heading', { name: 'Fixture recovery succeeded' }).waitFor({ state: 'visible' });
      assert.equal(page.url(), `${base}/recover`);
      assert.equal(navigations.at(-1).method, 'GET');
    } finally { await ctx.close(); }
  });

  await check('navigation links and keyboard focus return to real fixture routes', async () => {
    const { ctx, page } = await context({ width: 1440, height: 1000 });
    try {
      await page.goto(`${base}/404`, { waitUntil: 'networkidle' });
      await page.getByRole('link', { name: 'Fishbound', exact: true }).focus();
      await page.keyboard.press('Tab');
      assert.equal(await page.evaluate(() => document.activeElement?.textContent.trim()), 'Back to Camp');
      assert.notEqual(await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle), 'none');
      await page.getByRole('link', { name: 'Back to Camp' }).click();
      await page.getByRole('heading', { name: 'Fixture Camp' }).waitFor({ state: 'visible' });
      assert.equal(new URL(page.url()).hash, '#camp');
      await page.goto(`${base}/404`, { waitUntil: 'networkidle' });
      await page.getByRole('link', { name: 'Open Fish Book' }).click();
      await page.getByRole('heading', { name: 'Fixture Fish Book' }).waitFor({ state: 'visible' });
    } finally { await ctx.close(); }
  });

  for (const viewport of [{ width: 1440, height: 1000 }, { width: 375, height: 812 }]) {
    for (const scenario of [{ status: 404, theme: 'light', palette: viewport.width === 375 ? 'ocean' : 'dockside' },
      { status: 500, theme: 'dark', palette: 'dockside' }]) {
      await check(`${scenario.status} ${scenario.theme}/${scenario.palette} ${viewport.width}px`, async () => {
        const { ctx, page } = await context(viewport, scenario);
        try {
          const response = await page.goto(`${base}/${scenario.status}`, { waitUntil: 'networkidle' });
          assert.equal(response.status(), scenario.status); await assertLayout(page, scenario.status);
          assert.equal(await page.locator('html').getAttribute('data-theme'), scenario.theme);
          assert.equal(await page.locator('html').getAttribute('data-palette'), scenario.palette);
          if (scenario.palette === 'ocean') assert.equal(await page.locator('.site-error-card').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(213, 234, 241)');
          const toggle = page.getByRole('button', { name: 'Dark mode' });
          await toggle.waitFor({ state: 'visible' }); assert.equal(await toggle.isEnabled(), true);
          await page.screenshot({ path: new URL(`${scenario.status}-${viewport.width}.png`, output).pathname.slice(1), fullPage: true, animations: 'disabled' });
          await toggle.click();
          assert.equal(await page.locator('html').getAttribute('data-theme'), scenario.theme === 'dark' ? 'light' : 'dark');
        } finally { await ctx.close(); }
      });
    }
    await check(`fatal fallback is standalone without JavaScript ${viewport.width}px`, async () => {
      const { ctx, page, responses } = await context(viewport, { javascript: false });
      try {
        const response = await page.goto(`${base}/fatal`, { waitUntil: 'networkidle' });
        assert.equal(response.status(), 500); assert.match(response.headers()['content-type'], /^text\/html/);
        assert.doesNotMatch(await response.text(), /UNSAFE_FATAL_MARKER/);
        await page.getByRole('heading', { name: "We've hit a snag." }).waitFor({ state: 'visible' });
        assert.equal(await page.title(), '500 · Fishbound');
        assert.equal(await page.locator('meta[name="robots"]').getAttribute('content'), 'noindex, nofollow');
        assert.equal(await page.locator('main').count(), 1);
        for (const path of ['/site-errors.css', '/fish/koi.png', '/fonts/error-silkscreen-latin-400.woff2', '/fonts/error-nunito-latin-400.woff2']) {
          assert(responses.some(row => row.url === `${base}${path}` && row.status === 200), `${path} must load locally`);
        }
        const [width, scroll] = await page.evaluate(() => [document.documentElement.clientWidth, document.documentElement.scrollWidth]);
        assert(scroll <= width + 1);
        await page.screenshot({ path: new URL(`fatal-${viewport.width}.png`, output).pathname.slice(1), fullPage: true, animations: 'disabled' });
        await page.getByRole('link', { name: 'Open Fish Book' }).click();
        await page.getByRole('heading', { name: 'Fixture Fish Book' }).waitFor({ state: 'visible' });
      } finally { await ctx.close(); }
    });
  }

  await check('unexpected error response and fatal template do not leak server exceptions', async () => {
    const { ctx, page } = await context({ width: 1440, height: 1000 });
    try {
      const response = await page.goto(`${base}/throw`, { waitUntil: 'networkidle' });
      assert.equal(response.status(), 500); assert.doesNotMatch(await response.text(), /UNSAFE_THROW_MARKER/);
      await assertLayout(page, 500);
      const fatal = await page.goto(`${base}/fatal`, { waitUntil: 'networkidle' });
      assert.equal(fatal.status(), 500); assert.doesNotMatch(await fatal.text(), /UNSAFE_FATAL_MARKER/);
    } finally { await ctx.close(); }
  });

  await check('lazy section reload drops the query but preserves the hash as GET', async () => {
    const { ctx, page } = await context({ width: 1440, height: 1000 });
    try {
      const navigations = [];
      page.on('request', request => { if (request.isNavigationRequest()) navigations.push({ url: request.url(), method: request.method() }); });
      await page.goto(`${legacy}/lazy.html?failures=2&token=secret-token#section`, { waitUntil: 'networkidle' });
      await page.getByRole('heading', { name: 'We hit a snag loading this section.' }).waitFor({ state: 'visible' });
      try {
        await page.getByRole('button', { name: 'Retry section' }).click({ timeout: 5000 });
      } catch (error) {
        const overlays = await page.locator('vite-error-overlay').evaluateAll(elements => elements.map(el => el.shadowRoot?.innerText ?? el.innerText));
        throw new Error(`Retry section click failed; Vite overlays: ${JSON.stringify(overlays)}`, { cause: error });
      }
      await page.getByRole('button', { name: 'Reload page' }).waitFor({ state: 'visible' });
      await page.getByRole('button', { name: 'Reload page' }).click();
      await page.waitForURL(`${legacy}/lazy.html#section`);
      assert.equal(navigations.at(-1).method, 'GET');
      assert.equal(navigations.at(-1).url, `${legacy}/lazy.html`);
    } finally { await ctx.close(); }
  });

  await check('private dev serves the new 404 without player actions', async () => {
    const { ctx, page } = await context({ width: 1440, height: 1000 });
    try {
      const response = await page.goto(`${live}/site-errors-read-only-not-found`, { waitUntil: 'networkidle' });
      assert.equal(response.status(), 404);
      await assertLayout(page, 404);
      assert.equal(await page.locator('.connection').count(), 0);
      await page.screenshot({ path: new URL('private-404.png', output).pathname.slice(1), fullPage: true, animations: 'disabled' });
    } finally { await ctx.close(); }
  });

  const { expected: expectedDocumentLogs, unexpected } = classifiedConsole();
  diagnostics.push(...unexpected);
  assert.deepEqual(blocked, []); assert.deepEqual(failed, []); assert.deepEqual(diagnostics, []);
  console.log(`EXPECTED_DOCUMENT_HTTP_LOGS=${expectedDocumentLogs.length}`);
} catch (error) {
  results.push({ name: 'site errors browser suite', status: 'FAIL', error: error.stack || String(error) });
  console.error(error); process.exitCode = 1;
} finally {
  const { expected, unexpected } = classifiedConsole();
  if (!diagnostics.length) diagnostics.push(...unexpected);
  await writeFile(new URL('expected-document-errors.json', output), JSON.stringify(expected, null, 2));
  await writeFile(new URL('browser-results.json', output), JSON.stringify({ results, diagnostics, blocked, failed, consoleErrors, documentErrors }, null, 2));
  await browser.close();
}
