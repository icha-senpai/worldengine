<script lang="ts">
  import type { Game } from './game.svelte';
  import type { SpeciesDefinition, BiomeDefinition } from '@fishing-game/generated/types';
  import { formatLength, formatWeight } from './measurements';
  import PageTurner from './PageTurner.svelte';
  let { game, pageSize = 12 } = $props<{ game: Game; pageSize?: number }>();
  let page = $state(1);
  let search = $state('');
  let biomeId = $state(0);
  let rarity = $state('');
  let outcome = $state('');
  let sort = $state('date');
  let descending = $state(true);
  const entries = $derived(game.journal?.entries ?? []);
  const total = $derived(Number(game.journal?.matchingEntries ?? 0n));
  const playerId = $derived(game.player?.playerId);
  $effect(() => { pageSize; page = 1; });
  $effect(() => {
    const query = { page, pageSize, sort, descending, search: search.trim(), biomeId, rarity, outcome };
    if (!game.ready || !playerId) return;
    const timer = setTimeout(() => game.queryJournal(query), 180);
    return () => clearTimeout(timer);
  });
  function reset() { search = ''; biomeId = 0; rarity = ''; outcome = ''; sort = 'date'; descending = true; page = 1; }
  const date = (micros: bigint) => new Date(Number(micros / 1000n)).toLocaleString();
</script>

