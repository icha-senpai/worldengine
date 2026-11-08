<script lang="ts">
  import type { Game } from './game.svelte';
  import { formatLength, formatWeight } from './measurements';
  import { rankMaximaSummary } from './rank-maxima';
  import { totalOrdinaryRaidSeals } from './raid-progress';
  import { caughtAtRank, matchesBookFilters, ownedDiscoveries } from './fish-book-ui';
  import { tooltip } from './tooltip';
  import RaidMastery from './RaidMastery.svelte';
  import PageTurner from './PageTurner.svelte';

  let { game, query, biome, rarity, pageSize, onselect }: {
    game: Game; query: string; biome: number; rarity: string; pageSize: number; onselect: (speciesId: number) => void;
  } = $props();
  let page = $state(1);
  $effect(() => { query; biome; rarity; pageSize; page = 1; });
  const owned = $derived(ownedDiscoveries(game.collection, game.player?.playerId));
  const ordinary = $derived(game.species.filter(row => row.countsForOrdinaryCollectionCompletion));
  const ordinaryIds = $derived(new Set(ordinary.map(row => row.speciesId)));
  const discoveries = $derived(owned.filter(row => ordinaryIds.has(row.speciesId)).length);
  const perfected = $derived(new Set(game.perfectSpecies.filter(row => row.playerId === game.player?.playerId).map(row => row.speciesId)));
  const maxima = $derived(rankMaximaSummary(game.rankMaxima, game.species, game.player?.playerId));
  const raidStats = $derived(game.raidStatistics.find(row => row.playerId === game.player?.playerId));
  const raidSeals = $derived(totalOrdinaryRaidSeals(game.raidBadges, ordinaryIds, game.player?.playerId));
  const book = $derived(game.species.filter(fish => matchesBookFilters(fish, query, biome, rarity)));
  const rankMinimums = (rank: string) => {
    const rule = game.rarities.find(row => row.tier === rank);
    return !rule || rank === 'F' ? 'Below D in length or weight'
      : `At least ${Number(rule.minimumLengthMillionths) / 1_000_000}× typical length and ${Number(rule.minimumWeightMillionths) / 1_000_000}× typical weight`;
  };
</script>

