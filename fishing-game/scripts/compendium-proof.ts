import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import catalog from '../content/species.json';
import world from '../content/world.json';
import { castChance, rankSpreads, weightRangeAtLength } from '../apps/web/src/lib/game/compendium';

const reference = [];
for (const fish of catalog.species) {
  const bands = rankSpreads(fish);
  assert.deepEqual(bands.map(row => row.rarity), fish.allowedRarities);
  assert(Math.abs(bands.reduce((sum, row) => sum + row.sharePercent, 0) - 100) < 1e-9);
  for (const band of bands) {
    assert(band.minLengthMm <= band.maxLengthMm && band.minWeightG <= band.maxWeightG);
    const top = weightRangeAtLength(fish, band.rarity, band.maxLengthMm);
    reference.push({speciesId:fish.speciesId,...band,minWeightAtMaxLength:top.min});
  }
}
for (const biome of world.biomes) {
  const pool = catalog.species.filter(fish => fish.biomeId === biome.biomeId);
  assert.equal(pool.reduce((sum, fish) => sum + fish.encounterWeight, 0),1_000_000);
}
const fihs=catalog.species.find(fish=>fish.key==='fihs')!;
const sock=catalog.species.find(fish=>fish.key==='nidalees-lost-sock')!;
assert.deepEqual(rankSpreads(fihs).map(row=>row.rarity),['UUR']);
assert.deepEqual(rankSpreads(sock).map(row=>row.rarity),['F']);
assert(Math.abs(castChance(fihs)-0.000100339150159872)<1e-12);
assert(Math.abs(castChance(sock)-0.000050169587539968)<1e-12);
await mkdir('.local',{recursive:true});
await writeFile('.local/compendium-ranges.json',JSON.stringify(reference));
const result=spawnSync('cargo',['run','-p','game-rules','--example','compendium-proof','--locked','--','.local/compendium-ranges.json'],{encoding:'utf8',windowsHide:true});
if(result.status!==0) throw Error(result.stderr||result.stdout);
console.log(result.stdout.trim());
console.log('PASS all species shares, restricted exceptions, approved base odds and biome pools.');
