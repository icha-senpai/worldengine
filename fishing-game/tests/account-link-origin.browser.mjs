import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { isAbsolute } from 'node:path';
import { pathToFileURL } from 'node:url';

const modulePath = process.env.FISHBOUND_PLAYWRIGHT_MODULE;
assert(modulePath && isAbsolute(modulePath), 'Set FISHBOUND_PLAYWRIGHT_MODULE to the installed Playwright module absolute path.');
const { chromium } = await import(pathToFileURL(modulePath).href);

const trustedOrigin = 'https://game.test';
const untrustedOrigin = 'https://untrusted.test';
const linkerOrigin = 'https://linker.test';
const fixturePath = '/fixture/private-path?marker=private-query';
const startPath = '/auth/discord/start';
const referrerMeta = '<meta name="referrer" content="strict-origin-when-cross-origin" />';
const template = await readFile(new URL('../apps/web/src/app.html', import.meta.url), 'utf8');
assert.equal(template.split(referrerMeta).length, 2, 'Expected exactly one approved referrer meta.');
for (const placeholder of ['%sveltekit.nonce%', '%sveltekit.head%', '%sveltekit.body%']) {
  assert(template.includes(placeholder), `Missing template placeholder ${placeholder}`);
}

const browser = await chromium.launch({
  channel: 'msedge',
  headless: true,
  args: [
    '--disable-background-networking',
    '--disable-component-update',
    '--disable-default-apps',
    '--disable-sync',
    '--disable-quic',
    '--no-first-run',
    '--host-resolver-rules=MAP * ~NOTFOUND'
  ]
});

const devices = [
  { name: 'desktop', viewport: { width: 1440, height: 900 }, isMobile: false, hasTouch: false },
  {
    name: 'mobile',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
    userAgent: 'Mozilla/5.0 (Linux; Android 14; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36'
  }
];
const cases = [
  { name: 'baseline-no-meta', source: trustedOrigin, target: trustedOrigin, baseline: true, origin: 'null', status: 403, referer: null },
  { name: 'same-origin', source: trustedOrigin, target: trustedOrigin, origin: trustedOrigin, status: 400, referer: `${trustedOrigin}${fixturePath}` },
  { name: 'cross-origin', source: trustedOrigin, target: linkerOrigin, origin: trustedOrigin, status: 400, referer: `${trustedOrigin}/` },
  { name: 'untrusted-source', source: untrustedOrigin, target: linkerOrigin, origin: untrustedOrigin, status: 403, referer: `${untrustedOrigin}/` },
  { name: 'opaque-sandbox', source: trustedOrigin, target: linkerOrigin, sandbox: true, origin: 'null', status: 403 }
];

function escapeAttribute(value) {
  return value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

function renderFixture(testCase) {
  const action = `${testCase.target}${startPath}`;
  const form = `<form method="post" action="${action}"><input name="challenge_id" value="0"><input name="proof" value="0"><button type="submit">Link Discord</button></form>`;
  const body = testCase.sandbox
    ? `<iframe id="sandbox" sandbox="allow-forms" srcdoc="${escapeAttribute(form)}"></iframe>`
    : form;
  return (testCase.baseline ? template.replace(referrerMeta, '') : template)
    .replaceAll('%sveltekit.nonce%', 'fixture-nonce')
    .replace('%sveltekit.head%', '')
    .replace('%sveltekit.body%', body);
}

const results = [];
try {
  for (const { name: device, ...deviceOptions } of devices) {
    for (const testCase of cases) {
      const context = await browser.newContext({
        ...deviceOptions,
        serviceWorkers: 'block',
        acceptDownloads: false
      });
      const unexpected = [];
      const routingErrors = [];
      const posts = [];
      const sourceUrl = `${testCase.source}${fixturePath}`;
      const targetUrl = `${testCase.target}${startPath}`;
      try {
        await context.route('**/*', async route => {
          const request = route.request();
          try {
            if (request.url() === sourceUrl && request.method() === 'GET') {
              await route.fulfill({
                status: 200,
                headers: { 'content-type': 'text/html; charset=utf-8', 'referrer-policy': 'no-referrer', 'cache-control': 'no-store' },
                body: renderFixture(testCase)
              });
              return;
            }
            if (request.url() === targetUrl && request.method() === 'POST') {
              const fields = new URLSearchParams(request.postData() ?? '');
              assert.deepEqual([...fields.entries()], [['challenge_id', '0'], ['proof', '0']]);
              const headers = await request.allHeaders();
              const origin = headers.origin ?? null;
              const referer = headers.referer ?? null;
              const status = origin === trustedOrigin ? 400 : 403;
              posts.push({ origin, referer, status });
              await route.fulfill({
                status,
                headers: { 'content-type': 'text/plain; charset=utf-8', 'referrer-policy': 'no-referrer' },
                body: status === 400 ? 'Invalid linking challenge' : 'Open account linking from the matching game website.'
              });
              return;
            }
            unexpected.push({ method: request.method(), url: request.url() });
            await route.abort('blockedbyclient');
          } catch (error) {
            routingErrors.push(error.message);
            await route.abort('blockedbyclient').catch(() => {});
          }
        });
        const page = await context.newPage();
        await page.goto(sourceUrl, { waitUntil: 'load', timeout: 10000 });
        const button = testCase.sandbox
          ? page.frameLocator('#sandbox').getByRole('button', { name: 'Link Discord' })
          : page.getByRole('button', { name: 'Link Discord' });
        const [response] = await Promise.all([
          page.waitForResponse(response => response.url() === targetUrl && response.request().method() === 'POST', { timeout: 10000 }),
          deviceOptions.hasTouch ? button.tap({ timeout: 10000 }) : button.click({ timeout: 10000 })
        ]);
        assert.deepEqual(unexpected, [], 'Unexpected network requests must be blocked.');
        assert.deepEqual(routingErrors, [], 'Fixture routing failed.');
        assert.equal(posts.length, 1);
        const observed = posts[0];
        assert.equal(response.status(), testCase.status);
        assert.equal(observed.status, testCase.status);
        assert.equal(observed.origin, testCase.origin);
        if ('referer' in testCase) assert.equal(observed.referer, testCase.referer);
        if (testCase.target !== testCase.source && observed.referer) {
          assert(!observed.referer.includes('private-path') && !observed.referer.includes('private-query'));
        }
        results.push({ device, case: testCase.name, ...observed });
      } finally {
        await context.close();
      }
    }
  }
  console.log(JSON.stringify({ passed: true, cases: results }, null, 2));
} finally {
  await browser.close();
}
