import fs from 'node:fs';

// Discussion draft only. This does not change game content or publish a module.
const root = new URL('../', import.meta.url);
const current = JSON.parse(fs.readFileSync(new URL('spacetimedb/src/content/evergather.json', root), 'utf8'));
const metals = ['Copper', 'Bronze', 'Steel', 'Chromium Steel', 'Cobalt Steel', 'Titanium', 'Tungsten Alloy', 'Mithril', 'Adamantite', 'Stariron'];
const ores = ['Copper', 'Tin', 'Iron', 'Chromite', 'Cobalt', 'Titanium', 'Tungsten', 'Mithril', 'Adamantite', 'Stariron'];
const woods = ['Ash', 'Birch', 'Pine', 'Oak', 'Maple', 'Yew', 'Ironwood', 'Ebony', 'Heartwood', 'Starwood'];
const fish = ['Minnow', 'Perch', 'Trout', 'Carp', 'Salmon', 'Pike', 'Tuna', 'Swordfish', 'Moonfin', 'Starfin'];
const herbs = ['Mint', 'Sage', 'Yarrow', 'Lavender', 'Ginseng', 'Moonleaf', 'Starbloom', 'Emberroot', 'Dragonwort', 'Aetherblossom'];
const animals = ['Rabbit', 'Deer', 'Boar', 'Wolf', 'Bear', 'Direwolf', 'Wyvern', 'Basilisk', 'Drake', 'Elder Dragon'];
const fibers = ['Hemp', 'Flax', 'Cotton', 'Jute', 'Sisal', 'Silkweed', 'Moonflax', 'Ironhemp', 'Sunweave', 'Aethercotton'];
const gems = ['Quartz', 'Agate', 'Jade', 'Amethyst', 'Topaz', 'Ruby', 'Sapphire', 'Emerald', 'Diamond', 'Astral Crystal'];
const birds = ['Crow', 'Goose', 'Pheasant', 'Falcon', 'Osprey', 'Roc', 'Gryphon', 'Stormhawk', 'Phoenix', 'Starwing'];
const regions = ['Greenbank', 'Tinridge', 'Ironvale', 'Stonewall', 'Cobalt Reach', 'Sunspire', 'Obsidian Pass', 'Moonhaven', 'Dragonfall', 'Starfall'];
const saltNames = ['Shore Salt', 'Bay Salt', 'Reef Salt', 'Deepwater Salt', 'Pearlsea Salt', 'Sunsea Salt', 'Stormsea Salt', 'Moonsea Salt', 'Dragonsea Salt', 'Starsea Salt'];
const tiers = Array.from({ length: 10 }, (_, index) => ({ tier: index + 1, level: (index + 1) * 10, region: regions[index] }));
const lines = [];
function line(skill, mode, noun, names, purpose, input = () => [], action = (i) => `${noun}: ${names[i]}`) {
  const definition = current.skills[skill];
  lines.push({ skill, label: definition.label, category: definition.category, mode, noun, names, purpose, input, action });
}
const ref = (skill, tier, quantity = 1) => ({ item: `${skill}_${tier}`, quantity });
const extra = (kind, tier, quantity = 1) => ({ item: `${kind}_${tier}`, quantity });
const own = (skill, tier, quantity = 1) => ref(skill, tier, quantity);
const names = (array, suffix) => array.map((name) => `${name} ${suffix}`);

line('fishing', 'gather', 'Fish', fish, 'Rations and fish oils.', undefined, (i) => `Catch ${fish[i]}`);
line('mining', 'gather', 'Ore', names(ores, 'Ore'), 'Ingots and alloys; each ore has a defined smelting recipe.', undefined, (i) => `Mine ${ores[i]} Ore`);
line('woodcutting', 'gather', 'Logs', names(woods, 'Log'), 'Planks, handles, charcoal, hull fittings, and braces.', undefined, (i) => `Fell ${woods[i]}`);
line('foraging', 'gather', 'Herbs', herbs, 'Extracts, tonics, inks, and rarity reagents.', undefined, (i) => `Gather ${herbs[i]}`);
line('hunting', 'gather', 'Hides and scales', animals.map((name, i) => `${name} ${i < 6 ? 'Hide' : 'Scales'}`), 'Leather, scale panels, straps, and equipment.', undefined, (i) => `Hunt ${animals[i]}`);
line('farming', 'gather', 'Fibers', names(fibers, 'Fiber'), 'Cloth, bindings, bandages, bags, and banners.', undefined, (i) => `Harvest ${fibers[i]}`);
line('excavation', 'gather', 'Buried gemstones', gems.map((name) => `Rough ${name}`), 'Cut gems, settings, runestones, and precision tools.', undefined, (i) => `Excavate ${gems[i]} Deposits`);

