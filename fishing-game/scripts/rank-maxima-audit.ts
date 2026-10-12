import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import catalog from '../content/species.json';
import world from '../content/world.json';
import crafting from '../content/crafting.json';
import rules from '../content/game-rules.json';
import { baselineLoadout, castChance, perfectCatchOdds, rankMaximumOdds, rankSpreads, weightRangeAtLength, type RateLoadout } from '../apps/web/src/lib/game/compendium';
import { bestPerfectLoadout } from '../apps/web/src/lib/game/compendium-loadouts';

const trophy = crafting.baits.find(row => row.key === 'trophy-chum')!;
const before: RateLoadout = {...bestPerfectLoadout, rulesVersion:11};
const after = bestPerfectLoadout;
const noBait: RateLoadout = {...after,name:'Prismatic Abyssal Rod · no bait',luckBp:after.luckBp-trophy.luckBp,sizeRolls:1};
const reference = [];
type RankResult = {
  rank: string;
  beforeOneIn: number;
  afterOneIn: number;
  improvement: number;
  afterSelectionOneIn: number;
  meanDaysAt25: number;
  meanDaysAt50: number;
};
const allSpecies: {fish: string; key: string; biome: string; ranks: RankResult[]}[] = [];
for(const fish of catalog.species) {
  const results = fish.ranks.map(rank => {
    const old = rankMaximumOdds(fish,rank.rarity,before)!;
    const current = rankMaximumOdds(fish,rank.rarity,after)!;
    assert(current.chancePerCast >= old.chancePerCast);
    assert(current.sizeChance >= -Math.expm1(trophy.sizeRolls*Math.log1p(-1/rules.perfectRollOneIn)));
    assert.equal(castChance(fish,rank.rarity,before),castChance(fish,rank.rarity,after));
    assert.deepEqual(rankMaximumOdds(fish,rank.rarity,{...noBait,rulesVersion:11}),rankMaximumOdds(fish,rank.rarity,noBait));
    if(rank.rarity==='UUR') assert.equal(current.chancePerCast,old.chancePerCast);
    return {rank:rank.rarity,beforeOneIn:1/old.chancePerCast,afterOneIn:1/current.chancePerCast,improvement:current.chancePerCast/old.chancePerCast,
      afterSelectionOneIn:1/current.sizeChance,meanDaysAt25:1/current.chancePerCast/25,meanDaysAt50:1/current.chancePerCast/50};
  });
  allSpecies.push({fish:fish.name,key:fish.key,biome:world.biomes.find(row=>row.biomeId===fish.biomeId)!.name,ranks:results});
  for(const loadout of [before,after,baselineLoadout,noBait]) for(const band of rankSpreads(fish,loadout)) {
    const maximum=rankMaximumOdds(fish,band.rarity,loadout)!;
    reference.push({speciesId:fish.speciesId,loadout,...band,minWeightAtMaxLength:weightRangeAtLength(fish,band.rarity,band.maxLengthMm).min,
      naturalMaximumSizeChance:maximum.naturalSizeChance,maximumSizeChance:maximum.sizeChance,maximumCastChance:maximum.chancePerCast});
  }
}
const sock = catalog.species.find(row=>row.key==='nidalees-lost-sock')!;
assert.equal(perfectCatchOdds(sock,after),null);
assert.equal(rankMaximumOdds(sock,'UUR',after),null);
await mkdir('.local',{recursive:true});
await writeFile('.local/trophy-all-ranks-ranges.json',JSON.stringify(reference));
const proof=spawnSync('cargo',['run','-p','game-rules','--example','compendium-proof','--locked','--','.local/trophy-all-ranks-ranges.json'],{encoding:'utf8',windowsHide:true});
if(proof.status!==0) throw Error(proof.stderr||proof.stdout);
console.log(proof.stdout.trim());
const minimumSizeChance = -Math.expm1(trophy.sizeRolls * Math.log1p(-1 / rules.perfectRollOneIn));
const summary={versions:{catalog:catalog.version,world:world.version,crafting:crafting.version,beforeRules:11,afterRules:rules.version},loadout:after,
  endpointOneIn:rules.perfectRollOneIn,worstCaseAfterSelectionOneIn:1/minimumSizeChance,allSpecies};
