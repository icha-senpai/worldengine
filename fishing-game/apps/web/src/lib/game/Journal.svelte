<script lang="ts">
  import type { Game } from './game.svelte';
  import type { SpeciesDefinition, BiomeDefinition } from '@fishing-game/generated/types';
  import { formatLength, formatWeight } from './measurements';
  import GameSelect from './GameSelect.svelte';
  import SearchInput from './SearchInput.svelte';
  import PageTurner from './PageTurner.svelte';
  let { game, pageSize = 12 } = $props<{ game: Game; pageSize?: number }>();
  let page = $state(1);
  let search = $state('');
  let biomeId = $state(0);
  let rarity = $state('');
  let outcome = $state('');
  let sort = $state('date');
  let descending = $state(true);
  const biomeOptions = $derived([
    { value: 0, label: 'All waters' },
    ...(game.biomes as BiomeDefinition[]).map(biome => ({ value: biome.biomeId, label: biome.name }))
  ]);
  const rankOptions = $derived([
    { value: '', label: 'All ranks' },
    ...(game.rarities as { tier: string }[]).map(rank => ({ value: rank.tier, label: rank.tier }))
  ]);
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
    <label class="search">Find a catch<SearchInput label="Find a catch" placeholder="Fish name, tin, treasure…" maxlength={80} value={search} oninput={value => {search = value; page = 1;}} /></label>
    <label>Biome<GameSelect label="Biome" value={biomeId} options={biomeOptions} onchange={value => {biomeId = value; page = 1;}} /></label>
    <label>Rank<GameSelect label="Rank" value={rarity} options={rankOptions} onchange={value => {rarity = value; page = 1;}} /></label>
    <label>Catch type<GameSelect label="Catch type" value={outcome} options={[{value:"",label:"All finds"},{value:"fish",label:"Fish"},{value:"junk",label:"Rusted tin"},{value:"treasure",label:"Treasure"}]} onchange={value => {outcome = value; page = 1;}} /></label>
  </div>
  <div class="sorting">
    <label>Sort by<GameSelect label="Sort by" value={sort} options={[{value:"date",label:"Date"},{value:"rank",label:"Rank"},{value:"weight",label:"Weight"},{value:"length",label:"Length"},{value:"xp",label:"XP earned"}]} onchange={value => {sort = value; page = 1;}} /></label>
    <label>Order<GameSelect label="Order" value={descending} options={[{value:true,label:sort==="date"?"Newest first":sort==="rank"?"Highest rank first":sort==="xp"?"Most XP first":sort==="weight"?"Heaviest first":"Longest first"},{value:false,label:sort==="date"?"Oldest first":sort==="rank"?"Lowest rank first":sort==="xp"?"Least XP first":sort==="weight"?"Lightest first":"Shortest first"}]} onchange={value => {descending = value; page = 1;}} /></label>
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
  section{background:var(--paper);color:var(--ink);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 3px var(--panel-highlight),0 7px 0 var(--site-base);padding:36px;margin-top:30px}
  .heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.eyebrow{font-size:14px;letter-spacing:2px;color:var(--ink-muted);margin:0 0 5px;font-weight:800}h2{font:40px var(--font-game);margin:0}.count{font:22px var(--font-game);white-space:nowrap}.intro,.footnote{color:var(--ink-muted);font-size:16px}.intro{margin:10px 0 20px}.footnote{font-size:14px;margin:20px 0 0}
  .filters,.sorting{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}.filters{padding-bottom:16px;border-bottom:1px dashed var(--paper-edge)}.filters label{flex:1;min-width:135px}.filters .search{flex:2;min-width:190px}.sorting{margin:16px 0 18px}label{display:flex;flex-direction:column;gap:5px;font-size:14px;color:var(--ink-muted);font-weight:800}.filters :global(.game-search),.filters :global(.game-select),.sorting :global(.game-select){width:100%}.sorting label{min-width:150px}.reset,.error button{min-height:38px;padding:7px 12px;border:2px solid var(--paper-edge);background:var(--panel-inset);color:var(--ink);font:20px var(--font-game)}.status{margin-left:auto;font-size:14px;color:var(--ink-muted);padding:9px 0}
  .entry{display:flex;gap:20px;align-items:center;padding:20px 0;border-bottom:1px dashed var(--paper-edge)}.entry img{width:80px;height:80px;object-fit:contain;image-rendering:pixelated}.identity{flex:1;min-width:0}h3{font:27px var(--font-game);margin:0;overflow-wrap:anywhere}.rank{font:17px var(--font-game);background:var(--panel-inset);border:1px solid var(--paper-edge);padding:2px 6px;display:inline-block;vertical-align:middle}.identity p{font-size:16px;color:var(--ink-muted);margin:4px 0}.identity time{font-size:14px;color:var(--ink-muted)}dl{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0;flex:0 0 42%}dt{font-size:14px;color:var(--ink-muted)}dd{margin:3px 0 0;font-size:16px;font-weight:800;overflow-wrap:anywhere}.xp{color:var(--success-ink)}.loading{opacity:.55}.empty{padding:30px 0;color:var(--ink-muted)}.error{color:var(--danger-ink);font-size:16px}
  @media(max-width:800px){.entry{flex-wrap:wrap}dl{flex:1 0 100%;padding-left:100px}.status{margin-left:0}}
  @media(max-width:600px){section{padding:20px;margin-top:22px}.heading{align-items:flex-start;flex-direction:column;gap:3px}h2{font-size:30px}.count{font-size:18px}.filters{gap:10px}.filters label{min-width:100px}.filters .search{flex-basis:100%;min-width:0}.sorting{gap:10px}.sorting label{flex:1;min-width:110px}.status{flex:1;text-align:right}.entry{gap:10px}.entry img{width:50px;height:50px}h3{font-size:23px}dl{padding-left:0;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}dd{font-size:13px}.identity time{font-size:12px}}
</style>
