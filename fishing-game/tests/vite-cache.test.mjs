import assert from 'node:assert/strict';
import test from 'node:test';
import { svelteKitOutDir } from '../apps/web/vite-cache.ts';

test('private and production databases use the normal SvelteKit cache', () => {
  for (const database of [undefined, '', 'fishbound-dev-local', 'fishbound-prod']) {
    assert.equal(svelteKitOutDir(database), '.svelte-kit');
  }
});

test('proof databases use a fixed cache path without path interpolation', () => {
  assert.equal(svelteKitOutDir('fishbound-proof-raids-1793354943100'), '.svelte-kit-proof');
  assert.equal(svelteKitOutDir('fishbound-proof-../../'), '.svelte-kit-proof');
});
