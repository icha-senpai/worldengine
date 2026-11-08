import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { safeRetryPath, siteErrorCopy } from '../apps/web/src/lib/game/site-errors.ts';

test('HTTP statuses use the approved player copy and manual retry policy', () => {
  const cases = [
    [404, "That spot isn't on the map.", "We couldn't find this page. It may have moved, or the link may be incomplete.", false],
    [401, "Let's get you back to Camp.", 'Head back to Camp to link your Discord account, then try opening this page again.', false],
    [403, 'This part of the shore is closed.', "You can't open this page with your current access. Head back to Camp or choose another page.", false],
    [408, 'The tide took too long.', 'This page took too long to answer. Try again in a moment, or head back to Camp.', true],
    [504, 'The tide took too long.', 'This page took too long to answer. Try again in a moment, or head back to Camp.', true],
    [410, 'This trail has washed away.', 'That page is no longer available. Head back to Camp or open the Fish Book.', false],
    [429, 'One moment, angler.', 'Too many requests reached this page. Give it a moment before trying again.', true],
    [500, "We've hit a snag.", 'Something went wrong while loading this page. Try again in a moment, or head back to Camp.', true],
    [599, "We've hit a snag.", 'Something went wrong while loading this page. Try again in a moment, or head back to Camp.', true],
    [502, 'The dock is taking a breather.', "Fishbound couldn't reach this part of the site right now. Try again shortly.", true],
    [503, 'The dock is taking a breather.', "Fishbound couldn't reach this part of the site right now. Try again shortly.", true],
    [418, "We couldn't open this page.", 'Head back to Camp or choose another page to keep exploring.', false],
  ];
  for (const [status, title, description, retry] of cases) {
    assert.deepEqual(siteErrorCopy(status), { status, title, description, retry });
  }
});

test('invalid statuses and extra untrusted messages cannot change player copy', () => {
  for (const invalid of [undefined, null, NaN, Infinity, -1, 0, 399, 600, 404.1, '404', {}, Symbol('secret')]) {
    assert.deepEqual(siteErrorCopy(invalid, 'UNSAFE_MESSAGE'), siteErrorCopy(500));
  }
  assert.equal(JSON.stringify(siteErrorCopy(404, 'UNSAFE_MESSAGE')).includes('UNSAFE_MESSAGE'), false);
});

test('retry paths cannot leave the origin or retain queries and fragments', () => {
  for (const value of [undefined, null, {}, '', '//evil.example', '/\\evil.example', '\\evil', 'https://evil.example/', 'javascript:alert(1)', '/x\r\nHost:evil', '/\u0000hidden', '/\u007f']) {
    assert.equal(safeRetryPath(value), '/');
  }
  assert.equal(safeRetryPath('/'), '/');
  assert.equal(safeRetryPath('/pond/path?token=secret#section'), '/pond/path');
  assert.equal(safeRetryPath('/pond/../catalog?private=1'), '/catalog');
});

test('static and route errors avoid untrusted messages and game dependencies', async () => {
  const read = path => readFile(new URL(path, import.meta.url), 'utf8');
  const [fallback, route, component, lazy] = await Promise.all([
    read('../apps/web/src/error.html'), read('../apps/web/src/routes/+error.svelte'),
    read('../apps/web/src/lib/game/ErrorScreen.svelte'), read('../apps/web/src/lib/game/LazyPanel.svelte'),
  ]);
  assert.match(fallback, /%sveltekit\.status%/);
  assert.doesNotMatch(fallback, /%sveltekit\.error\.message%|<script\b|on(?:click|load)=/i);
  assert.match(fallback, /href="\/site-errors\.css"/);
  assert.match(fallback, /href="\/#camp"/);
  assert.match(fallback, /href="\/catalog"/);
  assert.match(route, /status=\{page\.status\}\s+retryPath=\{page\.url\.pathname\}/);
  assert.doesNotMatch(route, /page\.error/);
  assert.doesNotMatch(component, /\bGame\b|\bSDK\b|error\.message|location\.search/);
  assert.match(component, /data-sveltekit-reload/);
  assert.match(lazy, /location\.assign\(safeRetryPath\(window\.location\.pathname\) \+ window\.location\.hash\)/);
  assert.doesNotMatch(lazy, /location\.reload|location\.search/);
});

test('standalone fallback fonts and Nunito license match installed sources byte for byte', async () => {
  for (const [installed, copied] of [
    ['../node_modules/@fontsource/silkscreen/files/silkscreen-latin-400-normal.woff2', '../assets/fonts/error-silkscreen-latin-400.woff2'],
    ['../node_modules/@fontsource/nunito/files/nunito-latin-400-normal.woff2', '../assets/fonts/error-nunito-latin-400.woff2'],
    ['../node_modules/@fontsource/nunito/LICENSE', '../assets/fonts/nunito-OFL.txt'],
  ]) {
    assert.deepEqual(await readFile(new URL(copied, import.meta.url)), await readFile(new URL(installed, import.meta.url)));
  }
});
