import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { raidArtwork } from '../apps/web/src/lib/game/raid-art.ts';

const raids = JSON.parse(readFileSync(new URL('../content/raids.json', import.meta.url), 'utf8'));
const manifest = JSON.parse(readFileSync(new URL('../assets/manifest.json', import.meta.url), 'utf8'));
const assetsRoot = new URL('../assets/', import.meta.url);
const grades = raids.grades.map(row => row.name.toLowerCase());
const families = [
  { kind: 'seal', prefix: '/raid-seals/' },
  { kind: 'fillets', prefix: '/raid-materials/fillets-' },
  { kind: 'scales', prefix: '/raid-materials/scales-' }
];

test('raid artwork URLs cover each raid family and policy grade', () => {
  assert.equal(raidArtwork('supply'), '/baits/expedition-chum.png');
  assert.equal(raidArtwork('supply', 9), '/baits/expedition-chum.png');

  for (const family of families) {
    for (const [grade, rank] of grades.entries()) {
      const expected = `${family.prefix}${rank}.png`;
      assert.equal(raidArtwork(family.kind, grade), expected);
    }
  }
  assert.equal(raidArtwork('unknown', 0), undefined);
});

test('invalid grades never guess an artwork rank', () => {
  for (const family of ['seal', 'fillets', 'scales']) {
    for (const grade of [undefined, -1, 10, NaN, Infinity, -Infinity, 0.5]) {
      assert.equal(raidArtwork(family, grade), undefined, `${family} grade ${String(grade)}`);
    }
  }
});

test('all 31 raid assets are manifest-listed 256px RGBA PNGs with matching SHA256', () => {
  const expectedUrls = [
    '/baits/expedition-chum.png',
    ...grades.map(grade => `/raid-seals/${grade}.png`),
    ...grades.map(grade => `/raid-materials/fillets-${grade}.png`),
    ...grades.map(grade => `/raid-materials/scales-${grade}.png`)
  ].sort();
  const records = [
    ...manifest.baits.filter(row => row.key === 'expedition-chum'),
    ...manifest.raidSeals,
    ...manifest.raidMaterials
  ];
  assert.equal(manifest.version, 1);
  assert.equal(records.length, 31);
  assert.deepEqual(records.map(row => row.url).sort(), expectedUrls);

  const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
  for (const record of records) {
    const path = fileURLToPath(new URL(record.url.slice(1), assetsRoot));
    const bytes = readFileSync(path);
    assert.deepEqual(bytes.subarray(0, 8), signature, record.url);
    assert.equal(bytes.toString('ascii', 12, 16), 'IHDR', record.url);
    assert.equal(bytes.readUInt32BE(16), 256, record.url);
    assert.equal(bytes.readUInt32BE(20), 256, record.url);
    assert.equal(bytes[25], 6, `${record.url} must be RGBA PNG`);
    assert.equal(record.width, 256, record.url);
    assert.equal(record.height, 256, record.url);
    assert.equal(record.bytes, bytes.length, record.url);
    assert.equal(
      record.sha256,
      createHash('sha256').update(bytes).digest('hex'),
      record.url
    );
  }
});
