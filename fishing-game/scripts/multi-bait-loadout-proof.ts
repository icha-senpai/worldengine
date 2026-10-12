import assert from 'node:assert/strict';
import crafting from '../content/crafting.json';
import { equippedBaits, stackedBaitBonuses, stackedMaterials } from '../apps/web/src/lib/game/bait-loadout';
const baits=crafting.baits.map(b=>({...b,resourceQuantity:BigInt(b.resourceQuantity)}));
for(const first of baits) for(const second of baits) {
  const state={baits,baitLoadout:{baitId:first.baitId,secondaryBaitId:second.baitId},ownedBaits:[...new Set([first.baitId,second.baitId])].map(baitId=>({baitId,usesLeft:2n})),config:{version:14}};
  const used=equippedBaits(state);assert.equal(used.length,2);
  const bonuses=stackedBaitBonuses(used.map(r=>r.bait));
  assert.equal(bonuses.luckBp,first.luckBp+second.luckBp);assert.equal(bonuses.xpBonusBp,first.xpBonusBp+second.xpBonusBp);
  assert.equal(bonuses.sizeRolls,Math.max(1,(first.luckBp?first.sizeRolls:0)+(second.luckBp?second.sizeRolls:0)));
  assert.equal(equippedBaits({...state,config:{version:13}}).length,1);
}
const twin={baits,baitLoadout:{baitId:13,secondaryBaitId:13},ownedBaits:[{baitId:13,usesLeft:1n}],config:{version:14}};
assert.equal(equippedBaits(twin).length,1);assert.equal(stackedBaitBonuses(equippedBaits(twin).map(r=>r.bait)).sizeRolls,8);
assert.equal(equippedBaits({...twin,ownedBaits:[{baitId:13,usesLeft:0n}]}).length,0);
assert.equal(equippedBaits({...twin,baitLoadout:{baitId:0,secondaryBaitId:13}})[0].slot,2);
assert.equal(stackedMaterials([baits[9],baits[9]]),'+6 rusted tin');
assert.equal(stackedMaterials([baits[9],baits[11]]),'+3 rusted tin · +3 scrap');
assert.deepEqual(stackedBaitBonuses([baits[12],baits[12]]),{luckBp:5000,xpBonusBp:0,sizeRolls:16});
console.log('PASS all 169 displayed bait pairs, legacy rules, odd/depleted shared stock, standalone secondary slot and stacked material totals.');
