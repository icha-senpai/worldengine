<script lang="ts">
  import type { AnglerStatistics, AnglerStanding, LegendaryFind, BiomeDefinition, RaidStatistics, SpeciesDefinition, SpeciesRecord } from '@fishing-game/generated/types';
  import Statistics from './Statistics.svelte';
  import PageTurner from './PageTurner.svelte';
  import { formatLength, formatWeight } from './measurements';
  import GameSelect from './GameSelect.svelte';
  import SearchInput from './SearchInput.svelte';

  interface RecordBookData {
    species: SpeciesDefinition[];
    records: SpeciesRecord[];
    standings: AnglerStanding[];
    statistics: AnglerStatistics[];
    raidStatistics: RaidStatistics[];
    legendaryFinds: LegendaryFind[];
    biomes: BiomeDefinition[];
    player: { playerId: bigint } | null;
    ready: boolean;
  }
  let { game, pageSize = 12 }: { game: RecordBookData; pageSize?: number } = $props();
  function openProfile(event: MouseEvent, playerId: bigint) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    event.stopPropagation();
    const url = new URL(window.location.href);
    url.searchParams.set('angler', playerId.toString());
    url.hash = 'anglers';
    window.history.pushState(null, '', url);
    window.dispatchEvent(new PopStateEvent('popstate'));
    window.scrollTo(0, 0);
  }
  type Category = 'discoveries' | 'fishCount' | 'uurCount' | 'recordsHeld' | 'totalCasts' | 'bonusPulls' | 'treasureCount' | 'junkCount' | 'collectionPairs' | 'totalWeightG' | 'saleEarnings' | 'biggestSingleSale' | 'biggestSaleBatch' | 'recordsSet' | 'daysFished' | 'bestRelativeLengthMillionths';
  const categories: { key: Category; name: string; unit: string; description: string; icon: string }[] = [
    { key: 'discoveries', name: 'Collectors', unit: 'species', description: 'Unique ordinary species discovered. The two legendary finds are bonus discoveries.', icon: '▤' },
    { key: 'fishCount', name: 'Most Fish Caught', unit: 'fish', description: 'Every successful fish catch counts, even after it is sold. Junk and treasure do not count.', icon: '🎣' },
    { key: 'uurCount', name: 'Trophy Hunters', unit: 'UUR catches', description: 'Lifetime UUR fish catches, including Fihs. Every trophy counts, even after selling.', icon: '✦' },
    { key: 'recordsHeld', name: 'Record Holders', unit: 'records', description: 'Current longest and heaviest species records. Each title counts once and can change hands.', icon: '♛' },
    { key: 'totalCasts', name: 'Most Casts', unit: 'casts', description: 'Completed casts across all waters.', icon: '🎣' },
    { key: 'bonusPulls', name: 'Bonus Pulls', unit: 'bonus pulls', description: 'Extra catches from rod bonus pulls. Boat and net haul catches do not count.', icon: '✦' },
    { key: 'treasureCount', name: 'Treasure Hunters', unit: 'treasures', description: 'Lifetime treasure catches.', icon: '◇' },
    { key: 'junkCount', name: 'Cleanup Crew', unit: 'junk catches', description: 'Lifetime junk catches.', icon: '♻' },
    { key: 'collectionPairs', name: 'Rank Collectors', unit: 'pairs', description: 'Distinct ordinary species and rank combinations discovered.', icon: '▤' },
    { key: 'totalWeightG', name: 'Catch Weight', unit: 'caught weight', description: 'Recorded weight including sold fish. Older journal coverage may be incomplete.', icon: '⚓' },
    { key: 'saleEarnings', name: 'Fish-sale Earnings', unit: 'coins', description: 'Recorded fish sales. Older receipts may be incomplete; daily rewards do not count.', icon: '●' },
    { key: 'biggestSingleSale', name: 'Most Expensive Fish', unit: 'coins', description: 'Highest saved value of a caught fish, kept or sold.', icon: '●' },
    { key: 'biggestSaleBatch', name: 'Biggest Batch', unit: 'coins', description: 'Largest recorded sale batch. Older receipts may be incomplete.', icon: '●' },
    { key: 'recordsSet', name: 'Record Setters', unit: 'record catches', description: 'Catches establishing or breaking a global species record. Recovered history is included; one catch counts once.', icon: '♛' },
    { key: 'daysFished', name: 'Days on the Water', unit: 'UTC days', description: 'Distinct fishing dates in UTC. Older journal coverage may be incomplete.', icon: '☀' },
    { key: 'bestRelativeLengthMillionths', name: 'Relative Size', unit: 'times typical length', description: 'Best fish length relative to its species typical length. Older journal coverage may be incomplete.', icon: '↔' },
  ];
  let view = $state<'fish' | 'anglers'>('fish');
  let category = $state<Category>('discoveries');
  let biome = $state(0);
  let query = $state('');
  let mine = $state(false);
  let recordPage = $state(1);
  let boardPage = $state(1);
  let legendPage = $state(1);
  $effect(() => { pageSize; recordPage = boardPage = legendPage = 1; });
  $effect(() => { if (!game.player && mine) { mine = false; recordPage = 1; } });
  const ordinary = $derived(game.species.filter(fish => fish.countsForOrdinaryCollectionCompletion));
  const recordMap = $derived(new Map(game.records.map(record => [record.key, record])));
  const records = $derived(ordinary.map(fish => ({
    fish, length: recordMap.get(BigInt(fish.speciesId) * 2n), weight: recordMap.get(BigInt(fish.speciesId) * 2n + 1n),
  })).filter(row => (!biome || row.fish.biomeId === biome)
    && row.fish.name.toLowerCase().includes(query.trim().toLowerCase())
    && (!mine || !!game.player && (row.length?.playerId === game.player.playerId || row.weight?.playerId === game.player.playerId)))
    .sort((a, b) => a.fish.name.localeCompare(b.fish.name)));
  const activeCategory = $derived(categories.find(item => item.key === category)!);
  const boardRows = $derived(game.standings.map(row => ({ ...row, ...game.statistics.find(stat => stat.playerId === row.playerId) })));
  const value = (row: (typeof boardRows)[number]) => BigInt(row[category] ?? 0);
  const ranked = $derived.by(() => {
    const sorted = boardRows.filter(row => value(row) > 0n).slice().sort((a, b) => {
      const left = value(a); const right = value(b);
      return left === right ? a.playerId < b.playerId ? -1 : a.playerId > b.playerId ? 1 : 0 : left > right ? -1 : 1;
    });
    let rank = 0;
    return sorted.map((angler, index) => {
      if (!index || value(angler) !== value(sorted[index - 1])) rank = index + 1;
      return { angler, rank };
    });
  });
  const ownRank = $derived(ranked.find(row => row.angler.playerId === game.player?.playerId));
  const ownStanding = $derived(boardRows.find(row => row.playerId === game.player?.playerId));
  const legends = $derived(game.species.filter(fish => !fish.countsForOrdinaryCollectionCompletion));
  const finds = $derived(game.legendaryFinds.slice().sort((a, b) => a.firstCaughtAt.microsSinceUnixEpoch < b.firstCaughtAt.microsSinceUnixEpoch ? -1 : a.firstCaughtAt.microsSinceUnixEpoch > b.firstCaughtAt.microsSinceUnixEpoch ? 1 : a.key < b.key ? -1 : a.key > b.key ? 1 : 0));
  const number = (value: bigint | number) => value.toLocaleString();
  const score = (angler: (typeof boardRows)[number], withCapacity = true) => (category === 'totalWeightG' ? formatWeight(value(angler)) : category === 'bestRelativeLengthMillionths' ? (Number(value(angler)) / 1000000).toFixed(2) + '×' : number(value(angler))) + (category === 'discoveries' && withCapacity ? ` / ${ordinary.length}` : '');
  const date = (micros: bigint) => new Date(Number(micros / 1000n)).toLocaleString();
  const biomeName = (id: number) => game.biomes.find(row => row.biomeId === id)?.name ?? 'Unknown waters';
  const recordValue = (record: SpeciesRecord) => record.metric === 'length'
    ? formatLength(record.measurement)
    : formatWeight(record.measurement);
  function switchTab(next: 'fish' | 'anglers') { view = next; }
  function tabKey(event: KeyboardEvent) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    view = event.key === 'Home' ? 'fish' : event.key === 'End' ? 'anglers' : view === 'fish' ? 'anglers' : 'fish';
    document.getElementById('records-tab-' + view)?.focus();
  }
