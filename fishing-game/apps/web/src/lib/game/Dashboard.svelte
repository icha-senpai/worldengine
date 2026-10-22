<script lang="ts">
  import { formatLength, formatWeight } from './measurements';
  import { onMount, tick, untrack } from 'svelte';
  import * as env from '$app/env/public';
  import { Game } from './game.svelte';
  import { connectionDiagnostics } from './connection-diagnostics';
  import PondScene from './PondScene.svelte';
  import Compendium from './Compendium.svelte';
  import Records from './Records.svelte';
  import Trader from './Trader.svelte';
  import Quests from './Quests.svelte';
  import TraderStall from './TraderStall.svelte';
  import PageTurner from './PageTurner.svelte';
  import TackleBoxItems from './TackleBoxItems.svelte';
  import Gear from './Gear.svelte';
  import Achievements from './Achievements.svelte';
  import Anglers from './Anglers.svelte';
  import AnglerLevel from './AnglerLevel.svelte';
  import world from '../../../../../content/world.json';
  import Journal from './Journal.svelte';
  import ThemeToggle from './ThemeToggle.svelte';
  import { rankMaximaSummary } from './rank-maxima';
  type CampView = 'camp' | 'quests' | 'inventory' | 'collection' | 'journal' | 'waters' | 'records' | 'compendium' | 'trader' | 'achievements' | 'anglers';
  let { initialView = 'camp' } = $props<{ initialView?: CampView }>();
  const tabs: { id: CampView; label: string; personal?: boolean }[] = [
    { id: 'camp', label: 'Camp' }, { id: 'quests', label: 'Quests', personal: true }, { id: 'trader', label: 'Trader' }, { id: 'inventory', label: 'Tackle box', personal: true },
    { id: 'collection', label: 'Collection', personal: true }, { id: 'journal', label: 'Journal', personal: true },
    { id: 'waters', label: 'World map' }, { id: 'records', label: 'Records' }, { id: 'compendium', label: 'Compendium' },
    { id: 'achievements', label: 'Achievements', personal: true }, { id: 'anglers', label: 'Anglers' },
  ];
  const game = new Game();
  let activeView = $state<CampView>(untrack(() => initialView));
  let menuOpen = $state(false);
  let menuButton: HTMLButtonElement;
  const visibleView = $derived(!game.player && tabs.find(tab => tab.id === activeView)?.personal ? 'camp' : activeView);
  const viewLabel = $derived(tabs.find(tab => tab.id === visibleView)!.label);
  $effect(() => { game.setView(visibleView); });
  let inventoryPage = $state(1);
  const inventoryPageSize = 25;
  const saleBatchSize = 50;
  const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  let selectionRank = $state('');
  let bookPage = $state(1);
  let worldPage = $state(1);
  let pageSize = $state(12);
  let selected = $state<bigint[]>([]);
  let showQuote = $state(false);
  let bookBiome = $state(1);
  let bookTier = $state('');
  let bookQuery = $state('');
  let linkForm: HTMLFormElement;
  let now = $state(Date.now());
  const grades = ['Tiny', 'Small', 'Typical', 'Large', 'Trophy', 'Colossal'];
  const level = $derived(game.profile?.level ?? 1);
  const currentBiome = $derived(game.biomes.find(row => row.biomeId === game.player?.selectedBiomeId));
  const currentRod = $derived(game.rods.find(row => row.rodId === game.player?.equippedRodId));
  const ordinary = $derived(game.species.filter(row => row.countsForOrdinaryCollectionCompletion));
  const perfected = $derived(new Set(game.perfectSpecies.filter(row => row.playerId === game.player?.playerId).map(row => row.speciesId)));
  const maxima = $derived(rankMaximaSummary(game.rankMaxima, game.species, game.player?.playerId));
  const discoveries = $derived(game.collection.filter(row => ordinary.some(fish => fish.speciesId === row.speciesId)).length);
  const book = $derived(game.species.filter(fish => (!bookBiome || fish.biomeId === bookBiome) && (!bookTier || fish.allowedRarities.includes(bookTier)) && fish.name.toLowerCase().includes(bookQuery.trim().toLowerCase())));
  const remaining = $derived(game.player ? Math.max(0, Math.ceil(Number(game.player.nextCastAt.microsSinceUnixEpoch / 1000n - BigInt(now)) / 1000)) : 0);
  const quote = $derived(showQuote && game.action?.kind === 'sell' && !game.action.consumed ? game.action : null);
  const selectedOwned = $derived(selected.filter(id => game.inventory.some(fish => fish.catchId === id && !fish.favorite)));
  const saleBatch = $derived(selectedOwned.slice(0, saleBatchSize));
  const selectable = $derived(game.inventory.filter(fish => !fish.favorite));
  const speciesName = (id: number | undefined) => game.species.find(fish => fish.speciesId === id)?.name ?? 'Fish';
  const sprite = (id: number) => game.species.find(fish => fish.speciesId === id)?.spriteAsset ?? '/fish/minnow.png';
  const rankMinimums = (tier: string) => { const rule = game.rarities.find(row => row.tier === tier); return !rule || tier === 'F' ? 'Below D in length or weight' : `At least ${Number(rule.minimumLengthMillionths) / 1_000_000}× typical length and ${Number(rule.minimumWeightMillionths) / 1_000_000}× typical weight`; };
  const time = (micros: bigint) => new Date(Number(micros / 1000n)).toLocaleString();
  const linkUrl = (env.PUBLIC_ACCOUNT_LINK_URL ?? '').replace(/\/$/, '') + '/auth/discord/start';
  function select(id: bigint) { selected = selected.includes(id) ? selected.filter(value => value !== id) : [...selected, id]; showQuote = false; }
  function quickSelect(rank = '') {
    selected = selectable.filter(fish => !rank || fish.rarity === rank).map(fish => fish.catchId);
    showQuote = false;
  }
  function openView(view: CampView) {
    activeView = view;
    menuOpen = false;
    if (window.location.hash !== '#' + view) window.history.pushState(null, '', '#' + view);
    window.scrollTo(0, 0);
  }
  function readView() {
    menuOpen = false;
    const value = window.location.hash.slice(1);
    activeView = tabs.some(tab => tab.id === value) ? value as CampView : initialView;
  }
  function closeMenu() {
    menuOpen = false;
    menuButton?.focus();
  }
  function chooseMobileView(view: CampView) {
    openView(view);
    menuButton?.focus();
  }
  function tabKey(event: KeyboardEvent, id: CampView) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const available = tabs.filter(tab => !tab.personal || game.player);
    const index = available.findIndex(tab => tab.id === id);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? available.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + available.length) % available.length;
    openView(available[next].id);
    document.getElementById('tab-' + available[next].id)?.focus();
  }
  async function link() {
    await game.prepareLink();
    await tick();
    if (game.challenge && !game.challenge.consumed && !game.error) linkForm.requestSubmit();
  }
  async function preview() { await game.previewSale(saleBatch); showQuote = !game.error; }
  async function confirm() {
    if (!quote) return;
    const soldIds = quote.catchIds;
    await game.confirmSale(quote.nonce);
    if (!game.error) { selected = selected.filter(id => !soldIds.includes(id)); showQuote = false; }
  }
  onMount(() => {
    const stopDiagnostics = connectionDiagnostics.initialize();
    const compactScreen = window.matchMedia('(max-width: 600px)');
    const resizePages = () => {
      menuOpen = false;
      pageSize = compactScreen.matches ? 4 : 12;
      bookPage = worldPage = 1;
    };
    resizePages();
    compactScreen.addEventListener('change', resizePages);
    readView();
    window.addEventListener('popstate', readView);
    window.addEventListener('hashchange', readView);
    game.start(env.PUBLIC_SPACETIMEDB_URI ?? '', env.PUBLIC_SPACETIMEDB_DATABASE ?? '');
    const timer = setInterval(() => { now = Date.now(); }, 1000);
    return () => { stopDiagnostics(); clearInterval(timer); game.stop(); compactScreen.removeEventListener('change', resizePages); window.removeEventListener('popstate', readView); window.removeEventListener('hashchange', readView); };
  });
