import { readFile, writeFile } from 'node:fs/promises';
import { gunzipSync } from 'node:zlib';
import { REGIONS } from '../../src/bitcraft-ui/fishing-map.js';
const manifest = JSON.parse(await readFile('public/bitcraft-map/detail/manifest.json','utf8'));
const report = [];
async function sample(region) {
  const descriptor = JSON.parse(await readFile(`public/bitcraft-map/detail/${manifest.version}/region-${region}.json`,'utf8'));
  let chosen;
  for (const [x,z,hash] of descriptor.tiles) {
    const source = gunzipSync(await readFile(`output/research/terrain-source/l0-${x}_${z}.${hash}.bin.gz`));
    for (let rz = 0; rz < 256 && !chosen; rz++) for (let rx = 0; rx < 256; rx++) {
      const offset = ((rz + 1) * 258 + rx + 1) * 4;
      if (source[offset] !== 255 && source[offset + 3] > 0) {
        const gx = x * 256 + rx, gz = z * 256 + rz;
        chosen = { x,z,source, cx: Math.floor(gx / 32),cz: Math.floor(gz / 32) }; break;
      }
    }
    if (chosen) break;
  }
  if (!chosen) throw Error(`No water in region ${region}`);
  const host = 'https://relay.bitjita.com', database = `bitcraft-live-${region}`;
  const schema = await fetch(`${host}/v1/database/${database}/schema?version=9`).then(r => r.json());
  const table = schema.tables.find(t => t.name === 'terrain_chunk_state');
  const fields = schema.typespace.types[table.product_type_ref].Product.elements.map(e => e.name.some);
  const chunk = chosen.cz * 1000 + chosen.cx + 1;
  const row = await new Promise((resolve,reject) => {
    const socket = new WebSocket(`wss://relay.bitjita.com/v1/database/${database}/subscribe?compression=None`,'v1.json.spacetimedb');
    const timer = setTimeout(() => { socket.close(); reject(Error('timeout')); },20000);
    socket.onerror = () => { clearTimeout(timer); socket.close(); reject(Error('connection failed')); };
    socket.onmessage = async e => {
      const message = JSON.parse(typeof e.data === 'string' ? e.data : await e.data.text());
      if (message.IdentityToken) socket.send(JSON.stringify({ Subscribe: { request_id: 1,query_strings: [`SELECT * FROM terrain_chunk_state WHERE chunk_index = ${chunk}`] } }));
      if (!message.InitialSubscription) return;
      const inserts = message.InitialSubscription.database_update.tables.flatMap(t => t.updates.flatMap(u => u.inserts));
      const value = JSON.parse(inserts[0]);
      clearTimeout(timer); socket.close();
      resolve(Array.isArray(value) ? Object.fromEntries(fields.map((f,i) => [f,value[i]])) : value);
    };
  });
  let waterCells = 0, mismatches = 0, maxDifference = 0;
  for (let i = 0; i < 1024; i++) {
    const gx = chosen.cx * 32 + i % 32, gz = chosen.cz * 32 + Math.floor(i / 32);
    const offset = ((gz - chosen.z * 256 + 1) * 258 + gx - chosen.x * 256 + 1) * 4;
    if (chosen.source[offset] === 255) continue;
    const stored = chosen.source[offset + 3], live = Math.max(0,row.water_levels[i] - row.elevations[i]);
    if (!stored && !live) continue;
    waterCells++; if (stored !== live) mismatches++;
    maxDifference = Math.max(maxDifference,Math.abs(stored - live));
  }
  const result = { region,chunk,waterCells,mismatches,maxDifference };
  console.log(JSON.stringify(result)); return result;
}
for (let i = 0; i < REGIONS.length; i += 3) report.push(...await Promise.all(REGIONS.slice(i,i + 3).map(([id]) => sample(id))));
await writeFile('output/research/map-atlas-relay-verification-20261008.json',JSON.stringify({ version: manifest.version,sourceGeneratedAt: manifest.generatedAt,report },null,2) + '\n');
if (report.some(r => r.mismatches)) throw Error('Water sample disagrees with the permanent map');