</script>

{#snippet catchStat(record: SpeciesRecord | undefined, label: string)}
  <div class="catch-stat">
    <small class="mobile-label">{label}</small>
    {#if record}
      <div class="measurement"><strong>{recordValue(record)}</strong><span class="rank-badge" data-rank={record.rarity}>{record.rarity}</span></div>
      <span class="holder"><a href={'?angler=' + record.playerId + '#anglers'} onclick={event => openProfile(event, record.playerId)}>{record.displayName}</a>{#if record.playerId === game.player?.playerId}<em>You</em>{/if}</span>
    {:else}<span class="unclaimed">Unclaimed</span><small>Be the first.</small>{/if}
  </div>
{/snippet}

<section class="records-board" aria-labelledby="records-heading">
  <header class="board-heading"><div><p class="eyebrow">A CATCH WORTH BRAGGING ABOUT</p><h2 id="records-heading">The bragging board</h2><p>Big catches. Patient anglers. A little camp rivalry.</p></div><span class="wood-stamp">ALL WATERS<br /><b>ALL TIME</b></span></header>
  <div class="book-tabs" role="tablist" aria-label="Records views">
    <button id="records-tab-fish" role="tab" aria-selected={view === 'fish'} aria-controls="fish-records-panel" tabindex={view === 'fish' ? 0 : -1} onclick={() => switchTab('fish')} onkeydown={tabKey}>Fish Records</button>
    <button id="records-tab-anglers" role="tab" aria-selected={view === 'anglers'} aria-controls="angler-records-panel" tabindex={view === 'anglers' ? 0 : -1} onclick={() => switchTab('anglers')} onkeydown={tabKey}>Angler Leaderboards</button>
  </div>

  <div id="fish-records-panel" role="tabpanel" aria-labelledby="records-tab-fish" hidden={view !== 'fish'}>
    <div class="filters">
      <div class="search"><label for="record-search">Find a fish</label><SearchInput id="record-search" label="Find a fish" placeholder="Carp, koi, sturgeon…" value={query} oninput={value => {query = value; recordPage = 1;}} /></div>
      <div><label for="record-biome">Biome</label><GameSelect id="record-biome" label="Biome" value={biome} options={[{value:0,label:"All waters"},...game.biomes.map(water=>({value:water.biomeId,label:water.name}))]} onchange={value => {biome = value; recordPage = 1;}} /></div>
      <label class="mine-filter"><input type="checkbox" bind:checked={mine} disabled={!game.player} onchange={() => recordPage = 1} />Records I hold</label>
    </div>
    <p class="note">{game.ready ? `${records.length} matching species` : 'Loading the record book…'} · Length and weight may belong to different catches.{#if !game.player}{' '}Link Discord to find your own records.{/if}</p>
    <div class="record-columns" aria-hidden="true"><span>FISH / WATERS</span><span>LONGEST CATCH</span><span>HEAVIEST CATCH</span></div>
    <div class="record-list">
      {#each records.slice((recordPage - 1) * pageSize, recordPage * pageSize) as row (row.fish.speciesId)}
        <details class="fish-record" data-species={row.fish.key}>
          <summary>
            <div class="species"><div class="sprite-slot"><img src={row.fish.spriteAsset} alt="" loading="lazy" /></div><div><strong>{row.fish.name}</strong><small>{biomeName(row.fish.biomeId)}</small></div><span class="expand" aria-hidden="true">＋</span></div>
            {@render catchStat(row.length, 'Longest catch')}{@render catchStat(row.weight, 'Heaviest catch')}
          </summary>
          <div class="record-detail">
            <small class="discord-record">Fish ID {row.fish.speciesId} · Discord: <code>/leaderboard species_id:{row.fish.speciesId}</code></small>
            {#each [row.length, row.weight] as record, index}
              <div><b>{index === 0 ? 'Longest' : 'Heaviest'}</b>{#if record}<p>{record.displayName} · {record.rarity} rank · {recordValue(record)}</p><small>Caught {date(record.caughtAt.microsSinceUnixEpoch)} · Catch #{record.catchId.toString()}</small>{:else}<p>This title is waiting for its first catch.</p>{/if}</div>
            {/each}
          </div>
        </details>
      {/each}
      {#if game.ready && !records.length}<p class="empty">{mine ? 'No matching records held yet. Your next cast could change that.' : 'No fish match these filters.'}</p>{/if}
    </div>
    <PageTurner bind:page={recordPage} total={records.length} {pageSize} label="Fish record pages" />
  </div>

  <div id="angler-records-panel" role="tabpanel" aria-labelledby="records-tab-anglers" hidden={view !== 'anglers'}>
    <div class="categories" role="group" aria-label="Leaderboard categories">{#each categories as item}<button aria-pressed={category === item.key} onclick={() => { category = item.key; boardPage = 1; }}><span aria-hidden="true">{item.icon}</span>{item.name}</button>{/each}</div>
    <div class="category-heading"><h3>{activeCategory.name}</h3><span>All-time · {ranked.length} ranked {ranked.length === 1 ? 'angler' : 'anglers'}</span></div><p class="note">{activeCategory.description} Equal scores share a rank.</p>
    {#if ranked.length}
      <div class="podiums" aria-label="Top three anglers">
        {#each ranked.slice(0, 3) as row, index (row.angler.playerId)}
          <article class="podium" class:you={row.angler.playerId === game.player?.playerId} data-place={index + 1}>
            <span class="medal" aria-label={'Rank ' + row.rank}>{row.rank === 1 ? '🥇' : row.rank === 2 ? '🥈' : row.rank === 3 ? '🥉' : '♛'}</span>
            <strong><a href={'?angler=' + row.angler.playerId + '#anglers'} onclick={event => openProfile(event, row.angler.playerId)}>{row.angler.displayName}</a></strong>{#if row.angler.playerId === game.player?.playerId}<em>You</em>{/if}
            <b>{score(row.angler, false)}</b><small>{category === 'discoveries' ? `of ${ordinary.length} species` : activeCategory.unit}</small><div class="plinth">#{row.rank}</div>
          </article>
        {/each}
      </div>
      {#if ranked.length > 3}
        <ol class="angler-list" start={(boardPage - 1) * pageSize + 4}>
          {#each ranked.slice(3 + (boardPage - 1) * pageSize, 3 + boardPage * pageSize) as row (row.angler.playerId)}
            <li class:you={row.angler.playerId === game.player?.playerId}><span class="position">#{row.rank}</span><strong><a href={'?angler=' + row.angler.playerId + '#anglers'} onclick={event => openProfile(event, row.angler.playerId)}>{row.angler.displayName}</a>{#if row.angler.playerId === game.player?.playerId}<em>You</em>{/if}</strong><span class="score">{score(row.angler)}<small>{activeCategory.unit}</small></span></li>
          {/each}
        </ol><PageTurner bind:page={boardPage} total={ranked.length - 3} {pageSize} label="Angler leaderboard pages" />
      {/if}
    {:else}<div class="empty-board"><span aria-hidden="true">♛</span><h3>{game.ready ? 'A title waiting to be claimed.' : 'Checking the standings…'}</h3><p>{game.ready ? 'The first qualifying catch will put an angler on this board.' : 'The camp is connecting to the waters.'}</p></div>{/if}
    <div class="your-position" aria-live="polite"><span>YOUR POSITION</span>{#if game.player}<strong>{ownRank ? '#' + ownRank.rank : 'Unranked'}</strong><span>{ownStanding ? score(ownStanding) : '0'} {activeCategory.unit}</span>{:else}<span>Link Discord to see where you stand.</span>{/if}</div>
  </div>

  {#if game.statistics.find(row => row.playerId === game.player?.playerId)}<h3>Your fishing story</h3><Statistics stats={game.statistics.find(row => row.playerId === game.player?.playerId)!} species={game.species} biomes={game.biomes} raidStats={game.raidStatistics.find(row => row.playerId === game.player?.playerId)} />{/if}

  <aside class="legendary" aria-labelledby="legendary-heading">
    <div class="legendary-heading"><span aria-hidden="true">✦</span><div><p class="eyebrow">THE ONES YOU TELL STORIES ABOUT</p><h3 id="legendary-heading">Legendary Finds</h3></div><span aria-hidden="true">✦</span></div>
    <p class="note">Two bonus discoveries. Neither is required to complete the ordinary fish book.</p>
    <div class="legend-shrines">{#each legends as fish (fish.speciesId)}{@const discoveries = finds.filter(find => find.speciesId === fish.speciesId)}<article><div class="legend-art"><img src={fish.spriteAsset} alt="" /><span>{fish.allowedRarities.join(' / ')} RANK</span></div><div><h4>{fish.name}</h4><p>{fish.key === 'fihs' ? 'The mythical fish. Second rarest in the waters.' : 'The impossible sock. Rarest catch of them all.'}</p><small>{discoveries.length ? `${discoveries.length} ${discoveries.length === 1 ? 'angler has' : 'anglers have'} found this legend.` : 'No confirmed finds yet.'}</small></div></article>{/each}</div>
    {#if finds.length}<ul class="legend-find-list">{#each finds.slice((legendPage - 1) * pageSize, legendPage * pageSize) as find (find.key)}<li class:you={find.playerId === game.player?.playerId}><div><strong><a href={'?angler=' + find.playerId + '#anglers'} onclick={event => openProfile(event, find.playerId)}>{find.displayName}</a></strong>{#if find.playerId === game.player?.playerId}<em>You</em>{/if}<span>{game.species.find(fish => fish.speciesId === find.speciesId)?.name} · {number(find.count)} caught</span></div><small>First found {date(find.firstCaughtAt.microsSinceUnixEpoch)}</small></li>{/each}</ul><PageTurner bind:page={legendPage} total={finds.length} {pageSize} label="Legendary find pages" />{/if}
  </aside>
</section>

<style>
  .discord-record{grid-column:1/-1;color:var(--ink);overflow-wrap:anywhere}
  a{color:inherit;text-decoration:underline;text-decoration-color:var(--paper-edge);text-underline-offset:3px}a:hover{color:var(--success-ink)}a:focus-visible{outline:3px solid var(--control-focus);outline-offset:3px}
  .records-board{padding:36px;background:var(--panel-texture),var(--paper);color:var(--ink);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 6px 0 var(--wood-dark)}
  .board-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:24px;flex-wrap:wrap}.eyebrow{font-family:var(--font-game);font-size:16px;letter-spacing:.06em;color:var(--ink);margin:0 0 6px}h2,h3,h4{font-family:var(--font-game);font-weight:500;line-height:1.2;margin:0}h2{font-size:40px}.board-heading p:last-child{font-size:16px;color:var(--ink-muted);margin:8px 0 0}.wood-stamp{flex-shrink:0;padding:10px 15px;background:var(--wood);color:var(--site-text);border:2px solid var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight);text-align:center;font-size:14px;letter-spacing:.08em;transform:rotate(3deg)}.wood-stamp b{font-family:var(--font-game);font-size:22px;font-weight:500}
  .book-tabs{display:flex;gap:8px;border-bottom:3px solid var(--paper-edge);margin-bottom:22px;flex-wrap:wrap}.book-tabs button{font-family:var(--font-game);font-size:24px;padding:11px 20px;border:2px solid var(--paper-edge);border-bottom:0;background:var(--panel-inset);color:var(--ink)}.book-tabs button[aria-selected=true]{background:var(--selected-background);color:var(--selected-ink);box-shadow:inset 0 0 0 2px var(--panel-highlight)}
  .filters{display:flex;gap:16px;align-items:end;flex-wrap:wrap;padding:14px;background:var(--panel-inset);border:1px solid var(--paper-edge)}.filters>div{display:flex;flex-direction:column;gap:6px;min-width:0}.search{flex:1}.filters label{font-size:16px;font-weight:700;color:var(--ink)}.filters :global(.game-search),.filters :global(.game-select){min-width:0;width:100%}.mine-filter{display:flex;align-items:center;gap:8px;min-height:44px}.note{font-size:16px;color:var(--ink);margin:16px 0}.record-columns,summary{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) minmax(0,1fr);gap:18px}.record-columns{padding:8px 16px;font-size:14px;color:var(--ink);letter-spacing:.08em}.record-list{border:1px solid var(--paper-edge)}.fish-record{background:var(--paper-light);border-bottom:1px dashed var(--paper-edge)}.fish-record:last-child{border-bottom:0}summary{list-style:none;padding:14px 16px;cursor:pointer;align-items:center}summary::-webkit-details-marker{display:none}summary:hover{background:var(--paper-light)}summary:focus-visible{outline:3px solid var(--control-focus);outline-offset:-3px}.species{display:flex;align-items:center;gap:12px;min-width:0}.species>div:last-of-type{min-width:0}.species strong{display:block;font-family:var(--font-game);font-size:24px;line-height:1.15;overflow-wrap:anywhere}.species small{display:block;font-size:14px;color:var(--ink);margin-top:5px}.sprite-slot{width:58px;height:52px;flex-shrink:0;display:grid;place-items:center;background:var(--art-background);border:2px solid var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight)}.sprite-slot img{width:48px;height:42px;object-fit:contain;image-rendering:pixelated}.expand{font-size:21px;margin-left:auto;color:var(--ink-muted)}.fish-record[open] .expand{transform:rotate(45deg)}.measurement{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.measurement strong{font-family:var(--font-game);font-size:28px;font-weight:500;line-height:1.2}.rank-badge{display:inline-block;font-family:var(--font-game);font-size:16px;padding:1px 6px;border:1px solid var(--paper-edge);background:var(--panel-inset);color:var(--ink)}.rank-badge[data-rank=UUR]{background:var(--rank-trophy-background);color:var(--ink);border-color:var(--special-ink)}.rank-badge[data-rank=UR],.rank-badge[data-rank=SSS]{background:var(--rank-high-background);color:var(--ink)}.holder{display:block;font-size:16px;color:var(--ink);margin-top:5px;overflow-wrap:anywhere}em{display:inline-block;font-size:14px;font-style:normal;padding:1px 5px;background:var(--selected-background);color:var(--selected-ink);margin-left:6px;vertical-align:middle}.unclaimed{font-family:var(--font-game);font-size:24px;color:var(--ink-muted)}.catch-stat>small{display:block;color:var(--ink-muted);font-size:14px}.mobile-label{display:none!important}.record-detail{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin:0 16px;padding:15px 0;border-top:1px dashed var(--paper-edge);font-size:16px}.record-detail b{color:var(--ink)}.record-detail p{margin:5px 0}.record-detail small{color:var(--ink-muted);font-size:14px}
  .categories{display:flex;gap:8px;flex-wrap:wrap}.categories button{min-width:180px;display:flex;align-items:center;justify-content:center;gap:8px;flex:1 1 180px;font-family:var(--font-game);font-size:20px;padding:12px 10px;border:2px solid var(--paper-edge);background:var(--panel-inset);color:var(--ink);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 3px 0 var(--wood-dark)}.categories button[aria-pressed=true]{background:var(--selected-background);color:var(--selected-ink);border-color:var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 3px 0 var(--wood-dark)}.category-heading{display:flex;align-items:baseline;justify-content:space-between;gap:15px;margin-top:26px}h3{font-size:31px}.category-heading>span{font-size:14px;color:var(--ink)}.podiums{display:flex;align-items:end;justify-content:center;gap:14px;padding:15px 0 22px}.podium{position:relative;text-align:center;flex:1;max-width:300px;min-width:0;padding:18px 12px 0;background:var(--panel-inset);border:2px solid var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight),3px 4px 0 var(--wood-dark)}.podium[data-place="1"]{order:2;background:var(--paper-light);border-color:var(--paper-edge)}.podium[data-place="2"]{order:1}.podium[data-place="3"]{order:3}.medal{display:block;font-size:40px;line-height:1.3;margin-bottom:8px}.podium>strong{display:block;overflow-wrap:anywhere;font-family:var(--font-game);font-size:27px;font-weight:500}.podium>b{display:block;font-family:var(--font-game);font-size:35px;font-weight:500;margin-top:12px;overflow-wrap:anywhere}.podium>small{color:var(--ink-muted);font-size:14px}.plinth{font-family:var(--font-game);font-size:30px;color:var(--site-text);background:var(--wood);border:2px solid var(--paper-edge);border-bottom:0;margin:15px -12px 0;padding:12px}.podium[data-place="1"] .plinth{padding-top:28px}.podium[data-place="3"] .plinth{padding-top:6px}.you{outline:2px solid var(--paper-edge);outline-offset:-2px}.angler-list,.legend-find-list{list-style:none;padding:0;margin:0;border:1px solid var(--paper-edge)}.angler-list li{display:grid;grid-template-columns:50px minmax(0,1fr) auto;align-items:center;gap:12px;padding:12px 16px;background:var(--paper-light);border-bottom:1px dashed var(--paper-edge)}.angler-list li:last-child{border:0}.position{font-family:var(--font-game);font-size:26px;color:var(--ink-muted)}.angler-list strong{font-weight:700;overflow-wrap:anywhere;font-size:17px}.score{text-align:right;font-family:var(--font-game);font-size:26px}.score small{display:block;font-family:var(--font-body);font-size:14px;color:var(--ink-muted)}.your-position{position:sticky;bottom:12px;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:22px;padding:12px 18px;background:var(--selected-background);color:var(--selected-ink);border:2px solid var(--paper-edge);box-shadow:0 4px 0 var(--wood-dark)}.your-position>span:first-child{font-family:var(--font-game);font-size:19px}.your-position strong{font-family:var(--font-game);font-size:29px;font-weight:500}.your-position>span:last-child{font-size:16px}.empty,.empty-board{text-align:center;padding:24px;color:var(--ink-muted);font-size:16px}.empty-board{padding:36px 20px;background:var(--panel-inset);border:1px dashed var(--paper-edge)}.empty-board>span{font-size:42px;color:var(--ink-muted)}.empty-board h3{margin-top:12px}.empty-board p{margin-bottom:0}
  .legendary{margin-top:36px;padding:22px;background:var(--panel-inset);border:2px solid var(--paper-edge);box-shadow:inset 0 0 0 3px var(--panel-highlight)}.legendary-heading{display:flex;gap:14px;align-items:center}.legendary-heading>span{color:var(--ink-muted);font-size:32px}.legendary-heading>span:last-child{margin-left:auto}.legendary h3{font-size:33px}.legendary .eyebrow{font-size:14px}.legend-shrines{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.legend-shrines article{display:flex;align-items:center;gap:14px;padding:14px;background:var(--paper-light);border:1px solid var(--paper-edge);min-width:0}.legend-art{position:relative;flex-shrink:0;width:90px;height:95px;display:flex;align-items:center;justify-content:center;flex-direction:column;background:var(--art-background);border:2px solid var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight)}.legend-art img{width:70px;height:66px;object-fit:contain;image-rendering:pixelated}.legend-art span{font-family:var(--font-game);font-size:16px;color:var(--art-ink)}.legendary h4{font-size:27px;overflow-wrap:anywhere}.legend-shrines p{font-size:14px;color:var(--ink);margin:6px 0}.legend-shrines small{font-size:14px;color:var(--ink-muted)}.legend-find-list{margin-top:18px}.legend-find-list li{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:12px 14px;border-bottom:1px dashed var(--paper-edge);background:var(--paper-light)}.legend-find-list li:last-child{border:0}.legend-find-list strong{font-size:16px}.legend-find-list span{display:block;font-size:14px;color:var(--ink)}.legend-find-list small{font-size:14px;color:var(--ink-muted)}
  @media(max-width:800px){.legend-shrines{grid-template-columns:1fr}.categories button{min-width:140px;flex-basis:40%}.record-columns,summary{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr) minmax(0,1fr);gap:12px}.species{gap:8px}.sprite-slot{width:44px;height:44px}.sprite-slot img{width:38px;height:36px}.species strong{font-size:20px}.measurement strong{font-size:23px}.expand{display:none}}
  @media(max-width:600px){.records-board{padding:20px 14px}.board-heading{gap:10px}h2{font-size:31px}.wood-stamp{padding:7px;font-size:11px}.wood-stamp b{font-size:16px}.board-heading p:last-child{font-size:13px}.book-tabs{gap:5px}.book-tabs button{font-size:19px;padding:10px 8px;flex:1}.filters{padding:12px;gap:10px}.filters .search{flex-basis:100%}.filters>div:nth-child(2){flex:1}.mine-filter{flex:1;font-size:13px!important}.note{font-size:13px}.record-columns{display:none}summary{grid-template-columns:minmax(0,1fr) minmax(0,1fr);padding:12px;gap:12px}.species{grid-column:1/-1}.species strong{font-size:24px}.sprite-slot{width:50px;height:44px}.species .expand{display:block}.mobile-label{display:block!important;margin-bottom:4px}.holder{font-size:13px}.measurement strong{font-size:24px}.record-detail{grid-template-columns:1fr;gap:12px;margin:0 12px}.categories{gap:8px}.categories button{min-width:140px;font-size:17px;padding:10px 5px;gap:5px}.category-heading{align-items:start;flex-direction:column;gap:5px}h3{font-size:27px}.podiums{gap:6px;padding-top:8px}.podium{padding:10px 5px 0}.medal{font-size:29px}.podium>strong{font-size:19px}.podium>b{font-size:25px}.podium>small{font-size:12px}.plinth{margin:12px -5px 0;font-size:24px}.angler-list li{grid-template-columns:34px minmax(0,1fr) auto;gap:8px;padding:10px}.angler-list strong{font-size:14px}.score{font-size:20px}.your-position{padding:10px;gap:8px;flex-wrap:wrap;bottom:8px}.your-position>span:first-child{font-size:16px}.your-position>span:last-child{font-size:13px}.legendary{padding:14px;margin-top:26px}.legendary-heading{gap:8px}.legendary-heading>span{font-size:22px}.legendary .eyebrow{font-size:12px}.legendary h3{font-size:27px}.legend-shrines article{padding:10px;gap:10px}.legend-art{width:72px;height:85px}.legend-art img{width:57px;height:57px}.legendary h4{font-size:23px}.legend-find-list li{align-items:start;flex-direction:column;gap:5px}}
  .catch-stat{min-width:0;overflow-wrap:anywhere}
  @media(max-width:600px){.unclaimed{font-size:14px}}
</style>