line('smelting', 'recipe', 'Ingots', names(metals, 'Ingot'), 'Metal components and tools. Charcoal is both fuel and, for steel, a carbon source.', (t) => {
  const inputs = [own('mining', t, 2), own('survival', t)];
  if (t === 2) return [own('mining', 1), own('mining', 2), own('survival', t)];
  if (t === 4 || t === 5) inputs.push(own('mining', 3));
  if (t === 7) inputs.push(own('mining', 5));
  return inputs;
}, (i) => `Smelt ${metals[i]} Ingots`);
line('milling', 'recipe', 'Planks', names(woods, 'Plank'), 'Handles, hulls, braces, cases, and crates.', (t) => [own('woodcutting', t, 2)], (i) => `Mill ${woods[i]} Planks`);
line('tanning', 'recipe', 'Leather and scale panels', animals.map((name, i) => `${name} ${i < 6 ? 'Leather' : 'Scale Panel'}`), 'Straps, grips, bags, bellows, and field kits.', (t) => [own('hunting', t, 2)], (i) => `${i < 6 ? 'Tan' : 'Cure'} ${animals[i]} ${i < 6 ? 'Leather' : 'Scales'}`);
line('cutting', 'recipe', 'Cut gems', gems.map((name) => `Cut ${name}`), 'Runestones, mounted gems, and precision tools.', (t) => [own('excavation', t, 2)], (i) => `Cut ${gems[i]}`);
line('weaving', 'recipe', 'Cloth', names(fibers, 'Cloth'), 'Bindings, maps, clothing components, bags, and bandages.', (t) => [own('farming', t, 2)], (i) => `Weave ${fibers[i]} Cloth`);

line('smithing', 'recipe', 'Tool heads', names(metals, 'Tool Head'), 'Pickaxes, hatchets, hammers, blades, and precision implements.', (t) => [own('smelting', t, 2)], (i) => `Forge ${metals[i]} Tool Heads`);
line('carpentry', 'recipe', 'Handles', names(woods, 'Handle'), 'Tool handles, rods, focus grips, and banners.', (t) => [own('milling', t, 2)], (i) => `Shape ${woods[i]} Handles`);
line('cooking', 'recipe', 'Rations', fish.map((name) => `Smoked ${name} Ration`), 'Usable XP buffs, field provisions, camp kits, and trade crates.', (t) => [own('fishing', t, 2), own('sailing', t), own('survival', t)], (i) => `Prepare ${fish[i]} Rations`);
line('alchemy', 'recipe', 'Extracts', names(herbs, 'Extract'), 'Tonics, map ink, and same-tier rarity attempts.', (t) => [own('foraging', t, 2)], (i) => `Extract ${herbs[i]}`);
line('tailoring', 'recipe', 'Bindings', names(fibers, 'Binding'), 'Bags, banners, maps, bandages, arrows, and tool assembly.', (t) => [own('weaving', t, 2)], (i) => `Sew ${fibers[i]} Bindings`);
line('leatherworking', 'recipe', 'Straps', animals.map((name, i) => `${name} ${i < 6 ? 'Leather' : 'Scale'} Strap`), 'Grips, harnesses, bellows, and field equipment.', (t) => [own('tanning', t, 2)], (i) => `Cut ${animals[i]} Straps`);
line('engineering', 'recipe', 'Mechanisms', names(metals, 'Mechanism'), 'Traps, calipers, survey kits, lapidary kits, and navigation tools.', (t) => [own('smelting', t, 2), own('smithing', t)], (i) => `Assemble ${metals[i]} Mechanisms`);
line('enchanting', 'recipe', 'Runestones', names(gems, 'Runestone'), 'Rune focuses, spell focuses, and same-tier rarity attempts.', (t) => [own('cutting', t), own('magic', t, 2)], (i) => `Inscribe ${gems[i]} Runestones`);
line('jewelcrafting', 'recipe', 'Mounted gems', names(gems, 'Mount'), 'Precision and magical equipment; gems are set in same-tier metal.', (t) => [own('cutting', t), own('smelting', t)], (i) => `Set ${gems[i]}`);
line('boatbuilding', 'recipe', 'Hull fittings', names(woods, 'Hull Fitting'), 'Shipwright and navigation kits, plus sailing supply requirements.', (t) => [own('milling', t, 2), own('leatherworking', t), own('furniture', t)], (i) => `Build ${woods[i]} Hull Fittings`);
line('furniture', 'recipe', 'Varnishes', names(woods, 'Varnish'), 'Wooden equipment assembly and maintenance. Free-standing furniture is outside this item reset.', (t) => [own('exploration', t, 2), extra('fish_oil', t)], (i) => `Blend ${woods[i]} Varnish`);
line('construction', 'recipe', 'Brace kits', names(woods, 'Brace Kit'), 'Builder, joinery, and forge-bellows assembly. Settlement buildings are outside this item reset.', (t) => [own('milling', t, 2), own('smithing', t), own('tailoring', t)], (i) => `Assemble ${woods[i]} Braces`);

