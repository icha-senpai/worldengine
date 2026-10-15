import { requireRustWorkflow } from './rust-build.mjs';
import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
import {spawnSync} from 'node:child_process';
import catalog from '../content/species.json';
import world from '../content/world.json';
import crafting from '../content/crafting.json';
import rules from '../content/game-rules.json';
import {castChance,rankSpreads,rankMaximumOdds,perfectCatchOdds,weightRangeAtLength,type RateLoadout} from '../apps/web/src/lib/game/compendium';
requireRustWorkflow();

const proof:any[]=[];
const summaries:any[]=[];
for(const bait of crafting.baits.filter(b=>[3,4,5].includes(b.baitId))) {
 const after:RateLoadout={name:`Prismatic Abyssal · ${bait.name}`,power:185,luckBp:7000+bait.luckBp,sizeRolls:bait.sizeRolls,rulesVersion:rules.version};
 const before={...after,sizeRolls:1,rulesVersion:11};
 for(const f of catalog.species) for(const r of f.ranks) {
  assert.equal(castChance(f,r.rarity,before),castChance(f,r.rarity,after),'Species/rank odds unchanged');
  assert(rankMaximumOdds(f,r.rarity,after)!.sizeChance>=rankMaximumOdds(f,r.rarity,before)!.sizeChance);
  for(const loadout of [before,after]) {
   const band=rankSpreads(f,loadout).find(b=>b.rarity===r.rarity)!;
   const maximum=rankMaximumOdds(f,r.rarity,loadout)!;
   proof.push({speciesId:f.speciesId,loadout,...band,minWeightAtMaxLength:weightRangeAtLength(f,r.rarity,band.maxLengthMm).min,naturalMaximumSizeChance:maximum.naturalSizeChance,maximumSizeChance:maximum.sizeChance,maximumCastChance:maximum.chancePerCast});
  }
 }
 const byBiome=world.biomes.map(b=>{
  const chance=(loadout:RateLoadout)=>{const q=loadout.power/200;const p=catalog.species.filter(f=>f.biomeId===b.biomeId).reduce((sum,f)=>{const c=perfectCatchOdds(f,loadout)?.chancePerCast??0;return sum+2*c/(1+q+Math.sqrt((1+q)**2-4*q*c));},0);return (1+q)*p-q*p*p;};
  return {biome:b.name,beforeOneIn:1/chance(before),afterOneIn:1/chance(after)};
 });
 summaries.push({bait:bait.name,packPrice:bait.priceCoins,luckBp:bait.luckBp,sizeRolls:bait.sizeRolls,byBiome});
}
await writeFile('.local/luck-bait-sizing-proof.json',JSON.stringify(proof));
const check=spawnSync('cargo',['run','-p','game-rules','--example','compendium-proof','--locked','--','.local/luck-bait-sizing-proof.json'],{encoding:'utf8',windowsHide:true});
if(check.status!==0)throw Error(check.stderr||check.stdout);
console.log(check.stdout.trim());
await writeFile('.local/luck-bait-sizing-odds.json',JSON.stringify({versions:{rules:rules.version,crafting:crafting.version},summaries},null,2));
console.log(JSON.stringify(summaries.map(s=>({bait:s.bait,sizeRolls:s.sizeRolls,price:s.packPrice,pond:s.byBiome[0]})),null,2));
