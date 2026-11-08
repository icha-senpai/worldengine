<script lang="ts">
  import { formatLength, formatWeight } from './measurements';
  import GameSelect from './GameSelect.svelte';
  import { tooltip } from './tooltip';
  import { onMount, tick, untrack } from 'svelte';
  import * as env from '$app/env/public';
  import { Game } from './game.svelte';
  import { connectionDiagnostics } from './connection-diagnostics';
  import PondScene from './PondScene.svelte';
  import CastCooldown from './CastCooldown.svelte';
  import { formatCooldownSeconds, runningHaulDeadline } from './activity-timers';
  import { deadlineExpired, remainingCooldownSeconds } from './server-clock';
  import LazyPanel from './LazyPanel.svelte';
  import TraderStall from './TraderStall.svelte';
  import PageTurner from './PageTurner.svelte';
  import AnglerLevel from './AnglerLevel.svelte';
  import world from '../../../../../content/world.json';
  import ThemeToggle from './ThemeToggle.svelte';
  import { discordPlayUrl, onboardingNextSteps } from './ui-onboarding';
  import { formatCampRoute, parseCampHash, type CampRoute, type CampView } from './camp-navigation';
  let { initialView = 'camp' }: {
    initialView?: CampView;
  } = $props();
  const tabs: { id: CampView; label: string; personal?: boolean }[] = [
    { id: 'camp', label: 'Camp' }, { id: 'quests', label: 'Quests', personal: true }, { id: 'trader', label: 'Shop' }, { id: 'inventory', label: 'Tackle box', personal: true }, { id: 'tideforge', label: 'Tideforge', personal: true },
    { id: 'journal', label: 'Journal', personal: true },
    { id: 'waters', label: 'World map' }, { id: 'records', label: 'Records' }, { id: 'compendium', label: 'Fish Book' },
    { id: 'achievements', label: 'Achievements', personal: true }, { id: 'anglers', label: 'Anglers' },
  ];
  const game = new Game();
  const initialRoute = untrack(() => parseCampHash(`#${initialView}`));
  let activeView = $state<CampView>(initialRoute.view);
  let activeSection = $state<string | undefined>(initialRoute.section);
  let menuOpen = $state(false);
  let menuButton: HTMLButtonElement;
  let linkButton = $state<HTMLButtonElement>();
  let linkReason = $state<HTMLElement>();
  let mobileScreen = $state(false);
  let progressOpen = $state(false);
  const visibleView = $derived(!game.player && tabs.find(tab => tab.id === activeView)?.personal ? 'camp' : activeView);
  const viewLabel = $derived(tabs.find(tab => tab.id === visibleView)!.label);
  $effect(() => { game.setView(visibleView); });
  $effect(() => { progressOpen = !mobileScreen || visibleView === 'camp'; });
  let inventoryPage = $state(1);
  const inventoryPageSize = 25;
  const saleBatchSize = 50;
  const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  let selectionRank = $state('');
  let worldPage = $state(1);
  let pageSize = $state(12);
  let selected = $state<bigint[]>([]);
  let showQuote = $state(false);
  let tackleRevision = 0;
  const boatDeadline = $derived(runningHaulDeadline(game.haulTrips, 'boat'));
  const boatRaid = $derived(game.raidAttempts.find(row =>
    row.status === 'deployed' || row.status === 'running'));
  const netDeadline = $derived(runningHaulDeadline(game.haulTrips, 'net'));
  const nextCastSeconds = $derived(remainingCooldownSeconds(game.player?.nextCastAt.microsSinceUnixEpoch ?? null, game.serverNowMs));
  let devLoginAvailable = $state(false);
  let devLoginBusy = $state(false);
  let devLoginError = $state('');
  let linkForm: HTMLFormElement;
  const grades = ['Tiny', 'Small', 'Typical', 'Large', 'Trophy', 'Colossal'];
  const level = $derived(game.profile?.level ?? 1);
  const currentBiome = $derived(game.biomes.find(row => row.biomeId === game.player?.selectedBiomeId));
  const currentRod = $derived(game.rods.find(row => row.rodId === game.player?.equippedRodId));
  const ordinary = $derived(game.species.filter(row => row.countsForOrdinaryCollectionCompletion));
  const discoveries = $derived(game.collection.filter(row => row.playerId === game.player?.playerId && row.count > 0n && ordinary.some(fish => fish.speciesId === row.speciesId)).length);
  const nextSteps = $derived.by(() => {
    const playerId = game.player?.playerId;
    const inventory = game.inventory.filter(fish => fish.playerId === playerId);
    return onboardingNextSteps({
      discoveryCount: game.collection.filter(row => row.playerId === playerId && row.count > 0n).length,
      inventoryCount: inventory.length,
      hasFavorite: inventory.some(fish => fish.favorite),
    });
  });
  const quote = $derived(showQuote && game.action?.kind === 'sell' && !game.action.consumed ? game.action : null);
  const selectedOwned = $derived(selected.filter(id => game.inventory.some(fish => fish.catchId === id && !fish.favorite)));
  const saleBatch = $derived(selectedOwned.slice(0, saleBatchSize));
  const selectable = $derived(game.inventory.filter(fish => !fish.favorite));
  const speciesName = (id: number | undefined) => game.species.find(fish => fish.speciesId === id)?.name ?? 'Fish';
  const sprite = (id: number) => game.species.find(fish => fish.speciesId === id)?.spriteAsset ?? '/fish/minnow.png';
  const time = (micros: bigint) => new Date(Number(micros / 1000n)).toLocaleString();
  const linkUrl = (env.PUBLIC_ACCOUNT_LINK_URL ?? '').replace(/\/$/, '') + '/auth/discord/start';
  const playUrl = discordPlayUrl(env.PUBLIC_DISCORD_PLAY_URL);
  function select(id: bigint) { selected = selected.includes(id) ? selected.filter(value => value !== id) : [...selected, id]; showQuote = false; }
  function quickSelect(rank = '') {
    selected = selectable.filter(fish => !rank || fish.rarity === rank).map(fish => fish.catchId);
    showQuote = false;
  }
  function applyRoute(route: CampRoute) {
    if (activeView === 'inventory' && route.view !== 'inventory') { showQuote = false; tackleRevision++; }
    activeView = route.view;
    activeSection = route.section;
  }
  function openRoute(view: CampView, section?: string) {
    const hash = formatCampRoute(view, section);
    applyRoute(parseCampHash(hash, initialView));
    menuOpen = false;
    if (window.location.hash !== hash) window.history.pushState(null, '', hash);
    window.scrollTo(0, 0);
  }
  function openView(view: CampView) { openRoute(view, view === 'compendium' ? game.player ? 'collection' : 'field-guide' : undefined); }
  function readView() {
    menuOpen = false;
    applyRoute(parseCampHash(window.location.hash, initialView));
  }
  function closeMenu() {
    menuOpen = false;
    menuButton?.focus();
  }
  function chooseMobileView(view: CampView) {
    openView(view);
    menuButton?.focus();
  }
  function focusableDisabledHelp(node: HTMLElement) {
    node.tabIndex = 0;
    return { destroy: () => node.removeAttribute('tabindex') };
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
  async function openLinkControls() {
    openView('camp');
    await tick();
    if (linkButton?.disabled) linkReason?.focus();
    else linkButton?.focus();
  }
  async function devLogin() {
    devLoginBusy = true; devLoginError = '';
    try {
      await game.prepareLink();
      await tick();
      if (!game.challenge || game.challenge.consumed || game.error) { devLoginError = 'Dev login is not available right now.'; return; }
      const response = await fetch('/api/dev-login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ challengeId: game.challenge.challengeId.toString(), proof: game.challenge.proof.toString() }) });
      if (response.status === 204) window.location.reload();
      else devLoginError = 'Dev login failed. Try again.';
    } catch { devLoginError = 'Dev login failed. Try again.'; }
    finally { devLoginBusy = false; }
  }
  async function preview() { const revision = tackleRevision; await game.previewSale(saleBatch); showQuote = !game.error && tackleRevision === revision && visibleView === 'inventory'; }
  async function confirm() {
    if (!quote || deadlineExpired(quote.expiresAt.microsSinceUnixEpoch, game.serverNowMs)) return;
    const soldIds = quote.catchIds;
    await game.confirmSale(quote.nonce);
    if (!game.error) { selected = selected.filter(id => !soldIds.includes(id)); showQuote = false; }
  }
  onMount(() => {
    const stopDiagnostics = connectionDiagnostics.initialize();
    const compactScreen = window.matchMedia('(max-width: 600px)');
    const resizePages = () => {
      menuOpen = false;
      mobileScreen = compactScreen.matches;
      pageSize = compactScreen.matches ? 4 : 12;
      worldPage = 1;
    };
    resizePages();
    compactScreen.addEventListener('change', resizePages);
    devLoginAvailable = import.meta.env.DEV && (env.PUBLIC_SPACETIMEDB_URI ?? '') === 'wss://fishbound.test' && (env.PUBLIC_SPACETIMEDB_DATABASE ?? '') === 'fishbound-dev-local' && ['https://fishbound.test', 'http://127.0.0.1:5174', 'http://localhost:5174'].includes(window.location.origin);
    readView();
    window.addEventListener('popstate', readView);
    window.addEventListener('hashchange', readView);
    game.start(env.PUBLIC_SPACETIMEDB_URI ?? '', env.PUBLIC_SPACETIMEDB_DATABASE ?? '');
    return () => { stopDiagnostics(); game.stop(); compactScreen.removeEventListener('change', resizePages); window.removeEventListener('popstate', readView); window.removeEventListener('hashchange', readView); };
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
        {#if tab.personal && !game.player}
          <span class="disabled-nav" role="group" aria-label={`${tab.label}: Link Discord to open this view`} use:focusableDisabledHelp use:tooltip={'Link Discord to open this view'}>
            <button type="button" disabled>{tab.label}</button>
          </span>
        {:else}
          <button type="button" aria-current={visibleView === tab.id ? 'page' : undefined} onclick={() => chooseMobileView(tab.id)}>{tab.label}</button>
        {/if}
      {/each}
    </div>
  </nav>
  {#if !game.player}<div class="link-notice">Link Discord to open your tackle box, quests and personal progress. <button class="text-button" onclick={openLinkControls}>Link account</button></div>{/if}
  {#if devLoginAvailable}
    <p class="dev-login">DEV · Local data only{#if game.player} · linked as {game.profile?.displayName ?? game.player.displayName}{:else} · <button class="dev-login-button" disabled={!game.ready || game.busy || devLoginBusy} onclick={devLogin}>{devLoginBusy ? 'Linking…' : 'Use icha_'}</button>{#if devLoginError}<small class="dev-login-error" role="alert">{devLoginError}</small>{/if}{/if}</p>
  {/if}
  <header class="camp-banner" class:compact-banner={visibleView !== 'camp'}>
    <div class="camp-intro"><p class="eyebrow">{currentBiome?.name ?? 'SEVEN WATERS, ONE JOURNEY'}</p><h1>{visibleView === 'camp' ? 'Fishbound' : viewLabel}</h1>{#if visibleView === 'camp'}<p class="intro">Your next keeper is out there.</p>
      {#if game.player}<p class="cast-hint">Cast with <code>/fish</code> in Discord <span>·</span> One cast every minute</p>
      {:else}
        <p class="cast-hint">Open a server where Fishbound is available and use <code>/fish</code>. Then link the same Discord account here.</p>
        <div class="hero-actions"><a class="discord-action" href={playUrl} target="_blank" rel="noopener noreferrer">Open Discord</a>
          <button class="secondary" bind:this={linkButton} disabled={!game.ready || game.busy || devLoginBusy || !env.PUBLIC_ACCOUNT_LINK_URL} onclick={link}>{game.busy ? 'Preparing…' : 'Link Discord account'}</button>
        </div>
        <small class="link-reason" bind:this={linkReason} tabindex="-1">{!env.PUBLIC_ACCOUNT_LINK_URL ? 'Discord sign-in will be available soon.' : !game.ready ? 'Account linking becomes available when the pond is connected.' : game.busy || devLoginBusy ? 'Wait for the current action to finish.' : 'One account, shared between Discord and this browser.'}</small>
      {/if}
      <a class="book-link" href="#compendium" onclick={event => { event.preventDefault(); openView('compendium'); }}>Open the Fish Book <span aria-hidden="true">→</span></a>{/if}</div>
    {#if visibleView === 'camp' || !mobileScreen}<PondScene compact={visibleView !== 'camp'} />{/if}
  </header>

  {#if game.player}
    <details class="progress-details" class:camp-progress={visibleView === 'camp'} bind:open={progressOpen}>
      <summary>Your camp · {game.player.coins.toString()} coins · Next cast {nextCastSeconds === null ? 'Syncing…' : nextCastSeconds ? formatCooldownSeconds(nextCastSeconds) : 'Ready!'}</summary>
    <section class="stats" aria-label="Your progress">
      <div><AnglerLevel totalXp={game.player.totalXp} levelCap={game.config?.levelCap ?? world.levelCap} rulesVersion={game.config?.version ?? 9} /></div>
      <div><span>◈ COIN POUCH</span><strong class="coin-value">{game.player.coins.toString()}</strong><small>For your next adventure</small></div>
      <div><span>▤ COLLECTION BOOK</span><strong>{discoveries}<em> / {ordinary.length}</em></strong><progress value={discoveries} max={ordinary.length || 249} aria-label="Ordinary species discovered"></progress><small>Two bonus discoveries await</small></div>
      <div><span>⌛ NEXT CAST</span><strong class="timer-value"><CastCooldown nextCastAt={game.player?.nextCastAt.microsSinceUnixEpoch ?? null} /></strong><small>Use /fish in Discord</small></div>
      <div><span>{boatRaid ? '🚣 BOAT RAID' : '🚣 BOAT HAUL'}</span><strong class="timer-value">{#if boatRaid?.status === 'deployed'}Launch <CastCooldown nextCastAt={boatRaid.startsAt.microsSinceUnixEpoch} formatMinutes={true} zeroLabel="Starting…" />{:else if boatRaid?.status === 'running'}Raid end <CastCooldown nextCastAt={boatRaid.endsAt.microsSinceUnixEpoch} formatMinutes={true} zeroLabel="Settling…" />{:else if boatDeadline === null}Ready!{:else}<CastCooldown nextCastAt={boatDeadline} formatMinutes={true} zeroLabel="Finishing…" />{/if}</strong><small>{boatRaid ? 'Your boat is committed to this raid.' : 'Use /fishboat in Discord'}</small></div>
      <div><span>🕸 NET HAUL</span><strong class="timer-value">{#if netDeadline === null}Ready!{:else}<CastCooldown nextCastAt={netDeadline} formatMinutes={true} zeroLabel="Finishing…" />{/if}</strong><small>Use /fishnet in Discord</small></div>
    </section>
    </details>
  {/if}

  <div class="camp-tabs" role="tablist" aria-label="Fishing camp views">
    {#each tabs as tab}
      {#if tab.personal && !game.player}
        <span class="disabled-nav" role="group" aria-label={`${tab.label}: Link Discord to open this view`} use:focusableDisabledHelp use:tooltip={'Link Discord to open this view'}>
          <button id={'tab-' + tab.id} role="tab" aria-selected="false" aria-controls="camp-panel" tabindex="-1" disabled>{tab.label}</button>
        </span>
      {:else}
        <button id={'tab-' + tab.id} role="tab" aria-selected={visibleView === tab.id} aria-controls={tab.id === 'compendium' ? 'panel-compendium' : 'camp-panel'} tabindex={visibleView === tab.id ? 0 : -1} onclick={() => openView(tab.id)} onkeydown={event => tabKey(event, tab.id)}>{tab.label}</button>
      {/if}
    {/each}
  </div>

  {#if game.error}<p class="notice error" role="alert">{game.error}</p>{/if}
  {#if game.reconnecting}<p class="notice" role="status">Reconnecting… Your last synced progress is still shown. Actions are paused until live data returns.</p>{/if}
  {#if game.sectionLoading && !game.reconnecting}<p class="notice" role="status">Loading this view…</p>{/if}
  {#if !game.ready && !game.reconnecting && !game.sectionLoading && env.PUBLIC_SPACETIMEDB_DATABASE}<button class="secondary" onclick={() => game.reconnect()}>Reconnect</button>{/if}

  <div class="view-panel" role="tabpanel" id="camp-panel" aria-labelledby={'tab-' + visibleView} tabindex="0" hidden={visibleView === 'compendium'}>
  {#if visibleView === 'camp'}
    <section class="welcome" aria-labelledby="welcome"><div><p class="eyebrow">A FRESH PAGE IN YOUR FISHING JOURNAL</p><h2 id="welcome">{game.player ? 'Back at the pond.' : 'Grab a rod. Make a little history.'}</h2>{#if game.player}<p>Your {game.equippedGearLabel('rod') ?? currentRod?.name ?? 'rod'} is ready for {currentBiome?.name ?? 'the pond'}. Manage your catches in your tackle box, gear and bait in Tideforge, or turn a page in your collection book.</p>
        <div class="next-steps"><h3>Next steps</h3><ul>{#each nextSteps as step}<li>{step.text}{#if step.view} <button class="inline-action" onclick={() => { if (step.view) openRoute(step.view, step.view === 'tideforge' ? 'gear' : undefined); }}>{step.view === 'tideforge' ? 'Manage gear & bait' : 'Open tackle box'}</button>{/if}</li>{/each}</ul></div>
      {:else}<ol class="getting-started"><li>Open Discord and use <code>/fish</code> where Fishbound is available.</li><li>Link the same Discord account here.</li><li>Favorite a keeper and sell your spares from your tackle box.</li></ol>{/if}<p class="muted">251 species. Seven waters. Start with the 28 fish waiting in Meadow Pond.</p></div>
      {#if game.player}<div class="login"><button onclick={() => openView('inventory')}>Open tackle box →</button><button class="secondary" onclick={() => openView('collection')}>Open Fish Book →</button></div>{/if}
    </section>
    <TraderStall onclick={() => openView('trader')} />
  {/if}
  {#if visibleView === 'quests' && game.player}<LazyPanel active={true} load={() => import('./Quests.svelte')} componentProps={{ game }} />{/if}
  {#if game.player}
    {#if visibleView === 'inventory'}
    <section aria-labelledby="inventory"><div class="section-heading"><div><p class="eyebrow">KEEP THE GOOD ONES</p><h2 id="inventory">Your tackle box <span>{game.inventory.length} fish kept</span></h2></div></div>
      <div class="tackle-shortcuts"><button class="secondary" onclick={() => openRoute('tideforge', 'gear')}>Manage gear &amp; bait</button><button class="secondary" onclick={() => openRoute('tideforge', 'materials')}>Open materials</button></div>
        <div class="tackle-panel">
          <div class="fish-actions"><button class="secondary" disabled={!selectedOwned.length || game.busy || !game.ready} onclick={preview}>Preview sale ({saleBatch.length}{selectedOwned.length > saleBatchSize ? ' of ' + selectedOwned.length : ''})</button></div>
          {#if game.inventory.length}
            <div class="quick-select" aria-label="Quick fish selection">
              <label for="selection-rank">Quick select by rank<GameSelect id="selection-rank" label="Quick select by rank" value={selectionRank} options={[{value:"",label:"Choose a rank."},...ranks.map(rank=>({value:rank,label:rank+" ("+selectable.filter(fish=>fish.rarity===rank).length+")"}))]} disabled={game.busy || !game.ready} onchange={value => { selectionRank = value; }} /></label>
              <button class="secondary" disabled={!selectionRank || !selectable.some(fish => fish.rarity === selectionRank) || game.busy || !game.ready} onclick={() => quickSelect(selectionRank)}>Select rank</button>
              <button class="secondary" disabled={!selectable.length || game.busy || !game.ready} onclick={() => { selectionRank = ''; quickSelect(); }}>Select all</button>
              <button class="secondary" disabled={!selected.length || game.busy || !game.ready} onclick={() => { selectionRank = ''; selected = []; showQuote = false; }}>Clear selection</button>
              <p class="muted" aria-live="polite">{selectedOwned.length} selected across all pages. Favorites stay protected.{#if selectedOwned.length > saleBatchSize} Preview and sell up to {saleBatchSize} at a time; the rest stay selected.{/if}</p>
            </div>
          {/if}
          {#if quote}
            <div class="sale" role="status"><div><strong>Sell {quote.catchIds.length} selected {quote.catchIds.length === 1 ? 'catch' : 'catches'} for {quote.quotedCoins.toString()} coins?</strong><p>{quote.catchIds.map(id => { const fish = game.inventory.find(row => row.catchId === id); return '#' + id + ' ' + (fish ? speciesName(fish.speciesId) : '(catch changed)'); }).join(' · ')}</p><small>Discoveries and records remain. Favorites are protected. Preview expires at {time(quote.expiresAt.microsSinceUnixEpoch)}.</small>{#if game.serverNowMs === null}<p>Syncing server time…</p>{/if}</div><div class="sale-actions"><button disabled={game.busy || !game.ready || deadlineExpired(quote.expiresAt.microsSinceUnixEpoch, game.serverNowMs)} onclick={confirm}>Confirm sale · {quote.quotedCoins.toString()} coins</button><button class="secondary" onclick={() => { showQuote = false; }}>Cancel</button></div></div>
          {/if}
          {#if !game.inventory.length}<p class="empty">Your next catch could be the keeper. Try <code>/fish</code> in Discord.</p>{:else}<div class="catch-grid">
            {#each game.inventory.slice((inventoryPage - 1) * inventoryPageSize, inventoryPage * inventoryPageSize) as fish (fish.catchId)}
              <article class="catch" class:favorite={fish.favorite}><div class="catch-tools"><label><input type="checkbox" checked={selected.includes(fish.catchId)} disabled={fish.favorite || game.busy || !game.ready} onchange={() => select(fish.catchId)} /><span class="sr-only">Select catch #{fish.catchId.toString()}</span></label><button class="star" class:active={fish.favorite} disabled={game.busy || !game.ready} onclick={() => { showQuote = false; game.favorite(fish); }} aria-label={(fish.favorite ? 'Unfavorite' : 'Favorite') + ' catch #' + fish.catchId}>{fish.favorite ? '★' : '☆'}</button></div><div class="fish-art"><img src={sprite(fish.speciesId)} alt={speciesName(fish.speciesId)} /><span>{fish.rarity} RANK</span></div><p class="grade">{grades[fish.sizeGrade]}</p><h3>{speciesName(fish.speciesId)}</h3><p class="measure">{formatLength(fish.lengthMm)} <span>·</span> {formatWeight(fish.weightG)}</p><p class="relative-size">{(fish.lengthMm / (game.species.find(row => row.speciesId === fish.speciesId)?.typicalLengthMm ?? fish.lengthMm)).toFixed(2)}× typical length<br />{(Number(fish.weightG) / Number(game.species.find(row => row.speciesId === fish.speciesId)?.typicalWeightG ?? fish.weightG)).toFixed(2)}× typical weight</p><div class="catch-footer"><span>#{fish.catchId.toString()}</span><span>{fish.saleValueCoins.toString()} coins</span></div></article>
            {/each}
          </div>{/if}
          <PageTurner bind:page={inventoryPage} total={game.inventory.length} pageSize={inventoryPageSize} label="Tackle box pages" />
        </div>
    </section>

    {/if}
    {#if visibleView === 'journal'}
    <LazyPanel active={true} load={() => import('./Journal.svelte')} componentProps={{ game, pageSize }} />
    {/if}
  {/if}

  {#if visibleView === 'waters' && game.biomes.length}
    <section aria-labelledby="waters"><p class="eyebrow">SEVEN WATERS, ONE JOURNEY</p><h2 id="waters">Explore the biomes</h2>
      {#if game.player}<p class="muted">Equipped: {game.equippedGearLabel('rod') ?? currentRod?.name} · power {game.rodStats(currentRod?.rodId ?? 0).power}. Buy rods and permanent biome licences from the camp trader. Travel keeps your cast cooldown.</p>
        <button class="secondary" onclick={() => openRoute('tideforge', 'gear')}>Manage gear &amp; bait</button>
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
  {#key `${game.tideforgeIdentity ?? ''}:${game.player?.playerId ?? ''}`}
    <div hidden={visibleView !== 'tideforge' && visibleView !== 'trader'}>
      <LazyPanel active={visibleView === 'tideforge' || visibleView === 'trader'} load={() => import('./Tideforge.svelte')}
        componentProps={{ game, active: visibleView === 'tideforge' || visibleView === 'trader', presentation: visibleView === 'trader' ? 'shop' : 'workshop', requestedSection: activeSection, onNavigate: openRoute, pageSize }} />
    </div>
  {/key}
  <div hidden={visibleView !== 'records'}><LazyPanel active={visibleView === 'records'} load={() => import('./Records.svelte')} componentProps={{ game, pageSize }} /></div>
  {#if game.player}<div hidden={visibleView !== 'achievements'}><LazyPanel active={visibleView === 'achievements'} load={() => import('./Achievements.svelte')} componentProps={{ game, pageSize }} /></div>{/if}
  <div hidden={visibleView !== 'anglers'}><LazyPanel active={visibleView === 'anglers'} load={() => import('./Anglers.svelte')} componentProps={{ game, pageSize }} /></div>

  </div>
  <div class="view-panel" role="tabpanel" id="panel-compendium" aria-labelledby="tab-compendium" tabindex="0" hidden={visibleView !== 'compendium'}>
    <LazyPanel active={visibleView === 'compendium'} load={() => import('./Compendium.svelte')} componentProps={{ game, active: visibleView === 'compendium', requestedView: activeSection === 'collection' ? 'collection' : 'field-guide', onViewChange: (view: 'collection' | 'field-guide') => openRoute('compendium', view), collectionPageSize: pageSize }} />
  </div>

  <footer><span>Fishbound · One cast at a time.</span><div>{#if game.lastSync}<span>Updated {game.lastSync.toLocaleTimeString()}</span>{/if}{#if game.player}<button class="text-button" disabled={game.busy || !game.ready} use:tooltip={'Your Discord account and fishing progress stay saved. Link again to use them in this browser.'} onclick={() => game.logout()}>Sign out of this browser</button>{/if}<ThemeToggle /></div></footer>
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
  .connection>span{width:7px;height:7px;background:var(--site-muted)}.connection.live>span{background:var(--site-success)}
  .camp-banner{display:grid;grid-template-columns:1fr 1.1fr;gap:36px;align-items:stretch;margin:28px 0 32px;padding:30px;background:var(--site-panel);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 1px var(--site-border),0 6px 0 var(--site-base)}
  .camp-intro{padding:14px 8px;align-self:center;min-width:0}
  .eyebrow{font-family:var(--font-game);font-size:17px;letter-spacing:.06em;color:var(--ink);margin:0 0 8px}
  .camp-banner .eyebrow{color:var(--site-muted)}
  h1{font-family:var(--font-game);font-size:clamp(48px,4.4vw,72px);font-weight:700;line-height:1;margin:12px 0;color:var(--gold);text-shadow:3px 4px 0 var(--site-base)}
  .intro{font-size:21px;color:var(--site-text);margin:14px 0}
  .cast-hint{font-size:16px;color:var(--site-muted)}.cast-hint span{margin:0 6px}
  .cast-hint code{color:var(--site-text);background:var(--site-base);padding:3px 7px;border:1px solid var(--site-border)}
  .book-link{display:inline-flex;gap:8px;align-items:center;margin-top:12px;color:var(--site-text);font-size:16px;text-decoration:underline;text-underline-offset:3px}
  .hero-actions{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 8px}.discord-action{display:inline-flex;align-items:center;justify-content:center;border:2px solid var(--wood-dark);padding:10px 16px;background:var(--gold);color:var(--button-ink);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 3px 0 var(--wood-dark);font-family:var(--font-game);font-size:20px;text-decoration:none}.discord-action:hover{background:var(--button-hover)}.link-reason{display:block;color:var(--site-text);line-height:1.4}
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
  .login{display:flex;flex-direction:column;justify-content:center;gap:16px;max-width:380px}
  .getting-started,.next-steps ul{padding-left:22px;line-height:1.7}.next-steps h3{font-size:24px}.next-steps li{margin:6px 0}.inline-action{border:0;padding:0;background:transparent;box-shadow:none;color:var(--ink);font:inherit;text-decoration:underline;text-underline-offset:3px}.inline-action:hover:not(:disabled){background:transparent}
  .dev-login{margin-top:20px;font-size:14px;line-height:1.6;color:var(--site-text)}.dev-login-button{font-size:16px;padding:6px 12px}.dev-login-error{display:block;color:var(--site-text);margin-top:6px}
  .notice{padding:16px;border:3px solid var(--paper-edge);background:var(--panel-inset);color:var(--ink);font-size:17px}.notice.error{border-color:var(--danger-ink);color:var(--danger-ink)}
  .stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:0;padding:0;background:var(--wood);color:var(--site-text);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 5px 0 var(--site-base)}.stats::before,.stats::after{display:none}
  .progress-details>summary{display:none}
  .stats .timer-value{font-size:clamp(18px,1.5vw,32px)}.stats .coin-value{font-size:clamp(18px,2.2vw,30px)}
  @media(max-width:1200px){.stats{grid-template-columns:repeat(3,minmax(0,1fr))}.stats>div:nth-child(3n){border-right:0}.stats>div:nth-child(-n+3){border-bottom:2px solid var(--paper-edge)}.stats .timer-value{font-size:32px}}
  .stats>div{padding:20px 24px;border-right:2px solid var(--paper-edge);min-width:0}.stats>div:last-child{border:0}
  .stats span{font-family:var(--font-game);font-size:19px;color:var(--site-text)}.stats strong{font-family:var(--font-game);font-weight:500;font-size:42px;display:block;margin:8px 0;line-height:1.15;text-shadow:2px 2px 0 var(--site-base);overflow-wrap:anywhere}.stats em{font-size:26px;font-style:normal;color:var(--site-muted)}.stats small{font-size:14px;color:var(--site-text);display:block}
  progress{display:block;width:100%;height:8px;accent-color:var(--gold);border:1px solid var(--site-base);margin:8px 0;background:var(--site-base)}progress::-webkit-progress-bar{background:var(--site-base)}progress::-webkit-progress-value{background:var(--gold)}
  .section-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.section-heading h2{margin-bottom:0}
  .catch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px}
  .quick-select{display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;padding:16px;margin-bottom:20px;border:1px dashed var(--paper-edge);background:var(--panel-inset)}
  .quick-select label{display:flex;flex-direction:column;gap:6px;font-size:16px;font-weight:700;max-width:100%}
  .quick-select :global(.game-select){max-width:100%}
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
  .biome-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:22px}.biome-grid article{padding:20px;border:2px solid var(--paper-edge);background:var(--panel-inset);box-shadow:3px 4px 0 var(--wood-dark);position:relative;overflow:hidden}
  .biome-grid article::before{content:'';display:block;height:36px;margin:-20px -20px 18px;background:linear-gradient(155deg,transparent 35%,#456f58 36% 65%,transparent 66%),linear-gradient(30deg,#365c52 45%,#7b9865 46% 68%,#e8be81 69%);border-bottom:2px solid var(--paper-edge)}
  .biome-grid article:nth-child(3n)::before{filter:hue-rotate(25deg)}.biome-grid article:nth-child(4)::before{filter:hue-rotate(90deg)}.biome-grid article:nth-child(6)::before{filter:hue-rotate(140deg) saturate(.6)}.biome-grid article:nth-child(7)::before{filter:brightness(.7)}
  .biome-grid article.active{border-color:var(--control-accent);box-shadow:inset 0 0 0 2px var(--panel-highlight),3px 4px 0 var(--wood-dark)}.biome-grid small{font-family:var(--font-game);font-size:17px;color:var(--ink)}.biome-grid p{font-size:16px;color:var(--ink);line-height:1.7}.biome-grid button{margin-top:8px}
  footer{border-top:1px solid var(--paper-edge);margin-top:38px;padding:24px 0 32px;display:flex;justify-content:space-between;gap:16px;color:var(--site-muted);font-size:14px}footer>div{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center}.text-button{color:var(--site-text);background:transparent;border:0;font-family:var(--font-body);font-size:14px;padding:0;box-shadow:none}.text-button:hover:not(:disabled){background:transparent}
  .sr-only{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}
  @media(max-width:850px){main{padding:16px 18px 0}nav{gap:5px;padding:10px}.brand{font-size:26px}nav a{font-size:18px;padding:6px 8px}.connection{margin-left:auto}.camp-banner{gap:20px;padding:18px;grid-template-columns:1fr 1fr}.camp-intro{padding:0}h1{font-size:clamp(34px,5vw,44px)}.intro{font-size:18px}.cast-hint span{display:none}.book-link{font-size:18px;gap:10px}section{padding:22px}.stats>div{padding:18px 16px}.stats span{font-size:16px}.stats .timer-value{font-size:24px}}
  @media(max-width:600px){main{padding:12px 12px 0}nav{gap:2px}.brand{width:100%;margin:0 0 4px;font-size:26px}nav a{font-size:17px;padding:6px 7px}.connection{font-size:12px;padding:4px 6px}.camp-banner{grid-template-columns:1fr;padding:16px;margin:22px 0;gap:20px}.camp-intro{padding:8px}h1{font-size:clamp(28px,9vw,38px)}.cast-hint{font-size:14px}.welcome{grid-template-columns:1fr;gap:20px;padding:22px}.login{max-width:none}.stats{grid-template-columns:1fr 1fr}.stats>div:nth-child(odd){border-right:2px solid var(--paper-edge)}.stats>div:nth-child(even){border-right:0}.stats>div:nth-child(-n+4){border-bottom:2px solid var(--paper-edge)}.stats>div{padding:18px}.stats strong{font-size:clamp(18px,5.5vw,24px);overflow-wrap:anywhere}.stats .timer-value{font-size:clamp(18px,5.5vw,24px)}.section-heading{align-items:flex-start;flex-wrap:wrap}section{padding:20px;margin-top:22px}h2{font-size:30px}.catch-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.catch{padding:10px}.catch h3{font-size:20px;overflow-wrap:anywhere}.fish-art{height:105px}.fish-art img{width:95px;height:80px}.fish-art>span{font-size:14px}.measure{font-size:12px}.relative-size{font-size:12px}.catch-footer{font-size:12px}.sale-actions{flex-wrap:wrap}footer{flex-direction:column}footer>div{justify-content:space-between}}

  .compact-banner{padding:20px 30px;margin:20px 0;grid-template-columns:minmax(0,1fr) clamp(280px,30vw,360px);gap:20px}
  .compact-banner .camp-intro{padding:0}.compact-banner .eyebrow{margin-bottom:4px}.compact-banner h1{font-size:clamp(30px,3.2vw,46px);margin:0;line-height:1.1;overflow-wrap:anywhere}
  .camp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:24px 0 0;padding:10px;background:var(--wood-dark);border:2px solid var(--paper-edge);box-shadow:0 4px 0 var(--site-base);position:sticky;top:8px;z-index:5}
  .camp-tabs button{padding:10px 16px;font-size:21px;background:var(--wood);color:var(--site-text);border-color:var(--paper-edge);box-shadow:none}
  .camp-tabs button:hover:not(:disabled):not([aria-selected=true]){background:var(--nav-hover)}
  .camp-tabs button[aria-selected=true]{background:var(--paper);color:var(--ink);border-color:var(--paper-edge);box-shadow:inset 0 -3px 0 var(--panel-highlight)}
  .camp-tabs button:disabled{opacity:.45;filter:none;box-shadow:none}
  .disabled-nav{display:inline-flex;min-width:0}.disabled-nav:focus-visible{outline:3px solid var(--site-focus);outline-offset:2px}.disabled-nav>button{pointer-events:none}
  .link-notice{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:12px 0;color:var(--site-text);font-size:14px}.link-notice .text-button{text-decoration:underline;text-underline-offset:3px}
  .tackle-panel{min-width:0}
  .tackle-shortcuts{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
  .fish-actions{display:flex;justify-content:flex-end;margin-bottom:20px}
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
    .mobile-navigation .disabled-nav>button{width:100%}
    .hero-actions{flex-direction:column}.hero-actions>*{width:100%;box-sizing:border-box}
    .compact-banner{grid-template-columns:1fr;padding:12px;margin:12px 0}.compact-banner h1{font-size:30px}.compact-banner .eyebrow{display:none}
    .progress-details:not(.camp-progress)>summary{display:block;padding:10px 12px;border:2px solid var(--paper-edge);background:var(--wood);color:var(--site-text);cursor:pointer;line-height:1.4}.progress-details:not(.camp-progress) .stats{margin-top:8px}
    .camp-tabs{display:none}.view-panel{margin-top:14px}
    .fish-actions{justify-content:stretch}
    .fish-actions button{width:100%}
  }
  nav,.camp-tabs,footer,.dev-login{--control-focus:var(--site-focus)}
  .book-link:focus-visible,.discord-action:focus-visible,.progress-details>summary:focus-visible,.view-panel:focus-visible{outline:3px solid var(--site-focus);outline-offset:2px}
</style>
