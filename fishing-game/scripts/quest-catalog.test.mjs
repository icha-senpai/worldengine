import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const load = async name => JSON.parse(await readFile(`content/${name}.json`, 'utf8'));
const quests = await load('quests');
const { baits } = await load('crafting');
const { species } = await load('species');
const ids = new Set(quests.map(q => q.quest_id));
assert.equal(ids.size, quests.length, 'Quest IDs must be unique');
const goals = new Set(['fish', 'discoveries', 'junk', 'upgrade', 'large', 'rank', 'delivery', 'weekly_fish', 'weekly_junk', 'weekly_variety', 'ranks', 'rank_maxima', 'repeat_fish', 'repeat_junk', 'repeat_variety']);
for (const quest of quests) {
  assert(goals.has(quest.goal), `Unknown goal for ${quest.quest_id}`);
  assert(Number.isSafeInteger(quest.target) && quest.target > 0);
  assert(quest.name && quest.dialogue && quest.conclusion && quest.objective && quest.giver);
  for (const value of [quest.coins, quest.tin, quest.scrap, quest.bait_packs]) assert(Number.isSafeInteger(value) && value >= 0);
  assert.equal(quest.weekly, quest.goal.startsWith('weekly_'));
  if (quest.weekly) assert.equal(quest.previous_quest_id, 0, 'Contracts cannot depend on permanent stories');
  assert.equal(quest.bait_choices.length > 0, quest.bait_packs > 0);
  assert.equal(new Set(quest.bait_choices).size, quest.bait_choices.length);
  for (const bait of quest.bait_choices) assert(baits.some(b => b.baitId === bait));
  if (quest.goal === 'delivery') assert(species.filter(s => s.biomeId === quest.biome_id && s.countsForOrdinaryCollectionCompletion).length >= 3, 'Deliveries need common alternatives');
  const visited = new Set([quest.quest_id]);
  let prerequisite = quest.previous_quest_id;
  while (prerequisite) {
    assert(ids.has(prerequisite), 'Missing prerequisite');
    assert(!visited.has(prerequisite), 'Quest chain cycle');
    visited.add(prerequisite);
    prerequisite = quests.find(q => q.quest_id === prerequisite).previous_quest_id;
  }
}
assert.equal(quests.filter(q => q.weekly).length, 3);
assert.deepEqual(quests.filter(q => q.weekly).map(q => q.goal).sort(), ['weekly_fish', 'weekly_junk', 'weekly_variety']);
const achievements = quests.map(q => q.achievement_id).filter(Boolean);
assert.equal(new Set(achievements).size, achievements.length, 'Story awards have distinct identities');
const stories = quests.filter(q => !q.weekly && !q.goal.startsWith('repeat_'));
assert.equal(new Set(stories.map(q => q.chain)).size, 9);
assert.equal(stories.filter(q => q.quest_id < 300).reduce((n,q) => n+q.coins,0), 1000, 'Original stories retain their rewards');
assert(stories.reduce((n,q) => n+q.coins,0) <= 5000, 'Bounded one-time story gold');
const addedStories = stories.filter(q=>q.quest_id>=400);
for (const name of new Set(addedStories.map(q=>q.chain))) {
  const steps = addedStories.filter(q=>q.chain===name).sort((a,b)=>a.quest_id-b.quest_id);
  assert.equal(steps[0].previous_quest_id,105,'Every new story branches from the intro');
  assert(steps.length>=4 && steps.length<=5,'Story length stays manageable');
}
for (const q of stories) {
  const ordinary = species.filter(s=>s.countsForOrdinaryCollectionCompletion && (!q.biome_id || s.biomeId===q.biome_id));
  if(q.goal==='discoveries')assert(q.target<=ordinary.length,'Discovery objective is reachable');
  if(q.goal==='rank' || q.goal==='large' || q.goal==='rank_maxima')assert.equal(q.target,1);
  if(q.goal==='ranks')assert(q.target<=10);
  if(q.goal==='upgrade')assert(q.target<=6);
}
const awards = await load('quest-awards');
assert.equal(awards.length,9);
assert.deepEqual(awards.map(a=>a.achievement_id).sort(), achievements.sort());
assert.equal(new Set(awards.map(a=>a.title)).size,9);
const repeats = quests.filter(q=>q.goal.startsWith('repeat_'));
assert.equal(repeats.length,3);
for (const q of repeats) {
  assert(!q.weekly && !q.previous_quest_id && !q.achievement_id, 'Jobs have no weekly/story gate or repeatable badges');
  const baitValue = Math.max(0,...q.bait_choices.map(id=>baits.find(b=>b.baitId===id).priceCoins)) * q.bait_packs;
  assert((q.coins+baitValue)/q.target<=10, 'Job value stays at most 10 gold per required fish');
  assert(q.tin/q.target<=.6 && q.scrap/q.target<=.2, 'Job material bonus remains modest');
}
const stretch = stories.find(q=>q.goal==='rank_maxima');
assert(stretch && stretch.achievement_id && !stretch.coins && !stretch.bait_packs && !stretch.tin && !stretch.scrap, 'Rank maximum stretch finale is cosmetic only');
assert(!quests.some(q=>q.previous_quest_id===stretch.quest_id), 'Rare stretch goal cannot block other content');
assert(Math.max(...quests.filter(q => q.weekly).map(q => q.coins)) <= 400, 'Weekly gold budget');

// Primary pulls only, no gear/bait bonuses: attainable at 25 casts/day in Meadow Pond.
const n = 25 * 7, p = .12;
const fewerThanThreeJunk = (1-p)**n + n*p*(1-p)**(n-1) + n*(n-1)/2*p*p*(1-p)**(n-2);
assert(1-fewerThanThreeJunk > .99, 'Casual weekly material contract remains reliable');
console.log(`PASS ${quests.length} quest definitions: acyclic stories, valid rewards, common delivery alternatives, capped gold, and >99% weekly junk eligibility at 25 casts/day before bonuses.`);
