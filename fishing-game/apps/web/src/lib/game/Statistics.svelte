<script lang="ts">
  import type { AnglerStatistics, BiomeDefinition, RaidStatistics, SpeciesDefinition } from '@fishing-game/generated/types';
  import { formatLength, formatWeight } from './measurements';
  let { stats, species, biomes, raidStats }: { stats: AnglerStatistics; species: SpeciesDefinition[]; biomes: BiomeDefinition[]; raidStats?: RaidStatistics } = $props();
  const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  const fishName = (id?: number) => species.find(row => row.speciesId === id)?.name ?? 'None yet';
  const date = (micros: bigint) => new Date(Number(micros / 1000n)).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  const capacity = $derived(species.filter(row => row.countsForOrdinaryCollectionCompletion).length * ranks.length);
  const rate = $derived(stats.totalCasts ? (Number((stats.bonusPulls * 10000n + stats.totalCasts / 2n) / stats.totalCasts) / 100).toFixed(2) : '0.00');
</script>

<div class="statistics" aria-label="Angler lifetime statistics">
  <h4>Life on the water</h4>
  <dl>
    <div><dt>Total casts</dt><dd>{stats.totalCasts.toLocaleString()}</dd></div>
    <div><dt>Bonus pulls</dt><dd>{stats.bonusPulls.toLocaleString()} <small>{rate}% of casts</small></dd></div>
    <div><dt>Treasure catches</dt><dd>{stats.treasureCount.toLocaleString()}</dd></div>
    <div><dt>Junk catches</dt><dd>{stats.junkCount.toLocaleString()}</dd></div>
    <div><dt>Fishing days · UTC</dt><dd>{stats.daysFished.toLocaleString()}</dd></div>
    <div><dt>Favorite biome{stats.biomeHistoryComplete ? '' : ' · recorded'}</dt><dd>{biomes.find(row => row.biomeId === stats.favoriteBiomeId)?.name ?? 'None yet'}<small>{stats.favoriteBiomeCasts.toLocaleString()} casts</small></dd></div>
    <div><dt>Bonus fish / junk / treasure{stats.bonusHistoryComplete ? '' : ' · recorded'}</dt><dd>{stats.bonusFish.toLocaleString()} / {stats.bonusJunk.toLocaleString()} / {stats.bonusTreasure.toLocaleString()}</dd></div>
  </dl>
  <h4>The catch book</h4>
  <dl>
    <div><dt>Species / rank pairs</dt><dd>{stats.collectionPairs.toLocaleString()} / {capacity.toLocaleString()}</dd></div>
    <div><dt>Most-caught species</dt><dd>{fishName(stats.mostCaughtSpeciesId)}<small>{stats.mostCaughtSpeciesCount.toLocaleString()} fish</small></dd></div>
    <div><dt>{stats.historyComplete ? 'Lifetime' : 'Recorded'} caught weight</dt><dd>{formatWeight(stats.totalWeightG)}<small>{stats.measuredFishCount.toLocaleString()} measured fish, including sold catches</small></dd></div>
    <div><dt>Best relative size · length</dt><dd>{stats.bestRelativeSpeciesId === undefined ? 'None yet' : `${(Number(stats.bestRelativeLengthMillionths) / 1000000).toFixed(2)}× typical`}<small>{fishName(stats.bestRelativeSpeciesId)}{#if stats.bestRelativeSpeciesId !== undefined} · {formatLength(stats.bestRelativeLengthMm)} · {formatWeight(stats.bestRelativeWeightG)}{/if}</small></dd></div>
    <div><dt>Record-setting catches</dt><dd>{stats.recordsSet.toLocaleString()}<small>Tracked since {date(stats.recordsTrackedSince.microsSinceUnixEpoch)}</small></dd></div>
  </dl>
  <h4>Raid expeditions</h4>
  <dl>
    <div><dt>Community expeditions completed</dt><dd>{raidStats?.communityCompletions ?? 0n}</dd></div>
    <div><dt>Personal raids completed</dt><dd>{raidStats?.personalCompletions ?? 0n}</dd></div>
    <div><dt>Special encounters completed</dt><dd>{raidStats?.specialCompletions ?? 0n}</dd></div>
    <div><dt>Mastered raid bosses</dt><dd>{raidStats?.masteredBosses ?? 0n} / 249</dd></div>
  </dl>
  <div class="rank-totals" aria-label="Lifetime catches by rank">{#each ranks as rank, i}<span><b>{rank}</b>{(stats.rankCounts[i] ?? 0n).toLocaleString()}</span>{/each}</div>
  <h4>The coin trail</h4>
  <dl>
    <div><dt>{stats.saleHistoryComplete ? 'Lifetime' : 'Recorded'} fish-sale earnings</dt><dd>{stats.saleEarnings.toLocaleString()} coins</dd></div>
    <div><dt>Most expensive fish</dt><dd>{stats.biggestSingleSale.toLocaleString()} coins<small>Highest catch value, kept or sold</small></dd></div>
    <div><dt>Biggest sale batch{stats.saleHistoryComplete ? '' : ' · recorded'}</dt><dd>{stats.biggestSaleBatch.toLocaleString()} coins</dd></div>
  </dl>
  <p>Bonus pulls are extra rod catches, divided by all casts. Each completed boat or net haul counts as one cast, not as bonus pulls. A special raid fish counts as one catch activity and never as a bonus pull. Rank totals and collection pairs remain after selling. One catch counts once when it establishes or breaks either global species record.</p>
  {#if !stats.historyComplete || !stats.biomeHistoryComplete || !stats.bonusHistoryComplete}<p>Older journal coverage is incomplete. Recorded weight, days, biome activity and bonus types reflect surviving history plus every new cast. {stats.recordedCasts.toLocaleString()} of {stats.totalCasts.toLocaleString()} casts have journal evidence.</p>{/if}
  {#if !stats.saleHistoryComplete}<p>Older sale history is incomplete. Earnings and batch records include surviving sale receipts plus new sales; fish-value and record-setting records use catch evidence independently of sales.</p>{/if}
</div>

<style>
  .statistics{width:100%;margin:20px 0;padding:24px;background:var(--paper-light);border:2px solid var(--paper-edge);box-sizing:border-box;color:var(--ink)}h4{font-family:var(--font-game);font-size:28px;font-weight:400;margin:8px 0 14px}dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:22px;margin:0 0 20px}dt{font-size:16px;color:var(--ink-muted)}dd{margin:6px 0 0;font-family:var(--font-game);font-size:28px;overflow-wrap:anywhere}small{display:block;font-family:var(--font-body,inherit);font-size:14px;line-height:1.6;color:var(--ink-muted)}.rank-totals{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0}.rank-totals span{display:grid;gap:5px;min-width:48px;padding:8px;text-align:center;background:var(--panel-inset);border:1px solid var(--paper-edge);font-size:16px}.rank-totals b{color:var(--special-ink)}p{font-size:16px;line-height:1.6;color:var(--ink-muted)}@media(max-width:600px){.statistics{padding:12px}dl{grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}dd{font-size:23px}}
</style>
