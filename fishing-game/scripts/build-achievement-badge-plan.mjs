import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';

const database = process.argv[2] ?? 'fishbound-dev-local';
if (database !== 'fishbound-dev-local' && !/^fishbound-proof-/.test(database)) throw Error('Use a local Fishbound database.');
function rows(table) {
  return JSON.parse(execFileSync('spacetime', ['sql', '--server', 'http://127.0.0.1:3127', '--no-config', '--format', 'json', database, `SELECT * FROM ${table}`], { encoding:'utf8', windowsHide:true })).flatMap(block => block.rows);
}
const collections = new Map(rows('achievement_collection').map(([id, biome, rank, allRanks])=>[id,{biome,rank,allRanks}]));
const world = JSON.parse(readFileSync('content/world.json','utf8'));
const motifs = {
  1:'a lily pad, a small freshwater fish and gentle pond ripples, fern-green and turquoise',
  2:'a silver trout leaping over a curling river current between rounded pebbles, teal and pale blue',
  3:'a dark catfish with reeds and cattails above murky marsh water, moss-green and amber',
  4:'a luminous fish beneath a crescent moon reflected on still lake water, indigo and lavender',
  5:'a bright reef fish and coral beside a curling sea wave and shell, coral-orange and ocean-blue',
  6:'an icebound fish below a pointed glacier and a snowflake, icy cyan and pale silver',
  7:'a glowing deep-sea fish above a volcanic vent, violet and dark ocean-blue with ember accents',
  0:'a globe made of seven flowing water bands with a fish silhouette at its center, ocean-blue and forest-green',
};
const ranks=['F','D','C','B','A','S','SS','SSS','UR','UUR'];
const trims=[
  'weathered iron edging with wood-brown and slate-gray accents; simple and humble',
  'copper edging with muted green accents and one tiny inset stud',
  'bronze edging with jade accents and two inset studs',
  'polished silver edging with cobalt accents and three inset studs',
  'gold edging with a prominent emerald gemstone',
  'gold edging with sapphire gemstones and a small laurel flourish',
  'gold edging with amethyst gemstones and two laurel flourishes',
  'rich gold edging with amber gemstones, a small crown and complete laurel wreath',
  'rich gold edging with magenta crystal gemstones and a star-shaped crown',
  'radiant gold edging with prismatic cyan-pink-lavender crystal gemstones, a star-shaped crown and subtle pixel sparkles',
];
const milestones={
  1:'one fishing bobber making its first circular ripple, a tiny twig rod and hook, copper and pond-green',
  2:'one small cheerful freshwater fish resting above a curved fishing hook, copper and turquoise',
  3:'a bobber above three concentric ripples, a small silver hook, bronze and pond-green',
  4:'a bobber surrounded by many ripples and two crossed fishing rods, a gold laurel border',
  5:'a small fishing net filled with several fish, bronze and sea-green',
  6:'an overflowing fishing net and two crossed rods framed by a golden laurel wreath',
  7:'an open field journal with two small fish silhouettes on its pages and a magnifying glass, bronze and green',
  8:'an open field journal with a fern sprig and three small fish silhouettes, silver and green',
  9:'a richly bound field journal with three fish silhouettes, a quill and golden laurel accents',
  11:'a parchment fishing permit, a river wave and a silver trout, copper and teal',
  12:'a parchment fishing permit above a glowing deep-sea fish and volcanic vent, gold and violet',
  13:'a large emerald-accented trophy fish on a golden shield with a star',
  14:'one exceptionally long fish beside a simple measuring stick with blank tick marks, silver and turquoise',
  15:'a purple-crystal fishing rod crossing a small smithing hammer above an anvil, gold and violet',
  16:'a rainbow-crystal fishing rod crossing a smithing hammer above an anvil, radiant gold and prismatic accents',
  17:'a strange mythical fish matching the supplied Fihs sprite: cyan-blue body, a vivid pink lateral stripe, pale outline, golden spiky fins and a dark forked tail, beside a tiny purple crystal and two little stars, gold and violet',
  18:'one comically worn lost wool sock dangling from a fishing hook, a small gold star, plum-purple and cream',
};
const style='Use case: stylized-concept. Asset type: one individual achievement badge icon for the Fishbound fishing RPG, readable at 64 to 96 pixels. Style: crisp chunky 16-bit pixel art, visible square pixel blocks, dark stepped outlines, limited palette, warm handcrafted fantasy fishing-game feel. Shape: one compact round medallion with a slightly scalloped antique metal rim and two short ribbon tails below it, front-facing and symmetrical. Center the complete badge at about 80 percent of a square canvas, with generous empty transparent margins. Strong simple silhouette and a large legible central emblem; details must not become visual noise. Truly transparent background outside the badge. No words, letters, numbers, captions, watermark, external scene, people, extra badges or cropped edges. No smooth vector rendering, photorealism, blurry painting or checkerboard background.';
const assets=rows('achievement_definition').sort((a,b)=>a[0]-b[0]).map(([achievementId,name,description,title,target,bonus])=>{
  const collection=collections.get(achievementId);
  let subject=milestones[achievementId];
  let batch='milestones-and-bonuses';
  if(collection){
    subject=motifs[collection.biome];
    batch=collection.biome ? world.biomes.find(b=>b.biomeId===collection.biome).key : 'all-waters';
    const embellishment=collection.allRanks ? 'a full golden laurel wreath, an ornate prismatic crown and ten small jewel studs around the rim, clearly the ultimate completion medal' : collection.rank ? trims[ranks.indexOf(collection.rank)] : 'an antique bronze-and-gold rim with two fern sprigs, a naturalist collection medal';
    subject+=`. Border and ribbon treatment: ${embellishment}`;
  }
  if(!subject)throw Error(`No artwork motif for ${achievementId}`);
  return {achievementId,key:`badge-${achievementId}`,name,title,batch,url:`/achievements/badge-${achievementId}.png`,prompt:`${style}\nAchievement: ${name}. Meaning: ${description}\nCentral emblem: ${subject}. Do not write the achievement name or any rank text into the image.`};
});
if(assets.length!==113)throw Error('Review catalog changes before generating another batch.');
const referencePromptPrefix='The attached badge is a style and layout reference only. Create a distinct new badge for this achievement, preserving the pixel scale, scalloped medallion shape and short ribbon tails. Replace the central emblem and metal/ribbon colors as described below. Do not carry over the pond lily pad or freshwater fish unless required by this achievement.';
const plan={version:1,mode:'built-in image_gen',transparentBackground:true,style,styleReferenceAchievementId:10,referencePromptPrefix,assets};
writeFileSync('content/achievement-badge-art.json',`${JSON.stringify(plan,null,2)}\n`);
console.log(`Prepared ${assets.length} individual badge prompts in ${new Set(assets.map(a=>a.batch)).size} themed batches.`);