line('combat', 'activity', 'Weapon scrap', names(metals, 'Weapon Scrap'), 'An alternative ingot supply through a controlled recycling recipe.', () => [], (i) => `Skirmish at ${regions[i]}`);
line('slayer', 'activity', 'Monster essences', names(animals, 'Essence'), 'Tonic reagents; trophies are replaced with ingredients.', () => [], (i) => `Complete a ${regions[i]} Monster Hunt`);
line('defense', 'activity', 'Recovered plates', names(metals, 'Recovered Plate'), 'Shield assembly and armor-scrap recycling.', () => [], (i) => `Guard ${regions[i]}`);
line('healing', 'activity', 'Medical notes', names(regions, 'Medical Notes'), 'Consumed while formulating tonics after field treatment.', (t) => [extra('bandage', t)], (i) => `Treat Patients at ${regions[i]}`);
line('magic', 'activity', 'Arcane dust', names(gems, 'Arcane Dust'), 'Runestone production.', () => [], (i) => `Attune the ${gems[i]} Leyline`);
line('ranged', 'activity', 'Flight feathers', names(birds, 'Flight Feather'), 'Arrow bundles; optional ammunition boosts must not gate first access to a feather tier.', () => [], (i) => `Hunt ${birds[i]} with a Bow`);

line('exploration', 'activity', 'Resins', names(woods, 'Resin'), 'Varnish ingredients discovered on woodland surveys.', () => [], (i) => `Explore the ${woods[i]} Trails`);
line('dungeoneering', 'activity', 'Relic cores', names(gems, 'Relic Core'), 'Spell-focus assembly and same-tier rarity attempts.', () => [], (i) => `Recover Relics beneath ${regions[i]}`);
line('sailing', 'activity', 'Marine salts', saltNames, 'Cooking ingredients; marine gathering during coastal routes.', () => [], (i) => `Sail the ${regions[i]} Coast`);
line('survival', 'recipe', 'Charcoal', names(woods, 'Charcoal'), 'Fuel for smelting, rations, and rendered oils.', (t) => [own('woodcutting', t, 2)], (i) => `Burn ${woods[i]} Charcoal`);
line('cartography', 'recipe', 'Maps', names(regions, 'Map'), 'Orders, survey kits, map cases, exploration, and expedition supplies.', (t) => [own('weaving', t), own('alchemy', t)], (i) => `Survey and Draw a ${regions[i]} Map`);

line('reputation', 'commission', 'Guild permits', names(regions, 'Guild Permit'), 'Consumed to authorize same-tier rarity attempts; earned by supplying the region.', (t) => [own('trading', t)], (i) => `Supply the ${regions[i]} Guild`);
line('leadership', 'recipe', 'Crew orders', names(regions, 'Crew Orders'), 'Actual expedition supplies that organize a route and crew.', (t) => [own('cartography', t), own('tailoring', t)], (i) => `Plan a ${regions[i]} Expedition`);
line('trading', 'recipe', 'Supply crates', names(regions, 'Supply Crate'), 'Regional deliveries for gold/Trading XP and guild commissions for permits/Reputation XP.', (t) => [own('milling', t, 2), own('tailoring', t), own('cooking', t)], (i) => `Pack a ${regions[i]} Supply Crate`);

const catalog = new Map();
const recipes = [];
const activities = [];
const skills = new Map(lines.map((entry) => [entry.skill, entry]));
function add(value) {
  if (catalog.has(value.key)) throw new Error(`Duplicate key: ${value.key}`);
  catalog.set(value.key, { ...value, uses: [] });
}
for (const entry of lines) for (const { tier, level } of tiers) {
  const index = tier - 1;
  const key = `${entry.skill}_${tier}`;
  add({ key, name: entry.names[index], tier, level, skill: entry.skill, group: entry.category, kind: entry.noun, source: entry.mode, sourceLabel: entry.action(index) });
  const definition = { key, skill: entry.skill, tier, level, label: entry.action(index), inputs: entry.input(tier), outputs: [{ item: key, quantity: entry.skill === 'survival' ? 3 : 1 }] };
  if (entry.mode === 'recipe') recipes.push(definition);
  else activities.push({ ...definition, level: entry.mode === 'gather' && tier === 1 ? 1 : level });
}
const secondary = [
  { key: 'fish_oil', skill: 'cooking', names: names(fish, 'Oil'), kind: 'Oil', purpose: 'Varnish production', inputs: (t) => [own('fishing', t, 3), own('survival', t)] },
  { key: 'bandage', skill: 'tailoring', names: names(fibers, 'Bandage'), kind: 'Medical supply', purpose: 'Healing activities and medical supply contracts; no HP system is assumed', inputs: (t) => [own('weaving', t), own('tailoring', t)] },
  { key: 'tonic', skill: 'alchemy', names: names(herbs, 'Tonic'), kind: 'Consumable', purpose: 'A yield buff for the next ten eligible actions', inputs: (t) => [own('alchemy', t), own('slayer', t), own('healing', t)] },
  { key: 'arrows', skill: 'carpentry', names: names(metals, 'Arrow Bundle'), kind: 'Ammunition', purpose: 'Optional Ranged action bonus; one bundle has ten charges', inputs: (t) => [own('smithing', t), own('milling', t), own('ranged', t, 3), own('tailoring', t)] },
];
for (const family of secondary) for (const { tier, level } of tiers) {
  const key = `${family.key}_${tier}`;
  add({ key, name: family.names[tier - 1], tier, level, skill: family.skill, group: 'Supporting resources', kind: family.kind, source: 'recipe', sourceLabel: `Make ${family.names[tier - 1]}` });
  recipes.push({ key, skill: family.skill, tier, level, label: `Make ${family.names[tier - 1]}`, inputs: family.inputs(tier), outputs: [{ item: key, quantity: 1 }] });
}
// Recovered material is processed deliberately, rather than masquerading as ore.
for (const { tier, level } of tiers) for (const skill of ['combat', 'defense']) recipes.push({
  key: `recycle_${skill}_${tier}`, skill: 'smelting', tier, level, label: `Recycle ${skills.get(skill).names[tier - 1]}`,
  inputs: [own(skill, tier, 3), own('survival', tier)], outputs: [own('smelting', tier)],
});