<section aria-labelledby="journal" aria-busy={game.journalLoading}>
  <div class="heading"><div><p class="eyebrow">EVERY CATCH HAS A STORY</p><h2 id="journal">Fishing journal</h2></div><span class="count">{(game.journal?.totalEntries ?? 0n).toLocaleString()} saved finds</span></div>
  <p class="intro">Your catch history, including fish you’ve sold. Every new fish, tin, and treasure gets its own entry.</p>
  <div class="filters">
    <label class="search">Find a catch<input type="search" maxlength="80" placeholder="Fish name, tin, treasure…" bind:value={search} oninput={() => page = 1} /></label>
    <label>Biome<select bind:value={biomeId} onchange={() => page = 1}><option value={0}>All waters</option>{#each game.biomes as biome}<option value={biome.biomeId}>{biome.name}</option>{/each}</select></label>
    <label>Rank<select bind:value={rarity} onchange={() => page = 1}><option value="">All ranks</option>{#each game.rarities as rank}<option value={rank.tier}>{rank.tier}</option>{/each}</select></label>
    <label>Catch type<select bind:value={outcome} onchange={() => page = 1}><option value="">All finds</option><option value="fish">Fish</option><option value="junk">Rusted tin</option><option value="treasure">Treasure</option></select></label>
  </div>
  <div class="sorting">
    <label>Sort by<select bind:value={sort} onchange={() => page = 1}><option value="date">Date</option><option value="rank">Rank</option><option value="weight">Weight</option><option value="length">Length</option><option value="xp">XP earned</option></select></label>
    <label>Order<select bind:value={descending} onchange={() => page = 1}><option value={true}>{sort === 'date' ? 'Newest first' : sort === 'rank' ? 'Highest rank first' : sort === 'xp' ? 'Most XP first' : sort === 'weight' ? 'Heaviest first' : 'Longest first'}</option><option value={false}>{sort === 'date' ? 'Oldest first' : sort === 'rank' ? 'Lowest rank first' : sort === 'xp' ? 'Least XP first' : sort === 'weight' ? 'Lightest first' : 'Shortest first'}</option></select></label>
    <button class="reset" onclick={reset}>Reset filters</button>
    <span class="status" role="status">{game.journalLoading ? 'Turning the page…' : `${total.toLocaleString()} matching finds`}</span>
  </div>
  {#if game.journalError}<p class="error" role="alert">{game.journalError} <button onclick={() => game.queryJournal({page, pageSize, sort, descending, search, biomeId, rarity, outcome})}>Retry</button></p>{/if}
  <div class="entries" class:loading={game.journalLoading}>
    {#each entries as entry (entry.key)}
      {@const fish = game.species.find((row: SpeciesDefinition) => row.speciesId === entry.speciesId)}
      <article class="entry" data-journal-key={entry.key}>
        <img src={fish?.spriteAsset ?? (entry.outcome === 'junk' ? '/items/rusted-tin.png' : '/items/scrap.png')} alt="" loading="lazy" />
        <div class="identity"><h3>{entry.name} {#if entry.rarity}<span class="rank">{entry.rarity}</span>{/if}</h3><p>{game.biomes.find((row: BiomeDefinition) => row.biomeId === entry.biomeId)?.name ?? 'Biome not recorded'}</p><time datetime={new Date(Number(entry.caughtAt.microsSinceUnixEpoch / 1000n)).toISOString()}>{date(entry.caughtAt.microsSinceUnixEpoch)}</time></div>
        <dl><div><dt>Length</dt><dd>{entry.outcome === 'fish' ? formatLength(entry.lengthMm) : '—'}</dd></div><div><dt>Weight</dt><dd>{entry.outcome === 'fish' ? formatWeight(entry.weightG) : '—'}</dd></div><div><dt>XP earned</dt><dd class="xp">{entry.xpGranted === undefined ? 'Not recorded' : '+' + entry.xpGranted.toLocaleString()}</dd></div></dl>
      </article>
    {:else}<p class="empty">{game.journalLoading ? 'Opening your journal…' : game.journal?.totalEntries ? 'No catches match these filters. Try another page of your story.' : 'A fresh page. Go make a little fishing history.'}</p>{/each}
  </div>
  <PageTurner bind:page {total} {pageSize} label="Journal pages" />
  <p class="footnote">Older entries were recovered from the data still available. Missing XP or biome details are marked as unrecorded; unknown values sort last.</p>
</section>

<style>
  section{background:var(--paper);color:var(--ink);border:3px solid #3e3026;box-shadow:inset 0 0 0 3px #fff1cb,0 7px 0 #122b26;padding:30px;margin-top:30px}
  .heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.eyebrow{font-size:10px;letter-spacing:2px;color:#8b642c;margin:0 0 5px;font-weight:800}h2{font:36px var(--font-game);margin:0}.count{font:19px var(--font-game);white-space:nowrap}.intro,.footnote{color:#79664a;font-size:12px}.intro{margin:10px 0 20px}.footnote{font-size:11px;margin:20px 0 0}
  .filters,.sorting{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}.filters{padding-bottom:16px;border-bottom:1px dashed #b99a69}.filters label{flex:1;min-width:135px}.filters .search{flex:2;min-width:190px}.sorting{margin:16px 0 18px}label{display:flex;flex-direction:column;gap:5px;font-size:11px;color:#79664a;font-weight:800}input,select{width:100%;min-height:38px;padding:8px 10px;color:var(--ink);background:#fff2d0;border:2px solid #b49768;border-radius:0;font-size:12px}.sorting label{min-width:150px}.reset,.error button{min-height:38px;padding:7px 12px;border:2px solid #96794f;background:#e4d4a9;color:var(--ink);font:17px var(--font-game)}.status{margin-left:auto;font-size:11px;color:#79664a;padding:9px 0}
  .entry{display:flex;gap:16px;align-items:center;padding:14px 0;border-bottom:1px dashed #b99a69}.entry img{width:65px;height:65px;object-fit:contain;image-rendering:pixelated}.identity{flex:1;min-width:0}h3{font:23px var(--font-game);margin:0}.rank{font:14px var(--font-game);background:#dfcc99;border:1px solid #b99a69;padding:2px 6px;display:inline-block;vertical-align:middle}.identity p{font-size:12px;color:#79664a;margin:4px 0}.identity time{font-size:11px;color:#79664a}dl{display:grid;grid-template-columns:repeat(3,minmax(95px,1fr));gap:12px;margin:0;flex:0 0 42%}dt{font-size:10px;color:#79664a}dd{margin:3px 0 0;font-size:13px;font-weight:800}.xp{color:#536b43}.loading{opacity:.55}.empty{padding:30px 0;color:#79664a}.error{color:#90442e;font-size:13px}
  @media(max-width:800px){.entry{flex-wrap:wrap}dl{flex:1 0 100%;padding-left:81px}.status{margin-left:0}}
  @media(max-width:600px){section{padding:20px;margin-top:22px}.heading{align-items:flex-start;flex-direction:column;gap:3px}h2{font-size:28px}.count{font-size:16px}.filters{gap:10px}.filters label{min-width:100px}.filters .search{flex-basis:100%}.sorting{gap:10px}.sorting label{flex:1;min-width:110px}.status{flex:1;text-align:right}.entry{gap:10px}.entry img{width:50px;height:50px}h3{font-size:21px}dl{padding-left:0;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}dd{font-size:11px}.identity time{font-size:10px}}
</style>
