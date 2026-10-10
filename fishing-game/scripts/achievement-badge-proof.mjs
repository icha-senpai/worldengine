import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';

const manifest=JSON.parse(await readFile('assets/manifest.json','utf8'));
const plan=JSON.parse(await readFile('content/achievement-badge-art.json','utf8'));
const planned=new Set(plan.assets.map(asset=>asset.key));
assert.equal(plan.assets.length,113);
assert.equal(planned.size,113);
assert.equal(new Set(manifest.achievements.map(asset=>asset.key)).size,manifest.achievements.length);
assert.equal(new Set(manifest.achievements.map(asset=>asset.sha256)).size,manifest.achievements.length,'Each achievement has its own generated artwork');
if(process.argv.includes('--complete')) {
  assert.equal(manifest.achievements.length,plan.assets.length,'Every achievement needs artwork');
  assert.deepEqual(new Set(manifest.achievements.map(asset=>asset.key)),planned);
}
for(const asset of manifest.achievements) {
  assert(planned.has(asset.key));
  const bytes=await readFile(`assets${asset.url}`);
  assert.equal(createHash('sha256').update(bytes).digest('hex'),asset.sha256);
  assert.equal(bytes[25],6,'RGBA PNG required');
  assert.equal(bytes.readUInt32BE(16),bytes.readUInt32BE(20),'Square badge required');
  if(process.argv.includes('--live')) {
    const response=await fetch(`https://fish.ichaa.dev${asset.url}`);
    assert.equal(response.status,200);
    assert.equal(createHash('sha256').update(Buffer.from(await response.arrayBuffer())).digest('hex'),asset.sha256,'Public asset matches source');
  }
}
console.log(`PASS ${manifest.achievements.length} installed badges match unique achievement IDs, square RGBA PNGs and manifest hashes${process.argv.includes('--live')?', including HTTPS delivery':''}. ${113-manifest.achievements.length} prompts remain planned.`);
