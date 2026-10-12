import assert from 'node:assert/strict';
import catalog from '../content/species.json';
import { baselineLoadout, castChance, rankMaximumOdds, rankSpreads, type CatalogFish, type RateLoadout } from '../apps/web/src/lib/game/compendium';
import { bestPerfectLoadout } from '../apps/web/src/lib/game/compendium-loadouts';

const close=(actual:number,expected:number)=>assert(Math.abs(actual-expected)<1e-12,`${actual} != ${expected}`);
const ranks=['F','D','C','B','A','S','SS','SSS','UR','UUR'];
// Enumerate normal rank draws, failed/successful Focus coins, every missing
// replacement, and the updated collection before a possible second pull.
function branches(fish:CatalogFish,base:RateLoadout,mask:number) {
  const first=fish.ranks.map(r=>({rank:r.rarity,p:castChance(fish,r.rarity,{...base,power:0,focus:undefined})}));
  const missing=first.filter(r=>!(mask&(1<<ranks.indexOf(r.rank))));
  const totalMissing=missing.reduce((n,r)=>n+r.p,0);
  const result:{rank:string;p:number}[]=[];
  for(const row of first) {
    if(!totalMissing||!(mask&(1<<ranks.indexOf(row.rank)))) result.push(row);
    else { result.push({...row,p:row.p*0.8}); for(const next of missing) result.push({rank:next.rank,p:row.p*0.2*next.p/totalMissing}); }
  }
  return result;
}
let checked=0;
for(const fish of catalog.species.filter(f=>f.countsForOrdinaryCollectionCompletion)) {
  for(const mask of [1,511,512,1023]) for(const base of [{...baselineLoadout,power:200},bestPerfectLoadout]) {
    const focus={biomeIds:[fish.biomeId],rankMasks:{[fish.speciesId]:mask}};
    const loadout={...base,focus};
    close(castChance(fish,undefined,loadout),castChance(fish,undefined,base));
    const paths=branches(fish,base,mask);
    const speciesP=paths.reduce((s,r)=>s+r.p,0);
    for(const rank of fish.ranks) {
      const size=rankMaximumOdds(fish,rank.rarity,base)!.sizeChance;
      for(const sizeChance of [1,size]) {
        let expected=0;
        for(const first of [{rank:'other',p:1-speciesP},...paths]) {
          const firstHit=first.rank===rank.rarity?sizeChance:0;
          const maskAfter=first.rank==='other'?mask:mask|(1<<ranks.indexOf(first.rank));
          const secondHit=branches(fish,base,maskAfter).filter(r=>r.rank===rank.rarity).reduce((n,r)=>n+r.p*sizeChance,0);
          expected+=first.p*(firstHit+(1-firstHit)*base.power/200*secondHit);
        }
        close(sizeChance===1?castChance(fish,rank.rarity,loadout):rankMaximumOdds(fish,rank.rarity,loadout)!.chancePerCast,expected);
        checked++;
      }
    }
    close(rankSpreads(fish,loadout).reduce((n,r)=>n+r.sharePercent,0),100);
  }
}
for(const fish of catalog.species.filter(f=>!f.countsForOrdinaryCollectionCompletion)) {
  const loadout={...bestPerfectLoadout,focus:{biomeIds:[fish.biomeId],rankMasks:{[fish.speciesId]:1}}};
  close(castChance(fish,fish.allowedRarities[0],loadout),castChance(fish,fish.allowedRarities[0],bestPerfectLoadout));
}
console.log(`PASS ${checked.toLocaleString()} rank/perfect cast probabilities against independent branch enumeration, including first-pull collection updates; species/category mass and both exceptions preserved.`);