<div class="collection-progress">
  <p>{discoveries} of {ordinary.length} ordinary species discovered · ✦ {perfected.size} UUR perfected · {maxima.count} rank maxima · {maxima.fullyMaxedSpecies} fully maxed species</p>
  <p>{game.raidsSupported ? `Raid mastery: ${raidStats?.masteredBosses ?? 0n} / 249 bosses · ${raidSeals} / 2,490 grade seals` : 'Raid seals await the module update.'}</p>
  <p>Showing {book.length} species. Ordinary ranks come from length and weight relative to each species; both must meet the rank minimum. Fihs and Nidalees Lost Sock are bonus discoveries. ✓ means caught; ✦ means one catch reached both exact maxima for that rank. Marks remain after selling.</p>
  {#if rarity}<p>{rarity}: {rankMinimums(rarity)}.</p>{/if}
  <div class="collection-grid">
    {#each book.slice((page - 1) * pageSize, page * pageSize) as fish (fish.speciesId)}
      {@const discovery = owned.find(row => row.speciesId === fish.speciesId)}
      {@const caught = !!discovery && (!rarity || caughtAtRank(discovery, rarity) > 0n)}
      <article class="collection-entry" class:undiscovered={!caught}>
        <img src={fish.spriteAsset} alt="" loading="lazy" />
        <div>
          <small>{rarity ? rarity + ' RANK' : fish.allowedRarities.length === 10 ? 'F THROUGH UUR' : fish.allowedRarities[0] + ' ONLY'}{fish.countsForOrdinaryCollectionCompletion ? '' : ' · BONUS'}</small>
          <h3><button class="fish-details" aria-label={`Open ${fish.name} details`} aria-haspopup="dialog" onclick={() => onselect(fish.speciesId)}>{fish.name}</button>{#if rarity ? maxima.marks.has(`${fish.speciesId}:${rarity}`) : perfected.has(fish.speciesId)} <span class="perfect-mark" aria-label={rarity ? rarity + ' rank maximum' : 'UUR perfected species'} use:tooltip={'One catch at both exact maximum measurements'}>✦</span>{/if}</h3>
          <p>{game.biomes.find(row => row.biomeId === fish.biomeId)?.name}</p>
          {#if discovery}<p>{rarity ? caughtAtRank(discovery, rarity).toString() + ' caught at ' + rarity : discovery.count.toString() + ' caught across ranks'} · best across ranks {formatLength(discovery.bestLengthMm)} / {formatWeight(discovery.bestWeightG)}</p>
          {:else}<p>Waiting for your first discovery</p>{/if}
          <p class="maxima-count">✦ {fish.allowedRarities.filter(rank => maxima.marks.has(`${fish.speciesId}:${rank}`)).length} / {fish.allowedRarities.length} rank maxima</p>
          <div class="rank-progress" aria-label="Ranks caught and maxima">{#each fish.allowedRarities as rank}
            <span class:maxed={maxima.marks.has(`${fish.speciesId}:${rank}`)} class:earned={caughtAtRank(discovery, rank) > 0n} use:tooltip={maxima.marks.has(`${fish.speciesId}:${rank}`) ? rank + ' rank maximum: one catch at both exact maximum measurements' : rankMinimums(rank)}>{rank}{maxima.marks.has(`${fish.speciesId}:${rank}`) ? ' ✦' : caughtAtRank(discovery, rank) > 0n ? ' ✓' : ' ○'}</span>
          {/each}</div>
          <RaidMastery {game} speciesId={fish.speciesId} />
        </div>
        <span class="discovered" aria-label={caught ? 'Discovered at selected rank' : 'Undiscovered at selected rank'}>{caught ? '✓' : '○'}</span>
      </article>
    {/each}
    {#if !book.length}<p>No fish match these filters.</p>{/if}
  </div>
  <PageTurner bind:page total={book.length} {pageSize} label="Collection pages" />
</div>

<style>
  .collection-progress{display:grid;gap:12px;min-width:0;margin-top:18px}.collection-progress p{margin:0;line-height:1.6}
  .collection-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.collection-entry{display:flex;align-items:center;gap:16px;border:1px solid var(--paper-edge);padding:20px;background:var(--paper-light);box-shadow:2px 3px 0 var(--panel-highlight);min-width:0}.collection-entry>div{min-width:0;flex:1}
  .collection-entry>img{height:62px;width:70px;object-fit:contain;image-rendering:pixelated}.collection-entry small{font-family:var(--font-game);font-size:16px}.collection-entry h3{margin:5px 0;font-size:24px;overflow-wrap:anywhere}.collection-entry p{font-size:14px;margin:4px 0}.fish-details{font:inherit;color:inherit;background:transparent;border:0;padding:0;box-shadow:none;text-align:left;text-decoration:underline;text-underline-offset:3px}.fish-details:hover{background:transparent}
  .discovered{margin-left:auto;color:var(--success-ink);font-weight:700}.undiscovered>img{filter:grayscale(1);opacity:.4}.undiscovered .discovered{color:var(--ink-muted)}
  .rank-progress{display:flex;flex-wrap:wrap;gap:4px;margin-top:10px}.rank-progress span{font-family:var(--font-game);font-size:16px;color:var(--ink-muted);border:1px solid var(--paper-edge);padding:2px 4px;background:var(--paper-light)}.rank-progress span.earned{color:var(--selected-ink);border-color:var(--control-accent);background:var(--selected-background)}.rank-progress span.maxed{color:var(--ink);border-color:var(--special-ink);background:var(--paper-light);box-shadow:inset 0 0 0 1px var(--panel-highlight)}.maxima-count{font-weight:bold}
  @media(max-width:850px){.collection-grid{grid-template-columns:1fr}}@media(max-width:600px){.collection-entry{padding:12px;gap:10px}.collection-entry>img{width:50px;height:50px}}
</style>