</script>

<svelte:head><title>Fishbound · {viewLabel}</title><meta name="description" content="Your fishing camp, collection, records, and public fish compendium." /></svelte:head>
<svelte:window onkeydown={event => { if (event.key === 'Escape' && menuOpen) { event.preventDefault(); closeMenu(); } }} />

<main>
  <nav aria-label="Main navigation">
    <a class="brand desktop-brand" href="#camp" onclick={event => { event.preventDefault(); openView('camp'); }}><img src="/fish/koi.png" alt="" />Fishbound</a>
    <button class="mobile-menu-toggle" type="button" bind:this={menuButton} aria-label={menuOpen ? 'Close navigation' : 'Open navigation'} aria-expanded={menuOpen} aria-controls="mobile-navigation" onclick={() => menuOpen = !menuOpen}>
      <img src="/fish/koi.png" alt="" /><span>Fishbound</span>
      <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">{#if menuOpen}<path d="m6 6 12 12M6 18 18 6" />{:else}<path d="M3 6h18M3 12h18M3 18h18" />{/if}</svg>
    </button>
    <span class:live={game.ready} class="connection"><span></span>{game.status}</span>
    <div class="mobile-navigation" id="mobile-navigation" hidden={!menuOpen}>
      {#each tabs as tab}
        <button type="button" aria-current={visibleView === tab.id ? 'page' : undefined} disabled={!!tab.personal && !game.player} title={tab.personal && !game.player ? 'Link Discord to open this view' : undefined} onclick={() => chooseMobileView(tab.id)}>{tab.label}</button>
      {/each}
    </div>
  </nav>
  <header class="camp-banner" class:compact-banner={visibleView !== 'camp'}>
    <div class="camp-intro"><p class="eyebrow">{currentBiome?.name ?? 'SEVEN WATERS, ONE JOURNEY'}</p><h1>{visibleView === 'camp' ? 'Fishbound' : viewLabel}</h1>{#if visibleView === 'camp'}<p class="intro">Your next keeper is out there.</p><p class="cast-hint">Cast with <code>/fish</code> in Discord <span>·</span> One cast every minute</p><a class="book-link" href="#compendium" onclick={event => { event.preventDefault(); openView('compendium'); }}>Open the fish compendium <span aria-hidden="true">→</span></a>{/if}</div>
    <PondScene compact={visibleView !== 'camp'} />
  </header>

  {#if game.player}
    <section class="stats" aria-label="Your progress">
      <div><AnglerLevel totalXp={game.player.totalXp} levelCap={game.config?.levelCap ?? world.levelCap} rulesVersion={game.config?.version ?? 9} /></div>
      <div><span>◈ COIN POUCH</span><strong>{game.player.coins.toString()}</strong><small>For your next adventure</small></div>
      <div><span>▤ COLLECTION BOOK</span><strong>{discoveries}<em> / {ordinary.length}</em></strong><progress value={discoveries} max={ordinary.length || 249} aria-label="Ordinary species discovered"></progress><small>Two bonus discoveries await</small></div>
      <div><span>⌛ NEXT CAST</span><strong>{remaining ? remaining + 's' : 'Ready!'}</strong><small>Use /fish in Discord</small></div>
    </section>
  {/if}

  <div class="camp-tabs" role="tablist" aria-label="Fishing camp views">
    {#each tabs as tab}<button id={'tab-' + tab.id} role="tab" aria-selected={visibleView === tab.id} aria-controls={tab.id === 'compendium' ? 'panel-compendium' : 'camp-panel'} tabindex={visibleView === tab.id ? 0 : -1} disabled={!!tab.personal && !game.player} title={tab.personal && !game.player ? 'Link Discord to open this view' : undefined} onclick={() => openView(tab.id)} onkeydown={event => tabKey(event, tab.id)}>{tab.label}</button>{/each}
  </div>

  {#if game.error}<p class="notice error" role="alert">{game.error}</p>{/if}
  {#if game.reconnecting}<p class="notice" role="status">Reconnecting… Your last synced progress is still shown. Actions are paused until live data returns.</p>{/if}
  {#if game.sectionLoading && !game.reconnecting}<p class="notice" role="status">Loading this view…</p>{/if}
  {#if !game.ready && !game.reconnecting && !game.sectionLoading && env.PUBLIC_SPACETIMEDB_DATABASE}<button class="secondary" onclick={() => game.reconnect()}>Reconnect</button>{/if}

  <div class="view-panel" role="tabpanel" id="camp-panel" aria-labelledby={'tab-' + visibleView} tabindex="0" hidden={visibleView === 'compendium'}>
  {#if visibleView === 'camp'}
    <section class="welcome" aria-labelledby="welcome"><div><p class="eyebrow">A FRESH PAGE IN YOUR FISHING JOURNAL</p><h2 id="welcome">{game.player ? 'Back at the pond.' : 'Grab a rod. Make a little history.'}</h2>{#if game.player}<p>Your {currentRod?.name ?? 'rod'} is ready for {currentBiome?.name ?? 'the pond'}. Open your tackle box to manage your catches, or turn a page in your collection book.</p>{:else}<p>Use <code>/fish</code> in Discord, then link your account to open your tackle box, collection book, and personal records.</p>{/if}<p class="muted">251 species. Seven waters. Start with the 28 fish waiting in Meadow Pond.</p></div>
      <div class="login">{#if game.player}<button onclick={() => openView('inventory')}>Open tackle box →</button><button class="secondary" onclick={() => openView('collection')}>Open collection book →</button>{:else}<button disabled={!game.ready || game.busy || !env.PUBLIC_ACCOUNT_LINK_URL} onclick={link}>{game.busy ? 'Preparing…' : 'Link Discord account →'}</button><small>{!env.PUBLIC_ACCOUNT_LINK_URL ? 'Discord sign-in will be available soon.' : game.ready ? 'One account, shared between Discord and this browser.' : 'Account linking becomes available when the pond is connected.'}</small>{/if}</div>
    </section>
    <TraderStall onclick={() => openView('trader')} />
  {/if}
  {#if visibleView === 'trader'}<Trader {game} {pageSize} />{/if}
  {#if visibleView === 'quests' && game.player}<Quests {game} />{/if}
  {#if game.player}
    {#if visibleView === 'inventory'}
    <section aria-labelledby="inventory"><div class="section-heading"><div><p class="eyebrow">KEEP THE GOOD ONES</p><h2 id="inventory">Your tackle box <span>{game.inventory.length} fish kept</span></h2></div><button class="secondary" disabled={!selectedOwned.length || game.busy || !game.ready} onclick={preview}>Preview sale ({saleBatch.length}{selectedOwned.length > saleBatchSize ? ' of ' + selectedOwned.length : ''})</button></div>
      {#if game.inventory.length}
        <div class="quick-select" aria-label="Quick fish selection">
          <label for="selection-rank">Quick select by rank<select id="selection-rank" bind:value={selectionRank} disabled={game.busy || !game.ready}><option value="">Choose a rank…</option>{#each ranks as rank}<option value={rank}>{rank} ({selectable.filter(fish => fish.rarity === rank).length})</option>{/each}</select></label>
          <button class="secondary" disabled={!selectionRank || !selectable.some(fish => fish.rarity === selectionRank) || game.busy || !game.ready} onclick={() => quickSelect(selectionRank)}>Select rank</button>
          <button class="secondary" disabled={!selectable.length || game.busy || !game.ready} onclick={() => { selectionRank = ''; quickSelect(); }}>Select all</button>
          <button class="secondary" disabled={!selected.length || game.busy || !game.ready} onclick={() => { selectionRank = ''; selected = []; showQuote = false; }}>Clear selection</button>
          <p class="muted" aria-live="polite">{selectedOwned.length} selected across all pages. Favorites stay protected.{#if selectedOwned.length > saleBatchSize} Preview and sell up to {saleBatchSize} at a time; the rest stay selected.{/if}</p>
        </div>
      {/if}
      {#if quote}
        <div class="sale" role="status"><div><strong>Sell {quote.catchIds.length} selected {quote.catchIds.length === 1 ? 'catch' : 'catches'} for {quote.quotedCoins.toString()} coins?</strong><p>{quote.catchIds.map(id => { const fish = game.inventory.find(row => row.catchId === id); return '#' + id + ' ' + (fish ? speciesName(fish.speciesId) : '(catch changed)'); }).join(' · ')}</p><small>Discoveries and records remain. Favorites are protected. Preview expires at {time(quote.expiresAt.microsSinceUnixEpoch)}.</small></div><div class="sale-actions"><button disabled={game.busy || !game.ready || quote.expiresAt.microsSinceUnixEpoch <= BigInt(now) * 1000n} onclick={confirm}>Confirm sale · {quote.quotedCoins.toString()} coins</button><button class="secondary" onclick={() => { showQuote = false; }}>Cancel</button></div></div>
      {/if}
      <Gear {game} />
      <TackleBoxItems items={game.items} />
      {#if !game.inventory.length}<p class="empty">Your next catch could be the keeper. Try <code>/fish</code> in Discord.</p>{:else}<div class="catch-grid">
        {#each game.inventory.slice((inventoryPage - 1) * inventoryPageSize, inventoryPage * inventoryPageSize) as fish (fish.catchId)}
          <article class="catch" class:favorite={fish.favorite}><div class="catch-tools"><label><input type="checkbox" checked={selected.includes(fish.catchId)} disabled={fish.favorite || game.busy || !game.ready} onchange={() => select(fish.catchId)} /><span class="sr-only">Select catch #{fish.catchId.toString()}</span></label><button class="star" class:active={fish.favorite} disabled={game.busy || !game.ready} onclick={() => { showQuote = false; game.favorite(fish); }} aria-label={(fish.favorite ? 'Unfavorite' : 'Favorite') + ' catch #' + fish.catchId}>{fish.favorite ? '★' : '☆'}</button></div><div class="fish-art"><img src={sprite(fish.speciesId)} alt={speciesName(fish.speciesId)} /><span>{fish.rarity} RANK</span></div><p class="grade">{grades[fish.sizeGrade]}</p><h3>{speciesName(fish.speciesId)}</h3><p class="measure">{formatLength(fish.lengthMm)} <span>·</span> {formatWeight(fish.weightG)}</p><p class="relative-size">{(fish.lengthMm / (game.species.find(row => row.speciesId === fish.speciesId)?.typicalLengthMm ?? fish.lengthMm)).toFixed(2)}× typical length<br />{(Number(fish.weightG) / Number(game.species.find(row => row.speciesId === fish.speciesId)?.typicalWeightG ?? fish.weightG)).toFixed(2)}× typical weight</p><div class="catch-footer"><span>#{fish.catchId.toString()}</span><span>{fish.saleValueCoins.toString()} coins</span></div></article>
        {/each}
      </div>{/if}
      <PageTurner bind:page={inventoryPage} total={game.inventory.length} pageSize={inventoryPageSize} label="Tackle box pages" />
    </section>

    {/if}
    {#if visibleView === 'collection'}
    <section aria-labelledby="collection"><div class="section-heading"><div><p class="eyebrow">EVERY DISCOVERY COUNTS</p><h2 id="collection">Collection book</h2></div><span class="muted">{discoveries} of {ordinary.length} ordinary species discovered · ✦ {perfected.size} UUR perfected · {maxima.count} rank maxima · {maxima.fullyMaxedSpecies} fully maxed species</span></div>
      <div class="filters"><div><label for="book-biome">Biome</label><select id="book-biome" bind:value={bookBiome} onchange={() => bookPage = 1}><option value={0}>All waters</option>{#each game.biomes as biome}<option value={biome.biomeId}>{biome.name}</option>{/each}</select></div><div><label for="book-rank">Rank</label><select id="book-rank" bind:value={bookTier} onchange={() => bookPage = 1}><option value="">All ranks</option>{#each ['F','D','C','B','A','S','SS','SSS','UR','UUR'] as tier}<option value={tier}>{tier}</option>{/each}</select></div><div><label for="book-search">Find a fish</label><input id="book-search" type="search" placeholder="Name…" bind:value={bookQuery} oninput={() => bookPage = 1} /></div></div>
      <p class="muted">Showing {book.length} species. Ordinary ranks come from length and weight relative to each species; both must meet the rank minimum. Fihs and Nidalees Lost Sock are bonus discoveries. ✓ means caught; ✦ means one catch reached both exact maxima for that rank. Marks remain after selling.</p>{#if bookTier}<p class="muted">{bookTier}: {rankMinimums(bookTier)}.</p>{/if}<div class="collection-grid">
      {#each book.slice((bookPage - 1) * pageSize, bookPage * pageSize) as fish (fish.speciesId)}{@const discovery = game.collection.find(row => row.speciesId === fish.speciesId)}{@const caughtAtRank = !!discovery && (!bookTier || discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(bookTier)] > 0n)}<article class="collection-entry" class:undiscovered={!caughtAtRank}><img src={fish.spriteAsset} alt={fish.name} loading="lazy" /><div><small>{bookTier ? bookTier + ' RANK' : fish.allowedRarities.length === 10 ? 'F THROUGH UUR' : fish.allowedRarities[0] + ' ONLY'}{fish.countsForOrdinaryCollectionCompletion ? '' : ' · BONUS'}</small><h3>{fish.name}{#if bookTier ? maxima.marks.has(`${fish.speciesId}:${bookTier}`) : perfected.has(fish.speciesId)} <span class="perfect-mark" aria-label={bookTier ? bookTier + ' rank maximum' : 'UUR perfected species'} title="One catch at both exact maximum measurements">✦</span>{/if}</h3><p>{game.biomes.find(b => b.biomeId === fish.biomeId)?.name}</p>{#if discovery}<p>{bookTier ? discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(bookTier)].toString() + ' caught at ' + bookTier : discovery.count.toString() + ' caught across ranks'} · best across ranks {formatLength(discovery.bestLengthMm)} / {formatWeight(discovery.bestWeightG)}</p>{:else}<p>Waiting for your first discovery</p>{/if}<p class="maxima-count">✦ {fish.allowedRarities.filter(rank => maxima.marks.has(`${fish.speciesId}:${rank}`)).length} / {fish.allowedRarities.length} rank maxima</p><div class="rank-progress" aria-label="Ranks caught and maxima">{#each fish.allowedRarities as rank}<span class:maxed={maxima.marks.has(`${fish.speciesId}:${rank}`)} title={maxima.marks.has(`${fish.speciesId}:${rank}`) ? rank + ' rank maximum: one catch at both exact maximum measurements' : rankMinimums(rank)} class:earned={!!discovery && discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(rank)] > 0n}>{rank}{maxima.marks.has(`${fish.speciesId}:${rank}`) ? ' ✦' : discovery && discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(rank)] > 0n ? ' ✓' : ' ○'}</span>{/each}</div></div><span class="discovered" aria-label={caughtAtRank ? 'Discovered at selected rank' : 'Undiscovered at selected rank'}>{caughtAtRank ? '✓' : '○'}</span></article>{/each}
      {#if !book.length}<p class="muted">No fish match these filters.</p>{/if}
    </div><PageTurner bind:page={bookPage} total={book.length} {pageSize} label="Collection pages" /></section>

    {/if}
    {#if visibleView === 'journal'}
    <Journal {game} {pageSize} />
    {/if}
  {/if}

  {#if visibleView === 'waters' && game.biomes.length}
    <section aria-labelledby="waters"><p class="eyebrow">SEVEN WATERS, ONE JOURNEY</p><h2 id="waters">Explore the biomes</h2>
      {#if game.player}<p class="muted">Equipped: {currentRod?.name} · power {game.rodStats(currentRod?.rodId ?? 0).power}. Buy rods and permanent biome licences from the camp trader. Travel keeps your cast cooldown.</p>
        <div class="filters"><label>Equip an owned rod<select value={game.player.equippedRodId} disabled={game.busy || !game.ready} onchange={event => game.changeLoadout(undefined, Number(event.currentTarget.value))}>{#each game.rods as rod}<option value={rod.rodId} disabled={!game.ownedRods.some(owned => owned.rodId === rod.rodId) || rod.minimumLevel > level}>{rod.name} · level {rod.minimumLevel} · power {rod.power}</option>{/each}</select></label></div>
      {/if}
      <div class="biome-grid">{#each game.biomes.slice((worldPage - 1) * pageSize, worldPage * pageSize) as biome (biome.biomeId)}
        {@const pool = game.species.filter(fish => fish.biomeId === biome.biomeId)}
        {@const rod = game.rods.find(row => row.minimumLevel <= level && game.ownedRods.some(owned => owned.rodId === row.rodId))}
        {@const licensed = biome.biomeId === 1 || game.licences.some(row => row.biomeId === biome.biomeId)}
        {@const active = currentBiome?.biomeId === biome.biomeId}
        <article class:active><small>LEVEL {biome.minimumLevel} · LICENCE</small><h3>{biome.name}</h3><p>{biome.description}</p><p>{pool.length} species · {biome.fishWeight.toString()}% fish / {biome.junkWeight.toString()}% junk / {biome.treasureWeight.toString()}% treasure</p>
          <button class="secondary" disabled={!game.player || game.busy || !game.ready || level < biome.minimumLevel || !licensed || !rod || active} onclick={() => game.changeLoadout(biome.biomeId)}>{active ? 'Current waters' : level < biome.minimumLevel ? 'Unlock at level ' + biome.minimumLevel : !licensed ? 'Licence needed' : !rod ? 'Rod unavailable' : 'Travel'}</button>
          {#if game.player && (!licensed || !rod)}<button class="secondary" onclick={() => openView('trader')}>Visit camp trader →</button>{/if}
        </article>
      {/each}</div><PageTurner bind:page={worldPage} total={game.biomes.length} {pageSize} label="World map pages" />
    </section>
  {/if}
  <div hidden={visibleView !== 'records'}><Records {game} {pageSize} /></div>
  {#if game.player}<div hidden={visibleView !== 'achievements'}><Achievements {game} {pageSize} /></div>{/if}
  <div hidden={visibleView !== 'anglers'}><Anglers {game} {pageSize} /></div>

  </div>
  <div class="view-panel" role="tabpanel" id="panel-compendium" aria-labelledby="tab-compendium" tabindex="0" hidden={visibleView !== 'compendium'}><Compendium {game} active={visibleView === 'compendium'} /></div>

  <footer><span>Fishbound · One cast at a time.</span><div>{#if game.lastSync}<span>Updated {game.lastSync.toLocaleTimeString()}</span>{/if}{#if game.player}<button class="text-button" disabled={game.busy || !game.ready} onclick={() => game.logout()}>Unlink this browser</button>{/if}<ThemeToggle /></div></footer>
  <form method="POST" action={linkUrl} bind:this={linkForm} hidden><input name="challenge_id" value={game.challenge?.challengeId.toString() ?? ''} /><input name="proof" value={game.challenge?.proof.toString() ?? ''} /></form>
</main>

<style>
  main{max-width:1760px;margin:auto;padding:32px 40px 0}
  nav{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 18px;background:repeating-linear-gradient(0deg,#ffffff04 0 2px,transparent 2px 8px),var(--wood);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px var(--paper-edge),0 5px 0 var(--site-base)}
  nav a{text-decoration:none;font-family:var(--font-game);font-size:21px;padding:8px 12px;color:var(--site-text);white-space:nowrap}
  nav a:hover{background:var(--nav-hover);color:var(--site-text)}
  .mobile-menu-toggle,.mobile-navigation{display:none}
  .brand{display:flex;align-items:center;gap:8px;font-size:31px;margin-right:auto;padding:0!important}
  .brand img{width:46px;height:36px;object-fit:contain;image-rendering:pixelated}
  .connection{display:flex;align-items:center;gap:7px;font-size:14px;color:var(--site-text);padding:5px 8px;background:var(--site-panel);border:1px solid var(--site-border)}
  .connection>span{width:7px;height:7px;background:#9c8e75}.connection.live>span{background:#a8d29a}
  .camp-banner{display:grid;grid-template-columns:1fr 1.1fr;gap:36px;align-items:stretch;margin:28px 0 32px;padding:30px;background:var(--site-panel);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 1px var(--site-border),0 6px 0 var(--site-base)}
  .camp-intro{padding:14px 8px;align-self:center;min-width:0}
  .eyebrow{font-family:var(--font-game);font-size:17px;letter-spacing:.06em;color:var(--ink);margin:0 0 8px}
  .camp-banner .eyebrow{color:var(--site-muted)}
  h1{font-family:var(--font-game);font-size:clamp(48px,4.4vw,72px);font-weight:700;line-height:1;margin:12px 0;color:var(--gold);text-shadow:3px 4px 0 var(--site-base)}
  .intro{font-size:21px;color:var(--site-text);margin:14px 0}
  .cast-hint{font-size:16px;color:var(--site-muted)}.cast-hint span{margin:0 6px}
  .cast-hint code{color:var(--site-text);background:var(--site-base);padding:3px 7px;border:1px solid var(--site-border)}
  .book-link{display:inline-flex;gap:20px;align-items:center;padding:10px 16px;margin-top:10px;background:var(--gold);color:var(--button-ink);border:2px solid var(--wood-dark);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 4px 0 var(--site-base);text-decoration:none;font-family:var(--font-game);font-size:21px}
  .book-link:hover{background:var(--button-hover);transform:translateY(-1px)}
  section{padding:32px;margin-top:28px;color:var(--ink);background:var(--panel-texture),var(--paper);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 6px 0 var(--wood-dark);scroll-margin-top:24px;position:relative}
  section::before,section::after{content:'';position:absolute;top:8px;width:5px;height:5px;background:var(--paper-edge);box-shadow:0 1px 0 var(--panel-highlight)}section::before{left:8px}section::after{right:8px}
  h2{font-family:var(--font-game);font-weight:500;font-size:36px;line-height:1.2;margin:0 0 18px;color:var(--ink)}
  h3{font-family:var(--font-game);font-size:26px;font-weight:500;margin:8px 0;line-height:1.2}
  h2>span{font-family:var(--font-body);font-size:16px;color:var(--ink-muted);margin-left:12px;white-space:nowrap}
  .muted{color:var(--ink-muted);font-size:16px;line-height:1.7}
  code{color:var(--ink);font-size:16px;font-weight:700}
  button{border:2px solid var(--wood-dark);padding:10px 16px;background:var(--gold);color:var(--button-ink);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 3px 0 var(--wood-dark);font-family:var(--font-game);font-size:20px;font-weight:500;line-height:1.3;transition:transform .12s,background .12s}
  button:hover:not(:disabled){background:var(--button-hover);transform:translateY(-1px)}button:active:not(:disabled){transform:translateY(2px);box-shadow:inset 0 0 0 2px var(--panel-highlight)}
  button:disabled{opacity:.55;box-shadow:inset 0 0 0 2px var(--panel-highlight);filter:saturate(.6)}
  .secondary{color:var(--ink);background:var(--panel-inset);border-color:var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 3px 0 var(--wood-dark)}
  .secondary:hover:not(:disabled){background:var(--paper-light)}
  .welcome{display:grid;grid-template-columns:1.7fr 1fr;gap:32px;padding:36px}.welcome p{font-size:17px;line-height:1.7}.welcome h2{margin-bottom:12px}
  .login{display:flex;flex-direction:column;justify-content:center;gap:16px;max-width:380px}.login small{font-size:16px;line-height:1.6;color:var(--ink-muted)}
  .notice{padding:16px;border:3px solid #835a41;background:var(--panel-inset);color:var(--ink);font-size:17px}
  .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;padding:0;background:var(--wood);color:var(--site-text);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 5px 0 var(--site-base)}.stats::before,.stats::after{display:none}
  .stats>div{padding:20px 24px;border-right:2px solid var(--paper-edge);min-width:0}.stats>div:last-child{border:0}
  .stats span{font-family:var(--font-game);font-size:19px;color:var(--site-text)}.stats strong{font-family:var(--font-game);font-weight:500;font-size:42px;display:block;margin:8px 0;line-height:1.15;text-shadow:2px 2px 0 var(--site-base);overflow-wrap:anywhere}.stats em{font-size:26px;font-style:normal;color:var(--site-muted)}.stats small{font-size:14px;color:var(--site-text);display:block}
  progress{display:block;width:100%;height:8px;accent-color:var(--gold);border:1px solid var(--site-base);margin:8px 0;background:var(--site-base)}progress::-webkit-progress-bar{background:var(--site-base)}progress::-webkit-progress-value{background:var(--gold)}
  .section-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.section-heading h2{margin-bottom:0}
  .filters{display:flex;flex-wrap:wrap;gap:16px;margin:20px 0}.filters>div,.filters label{display:flex;flex-direction:column;gap:6px;font-size:16px;color:var(--ink);min-width:0}.filters label{font-weight:700}
  .filters select,.filters input{max-width:100%}
  .catch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px}
  .quick-select{display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;padding:16px;margin-bottom:20px;border:1px dashed var(--paper-edge);background:var(--panel-inset)}
  .quick-select label{display:flex;flex-direction:column;gap:6px;font-size:16px;font-weight:700;max-width:100%}
  .quick-select select{max-width:100%}
  .quick-select p{flex-basis:100%;margin:0}
  .catch{border:2px solid var(--paper-edge);padding:18px;background:var(--paper-light);box-shadow:inset 0 0 0 2px var(--panel-highlight),3px 4px 0 var(--panel-highlight)}
  .catch.favorite{border-color:var(--gold);box-shadow:inset 0 0 0 2px var(--panel-highlight),3px 4px 0 var(--wood-dark)}
  .catch-tools{display:flex;justify-content:space-between;align-items:center;height:28px}
  .star{background:transparent;border:0;color:var(--ink-muted);padding:0;font-family:var(--font-body);font-size:30px;line-height:1;box-shadow:none}.star:hover:not(:disabled){background:transparent}.star.active{color:var(--special-ink)}
  .fish-art{height:155px;display:flex;align-items:center;justify-content:center;position:relative;margin-top:12px;border:2px solid var(--paper-edge);background:var(--art-background);box-shadow:inset 0 0 0 3px var(--panel-highlight)}
  .fish-art img{width:115px;height:100px;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(3px 4px 0 var(--art-shadow))}
  .fish-art>span{position:absolute;right:5px;bottom:5px;border:1px solid var(--art-edge);color:var(--art-ink);background:var(--art-background);padding:2px 6px;font-family:var(--font-game);font-size:17px}
  .grade{color:var(--ink);font-size:14px;margin:12px 0 3px;font-weight:700}.measure{font-size:16px;color:var(--ink)}.measure span{margin:0 5px;color:var(--ink-muted)}.relative-size{font-size:14px;color:var(--ink);line-height:1.6}
  .catch-footer{border-top:1px dashed var(--paper-edge);display:flex;justify-content:space-between;padding-top:10px;margin-top:16px;font-size:14px;color:var(--ink);flex-wrap:wrap;gap:6px 12px}.catch-footer span:last-child{color:var(--ink);font-weight:700}
  .empty{background:var(--panel-inset);border:2px dashed var(--paper-edge);padding:24px;color:var(--ink);font-size:17px}
  .sale{border:2px solid var(--paper-edge);padding:22px;margin-bottom:20px;background:var(--paper-light);box-shadow:inset 0 0 0 2px var(--panel-highlight)}.sale strong{font-size:19px;color:var(--ink)}.sale p,.sale small{font-size:16px;color:var(--ink);line-height:1.7}.sale-actions{display:flex;gap:12px;margin-top:18px}
  .perfect-mark{color:var(--ink);text-shadow:none}
  .collection-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.collection-entry{display:flex;align-items:center;gap:16px;border:1px solid var(--paper-edge);padding:20px;background:var(--paper-light);box-shadow:2px 3px 0 var(--panel-highlight)}.collection-entry>div{min-width:0;flex:1}
  .collection-entry>img{height:62px;width:70px;object-fit:contain;image-rendering:pixelated}.collection-entry small{font-family:var(--font-game);font-size:16px;color:var(--ink)}.collection-entry h3{margin:5px 0;font-size:24px;overflow-wrap:anywhere}.collection-entry p{font-size:14px;color:var(--ink);margin:4px 0}
  .discovered{margin-left:auto;color:var(--success-ink);font-weight:700}.undiscovered>img{filter:grayscale(1);opacity:.4}.undiscovered h3{color:var(--ink)}.undiscovered .discovered{color:var(--ink-muted)}
  .rank-progress{display:flex;flex-wrap:wrap;gap:4px;margin-top:10px}.rank-progress span{font-family:var(--font-game);font-size:16px;color:var(--ink-muted);border:1px solid var(--paper-edge);padding:2px 4px;background:var(--paper-light)}.rank-progress span.earned{color:var(--selected-ink);border-color:var(--control-accent);background:var(--selected-background)}.rank-progress span.maxed{color:var(--ink);border-color:var(--special-ink);background:var(--paper-light);box-shadow:inset 0 0 0 1px var(--panel-highlight)}.maxima-count{color:var(--ink);font-weight:bold}
  .biome-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:22px}.biome-grid article{padding:20px;border:2px solid var(--paper-edge);background:var(--panel-inset);box-shadow:3px 4px 0 var(--wood-dark);position:relative;overflow:hidden}
  .biome-grid article::before{content:'';display:block;height:36px;margin:-20px -20px 18px;background:linear-gradient(155deg,transparent 35%,#456f58 36% 65%,transparent 66%),linear-gradient(30deg,#365c52 45%,#7b9865 46% 68%,#e8be81 69%);border-bottom:2px solid #8b7853}
  .biome-grid article:nth-child(3n)::before{filter:hue-rotate(25deg)}.biome-grid article:nth-child(4)::before{filter:hue-rotate(90deg)}.biome-grid article:nth-child(6)::before{filter:hue-rotate(140deg) saturate(.6)}.biome-grid article:nth-child(7)::before{filter:brightness(.7)}
  .biome-grid article.active{border-color:var(--control-accent);box-shadow:inset 0 0 0 2px var(--panel-highlight),3px 4px 0 var(--wood-dark)}.biome-grid small{font-family:var(--font-game);font-size:17px;color:var(--ink)}.biome-grid p{font-size:16px;color:var(--ink);line-height:1.7}.biome-grid button{margin-top:8px}
  footer{border-top:1px solid var(--paper-edge);margin-top:38px;padding:24px 0 32px;display:flex;justify-content:space-between;gap:16px;color:var(--site-muted);font-size:14px}footer>div{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center}.text-button{color:var(--site-text);background:transparent;border:0;font-family:var(--font-body);font-size:14px;padding:0;box-shadow:none}.text-button:hover:not(:disabled){background:transparent}
  .sr-only{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}
  @media(max-width:850px){main{padding:16px 18px 0}nav{gap:5px;padding:10px}.brand{font-size:26px}nav a{font-size:18px;padding:6px 8px}.connection{margin-left:auto}.camp-banner{gap:20px;padding:18px;grid-template-columns:1fr 1fr}.camp-intro{padding:0}h1{font-size:clamp(34px,5vw,44px)}.intro{font-size:18px}.cast-hint span{display:none}.book-link{font-size:18px;gap:10px}section{padding:22px}.collection-grid{grid-template-columns:1fr}.stats>div{padding:18px 16px}.stats span{font-size:16px}}
  @media(max-width:600px){main{padding:12px 12px 0}nav{gap:2px}.brand{width:100%;margin:0 0 4px;font-size:26px}nav a{font-size:17px;padding:6px 7px}.connection{font-size:12px;padding:4px 6px}.camp-banner{grid-template-columns:1fr;padding:16px;margin:22px 0;gap:20px}.camp-intro{padding:8px}h1{font-size:clamp(28px,9vw,38px)}.cast-hint{font-size:14px}.welcome{grid-template-columns:1fr;gap:20px;padding:22px}.login{max-width:none}.stats{grid-template-columns:1fr 1fr}.stats>div:nth-child(2){border-right:0}.stats>div:nth-child(-n+2){border-bottom:2px solid var(--paper-edge)}.stats>div{padding:18px}.stats strong{font-size:clamp(18px,5.5vw,24px);overflow-wrap:anywhere}.section-heading{align-items:flex-start;flex-wrap:wrap}section{padding:20px;margin-top:22px}h2{font-size:30px}.catch-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.catch{padding:10px}.catch h3{font-size:20px;overflow-wrap:anywhere}.fish-art{height:105px}.fish-art img{width:95px;height:80px}.fish-art>span{font-size:14px}.measure{font-size:12px}.relative-size{font-size:12px}.catch-footer{font-size:12px}.collection-entry{padding:12px;gap:10px}.collection-entry>img{width:50px;height:50px}.sale-actions{flex-wrap:wrap}footer{flex-direction:column}footer>div{justify-content:space-between}.filters{gap:12px}.filters>div{flex:1;min-width:110px}.filters>div:last-child{flex-basis:100%}.filters input{width:100%}}

  .compact-banner{padding:20px 30px;margin:20px 0;grid-template-columns:minmax(0,1fr) clamp(280px,30vw,360px);gap:20px}
  .compact-banner .camp-intro{padding:0}.compact-banner .eyebrow{margin-bottom:4px}.compact-banner h1{font-size:clamp(30px,3.2vw,46px);margin:0;line-height:1.1;overflow-wrap:anywhere}
  .camp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:24px 0 0;padding:10px;background:var(--wood-dark);border:2px solid var(--paper-edge);box-shadow:0 4px 0 var(--site-base);position:sticky;top:8px;z-index:5}
  .camp-tabs button{padding:10px 16px;font-size:21px;background:var(--wood);color:var(--site-text);border-color:var(--paper-edge);box-shadow:none}
  .camp-tabs button:hover:not(:disabled):not([aria-selected=true]){background:var(--nav-hover)}
  .camp-tabs button[aria-selected=true]{background:var(--paper);color:var(--ink);border-color:var(--paper-edge);box-shadow:inset 0 -3px 0 var(--panel-highlight)}
  .camp-tabs button:disabled{opacity:.45;filter:none;box-shadow:none}
  .view-panel{margin-top:18px}.view-panel>section{margin-top:0}
  @media(max-width:600px){
    nav{gap:8px}
    .desktop-brand{display:none}
    .mobile-menu-toggle{display:flex;align-items:center;gap:8px;flex:1;min-width:0;min-height:44px;padding:0;background:transparent;color:var(--site-text);border:0;box-shadow:none;font-size:20px;text-align:left}
    .mobile-menu-toggle:hover:not(:disabled){background:transparent;transform:none}
    .mobile-menu-toggle img{width:28px;height:28px;object-fit:contain;image-rendering:pixelated}
    .mobile-menu-toggle svg{width:22px;height:22px;flex:none;margin-left:auto}
    .mobile-menu-toggle span{overflow-wrap:anywhere}
    .connection{margin-left:0;font-size:11px}
    .mobile-navigation:not([hidden]){display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;flex-basis:100%;padding-top:10px;border-top:1px solid var(--paper-edge)}
    .mobile-navigation button{min-height:44px;padding:10px 8px;font-size:16px;overflow-wrap:anywhere;background:var(--wood-dark);color:var(--site-text);border-color:var(--paper-edge);box-shadow:none}
    .mobile-navigation button:hover:not(:disabled){background:var(--nav-hover);transform:none}
    .mobile-navigation button[aria-current=page]{background:var(--paper);color:var(--ink)}
    .mobile-navigation button:disabled{opacity:.45}
    .compact-banner{grid-template-columns:1fr;padding:16px}.compact-banner h1{font-size:30px}
    .camp-tabs{display:none}.view-panel{margin-top:14px}
  }
</style>
