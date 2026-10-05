<script lang="ts">
  import type { AnglerStanding, LegendaryFind, BiomeDefinition, SpeciesDefinition, SpeciesRecord } from '@fishing-game/generated/types';
  import PageTurner from './PageTurner.svelte';

  interface RecordBookData {
    species: SpeciesDefinition[];
    records: SpeciesRecord[];
    standings: AnglerStanding[];
    legendaryFinds: LegendaryFind[];
    biomes: BiomeDefinition[];
    player: { playerId: bigint } | null;
    ready: boolean;
  }
  let { game, pageSize = 12 }: { game: RecordBookData; pageSize?: number } = $props();
  type Category = 'discoveries' | 'fishCount' | 'uurCount' | 'recordsHeld';
  const categories: { key: Category; name: string; unit: string; description: string; icon: string }[] = [
    { key: 'discoveries', name: 'Collectors', unit: 'species', description: 'Unique ordinary species discovered. The two legendary finds are bonus discoveries.', icon: '▤' },
    { key: 'fishCount', name: 'Most Fish Caught', unit: 'fish', description: 'Every successful fish catch counts, even after it is sold. Junk and treasure do not count.', icon: '🎣' },
    { key: 'uurCount', name: 'Trophy Hunters', unit: 'UUR catches', description: 'Lifetime UUR fish catches, including Fihs. Every trophy counts, even after selling.', icon: '✦' },
    { key: 'recordsHeld', name: 'Record Holders', unit: 'records', description: 'Current longest and heaviest species records. Each title counts once and can change hands.', icon: '♛' },
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
  const ranked = $derived.by(() => {
    const sorted = game.standings.filter(row => BigInt(row[category]) > 0n).slice().sort((a, b) => {
      const left = BigInt(a[category]); const right = BigInt(b[category]);
      return left === right ? a.playerId < b.playerId ? -1 : a.playerId > b.playerId ? 1 : 0 : left > right ? -1 : 1;
    });
    let rank = 0;
    return sorted.map((angler, index) => {
      if (!index || BigInt(angler[category]) !== BigInt(sorted[index - 1][category])) rank = index + 1;
      return { angler, rank };
    });
  });
  const ownRank = $derived(ranked.find(row => row.angler.playerId === game.player?.playerId));
  const ownStanding = $derived(game.standings.find(row => row.playerId === game.player?.playerId));
  const legends = $derived(game.species.filter(fish => !fish.countsForOrdinaryCollectionCompletion));
  const finds = $derived(game.legendaryFinds.slice().sort((a, b) => a.firstCaughtAt.microsSinceUnixEpoch < b.firstCaughtAt.microsSinceUnixEpoch ? -1 : a.firstCaughtAt.microsSinceUnixEpoch > b.firstCaughtAt.microsSinceUnixEpoch ? 1 : a.key < b.key ? -1 : a.key > b.key ? 1 : 0));
  const number = (value: bigint | number) => value.toLocaleString();
  const score = (angler: AnglerStanding) => number(angler[category]) + (category === 'discoveries' ? ` / ${ordinary.length}` : '');
  const date = (micros: bigint) => new Date(Number(micros / 1000n)).toLocaleString();
  const biomeName = (id: number) => game.biomes.find(row => row.biomeId === id)?.name ?? 'Unknown waters';
  const recordValue = (record: SpeciesRecord) => record.metric === 'length'
    ? (Number(record.measurement) / 10).toFixed(1) + ' cm'
    : (Number(record.measurement) / 1000).toFixed(3) + ' kg';
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
      <span class="holder">{record.displayName}{#if record.playerId === game.player?.playerId}<em>You</em>{/if}</span>
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
      <div class="search"><label for="record-search">Find a fish</label><input id="record-search" type="search" placeholder="Carp, koi, sturgeon…" bind:value={query} oninput={() => recordPage = 1} /></div>
      <div><label for="record-biome">Biome</label><select id="record-biome" bind:value={biome} onchange={() => recordPage = 1}><option value={0}>All waters</option>{#each game.biomes as water}<option value={water.biomeId}>{water.name}</option>{/each}</select></div>
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
            <strong>{row.angler.displayName}</strong>{#if row.angler.playerId === game.player?.playerId}<em>You</em>{/if}
            <b>{number(row.angler[category])}</b><small>{category === 'discoveries' ? `of ${ordinary.length} species` : activeCategory.unit}</small><div class="plinth">#{row.rank}</div>
          </article>
        {/each}
      </div>
      {#if ranked.length > 3}
        <ol class="angler-list" start={(boardPage - 1) * pageSize + 4}>
          {#each ranked.slice(3 + (boardPage - 1) * pageSize, 3 + boardPage * pageSize) as row (row.angler.playerId)}
            <li class:you={row.angler.playerId === game.player?.playerId}><span class="position">#{row.rank}</span><strong>{row.angler.displayName}{#if row.angler.playerId === game.player?.playerId}<em>You</em>{/if}</strong><span class="score">{score(row.angler)}<small>{activeCategory.unit}</small></span></li>
          {/each}
        </ol><PageTurner bind:page={boardPage} total={ranked.length - 3} {pageSize} label="Angler leaderboard pages" />
      {/if}
    {:else}<div class="empty-board"><span aria-hidden="true">♛</span><h3>{game.ready ? 'A title waiting to be claimed.' : 'Checking the standings…'}</h3><p>{game.ready ? 'The first qualifying catch will put an angler on this board.' : 'The camp is connecting to the waters.'}</p></div>{/if}
    <div class="your-position" aria-live="polite"><span>YOUR POSITION</span>{#if game.player}<strong>{ownRank ? '#' + ownRank.rank : 'Unranked'}</strong><span>{ownStanding ? score(ownStanding) : '0'} {activeCategory.unit}</span>{:else}<span>Link Discord to see where you stand.</span>{/if}</div>
  </div>

  <aside class="legendary" aria-labelledby="legendary-heading">
    <div class="legendary-heading"><span aria-hidden="true">✦</span><div><p class="eyebrow">THE ONES YOU TELL STORIES ABOUT</p><h3 id="legendary-heading">Legendary Finds</h3></div><span aria-hidden="true">✦</span></div>
    <p class="note">Two bonus discoveries. Neither is required to complete the ordinary fish book.</p>
    <div class="legend-shrines">{#each legends as fish (fish.speciesId)}{@const discoveries = finds.filter(find => find.speciesId === fish.speciesId)}<article><div class="legend-art"><img src={fish.spriteAsset} alt="" /><span>{fish.allowedRarities.join(' / ')} RANK</span></div><div><h4>{fish.name}</h4><p>{fish.key === 'fihs' ? 'The mythical fish. Second rarest in the waters.' : 'The impossible sock. Rarest catch of them all.'}</p><small>{discoveries.length ? `${discoveries.length} ${discoveries.length === 1 ? 'angler has' : 'anglers have'} found this legend.` : 'No confirmed finds yet.'}</small></div></article>{/each}</div>
    {#if finds.length}<ul class="legend-find-list">{#each finds.slice((legendPage - 1) * pageSize, legendPage * pageSize) as find (find.key)}<li class:you={find.playerId === game.player?.playerId}><div><strong>{find.displayName}</strong>{#if find.playerId === game.player?.playerId}<em>You</em>{/if}<span>{game.species.find(fish => fish.speciesId === find.speciesId)?.name} · {number(find.count)} caught</span></div><small>First found {date(find.firstCaughtAt.microsSinceUnixEpoch)}</small></li>{/each}</ul><PageTurner bind:page={legendPage} total={finds.length} {pageSize} label="Legendary find pages" />{/if}
  </aside>
</section>

<style>
  .records-board{padding:30px;background:repeating-linear-gradient(0deg,#896b3410 0 1px,transparent 1px 6px),var(--paper);color:var(--ink);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px #fff0cb,0 6px 0 #132a25}
  .board-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:24px}.eyebrow{font-family:var(--font-game);font-size:13px;letter-spacing:.06em;color:#806440;margin:0 0 6px}h2,h3,h4{font-family:var(--font-game);font-weight:500;line-height:1.2;margin:0}h2{font-size:36px}.board-heading p:last-child{font-size:13px;color:var(--ink-muted);margin:8px 0 0}.wood-stamp{flex-shrink:0;padding:10px 15px;background:#58432f;color:#e8d2a0;border:2px solid #8c6b43;box-shadow:inset 0 0 0 2px #372c24;text-align:center;font-size:10px;letter-spacing:.08em;transform:rotate(3deg)}.wood-stamp b{font-family:var(--font-game);font-size:19px;font-weight:500}
  .book-tabs{display:flex;gap:8px;border-bottom:3px solid #705039;margin-bottom:22px}.book-tabs button{font-family:var(--font-game);font-size:21px;padding:11px 20px;border:2px solid #705039;border-bottom:0;background:#c7af80;color:#58432f}.book-tabs button[aria-selected=true]{background:#765239;color:#fff0cc;box-shadow:inset 0 0 0 2px #a78056}
  .filters{display:flex;gap:16px;align-items:end;flex-wrap:wrap;padding:14px;background:#e4d2a7;border:1px solid #b99b6c}.filters>div{display:flex;flex-direction:column;gap:6px;min-width:0}.search{flex:1}.filters label{font-size:12px;font-weight:700;color:#685034}.filters input[type=search],select{min-height:44px;min-width:0;width:100%;border:2px solid #a68a60;background:#fff1cd;color:var(--ink);padding:8px 12px;font:inherit}.mine-filter{display:flex;align-items:center;gap:8px;min-height:44px}.mine-filter input{width:18px;height:18px;accent-color:#426549}.note{font-size:12px;color:#79664a;margin:16px 0}.record-columns,summary{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) minmax(0,1fr);gap:18px}.record-columns{padding:8px 16px;font-size:10px;color:#80623c;letter-spacing:.08em}.record-list{border:1px solid #b99b6c}.fish-record{background:#fff0c9;border-bottom:1px dashed #c8ae80}.fish-record:last-child{border-bottom:0}summary{list-style:none;padding:14px 16px;cursor:pointer;align-items:center}summary::-webkit-details-marker{display:none}summary:hover{background:#f8e4b8}summary:focus-visible{outline:3px solid #eaa552;outline-offset:-3px}.species{display:flex;align-items:center;gap:12px;min-width:0}.species>div:last-of-type{min-width:0}.species strong{display:block;font-family:var(--font-game);font-size:21px;line-height:1.15;overflow-wrap:anywhere}.species small{display:block;font-size:10px;color:#806b4e;margin-top:5px}.sprite-slot{width:58px;height:52px;flex-shrink:0;display:grid;place-items:center;background:#385d56;border:2px solid #7b8860;box-shadow:inset 0 0 0 2px #587563}.sprite-slot img{width:48px;height:42px;object-fit:contain;image-rendering:pixelated}.expand{font-size:18px;margin-left:auto;color:#927545}.fish-record[open] .expand{transform:rotate(45deg)}.measurement{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.measurement strong{font-family:var(--font-game);font-size:24px;font-weight:500;line-height:1.2}.rank-badge{display:inline-block;font-family:var(--font-game);font-size:13px;padding:1px 6px;border:1px solid #b09564;background:#e8d6a8;color:#71592f}.rank-badge[data-rank=UUR]{background:#554271;color:#fff0c4;border-color:#aa8bca}.rank-badge[data-rank=UR],.rank-badge[data-rank=SSS]{background:#4c697c;color:#fff1cc}.holder{display:block;font-size:12px;color:#746045;margin-top:5px;overflow-wrap:anywhere}em{display:inline-block;font-size:10px;font-style:normal;padding:1px 5px;background:#426549;color:#f7edc7;margin-left:6px;vertical-align:middle}.unclaimed{font-family:var(--font-game);font-size:21px;color:#9c8059}.catch-stat>small{display:block;color:#947d5b;font-size:11px}.mobile-label{display:none!important}.record-detail{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:0 16px;padding:15px 0;border-top:1px dashed #c5ab7c;font-size:12px}.record-detail b{color:#705039}.record-detail p{margin:5px 0}.record-detail small{color:#8a7250;font-size:10px}
  .categories{display:flex;gap:8px;flex-wrap:wrap}.categories button{display:flex;align-items:center;justify-content:center;gap:8px;flex:1;font-family:var(--font-game);font-size:17px;padding:12px 10px;border:2px solid #7e6446;background:#e4d4a9;color:var(--ink);box-shadow:inset 0 0 0 2px #fff1cc,0 3px 0 #9d835f}.categories button[aria-pressed=true]{background:#385846;color:#fff0c9;border-color:#58724d;box-shadow:inset 0 0 0 2px #69825d,0 3px 0 #233c30}.category-heading{display:flex;align-items:baseline;justify-content:space-between;gap:15px;margin-top:26px}h3{font-size:27px}.category-heading>span{font-size:11px;color:#806b4e}.podiums{display:flex;align-items:end;justify-content:center;gap:14px;padding:15px 0 22px}.podium{position:relative;text-align:center;flex:1;max-width:230px;min-width:0;padding:18px 12px 0;background:#ebd9aa;border:2px solid #bc9e69;box-shadow:inset 0 0 0 2px #fff1cb,3px 4px 0 #c5aa78}.podium[data-place="1"]{order:2;background:#f8e4ac;border-color:#b38b40}.podium[data-place="2"]{order:1}.podium[data-place="3"]{order:3}.medal{display:block;font-size:36px;line-height:1.3;margin-bottom:8px}.podium>strong{display:block;overflow-wrap:anywhere;font-family:var(--font-game);font-size:23px;font-weight:500}.podium>b{display:block;font-family:var(--font-game);font-size:31px;font-weight:500;margin-top:12px}.podium>small{color:#8a7250;font-size:11px}.plinth{font-family:var(--font-game);font-size:26px;color:#fff0ce;background:repeating-linear-gradient(0deg,#fff1c508 0 2px,transparent 2px 8px),#775139;border:2px solid #4f3829;border-bottom:0;margin:15px -12px 0;padding:12px}.podium[data-place="1"] .plinth{padding-top:28px}.podium[data-place="3"] .plinth{padding-top:6px}.you{outline:2px solid #62805a;outline-offset:-2px}.angler-list,.legend-find-list{list-style:none;padding:0;margin:0;border:1px solid #b99b6c}.angler-list li{display:grid;grid-template-columns:50px minmax(0,1fr) auto;align-items:center;gap:12px;padding:12px 16px;background:#fff0c9;border-bottom:1px dashed #c8ae80}.angler-list li:last-child{border:0}.position{font-family:var(--font-game);font-size:22px;color:#91764e}.angler-list strong{font-weight:700;overflow-wrap:anywhere;font-size:14px}.score{text-align:right;font-family:var(--font-game);font-size:22px}.score small{display:block;font-family:var(--font-body);font-size:10px;color:#8a7250}.your-position{position:sticky;bottom:12px;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:22px;padding:12px 18px;background:#385846;color:#fff0c9;border:2px solid #8a9b69;box-shadow:0 4px 0 #243d30}.your-position>span:first-child{font-family:var(--font-game);font-size:16px}.your-position strong{font-family:var(--font-game);font-size:25px;font-weight:500}.your-position>span:last-child{font-size:12px}.empty,.empty-board{text-align:center;padding:24px;color:#8a7250;font-size:13px}.empty-board{padding:36px 20px;background:#ebd9aa;border:1px dashed #b99b6c}.empty-board>span{font-size:38px;color:#b69b62}.empty-board h3{margin-top:12px}.empty-board p{margin-bottom:0}
  .legendary{margin-top:36px;padding:22px;background:#e8d8b6;border:2px solid #9b8053;box-shadow:inset 0 0 0 3px #f7eac8}.legendary-heading{display:flex;gap:14px;align-items:center}.legendary-heading>span{color:#987438;font-size:28px}.legendary-heading>span:last-child{margin-left:auto}.legendary h3{font-size:29px}.legendary .eyebrow{font-size:11px}.legend-shrines{display:grid;grid-template-columns:1fr 1fr;gap:14px}.legend-shrines article{display:flex;align-items:center;gap:14px;padding:14px;background:#fff0c9;border:1px solid #c1a16b;min-width:0}.legend-art{position:relative;flex-shrink:0;width:90px;height:95px;display:flex;align-items:center;justify-content:center;flex-direction:column;background:#354e48;border:2px solid #a68948;box-shadow:inset 0 0 0 2px #637456}.legend-art img{width:70px;height:66px;object-fit:contain;image-rendering:pixelated}.legend-art span{font-family:var(--font-game);font-size:12px;color:#f3d98f}.legendary h4{font-size:23px}.legend-shrines p{font-size:11px;color:#806b4e;margin:6px 0}.legend-shrines small{font-size:10px;color:#927545}.legend-find-list{margin-top:18px}.legend-find-list li{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:12px 14px;border-bottom:1px dashed #c8ae80;background:#fff0c9}.legend-find-list li:last-child{border:0}.legend-find-list strong{font-size:13px}.legend-find-list span{display:block;font-size:11px;color:#806b4e}.legend-find-list small{font-size:10px;color:#927545}
  @media(max-width:800px){.legend-shrines{grid-template-columns:1fr}.categories button{flex-basis:40%}.record-columns,summary{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr) minmax(0,1fr);gap:12px}.species{gap:8px}.sprite-slot{width:44px;height:44px}.sprite-slot img{width:38px;height:36px}.species strong{font-size:18px}.measurement strong{font-size:21px}.expand{display:none}}
  @media(max-width:600px){.records-board{padding:20px 14px}.board-heading{gap:10px}h2{font-size:29px}.wood-stamp{padding:7px;font-size:8px}.wood-stamp b{font-size:14px}.board-heading p:last-child{font-size:11px}.book-tabs{gap:5px}.book-tabs button{font-size:17px;padding:10px 8px;flex:1}.filters{padding:12px;gap:10px}.filters .search{flex-basis:100%}.filters>div:nth-child(2){flex:1}.mine-filter{flex:1;font-size:11px!important}.filters input[type=search],select{font-size:13px}.note{font-size:11px}.record-columns{display:none}summary{grid-template-columns:1fr 1fr;padding:12px;gap:12px}.species{grid-column:1/-1}.species strong{font-size:22px}.sprite-slot{width:50px;height:44px}.species .expand{display:block}.mobile-label{display:block!important;margin-bottom:4px}.holder{font-size:11px}.measurement strong{font-size:22px}.record-detail{grid-template-columns:1fr;gap:12px;margin:0 12px}.categories{gap:8px}.categories button{font-size:15px;padding:10px 5px;gap:5px}.category-heading{align-items:start;flex-direction:column;gap:5px}h3{font-size:25px}.podiums{gap:6px;padding-top:8px}.podium{padding:10px 5px 0}.medal{font-size:27px}.podium>strong{font-size:17px}.podium>b{font-size:23px}.podium>small{font-size:9px}.plinth{margin:12px -5px 0;font-size:22px}.angler-list li{grid-template-columns:34px minmax(0,1fr) auto;gap:8px;padding:10px}.angler-list strong{font-size:12px}.score{font-size:19px}.your-position{padding:10px;gap:8px;flex-wrap:wrap;bottom:8px}.your-position>span:first-child{font-size:14px}.your-position>span:last-child{font-size:11px}.legendary{padding:14px;margin-top:26px}.legendary-heading{gap:8px}.legendary-heading>span{font-size:20px}.legendary .eyebrow{font-size:9px}.legendary h3{font-size:25px}.legend-shrines article{padding:10px;gap:10px}.legend-art{width:72px;height:85px}.legend-art img{width:57px;height:57px}.legendary h4{font-size:21px}.legend-find-list li{align-items:start;flex-direction:column;gap:5px}}
</style>
