import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('game document sets one compatible referrer policy before bootstrap', () => {
  const html = readFileSync(new URL('../apps/web/src/app.html', import.meta.url), 'utf8');
  const head = html.match(/<head\b[^>]*>([\s\S]*?)<\/head>/i);
  assert(head, 'game document must have a head');

  const tags = [...html.matchAll(/<meta\b[^>]*>/gi)]
    .filter(match => /\bname\s*=\s*(['"])referrer\1/i.test(match[0]));
  assert.equal(tags.length, 1, 'game document must have exactly one referrer meta');
  const tag = tags[0][0];
  assert.match(tag, /\bcontent\s*=\s*(['"])strict-origin-when-cross-origin\1/i);
  assert.doesNotMatch(tag, /\bcontent\s*=\s*(['"])no-referrer\1/i);

  const index = head[1].indexOf(tag);
  assert(index >= 0, 'referrer meta must be inside head');
  const charset = head[1].search(/<meta\b[^>]*\bcharset\s*=/i);
  assert(charset >= 0 && index > charset, 'referrer meta must follow charset');
  assert.match(head[1].slice(charset, index), /^<meta\b[^>]*>\s*$/i, 'referrer meta must immediately follow charset');
  assert(index < head[1].search(/<meta\b[^>]*\bname\s*=\s*(['"])viewport\1/i));
  assert(index < head[1].search(/<script\b/i), 'referrer meta must precede scripts');
  assert(index < head[1].indexOf('%sveltekit.head%'), 'referrer meta must precede bootstrap');
});
