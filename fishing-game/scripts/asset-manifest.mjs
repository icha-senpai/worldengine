import { createHash } from 'node:crypto';
import { readFile, readdir, writeFile } from 'node:fs/promises';

const root = new URL('../assets/', import.meta.url);
async function collect(folder) {
  const entries = [];
  for (const filename of (await readdir(new URL(`${folder}/`, root))).filter((name) => name.endsWith('.png')).sort()) {
    const buffer = await readFile(new URL(`${folder}/${filename}`, root));
    if (!buffer.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])) || buffer.toString('ascii', 12, 16) !== 'IHDR') {
      throw new Error(`Invalid PNG: ${folder}/${filename}`);
    }
    entries.push({ key: filename.slice(0, -4), url: `/${folder}/${filename}`, width: buffer.readUInt32BE(16), height: buffer.readUInt32BE(20), bytes: buffer.length, sha256: createHash('sha256').update(buffer).digest('hex') });
  }
  return entries;
}
const manifest = { version: 1, fish: await collect('fish'), rankCards: await collect('rank-cards'), items: await collect('items'), rods: await collect('rods'), licences: await collect('licences'), baits: await collect('baits') };
await writeFile(new URL('manifest.json', root), `${JSON.stringify(manifest, null, 2)}\n`);
console.log(`Asset manifest: ${manifest.fish.length} fish sprites, ${manifest.rankCards.length} rank cards, ${manifest.items.length} item sprites, ${manifest.rods.length} rods, ${manifest.licences.length} licences, ${manifest.baits.length} baits.`);