await writeFile('.local/trophy-all-ranks-odds.json',JSON.stringify(summary,null,2));
const carp=allSpecies.find(row=>row.key==='carp')!;
const examples=['minnow','pea-crab','carp','blue-marlin','great-hellfin-shark'].map(key=>allSpecies.find(row=>row.key===key)!);
const fmt=(n:number)=>Math.round(n).toLocaleString('en-US');
const table=carp.ranks.map(row=>`| ${row.rank} | 1 in ${fmt(row.beforeOneIn)} | 1 in ${fmt(row.afterOneIn)} | ${row.improvement.toFixed(1)}× |`).join('\n');
const exampleTable=examples.flatMap(fish=>fish.ranks.filter(row=>['F','UR'].includes(row.rank)).map(row=>`| ${fish.fish} | ${row.rank} | 1 in ${fmt(row.beforeOneIn)} | 1 in ${fmt(row.afterOneIn)} |`)).join('\n');
const report=`# Trophy Chum: maxima at every rank

Calculated ${new Date().toISOString().slice(0,10)} from species/world catalog ${catalog.version}, crafting ${crafting.version}, and rules ${rules.version}. This is a local candidate; rules 11 remains deployed until publication. Reproduce with \`npx tsx scripts/rank-maxima-audit.ts\`.

Rules 12 extends the existing 1-in-${rules.perfectRollOneIn.toLocaleString('en-US')} paired-endpoint roll to F through UUR for multi-attempt bait (currently Trophy Chum). Fish and rank are selected first; all eight attempts stay within that rank and retain the heaviest specimen, with length breaking ties. Existing UUR perfect odds, eligibility and rewards are preserved. The bait still costs ${trophy.priceCoins} coins per ${trophy.usesPerPurchase} uses and adds ${trophy.luckBp/100}% luck. XP and sale-value formulas remain unchanged; the extra maximum-size outcomes can slightly raise their averages.

For each attempt, \`s = endpoint + (1 - endpoint) / (lengthChoices × weightChoicesAtMaximumLength)\`. Eight attempts give \`1 - (1 - s)^8\`. Multiply by the species/rank pull probability and apply \`(1 + q)p - qp²\` for at least one success per cast. With Trophy Chum, after selecting the fish and rank, the paired maximum chance is at least 1 in ${summary.worstCaseAfterSelectionOneIn.toFixed(2)}; natural endpoints improve it further. This conditional chance is not the chance per cast.

Both columns below use Prismatic Abyssal Rod + Trophy Chum (power ${after.power}, ${after.luckBp/100}% luck, eight attempts). Before uses rules 11; after uses rules 12. Each target requires both exact maxima on the same catch. Per-cast odds include biome fish/junk/treasure category weights, graded luck tickets and the ${after.power/2}% independent bonus pull. Assume accepted eligible casts in the named fish's biome and continuous Trophy Chum use.

## Carp in Meadow Pond

| Rank | Before per cast | After per cast | Improvement |
|---|---:|---:|---:|
${table}

## Species contrast

| Species | Rank | Before per cast | After per cast |
|---|---|---:|---:|
${exampleTable}

## Verification and limits

The audit cross-checks all ${reference.length} displayed bands against Rust's actual luck adjustment, endpoint gate, inclusive measurement ranges and bonus-pull formula, with ${reference.length*200} generated natural samples. Forced endpoint tests cover all 2492 valid species/rank pairs without promotion. Seeded lower-rank frequency tests cover F and UR with 200,000 eight-attempt catches each; legacy RNG streams and UUR behavior remain stable. No live player data or deployment changes are made by this audit.

These are independent-trial odds, not pity thresholds. At 50 eligible casts/day, the mean wait for the exact F Carp target is ${Math.round(carp.ranks[0].meanDaysAt50).toLocaleString('en-US')} days; at 25/day it doubles. Rank-by-rank collections are still a long-term species-specific chase. The current Perfect Angler badge, species marks and announcement remain reserved for official UUR perfect catches.
`;
await writeFile('docs/trophy-chum-rank-maxima.md',report);
console.log(JSON.stringify({loadout:after,worstCaseAfterSelectionOneIn:summary.worstCaseAfterSelectionOneIn,carp:carp.ranks,examples:examples.map(row=>({fish:row.fish,ranks:row.ranks.filter(rank=>['F','UR','UUR'].includes(rank.rank))}))},null,2));
