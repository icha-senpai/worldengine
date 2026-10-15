import { requireRustWorkflow } from './rust-build.mjs';
import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import catalog from '../content/species.json';
import world from '../content/world.json';
import crafting from '../content/crafting.json';
import { baselineLoadout, castChance, perfectCatchOdds, rankSpreads, weightRangeAtLength, type RateLoadout } from '../apps/web/src/lib/game/compendium';
import { bestPerfectLoadout } from '../apps/web/src/lib/game/compendium-loadouts';

requireRustWorkflow();

assert.deepEqual([bestPerfectLoadout.power,bestPerfectLoadout.luckBp,bestPerfectLoadout.sizeRolls],[185,12000,16]);
const loadouts: RateLoadout[] = [baselineLoadout,
  {name:'Prismatic River Rod · Trophy Chum',power:50,luckBp:4250,sizeRolls:8,rulesVersion:11},
  bestPerfectLoadout,
  {...bestPerfectLoadout,name:'Legacy natural-only comparison',luckBp:9500,sizeRolls:8,rulesVersion:10},
];
const reference = [];
for(const loadout of loadouts) for(const fish of catalog.species) {
  const bands=rankSpreads(fish,loadout);
  const perfect=perfectCatchOdds(fish,loadout);
  assert(Math.abs(bands.reduce((sum,row)=>sum+row.sharePercent,0)-100)<1e-9);
  const speciesChance=castChance(fish,undefined,loadout);
  assert(speciesChance>0&&speciesChance<1);
  if(fish.key==='nidalees-lost-sock') assert.equal(perfect,null);
  else {
    assert(perfect&&perfect.chancePerCast>0&&perfect.chancePerCast<castChance(fish,'UUR',loadout));
    // Eight size attempts do not provide eight species/rank attempts.
    assert.equal(castChance(fish,'UUR',loadout),castChance(fish,'UUR',{...loadout,sizeRolls:1}));
  }
  for(const band of bands) reference.push({speciesId:fish.speciesId,loadout,...band,minWeightAtMaxLength:weightRangeAtLength(fish,band.rarity,band.maxLengthMm).min,
    ...(band.rarity==='UUR'&&perfect?{naturalPerfectSizeChance:perfect.naturalSizeChance,perfectSizeChance:perfect.sizeChance,perfectCastChance:perfect.chancePerCast}:{})});
}
// Verify the advertised best preset against every currently available combination
// for every eligible species, including tiny fish and Fihs.
for(const fish of catalog.species.filter(row=>row.key!=='nidalees-lost-sock')) {
  const best=perfectCatchOdds(fish,bestPerfectLoadout)!.chancePerCast;
  const rod=world.rods.at(-1)!;const quality=crafting.qualities.at(-1)!;
  for(const first of crafting.baits) for(const second of crafting.baits) {
    const candidate={name:'candidate pair',power:rod.power+quality.powerBonus,luckBp:rod.luckBp+quality.luckBp+first.luckBp+second.luckBp,sizeRolls:Math.max(1,(first.luckBp>0?first.sizeRolls:0)+(second.luckBp>0?second.sizeRolls:0)),rulesVersion:14};
    assert(best+1e-15>=perfectCatchOdds(fish,candidate)!.chancePerCast,`Best preset is weaker for ${fish.name}`);
  }
}
await writeFile('.local/compendium-loadout-ranges.json',JSON.stringify(reference));
const result=spawnSync('cargo',['run','-p','game-rules','--example','compendium-proof','--locked','--','.local/compendium-loadout-ranges.json'],{encoding:'utf8',windowsHide:true});
if(result.status!==0)throw Error(result.stderr||result.stdout);
console.log(result.stdout.trim());
console.log('PASS Rust luck/power and perfect-size odds for baseline, geared, best and legacy loadouts; best preset dominates all 169 bait pairs at maximum gear.');
const carp=catalog.species.find(row=>row.key==='carp')!;
console.log(JSON.stringify(loadouts.slice(0,3).map(loadout=>({loadout:loadout.name,carpPerfectOneIn:1/perfectCatchOdds(carp,loadout)!.chancePerCast})),null,2));
