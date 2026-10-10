<script lang="ts">
  import { formatLength, formatWeight } from './measurements';
  import { onMount, tick, untrack } from 'svelte';
  import * as env from '$app/env/public';
  import { Game } from './game.svelte';
  import PondScene from './PondScene.svelte';
  import Compendium from './Compendium.svelte';
  import Records from './Records.svelte';
  import Trader from './Trader.svelte';
  import TraderStall from './TraderStall.svelte';
  import PageTurner from './PageTurner.svelte';
  import TackleBoxItems from './TackleBoxItems.svelte';
  import Gear from './Gear.svelte';
  import Achievements from './Achievements.svelte';
  import Anglers from './Anglers.svelte';
  import AnglerLevel from './AnglerLevel.svelte';
  import Journal from './Journal.svelte';
  type CampView = 'camp' | 'inventory' | 'collection' | 'journal' | 'waters' | 'records' | 'compendium' | 'trader' | 'achievements' | 'anglers';
  let { initialView = 'camp' } = $props<{ initialView?: CampView }>();
  const tabs: { id: CampView; label: string; personal?: boolean }[] = [
    { id: 'camp', label: 'Camp' }, { id: 'trader', label: 'Trader' }, { id: 'inventory', label: 'Tackle box', personal: true },
    { id: 'collection', label: 'Collection', personal: true }, { id: 'journal', label: 'Journal', personal: true },
    { id: 'waters', label: 'World map' }, { id: 'records', label: 'Records' }, { id: 'compendium', label: 'Compendium' },
    { id: 'achievements', label: 'Achievements', personal: true }, { id: 'anglers', label: 'Anglers' },
  ];
  const game = new Game();
  let activeView = $state<CampView>(untrack(() => initialView));
  const visibleView = $derived(!game.player && tabs.find(tab => tab.id === activeView)?.personal ? 'camp' : activeView);
  const viewLabel = $derived(tabs.find(tab => tab.id === visibleView)!.label);
  let inventoryPage = $state(1);
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
  const discoveries = $derived(game.collection.filter(row => ordinary.some(fish => fish.speciesId === row.speciesId)).length);
  const book = $derived(game.species.filter(fish => (!bookBiome || fish.biomeId === bookBiome) && (!bookTier || fish.allowedRarities.includes(bookTier)) && fish.name.toLowerCase().includes(bookQuery.trim().toLowerCase())));
  const remaining = $derived(game.player ? Math.max(0, Math.ceil(Number(game.player.nextCastAt.microsSinceUnixEpoch / 1000n - BigInt(now)) / 1000)) : 0);
  const quote = $derived(showQuote && game.action?.kind === 'sell' && !game.action.consumed ? game.action : null);
  const selectedOwned = $derived(selected.filter(id => game.inventory.some(fish => fish.catchId === id && !fish.favorite)));
  const speciesName = (id: number | undefined) => game.species.find(fish => fish.speciesId === id)?.name ?? 'Fish';
  const sprite = (id: number) => game.species.find(fish => fish.speciesId === id)?.spriteAsset ?? '/fish/minnow.png';
  const rankMinimums = (tier: string) => { const rule = game.rarities.find(row => row.tier === tier); return !rule || tier === 'F' ? 'Below D in length or weight' : `At least ${Number(rule.minimumLengthMillionths) / 1_000_000}× typical length and ${Number(rule.minimumWeightMillionths) / 1_000_000}× typical weight`; };
  const time = (micros: bigint) => new Date(Number(micros / 1000n)).toLocaleString();
  const linkUrl = (env.PUBLIC_ACCOUNT_LINK_URL ?? '').replace(/\/$/, '') + '/auth/discord/start';
  function select(id: bigint) { selected = selected.includes(id) ? selected.filter(value => value !== id) : [...selected, id]; showQuote = false; }
  function openView(view: CampView) {
    activeView = view;
    if (window.location.hash !== '#' + view) window.history.pushState(null, '', '#' + view);
    window.scrollTo(0, 0);
  }
  function readView() {
    const value = window.location.hash.slice(1);
    activeView = tabs.some(tab => tab.id === value) ? value as CampView : initialView;
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
  async function preview() { await game.previewSale(selectedOwned); showQuote = !game.error; }
  async function confirm() {
    if (!quote) return;
    await game.confirmSale(quote.nonce);
    if (!game.error) { selected = []; showQuote = false; }
  }
  onMount(() => {
    const compactScreen = window.matchMedia('(max-width: 600px)');
    const resizePages = () => {
      pageSize = compactScreen.matches ? 4 : 12;
      inventoryPage = bookPage = worldPage = 1;
    };
    resizePages();
    compactScreen.addEventListener('change', resizePages);
    readView();
    window.addEventListener('popstate', readView);
    window.addEventListener('hashchange', readView);
    game.start(env.PUBLIC_SPACETIMEDB_URI ?? '', env.PUBLIC_SPACETIMEDB_DATABASE ?? '');
    const timer = setInterval(() => { now = Date.now(); }, 1000);
    return () => { clearInterval(timer); game.stop(); compactScreen.removeEventListener('change', resizePages); window.removeEventListener('popstate', readView); window.removeEventListener('hashchange', readView); };
  });
</script>

<svelte:head><title>Fishbound · {viewLabel}</title><meta name="description" content="Your fishing camp, collection, records, and public fish compendium." /></svelte:head>

<main>
  <nav aria-label="Main navigation"><a class="brand" href="#camp" onclick={event => { event.preventDefault(); openView('camp'); }}><img src="/fish/koi.png" alt="" />Fishbound</a><span class:live={game.ready} class="connection"><span></span>{game.status}</span></nav>
  <header class="camp-banner" class:compact-banner={visibleView !== 'camp'}>
    <div class="camp-intro"><p class="eyebrow">{currentBiome?.name ?? 'SEVEN WATERS, ONE JOURNEY'}</p><h1>{visibleView === 'camp' ? 'Fishbound' : viewLabel}</h1>{#if visibleView === 'camp'}<p class="intro">Your next keeper is out there.</p><p class="cast-hint">Cast with <code>/fish</code> in Discord <span>·</span> One cast every minute</p><a class="book-link" href="#compendium" onclick={event => { event.preventDefault(); openView('compendium'); }}>Open the fish compendium <span aria-hidden="true">→</span></a>{/if}</div>
    <PondScene compact={visibleView !== 'camp'} />
  </header>

  {#if game.player}
    <section class="stats" aria-label="Your progress">
      <div><AnglerLevel totalXp={game.player.totalXp} levelCap={game.config?.levelCap ?? 120} /></div>
      <div><span>◈ COIN POUCH</span><strong>{game.player.coins.toString()}</strong><small>For your next adventure</small></div>
      <div><span>▤ COLLECTION BOOK</span><strong>{discoveries}<em> / {ordinary.length}</em></strong><progress value={discoveries} max={ordinary.length || 249} aria-label="Ordinary species discovered"></progress><small>Two bonus discoveries await</small></div>
      <div><span>⌛ NEXT CAST</span><strong>{remaining ? remaining + 's' : 'Ready!'}</strong><small>Use /fish in Discord</small></div>
    </section>
  {/if}

  <div class="camp-tabs" role="tablist" aria-label="Fishing camp views">
    {#each tabs as tab}<button id={'tab-' + tab.id} role="tab" aria-selected={visibleView === tab.id} aria-controls={tab.id === 'compendium' ? 'panel-compendium' : 'camp-panel'} tabindex={visibleView === tab.id ? 0 : -1} disabled={!!tab.personal && !game.player} title={tab.personal && !game.player ? 'Link Discord to open this view' : undefined} onclick={() => openView(tab.id)} onkeydown={event => tabKey(event, tab.id)}>{tab.label}</button>{/each}
  </div>

  {#if game.error}<p class="notice error" role="alert">{game.error}</p>{/if}
  {#if !game.ready && env.PUBLIC_SPACETIMEDB_DATABASE}<button class="secondary" onclick={() => game.reconnect()}>Reconnect</button>{/if}

  <div class="view-panel" role="tabpanel" id="camp-panel" aria-labelledby={'tab-' + visibleView} tabindex="0" hidden={visibleView === 'compendium'}>
  {#if visibleView === 'camp'}
    <section class="welcome" aria-labelledby="welcome"><div><p class="eyebrow">A FRESH PAGE IN YOUR FISHING JOURNAL</p><h2 id="welcome">{game.player ? 'Back at the pond.' : 'Grab a rod. Make a little history.'}</h2>{#if game.player}<p>Your {currentRod?.name ?? 'rod'} is ready for {currentBiome?.name ?? 'the pond'}. Open your tackle box to manage your catches, or turn a page in your collection book.</p>{:else}<p>Use <code>/fish</code> in Discord, then link your account to open your tackle box, collection book, and personal records.</p>{/if}<p class="muted">251 species. Seven waters. Start with the 28 fish waiting in Meadow Pond.</p></div>
      <div class="login">{#if game.player}<button onclick={() => openView('inventory')}>Open tackle box →</button><button class="secondary" onclick={() => openView('collection')}>Open collection book →</button>{:else}<button disabled={!game.ready || game.busy || !env.PUBLIC_ACCOUNT_LINK_URL} onclick={link}>{game.busy ? 'Preparing…' : 'Link Discord account →'}</button><small>{!env.PUBLIC_ACCOUNT_LINK_URL ? 'Discord sign-in will be available soon.' : game.ready ? 'One account, shared between Discord and this browser.' : 'Account linking becomes available when the pond is connected.'}</small>{/if}</div>
    </section>
    <TraderStall onclick={() => openView('trader')} />
  {/if}
  {#if visibleView === 'trader'}<Trader {game} {pageSize} />{/if}
  {#if game.player}
    {#if visibleView === 'inventory'}
    <section aria-labelledby="inventory"><div class="section-heading"><div><p class="eyebrow">KEEP THE GOOD ONES</p><h2 id="inventory">Your tackle box <span>{game.inventory.length} fish kept</span></h2></div><button class="secondary" disabled={!selectedOwned.length || selectedOwned.length > 50 || game.busy || !game.ready} onclick={preview}>Preview sale ({selectedOwned.length})</button></div>
      {#if quote}
        <div class="sale" role="status"><div><strong>Sell {quote.catchIds.length} selected {quote.catchIds.length === 1 ? 'catch' : 'catches'} for {quote.quotedCoins.toString()} coins?</strong><p>{quote.catchIds.map(id => { const fish = game.inventory.find(row => row.catchId === id); return '#' + id + ' ' + (fish ? speciesName(fish.speciesId) : '(catch changed)'); }).join(' · ')}</p><small>Discoveries and records remain. Favorites are protected. Preview expires at {time(quote.expiresAt.microsSinceUnixEpoch)}.</small></div><div class="sale-actions"><button disabled={game.busy || !game.ready || quote.expiresAt.microsSinceUnixEpoch <= BigInt(now) * 1000n} onclick={confirm}>Confirm sale · {quote.quotedCoins.toString()} coins</button><button class="secondary" onclick={() => { showQuote = false; }}>Cancel</button></div></div>
      {/if}
      <Gear {game} />
      <TackleBoxItems items={game.items} />
      {#if !game.inventory.length}<p class="empty">Your next catch could be the keeper. Try <code>/fish</code> in Discord.</p>{:else}<div class="catch-grid">
        {#each game.inventory.slice((inventoryPage - 1) * pageSize, inventoryPage * pageSize) as fish (fish.catchId)}
          <article class="catch" class:favorite={fish.favorite}><div class="catch-tools"><label><input type="checkbox" checked={selected.includes(fish.catchId)} disabled={fish.favorite || game.busy || !game.ready} onchange={() => select(fish.catchId)} /><span class="sr-only">Select catch #{fish.catchId.toString()}</span></label><button class="star" class:active={fish.favorite} disabled={game.busy || !game.ready} onclick={() => { showQuote = false; game.favorite(fish); }} aria-label={(fish.favorite ? 'Unfavorite' : 'Favorite') + ' catch #' + fish.catchId}>{fish.favorite ? '★' : '☆'}</button></div><div class="fish-art"><img src={sprite(fish.speciesId)} alt={speciesName(fish.speciesId)} /><span>{fish.rarity} RANK</span></div><p class="grade">{grades[fish.sizeGrade]}</p><h3>{speciesName(fish.speciesId)}</h3><p class="measure">{formatLength(fish.lengthMm)} <span>·</span> {formatWeight(fish.weightG)}</p><p class="relative-size">{(fish.lengthMm / (game.species.find(row => row.speciesId === fish.speciesId)?.typicalLengthMm ?? fish.lengthMm)).toFixed(2)}× typical length<br />{(Number(fish.weightG) / Number(game.species.find(row => row.speciesId === fish.speciesId)?.typicalWeightG ?? fish.weightG)).toFixed(2)}× typical weight</p><div class="catch-footer"><span>#{fish.catchId.toString()}</span><span>{fish.saleValueCoins.toString()} coins</span></div></article>
        {/each}
      </div>{/if}
      <PageTurner bind:page={inventoryPage} total={game.inventory.length} {pageSize} label="Tackle box pages" />
    </section>

    {/if}
    {#if visibleView === 'collection'}
    <section aria-labelledby="collection"><div class="section-heading"><div><p class="eyebrow">EVERY DISCOVERY COUNTS</p><h2 id="collection">Collection book</h2></div><span class="muted">{discoveries} of {ordinary.length} ordinary species discovered</span></div>
      <div class="filters"><div><label for="book-biome">Biome</label><select id="book-biome" bind:value={bookBiome} onchange={() => bookPage = 1}><option value={0}>All waters</option>{#each game.biomes as biome}<option value={biome.biomeId}>{biome.name}</option>{/each}</select></div><div><label for="book-rank">Rank</label><select id="book-rank" bind:value={bookTier} onchange={() => bookPage = 1}><option value="">All ranks</option>{#each ['F','D','C','B','A','S','SS','SSS','UR','UUR'] as tier}<option value={tier}>{tier}</option>{/each}</select></div><div><label for="book-search">Find a fish</label><input id="book-search" type="search" placeholder="Name…" bind:value={bookQuery} oninput={() => bookPage = 1} /></div></div>
      <p class="muted">Showing {book.length} species. Ordinary ranks come from length and weight relative to each species; both must meet the rank minimum. Fihs and Nidalees Lost Sock are bonus discoveries.</p>{#if bookTier}<p class="muted">{bookTier}: {rankMinimums(bookTier)}.</p>{/if}<div class="collection-grid">
      {#each book.slice((bookPage - 1) * pageSize, bookPage * pageSize) as fish (fish.speciesId)}{@const discovery = game.collection.find(row => row.speciesId === fish.speciesId)}{@const caughtAtRank = !!discovery && (!bookTier || discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(bookTier)] > 0n)}<article class="collection-entry" class:undiscovered={!caughtAtRank}><img src={fish.spriteAsset} alt={fish.name} loading="lazy" /><div><small>{bookTier ? bookTier + ' RANK' : fish.allowedRarities.length === 10 ? 'F THROUGH UUR' : fish.allowedRarities[0] + ' ONLY'}{fish.countsForOrdinaryCollectionCompletion ? '' : ' · BONUS'}</small><h3>{fish.name}</h3><p>{game.biomes.find(b => b.biomeId === fish.biomeId)?.name}</p>{#if discovery}<p>{bookTier ? discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(bookTier)].toString() + ' caught at ' + bookTier : discovery.count.toString() + ' caught across ranks'} · best across ranks {formatLength(discovery.bestLengthMm)} / {formatWeight(discovery.bestWeightG)}</p>{:else}<p>Waiting for your first discovery</p>{/if}<div class="rank-progress" aria-label="Ranks caught">{#each fish.allowedRarities as rank}<span title={rankMinimums(rank)} class:earned={!!discovery && discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(rank)] > 0n}>{rank}{discovery && discovery.rankCounts[['F','D','C','B','A','S','SS','SSS','UR','UUR'].indexOf(rank)] > 0n ? ' ✓' : ' ○'}</span>{/each}</div></div><span class="discovered" aria-label={caughtAtRank ? 'Discovered at selected rank' : 'Undiscovered at selected rank'}>{caughtAtRank ? '✓' : '○'}</span></article>{/each}
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
  <div class="view-panel" role="tabpanel" id="panel-compendium" aria-labelledby="tab-compendium" tabindex="0" hidden={visibleView !== 'compendium'}><Compendium active={visibleView === 'compendium'} /></div>

  <footer><span>Fishbound · One cast at a time.</span><div>{#if game.lastSync}<span>Updated {game.lastSync.toLocaleTimeString()}</span>{/if}{#if game.player}<button class="text-button" disabled={game.busy || !game.ready} onclick={() => game.logout()}>Unlink this browser</button>{/if}</div></footer>
  <form method="POST" action={linkUrl} bind:this={linkForm} hidden><input name="challenge_id" value={game.challenge?.challengeId.toString() ?? ''} /><input name="proof" value={game.challenge?.proof.toString() ?? ''} /></form>
</main>

<style>
  main{max-width:1280px;margin:auto;padding:24px 32px 0}
  nav{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 18px;background:repeating-linear-gradient(0deg,#ffffff04 0 2px,transparent 2px 8px),var(--wood);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px #a3784b,0 5px 0 #122b27}
  nav a{text-decoration:none;font-family:var(--font-game);font-size:18px;padding:8px 12px;color:#ffedbd;white-space:nowrap}
  nav a:hover{background:#3e302655;color:#fff6d8}
  .brand{display:flex;align-items:center;gap:8px;font-size:27px;margin-right:auto;padding:0!important}
  .brand img{width:46px;height:36px;object-fit:contain;image-rendering:pixelated}
  .connection{display:flex;align-items:center;gap:7px;font-size:11px;color:#e7d8ba;padding:5px 8px;background:#362f29;border:1px solid #927b55}
  .connection>span{width:7px;height:7px;background:#9c8e75}.connection.live>span{background:#a8d29a}
  .camp-banner{display:grid;grid-template-columns:1fr 1.1fr;gap:30px;align-items:stretch;margin:28px 0 32px;padding:24px;background:#233e36;border:3px solid #162d28;box-shadow:inset 0 0 0 1px #6e8154,0 6px 0 #142c27}
  .camp-intro{padding:14px 8px;align-self:center}
  .eyebrow{font-family:var(--font-game);font-size:14px;letter-spacing:.06em;color:#806440;margin:0 0 8px}
  .camp-banner .eyebrow{color:#c2cc96}
  h1{font-family:var(--font-game);font-size:clamp(58px,6.5vw,84px);font-weight:700;line-height:1;margin:12px 0;color:#f8db8c;text-shadow:3px 4px 0 #48382e}
  .intro{font-size:18px;color:#e5ddbd;margin:14px 0}
  .cast-hint{font-size:12px;color:#c8d3ba}.cast-hint span{margin:0 6px}
  .cast-hint code{color:#ffeaa9;background:#152d28;padding:3px 7px;border:1px solid #7d885a}
  .book-link{display:inline-flex;gap:20px;align-items:center;padding:10px 16px;margin-top:10px;background:var(--gold);color:var(--ink);border:2px solid var(--wood-dark);box-shadow:inset 0 0 0 2px #ffe5a0,0 4px 0 #111e1b;text-decoration:none;font-family:var(--font-game);font-size:18px}
  .book-link:hover{background:#ffdd83;transform:translateY(-1px)}
  section{padding:28px;margin-top:28px;color:var(--ink);background:repeating-linear-gradient(0deg,#896b3410 0 1px,transparent 1px 6px),var(--paper);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px #fff0cb,0 6px 0 #132a25;scroll-margin-top:24px;position:relative}
  section::before,section::after{content:'';position:absolute;top:8px;width:5px;height:5px;background:#a47e4e;box-shadow:0 1px 0 #fff3d2}section::before{left:8px}section::after{right:8px}
  h2{font-family:var(--font-game);font-weight:500;font-size:32px;line-height:1.2;margin:0 0 18px;color:#4a3b29}
  h3{font-family:var(--font-game);font-size:22px;font-weight:500;margin:8px 0;line-height:1.2}
  h2>span{font-family:var(--font-body);font-size:13px;color:var(--ink-muted);margin-left:12px;white-space:nowrap}
  .muted{color:var(--ink-muted);font-size:13px;line-height:1.7}
  code{color:#654325;font-size:13px;font-weight:700}
  button{border:2px solid var(--wood-dark);padding:10px 16px;background:var(--gold);color:var(--ink);box-shadow:inset 0 0 0 2px #ffe5a0,0 3px 0 #9b7544;font-family:var(--font-game);font-size:17px;font-weight:500;line-height:1.3;transition:transform .12s,background .12s}
  button:hover:not(:disabled){background:#ffe09b;transform:translateY(-1px)}button:active:not(:disabled){transform:translateY(2px);box-shadow:inset 0 0 0 2px #ffe5a0}
  button:disabled{opacity:.55;box-shadow:inset 0 0 0 2px #dbc69b;filter:saturate(.6)}
  .secondary{background:#e4d4a9;border-color:#7e6446;box-shadow:inset 0 0 0 2px #fff1cc,0 3px 0 #9d835f}
  .welcome{display:grid;grid-template-columns:1.7fr 1fr;gap:32px;padding:30px}.welcome p{font-size:14px;line-height:1.7}.welcome h2{margin-bottom:12px}
  .login{display:flex;flex-direction:column;justify-content:center;gap:16px;max-width:300px}.login small{font-size:12px;line-height:1.6;color:var(--ink-muted)}
  .notice{padding:16px;border:3px solid #835a41;background:#f7d5b6;color:#663d31;font-size:14px}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:0;padding:0;background:var(--wood);color:#fff0c8;box-shadow:inset 0 0 0 2px #a5784b,0 5px 0 #122b27}.stats::before,.stats::after{display:none}
  .stats>div{padding:20px 24px;border-right:2px solid #4d3829}.stats>div:last-child{border:0}
  .stats span{font-family:var(--font-game);font-size:16px;color:#f3d898}.stats strong{font-family:var(--font-game);font-weight:500;font-size:38px;display:block;margin:8px 0;line-height:1.15;text-shadow:2px 2px 0 #392b23}.stats em{font-size:22px;font-style:normal;color:#d0bd97}.stats small{font-size:11px;color:#ecddbc;display:block}
  progress{display:block;width:100%;height:8px;accent-color:#b7ce79;border:1px solid #392d23;margin:8px 0;background:#392d23}progress::-webkit-progress-bar{background:#392d23}progress::-webkit-progress-value{background:#b7ce79}
  .section-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.section-heading h2{margin-bottom:0}
  .filters{display:flex;flex-wrap:wrap;gap:16px;margin:20px 0}.filters>div,.filters label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:#665039;min-width:0}.filters label{font-weight:700}
  .filters select,.filters input{max-width:100%;min-height:42px;border:2px solid #a68a60;background:#fff1cd;color:var(--ink);padding:8px 12px;box-shadow:inset 2px 2px 0 #dcc398;border-radius:0}
  .catch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
  .catch{border:2px solid #a38859;padding:14px;background:#fff0ca;box-shadow:inset 0 0 0 2px #f9e4b6,3px 4px 0 #ccb080}
  .catch.favorite{border-color:#9d7738;box-shadow:inset 0 0 0 2px #e9bd67,3px 4px 0 #b89453}
  .catch-tools{display:flex;justify-content:space-between;align-items:center;height:23px}.catch-tools input{accent-color:#5c8054;width:18px;height:18px}
  .star{background:transparent;border:0;color:#887654;padding:0;font-family:var(--font-body);font-size:26px;line-height:1;box-shadow:none}.star:hover:not(:disabled){background:transparent}.star.active{color:#b87821}
  .fish-art{height:135px;display:flex;align-items:center;justify-content:center;position:relative;margin-top:12px;border:2px solid #6a7657;background:repeating-linear-gradient(0deg,#ffffff05 0 2px,transparent 2px 8px),#385d56;box-shadow:inset 0 0 0 3px #78916a}
  .fish-art img{width:115px;height:100px;object-fit:contain;image-rendering:pixelated;filter:drop-shadow(3px 4px 0 #203b3580)}
  .fish-art>span{position:absolute;right:5px;bottom:5px;border:1px solid #dbbc73;color:#ffe5a4;background:#263c30;padding:2px 6px;font-family:var(--font-game);font-size:14px}
  .grade{color:#80623c;font-size:11px;margin:12px 0 3px;font-weight:700}.measure{font-size:13px;color:#544d39}.measure span{margin:0 5px;color:#9b825e}.relative-size{font-size:11px;color:#736445;line-height:1.6}
  .catch-footer{border-top:1px dashed #b99a69;display:flex;justify-content:space-between;padding-top:10px;margin-top:16px;font-size:11px;color:#736245}.catch-footer span:last-child{color:#8b6029;font-weight:700}
  .empty{background:#e8d6ad;border:2px dashed #a18c66;padding:24px;color:#766044;font-size:14px}
  .sale{border:2px solid #997437;padding:22px;margin-bottom:20px;background:#f6dda3;box-shadow:inset 0 0 0 2px #fff1c7}.sale strong{font-size:16px;color:#64492a}.sale p,.sale small{font-size:12px;color:#71593a;line-height:1.7}.sale-actions{display:flex;gap:12px;margin-top:18px}
  .collection-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.collection-entry{display:flex;align-items:center;gap:16px;border:1px solid #b89c6c;padding:16px;background:#fff1ce;box-shadow:2px 3px 0 #cdb383}.collection-entry>div{min-width:0;flex:1}
  .collection-entry>img{height:62px;width:70px;object-fit:contain;image-rendering:pixelated}.collection-entry small{font-family:var(--font-game);font-size:13px;color:#826542}.collection-entry h3{margin:5px 0;font-size:21px}.collection-entry p{font-size:11px;color:#79674a;margin:4px 0}
  .discovered{margin-left:auto;color:#537145;font-weight:700}.undiscovered>img{filter:grayscale(1);opacity:.4}.undiscovered h3{color:#73684f}.undiscovered .discovered{color:#a59b7e}
  .rank-progress{display:flex;flex-wrap:wrap;gap:4px;margin-top:10px}.rank-progress span{font-family:var(--font-game);font-size:12px;color:#8c7a58;border:1px solid #c7b185;padding:2px 4px;background:#eddfba}.rank-progress span.earned{color:#f6e4a7;border-color:#547145;background:#547145}
  .biome-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:18px}.biome-grid article{padding:20px;border:2px solid #a68a60;background:#e8d7b0;box-shadow:3px 4px 0 #c4a878;position:relative;overflow:hidden}
  .biome-grid article::before{content:'';display:block;height:36px;margin:-20px -20px 18px;background:linear-gradient(155deg,transparent 35%,#456f58 36% 65%,transparent 66%),linear-gradient(30deg,#365c52 45%,#7b9865 46% 68%,#e8be81 69%);border-bottom:2px solid #8b7853}
  .biome-grid article:nth-child(3n)::before{filter:hue-rotate(25deg)}.biome-grid article:nth-child(4)::before{filter:hue-rotate(90deg)}.biome-grid article:nth-child(6)::before{filter:hue-rotate(140deg) saturate(.6)}.biome-grid article:nth-child(7)::before{filter:brightness(.7)}
  .biome-grid article.active{border-color:#547247;box-shadow:inset 0 0 0 2px #9eb578,3px 4px 0 #bdab7a}.biome-grid small{font-family:var(--font-game);font-size:14px;color:#75613f}.biome-grid p{font-size:12px;color:#716043;line-height:1.7}.biome-grid button{margin-top:8px}
  footer{border-top:1px solid #6a7950;margin-top:38px;padding:24px 0 32px;display:flex;justify-content:space-between;gap:16px;color:#b7c29d;font-size:11px}footer>div{display:flex;gap:24px;align-items:center}.text-button{color:#d8d9b1;background:transparent;border:0;font-family:var(--font-body);font-size:11px;padding:0;box-shadow:none}.text-button:hover:not(:disabled){background:transparent}
  .sr-only{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}
  @media(max-width:850px){main{padding:16px 18px 0}nav{gap:5px;padding:10px}.brand{font-size:24px}nav a{font-size:16px;padding:6px 8px}.connection{margin-left:auto}.camp-banner{gap:20px;padding:18px;grid-template-columns:1fr 1fr}.camp-intro{padding:0}h1{font-size:62px}.intro{font-size:16px}.cast-hint span{display:none}.book-link{font-size:16px;gap:10px}section{padding:22px}.collection-grid{grid-template-columns:1fr}.stats>div{padding:18px 16px}.stats span{font-size:14px}}
  @media(max-width:600px){main{padding:12px 12px 0}nav{gap:2px}.brand{width:100%;margin:0 0 4px;font-size:26px}nav a{font-size:15px;padding:6px 7px}.connection{font-size:10px;padding:4px 6px}.camp-banner{grid-template-columns:1fr;padding:16px;margin:22px 0;gap:20px}.camp-intro{padding:8px}h1{font-size:clamp(38px,14vw,58px)}.camp-banner :global(.scene){min-height:210px}.cast-hint{font-size:12px}.welcome{grid-template-columns:1fr;gap:20px;padding:22px}.login{max-width:none}.stats{grid-template-columns:1fr 1fr}.stats>div:nth-child(2){border-right:0}.stats>div:nth-child(-n+2){border-bottom:2px solid #4d3829}.stats>div{padding:18px}.stats strong{font-size:34px}.section-heading{align-items:flex-start;flex-wrap:wrap}section{padding:20px;margin-top:22px}h2{font-size:28px}.catch-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.catch{padding:10px}.catch h3{font-size:19px}.fish-art{height:105px}.fish-art img{width:95px;height:80px}.fish-art>span{font-size:12px}.measure{font-size:10px}.relative-size{font-size:10px}.catch-footer{font-size:10px}.collection-entry{padding:12px;gap:10px}.collection-entry>img{width:50px;height:50px}.sale-actions{flex-wrap:wrap}footer{flex-direction:column}footer>div{justify-content:space-between}.filters{gap:12px}.filters>div{flex:1;min-width:110px}.filters>div:last-child{flex-basis:100%}.filters input{width:100%}}

  .compact-banner{padding:14px 24px;margin:20px 0;grid-template-columns:1fr 240px;gap:20px}
  .compact-banner .camp-intro{padding:0}.compact-banner .eyebrow{margin-bottom:4px}.compact-banner h1{font-size:40px;margin:0;line-height:1.1}
  .compact-banner :global(.scene){min-height:90px;height:90px}.compact-banner :global(.scene-label){display:none}
  .camp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:24px 0 0;padding:10px;background:var(--wood-dark);border:2px solid #a3784b;box-shadow:0 4px 0 #122b27;position:sticky;top:8px;z-index:5}
  .camp-tabs button{padding:10px 16px;font-size:18px;background:#775139;color:#f6e1b3;border-color:#a07e51;box-shadow:none}
  .camp-tabs button[aria-selected=true]{background:var(--paper);color:var(--ink);border-color:#e5c991;box-shadow:inset 0 -3px 0 #bea06e}
  .camp-tabs button:disabled{opacity:.45;filter:none;box-shadow:none}
  .view-panel{margin-top:18px}.view-panel>section{margin-top:0}
  @media(max-width:600px){.compact-banner{grid-template-columns:1fr;padding:16px}.compact-banner h1{font-size:34px}.compact-banner :global(.scene){display:none}.camp-tabs{padding:8px;gap:6px;margin-top:18px;top:4px}.camp-tabs button{flex:1 0 auto;font-size:16px;padding:8px 10px}.view-panel{margin-top:14px}}
</style>