const gear = [
  ['fishing', 'Fishing Rod', 'carpentry', 'wood', ['carpentry', 'tailoring', 'smithing', 'furniture']],
  ['mining', 'Pickaxe', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['woodcutting', 'Hatchet', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['foraging', 'Gathering Bag', 'tailoring', 'cloth', ['weaving', 'leatherworking', 'tailoring']],
  ['hunting', 'Trap Kit', 'engineering', 'metal', ['engineering', 'leatherworking', 'carpentry']],
  ['farming', 'Cultivator', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['excavation', 'Survey Kit', 'engineering', 'metal', ['engineering', 'smithing', 'carpentry', 'cartography']],
  ['smelting', 'Forge Bellows', 'leatherworking', 'leather', ['leatherworking', 'tailoring', 'construction']],
  ['milling', 'Saw', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['tanning', 'Hide Scraper', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['cutting', 'Lapidary Kit', 'engineering', 'metal', ['engineering', 'smithing', 'jewelcrafting']],
  ['weaving', 'Loom Shuttle', 'carpentry', 'wood', ['milling', 'tailoring', 'furniture']],
  ['smithing', 'Smithing Hammer', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['carpentry', 'Chisel Kit', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['cooking', 'Cookpot', 'smithing', 'metal', ['smelting', 'carpentry']],
  ['alchemy', 'Retort', 'engineering', 'gem', ['smelting', 'cutting', 'engineering']],
  ['tailoring', 'Needle Kit', 'smithing', 'metal', ['smithing', 'tailoring', 'leatherworking']],
  ['leatherworking', 'Awl Kit', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['engineering', 'Caliper Set', 'engineering', 'metal', ['engineering', 'smelting']],
  ['enchanting', 'Rune Focus', 'enchanting', 'gem', ['enchanting', 'jewelcrafting', 'carpentry']],
  ['jewelcrafting', 'Setting Kit', 'engineering', 'gem', ['engineering', 'jewelcrafting', 'smithing']],
  ['boatbuilding', 'Shipwright Kit', 'engineering', 'wood', ['boatbuilding', 'engineering', 'carpentry']],
  ['furniture', 'Joinery Kit', 'carpentry', 'wood', ['construction', 'furniture', 'carpentry']],
  ['construction', 'Builder Kit', 'engineering', 'wood', ['construction', 'engineering', 'smithing']],
  ['combat', 'Sword', 'smithing', 'metal', ['smithing', 'carpentry', 'leatherworking']],
  ['slayer', 'Tracker Kit', 'leatherworking', 'leather', ['leatherworking', 'engineering', 'carpentry']],
  ['defense', 'Shield', 'smithing', 'metal', ['defense', 'leatherworking', 'milling']],
  ['healing', 'Medic Kit', 'tailoring', 'cloth', ['weaving', 'leatherworking', 'bandage']],
  ['magic', 'Spell Focus', 'enchanting', 'gem', ['enchanting', 'dungeoneering', 'jewelcrafting']],
  ['ranged', 'Bow', 'carpentry', 'wood', ['milling', 'tailoring', 'furniture']],
  ['exploration', 'Scout Kit', 'leatherworking', 'leather', ['leatherworking', 'cartography', 'engineering']],
  ['dungeoneering', 'Delver Kit', 'engineering', 'metal', ['engineering', 'cartography', 'leatherworking']],
  ['sailing', 'Navigation Kit', 'engineering', 'gem', ['boatbuilding', 'cartography', 'engineering', 'jewelcrafting']],
  ['survival', 'Camp Kit', 'tailoring', 'cloth', ['weaving', 'leatherworking', 'cooking']],
  ['cartography', 'Map Case', 'leatherworking', 'leather', ['leatherworking', 'weaving', 'cartography']],
  ['reputation', 'Envoy Ledger', 'tailoring', 'cloth', ['weaving', 'milling', 'tailoring']],
  ['leadership', 'Command Banner', 'tailoring', 'cloth', ['weaving', 'carpentry', 'tailoring']],
  ['trading', 'Trade Ledger', 'tailoring', 'cloth', ['weaving', 'milling', 'tailoring']],
];
const prefixes = { metal: metals, wood: woods, cloth: fibers, leather: animals.map((name, i) => `${name} ${i < 6 ? 'Leather' : 'Scale'}`), gem: gems };
for (const [skill, noun, crafter, material, componentSkills] of gear) {
  const starterKey = `starter_${skill}`;
  add({ key: starterKey, name: `Basic ${noun}`, tier: 0, level: 1, skill, group: 'Starter equipment', kind: 'Equipment', source: 'character creation', sourceLabel: 'Starter issue' });
  catalog.get(starterKey).uses.push('Equip for introductory training; gold-only repairs');
  for (const { tier, level } of tiers) {
    const key = `tool_${skill}_${tier}`;
    const name = `${prefixes[material][tier - 1]} ${noun}`;
    add({ key, name, tier, level, skill, group: 'Equipment', kind: 'Equipment', source: 'recipe', sourceLabel: `Assemble ${name}` });
    const inputs = componentSkills.map((component, index) => ref(component, tier, index === 0 && component !== 'smithing' ? 2 : 1));
    recipes.push({ key, skill: crafter, equipmentSkill: skill, tier, level, label: `Assemble ${name}`, inputs, outputs: [{ item: key, quantity: 1 }] });
    catalog.get(key).uses.push(`Equip for ${current.skills[skill].label}; same-tier crafting and action bonuses`);
    catalog.get(inputs[0].item).uses.push(`Repair ${name}; salvage returns a bounded amount of this component`);
  }
}
for (const recipe of [...recipes, ...activities]) for (const input of recipe.inputs) {
  const value = catalog.get(input.item);
  if (!value) throw new Error(`Missing ${input.item} in ${recipe.key}`);
  if (value.tier > recipe.tier) throw new Error(`Higher-tier dependency in ${recipe.key}`);
  value.uses.push(`${recipe.label} (${input.quantity})`);
}
for (const { tier } of tiers) {
  for (const skill of ['alchemy', 'enchanting', 'dungeoneering', 'reputation']) catalog.get(`${skill}_${tier}`).uses.push('Rarity attempt on equipment of the same item tier');
  catalog.get(`cooking_${tier}`).uses.push('Use: proposed XP bonus for the next ten eligible actions; also expedition provisions');
  catalog.get(`tonic_${tier}`).uses.push('Use: proposed yield bonus for the next ten eligible actions');
  catalog.get(`arrows_${tier}`).uses.push('Use: ten optional Ranged bonus charges; normal Ranged activity remains available without ammunition buffs');
  catalog.get(`leadership_${tier}`).uses.push('Consumed by same-tier expeditions');
  catalog.get(`cartography_${tier}`).uses.push('Consumed by regional survey contracts');
  catalog.get(`boatbuilding_${tier}`).uses.push('Consumed by coastal supply contracts');
  catalog.get(`trading_${tier}`).uses.push('Consumed by delivery contracts for gold and Trading XP');
  catalog.get(`bandage_${tier}`).uses.push('Consumed by medical supply contracts');
}
for (const value of catalog.values()) value.uses = [...new Set(value.uses)];
if (lines.length !== 38 || gear.length !== 38) throw new Error('Skill coverage must be exactly 38');
if (new Set(lines.map((entry) => entry.skill)).size !== Object.keys(current.skills).length) throw new Error('Incomplete skill coverage');
if ([...catalog.values()].some((entry) => !entry.uses.length)) throw new Error('Dead-end items found');
// Reachability includes supplies consumed by activity/commission producers.
const reachable = new Set([...catalog.values()].filter((entry) => entry.tier === 0).map((entry) => entry.key));
let changed = true;
while (changed) {
  changed = false;
  for (const producer of [...recipes, ...activities]) if (producer.inputs.every((input) => reachable.has(input.item))) {
    for (const output of producer.outputs) if (!reachable.has(output.item)) { reachable.add(output.item); changed = true; }
  }
}
const unreachable = [...catalog.keys()].filter((key) => !reachable.has(key));
if (unreachable.length) throw new Error(`Unreachable items: ${unreachable.join(', ')}`);

const notes = [
  ['Agreed progression', 'Ten equipment and production tiers at levels 10, 20, 30, 40, 50, 60, 70, 80, 90, and 100. Introductory level 1–9 gathering collects the same tier-one raw materials. Tier-one production and improved equipment unlock at 10. Each skill has ten primary outputs, one per tier. Actions can be condensed or expanded.'],
  ['Starter training', 'All 38 skills receive an introductory action available below skill level 10. Training grants XP and a small appropriate tier-one raw-material reward, never a tier-one processed component or upgraded tool. Examples: Smelting sorts Copper Ore; Weaving prepares Hemp Fiber; Carpentry sorts Ash Logs. Training is replaced by normal tiered work at level 10. All skills receive Basic equipment at character creation. Starter repair costs gold and requires no processed material.'],
  ['Action layout', 'Seven gathering skills have ten material targets each: 70 gathering targets. Twenty-one primary skill lines produce their outputs by consuming materials: processing, crafting, Survival, Cartography, Leadership, and Trading. Nine non-production activity lines have ten encounters/routes each: 90 activities. Reputation has ten guild commissions that consume Supply Crates. Crafting and processing use the recipe interface instead of a parallel free-loot activity. Add 38 clearly separated introductory training actions. Expand secondary actions only when they provide a distinct material route or meaningful play choice.'],
  ['Naming and roles', 'Names identify the actual material or resource: Copper Ore, Bronze Ingot, Ash Handle, Hemp Binding, and Quartz Runestone. Early tiers use familiar materials; later tiers introduce fantasy materials. There are no numbered Common Tooling rewards. Regions name maps, permits, medical notes, and orders because those resources refer to a destination or institution. Mining supplies ore; Excavation is deliberately refocused on buried gemstones. Farming supplies fibers; Fishing supplies the first food line. Additional crop, meat, stone, or ceramic lines should be added only as full ten-tier families with real consumers.'],
  ['Tier and rarity', 'Item tier is independent of rarity. Six rarity qualities remain metadata on the same item identity. Proposed caps: T1 Common, T2 Uncommon, T3 Rare, T4 Epic, T5 Legendary, T6–T10 Mythic. A rarity attempt consumes same-tier Extract, Runestone, Relic Core, and Guild Permit plus gold. Preserve the current progress-on-failure system; chance, quantities, prices, and tier caps in this draft still need balancing.'],
  ['Equipment gates', 'A crafter needs the assembling profession at the item tier gate. Equipping requires the destination skill at that gate too, including purchased or traded tools. Crafting never requires the new tool it is trying to create. Starter tools remain usable through the first upgrade. Recipes consume component bundles; finished equipment is never an intermediate ingredient.'],
  ['Repairs upgrades and salvage', 'Repair consumes a tool’s first listed assembly component and gold; scale material count to missing durability. Tier upgrades consume the next-tier component bundle and gold, update the existing tool, and preserve its rarity within the new cap. Salvage returns less material than crafting consumed and cannot be a profitable item-generation loop. Starter repair remains gold-only.'],
  ['Consumables', 'Rations are usable XP buffs, tonics are usable yield buffs, and arrow bundles are optional Ranged bonuses. Use ten charges rather than timers so they work well in a click-based game. One active buff per family; show remaining charges, replacements, and effective bonuses clearly. Exact percentages are undecided. Bandages are real Healing activity supplies, rather than promising an HP system this game does not currently have. Oils feed varnish recipes.'],
  ['Jobs and expeditions', 'Jobs consume new resources through delivery, medical, coastal, and survey contracts. Expeditions consume same-tier Crew Orders and Rations and return useful existing materials from their destination’s tier, never bespoke trophy items. Reputation commissions consume Supply Crates and grant Guild Permits plus Reputation XP. Delivery contracts consume Supply Crates for gold and Trading XP. Tune outputs and gold so crafting, delivery, purchasing, and salvage cannot form a guaranteed gold loop.'],
  ['Scope and replacement', 'This is an item-economy proposal for the separate Space Evergather game. Keep the 38 skills, character identity, skill XP, and gold; replace item definitions, recipes, tool definitions, starter gear, action loot, jobs, expeditions, shop offers, and item-linked achievements together. Reset legacy item stacks and tools, replace starters, cancel obsolete contracts and listings, and reconcile any currency already held in pending trades. Rebuild item catalog projections and material-source guidance. No housing, settlement, world-engine, or simulated naval-combat subsystem is included. The live game has not been changed.'],
  ['Verification before reset', 'Back up the current data and produce exact affected-row counts before applying the replacement. Validate every item has a source and sink, every input exists, no recipe needs a higher-tier input, every tier is reachable from introductory actions, and every equipment slot has ten upgrades. Verify shop/market transfer gates, buff charge consumption, jobs, repairs, rarity attempts, salvage, and the materials popup against the new catalog. The checks performed for this proposal cover identity, coverage, sources, sinks, and graph reachability; they are not runtime or economy-balance tests.'],
  ['Discussion status', 'The tier gates, first-resource access at levels 1–9, starter gear, and permission to reshape actions are agreed. Resource names, recipes, the Excavation/Farming/Furniture role choices, buff mechanics, rarity caps, and the reset handling below are proposed for review. Quantity values are placeholders for balancing. No runtime catalog or database mutation is made by generating this proposal.'],
];
const proposal = {
  status: 'discussion draft', date: new Date().toISOString().slice(0, 10), tiers,
  counts: { skills: 38, primaryItems: 380, supportingItems: 40, tieredTools: 380, starters: 38, total: catalog.size, deadEnds: 0, unreachable: 0 },
  notes: Object.fromEntries(notes),
  lines: lines.map(({ input, action, ...entry }) => entry),
  supportingFamilies: secondary.map(({ inputs, ...entry }) => entry),
  equipment: gear.map(([skill, noun, crafter, material, componentSkills]) => ({ skill, noun, crafter, material, componentSkills })),
  catalog: [...catalog.values()], recipes, activities,
};
fs.writeFileSync(new URL('docs/evergather-economy-proposal.json', root), JSON.stringify(proposal, null, 2) + '\n');

const esc = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const itemName = (key) => catalog.get(key).name;
const inputsText = (inputs) => inputs.map((input) => `${input.quantity} × ${itemName(input.item)}`).join(' + ') || 'Activity reward; no material input';
const categories = [...new Set(lines.map((entry) => entry.category))];
const matrix = categories.map((category) => `<section><h2>${esc(category)} resource lines</h2><div class="scroll"><table><thead><tr><th>Skill and purpose</th>${tiers.map(({ tier, level }) => `<th>T${tier}<small>Level ${level}</small></th>`).join('')}</tr></thead><tbody>${lines.filter((entry) => entry.category === category).map((entry) => `<tr><th>${esc(entry.label)}<small>${esc(entry.mode)} · ${esc(entry.purpose)}</small></th>${entry.names.map((name) => `<td>${esc(name)}</td>`).join('')}</tr>`).join('')}</tbody></table></div></section>`).join('\n');
const gearTable = `<div class="scroll"><table><thead><tr><th>Equipped skill</th><th>Tool</th><th>Assembler</th><th>Tier-one component bundle</th></tr></thead><tbody>${gear.map(([skill, noun, crafter]) => { const recipe = recipes.find((entry) => entry.key === `tool_${skill}_1`); return `<tr><td>${esc(current.skills[skill].label)}</td><td>${esc(itemName(recipe.key))}</td><td>${esc(current.skills[crafter].label)}</td><td>${esc(inputsText(recipe.inputs))}</td></tr>`; }).join('')}</tbody></table></div>`;
const recipeRows = [...recipes, ...activities].map((entry) => {
  const outputs = entry.outputs.map((output) => `${output.quantity} × ${itemName(output.item)}`).join(', ');
  const skill = current.skills[entry.skill].label;
  const search = `${entry.label} ${skill} ${inputsText(entry.inputs)} ${outputs}`.toLowerCase();
  return `<tr data-tier="${entry.tier}" data-skill="${esc(skill)}" data-search="${esc(search)}"><td>${esc(entry.label)}<small>${esc(skill)} · T${entry.tier} · level ${entry.level}</small></td><td>${esc(inputsText(entry.inputs))}</td><td>${esc(outputs)}</td></tr>`;
}).join('\n');
const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" href="data:,"><title>Evergather ten tier economy proposal</title><style>
:root{font-family:system-ui,sans-serif;color-scheme:dark;background:#111827;color:#e5edf8}*{box-sizing:border-box}body{max-width:1700px;margin:0 auto;padding:30px}h1{font-size:32px;margin-bottom:10px}h2{font-size:23px;margin-top:35px}p,li{line-height:1.65;color:#bdcbe0}a{color:#8acbff}.draft{display:inline-block;background:#523b1c;color:#ffe0a3;padding:7px 12px;border-radius:6px}.stats{display:flex;gap:12px;flex-wrap:wrap;margin:24px 0}.stat{background:#1c2a40;border:1px solid #354969;border-radius:8px;padding:15px 20px}.stat strong{display:block;font-size:26px}.stat span{font-size:13px;color:#b9c9e1}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;font-size:13px}th,td{text-align:left;vertical-align:top;padding:13px;border-bottom:1px solid #334155;min-width:110px}th{background:#1e2d43}tbody tr:nth-child(even){background:#162236}small{display:block;font-size:12px;font-weight:400;color:#a9bbd4;margin-top:5px;line-height:1.5}section table th:first-child{min-width:240px;max-width:300px}.notes{max-width:1100px}details{background:#182437;border:1px solid #354969;border-radius:8px;padding:16px;margin:12px 0}summary{cursor:pointer;font-weight:650}.chain{font-size:16px;background:#173c36;border-radius:8px;padding:18px;line-height:1.7;color:#bdffe1}.filters{display:flex;gap:14px;flex-wrap:wrap;margin:18px 0}label{display:flex;flex-direction:column;gap:7px;font-size:13px;color:#b9c9e1}input,select{background:#1e2d43;border:1px solid #486285;border-radius:6px;padding:10px;color:#fff;font:inherit}input{width:350px;max-width:100%}tr[hidden]{display:none}#shown{font-weight:600}nav{display:flex;gap:18px;flex-wrap:wrap;margin:20px 0}@media(max-width:650px){body{padding:18px}h1{font-size:25px}.filters label,input,select{width:100%}}
</style></head><body><span class="draft">Discussion draft — live game unchanged</span><h1>Evergather ten tier economy proposal</h1><p>Ten production and equipment tiers at levels 10–100. Levels 1–9 collect tier-one raw materials using basic equipment. Preserve the 38 skills and replace the item economy with connected material chains.</p><div class="stats">${[['38','Skills'],['380','Primary tiered resources'],['40','Supporting tiered resources'],['380','Tiered tools'],['38','Starter tools'],['838','Unique proposed item types']].map(([count,label]) => `<div class="stat"><strong>${count}</strong><span>${label}</span></div>`).join('')}</div><nav><a href="#rules">Progression and decisions</a><a href="#matrix">All ten-tier resource lines</a><a href="#gear">All 38 tool families</a><a href="#recipes">Search every recipe and source</a><a href="evergather-economy-proposal.json" download>Download structured draft</a></nav><div class="chain">Copper Ore → Copper Ingot → Copper Tool Head<br>Ash Log → Ash Plank → Ash Handle<br>Rabbit Hide → Rabbit Leather → Rabbit Leather Strap<br><strong>1 Copper Tool Head + 1 Ash Handle + 1 Rabbit Leather Strap → Copper Pickaxe</strong></div><section id="rules" class="notes"><h2>Progression and decisions</h2>${notes.map(([heading,text]) => `<details${heading === 'Agreed progression' || heading === 'Discussion status' ? ' open' : ''}><summary>${esc(heading)}</summary><p>${esc(text)}</p></details>`).join('')}</section><div id="matrix"><h2>Complete resource matrix</h2><p>Every skill has ten primary outputs, one per tier. Supporting families also have ten entries each. Material names and role changes below are proposals.</p>${matrix}<section><h2>Supporting resource lines</h2><div class="scroll"><table><thead><tr><th>Family and purpose</th>${tiers.map(({tier,level}) => `<th>T${tier}<small>Level ${level}</small></th>`).join('')}</tr></thead><tbody>${secondary.map((entry) => `<tr><th>${esc(title(entry.key))}<small>${esc(entry.purpose)}</small></th>${entry.names.map((name) => `<td>${esc(name)}</td>`).join('')}</tr>`).join('')}</tbody></table></div></section></div><section id="gear"><h2>All 38 equipment families</h2><p>These are the tier-one examples. Each family repeats across all ten appropriate material tiers. The assembling profession and the equipped profession both require the item’s level gate. Component quantities are draft values.</p>${gearTable}</section><section id="recipes"><h2>Every proposed recipe and source action</h2><p>Includes material-consuming activities, commissions, recycling, supporting supplies, and final equipment recipes. Introductory training, job variants, expedition rewards, prices, and buff percentages still require their implementation definitions.</p><div class="filters"><label>Search<input id="search" type="search" placeholder="Copper, Pickaxe, Bandage…"></label><label>Tier<select id="tier"><option value="">All tiers</option>${tiers.map(({tier,level}) => `<option value="${tier}">T${tier} · level ${level}</option>`).join('')}</select></label><label>Producing skill<select id="skill"><option value="">All skills</option>${lines.map((entry) => entry.label).sort().map((label) => `<option>${esc(label)}</option>`).join('')}</select></label></div><p id="shown" role="status" aria-live="polite"></p><div class="scroll"><table id="recipe-table"><thead><tr><th>Recipe or activity</th><th>Inputs</th><th>Outputs</th></tr></thead><tbody>${recipeRows}</tbody></table></div></section><script>
const rows = [...document.querySelectorAll('#recipe-table tbody tr')];
const search = document.getElementById('search'), tier = document.getElementById('tier'), skill = document.getElementById('skill');
function filter(){let count=0;const terms=search.value.trim().toLowerCase().split(/\\s+/).filter(Boolean);for(const row of rows){row.hidden=(tier.value&&row.dataset.tier!==tier.value)||(skill.value&&row.dataset.skill!==skill.value)||!terms.every(term=>row.dataset.search.includes(term));if(!row.hidden)count++;}document.getElementById('shown').textContent=count+' of '+rows.length+' recipes and sources shown';}
for(const control of [search,tier,skill])control.addEventListener('input',filter);filter();
</script></body></html>`;
function title(value) { return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
fs.writeFileSync(new URL('docs/evergather-economy-proposal.html', root), html);
console.log(JSON.stringify({ ...proposal.counts, recipes: recipes.length, sourceActions: activities.length, productionLines: lines.filter((entry) => entry.mode === 'recipe').length, activityLines: lines.filter((entry) => entry.mode === 'activity').length, gatheringLines: lines.filter((entry) => entry.mode === 'gather').length, commissionLines: lines.filter((entry) => entry.mode === 'commission').length }, null, 2));
