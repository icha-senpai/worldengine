<script lang="ts">
  import type { Game } from './game.svelte.ts';
  import type { RaidAttempt, RaidGradeDefinition, SpeciesDefinition } from '@fishing-game/generated/types';
  import { onMount, tick } from 'svelte';
  import PageTurner from './PageTurner.svelte';
  import RaidArt from './RaidArt.svelte';
  import RaidResultDialog from './RaidResultDialog.svelte';
  import GameSelect from './GameSelect.svelte';
  import SearchInput from './SearchInput.svelte';
  import { tooltip } from './tooltip';
  import { ServerClock } from './server-clock';
  import policy from '../../../../../content/raids.json';
  import SectionTabs from './SectionTabs.svelte';
  import { clearedGradeCount, gradeCleared, gradeSelectable, nextSelectableGrade } from './raid-progress';
  import { raidResultSummary } from './raid-result';

  type RaidMode = 'raids' | 'community';
  type RaidSection = 'active' | 'personal' | 'special';
  let { game, mode = 'raids', onOpenActive }: {
    game: Game;
    mode?: RaidMode;
    onOpenActive?: () => void;
  } = $props();
  const raidTabs: { id: RaidSection; label: string }[] = [
    { id: 'active', label: 'Active' },
    { id: 'personal', label: 'Personal Raids' },
    { id: 'special', label: 'Special Encounters' }
  ];
  let raidSection = $state<RaidSection>('active');
  const clock = new ServerClock();
  let clockRevision = $state(0);
  let clockFailed = $state(false);
  const serverNowMs = $derived.by(() => {
    clockRevision;
    return clock.now();
  });
  const serverNowMicros = $derived(serverNowMs === null ? null : BigInt(Math.floor(serverNowMs)) * 1000n);
  const timeAvailable = $derived(serverNowMs !== null);
  const timeMessage = $derived(timeAvailable ? '' : clockFailed ? 'Server time is unavailable.' : 'Syncing server time…');
  let search = $state('');
  let biome = $state(0);
  let page = $state(1);
  let pageSize = 10;
  let withdrawAttempt = $state<RaidAttempt | null>(null);
  let resultAttempt = $state<RaidAttempt | null>(null);
  let withdrawDialog: HTMLDialogElement;
  let withdrawBusy = $state(false);
  let historyPage = $state(1);
  type RaidTimestamp = { microsSinceUnixEpoch: bigint };
  const ranks = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  const supplies = $derived(game.items.find(row => row.item === policy.supply.item)?.quantity ?? 0n);
  const boatReady = $derived(!!game.haulLoadout?.boatId && game.ownedHaulEquipment.some(row => row.equipmentId === game.haulLoadout?.boatId));
  const discoveredOrdinary = $derived(game.species.filter(row => row.countsForOrdinaryCollectionCompletion
    && (game.collection.find(progress => progress.speciesId === row.speciesId)?.count ?? 0n) > 0n));
  const ordinary = $derived(discoveredOrdinary.filter(row =>
    (!biome || row.biomeId === biome)
    && row.name.toLowerCase().includes(search.trim().toLowerCase())));
  const specialSpecies = $derived([74, 165].map(id => game.species.find(row => row.speciesId === id)).filter((row): row is SpeciesDefinition => !!row));
  const personalPages = $derived(Math.max(1, Math.ceil(ordinary.length / pageSize)));
  const personalPage = $derived(ordinary.slice((page - 1) * pageSize, page * pageSize));
  const attempts = $derived(game.raidAttempts.slice().sort((a, b) =>
    a.preparedAt.microsSinceUnixEpoch > b.preparedAt.microsSinceUnixEpoch ? -1 : 1));
  const activeAttempts = $derived(attempts.filter(row => ['prepared', 'deployed', 'running'].includes(row.status)));
  const terminalAttempts = $derived(attempts
    .filter(row => !['prepared', 'deployed', 'running'].includes(row.status))
    .sort((a, b) => {
      const aTime = a.settledAt?.microsSinceUnixEpoch ?? a.preparedAt.microsSinceUnixEpoch;
      const bTime = b.settledAt?.microsSinceUnixEpoch ?? b.preparedAt.microsSinceUnixEpoch;
      return aTime > bTime ? -1 : 1;
    }));
  const historyPages = $derived(Math.max(1, Math.ceil(terminalAttempts.length / 10)));
  const terminalPage = $derived(terminalAttempts.slice((historyPage - 1) * 10, historyPage * 10));
  const activeRaid = $derived(activeAttempts[0]);
  $effect(() => { if (page > personalPages) page = personalPages; });
  $effect(() => { if (historyPage > historyPages) historyPage = historyPages; });
  const communityStats = $derived(game.raidStatistics.find(row => row.playerId === game.player?.playerId));
  const gradeRow = (grade: number) => game.raidGrades.find(row => row.grade === grade);
  const gradeName = (grade: number) => gradeRow(grade)?.name ?? ranks[grade] ?? 'Raid';
  const badgeFor = (speciesId: number) => game.raidBadges.find(row =>
    row.playerId === game.player?.playerId && row.speciesId === speciesId);
  function highestClear(speciesId: number) {
    return clearedGradeCount(badgeFor(speciesId)?.clearedGrades) - 1;
  }
  const discovered = (speciesId: number) =>
    (game.collection.find(row => row.speciesId === speciesId)?.count ?? 0n) > 0n;
  const localDate = (timestamp: RaidTimestamp) => new Date(Number(timestamp.microsSinceUnixEpoch / 1000n)).toLocaleString();
  const utcDate = (timestamp: RaidTimestamp) => new Date(Number(timestamp.microsSinceUnixEpoch / 1000n)).toLocaleString(undefined, {
    timeZone: 'UTC', timeZoneName: 'short'
  });
  function remaining(timestamp: RaidTimestamp) {
    if (serverNowMs === null) return clockFailed ? 'Time unavailable' : 'Syncing server time…';
    const seconds = Math.max(0, Math.ceil((Number(timestamp.microsSinceUnixEpoch / 1000n) - serverNowMs) / 1000));
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return days ? `${days}d ${hours}h` : hours ? `${hours}h ${minutes}m` : `${minutes}m`;
  }
  const mats = (grade: RaidGradeDefinition) =>
    `${grade.fillets} fillets + ${grade.scales} scales`;
  const maximumBonus = (value: bigint) => (value / BigInt(policy.rewardBonusDivisor)).toString();
  function nextGrade(speciesId: number) {
    return nextSelectableGrade(badgeFor(speciesId)?.clearedGrades);
  }
  function alreadyJoined(eventId: bigint) {
    return attempts.some(row => row.kind === 'community' && row.eventId === eventId
      && row.status !== 'cancelled');
  }
  function signupOpen(event: { signupAt: RaidTimestamp; startsAt: RaidTimestamp }) {
    return serverNowMicros !== null && serverNowMicros >= event.signupAt.microsSinceUnixEpoch
      && serverNowMicros < event.startsAt.microsSinceUnixEpoch;
  }
  function due(attempt: RaidAttempt): boolean | null {
    if (serverNowMicros === null) return null;
    return serverNowMicros >= attempt.endsAt.microsSinceUnixEpoch;
  }
  async function showActiveRaid() {
    raidSection = 'active';
    onOpenActive?.();
    await tick();
    document.getElementById('raid-tab-active')?.focus();
  }
  async function prepare(speciesId: number, grade: number) {
    if (await game.prepareRaid(speciesId, grade)) await showActiveRaid();
  }
  async function prepareCommunity(eventId: bigint) {
    if (await game.prepareCommunityRaid(eventId)) await showActiveRaid();
  }
  async function deploy(attempt: RaidAttempt) {
    await game.deployRaid(attempt.id);
  }
  async function claim(attempt: RaidAttempt) {
    await game.claimRaid(attempt.id);
  }
  function requestWithdraw(attempt: RaidAttempt) {
    withdrawAttempt = attempt;
    withdrawDialog.showModal();
  }
  async function confirmWithdraw() {
    if (!withdrawAttempt || withdrawBusy || game.busy || !game.ready) return;
    withdrawBusy = true;
    try {
      if (await game.cancelRaid(withdrawAttempt.id)) {
        withdrawDialog.close();
        withdrawAttempt = null;
      }
    } finally {
      withdrawBusy = false;
    }
  }
  onMount(() => {
    let request: AbortController | null = null;
    let disposed = false;
    async function synchronize() {
      if (request || document.visibilityState !== 'visible') return;
      const controller = new AbortController();
      request = controller;
      const timeout = setTimeout(() => controller.abort(), 5000);
      const sent = performance.now();
      try {
        const response = await fetch('/api/time', { cache: 'no-store', signal: controller.signal });
        if (!response.ok) throw new Error('Server clock unavailable');
        const sample = await response.json();
        const received = performance.now();
        if (disposed) return;
        clock.synchronize(sample.serverTimeMs, sent, received);
        clockFailed = false;
        clockRevision++;
      } catch {
        if (!disposed) {
          clockFailed = true;
          clockRevision++;
        }
      } finally {
        clearTimeout(timeout);
        request = null;
      }
    }
    function resume() {
      if (document.visibilityState !== 'visible') return;
      clockRevision++;
      void synchronize();
    }
    void synchronize();
    const ticker = setInterval(() => clockRevision++, 1000);
    const syncTimer = setInterval(() => { void synchronize(); }, 60_000);
    document.addEventListener('visibilitychange', resume);
    window.addEventListener('pageshow', resume);
    return () => {
      disposed = true;
      request?.abort();
      clearInterval(ticker);
      clearInterval(syncTimer);
      document.removeEventListener('visibilitychange', resume);
      window.removeEventListener('pageshow', resume);
    };
  });
</script>

<section class="raid-board" aria-label="Raid expeditions">
  <header class="board-head">
    <div><p class="eyebrow">BOAT EXPEDITIONS</p><h2>{mode === 'community' ? 'Community Expeditions' : 'Raids'}</h2>
      <p>{mode === 'community' ? 'Join the shared expedition calendar; signup prepares an attempt, but deployment commits your boat and supplies.' : 'Preparing does not reserve your boat. Deploy explicitly before the countdown or the attempt is missed. Supplies are spent only on deployment.'}</p>
    </div>
    <p class="supply"><span class="supply-art" aria-hidden="true"><RaidArt kind="supply" size={40} /></span><small>EXPEDITION CHUM</small><strong>{supplies.toString()}</strong></p>
  </header>

  {#if !game.raidsSupported}
    <p class="notice" role="status">Raids are awaiting the module update. Your current game connection remains available.</p>
  {:else}
    {#if game.error}<p class="notice error" role="alert">{game.error}</p>{/if}
    {#if game.raidActionMessage}<p class="notice" role="status">{game.raidActionMessage}</p>{/if}
    {#if game.pendingRaidIntent}<p class="notice" role="status">{game.raidActionUnconfirmed ? 'A raid action is awaiting confirmation. Retry that same action after reconnecting.' : 'The raid action was acknowledged; syncing its saved receipt.'} A different raid action is locked until this intent is resolved.</p>{/if}
    {#if !timeAvailable}<p class="notice" role="status">{timeMessage} Signup, deployment, and due-claim actions remain disabled until time synchronizes.</p>{/if}

    {#if mode === 'raids'}
    <SectionTabs tabs={raidTabs} value={raidSection} prefix="raid" label="Raid sections" onselect={value => raidSection = value} />
    {#if raidSection === 'active'}
    <div class="attempts raid-tab-panel" role="tabpanel" id="raid-panel-active" aria-labelledby="raid-tab-active" tabindex="0">
      <h3 id="attempts-heading">Active expeditions</h3>
      {#if activeAttempts.length}
        {#each activeAttempts as attempt (attempt.id)}
          {@const claimDue = due(attempt)}
          {@const fish = attempt.speciesId ? game.species.find(row => row.speciesId === attempt.speciesId) : undefined}
          <article class="attempt-card" class:with-fish={!!fish} data-status={attempt.status}>
            {#if fish}<div class="raid-fish-art"><img src={fish.spriteAsset} alt={fish.name} loading="lazy" /></div>{/if}
            <div class="attempt-copy"><p class="eyebrow">{attempt.kind === 'community' ? 'COMMUNITY EXPEDITION' : attempt.kind === 'special' ? 'SPECIAL ENCOUNTER' : `${gradeName(attempt.grade ?? 0)} RAID`}</p>
              <h4>{fish?.name ?? (attempt.kind === 'community' ? game.communityRaids.find(row => row.eventId === attempt.eventId)?.name ?? 'Community expedition' : 'Raid target')}</h4>
              <p>Status: <strong>{attempt.status}</strong> · Boat pinned: {attempt.boatName}</p>
              <p>Starts {localDate(attempt.startsAt)} local ({utcDate(attempt.startsAt)}); ends {localDate(attempt.endsAt)} local ({utcDate(attempt.endsAt)}).</p>
              {#if attempt.status === 'prepared'}<p>Deployment window closes in {remaining(attempt.deploymentDeadline)}. This prepared attempt does not reserve your boat.</p>{/if}
              {#if attempt.status === 'deployed'}<p>Launch in {remaining(attempt.startsAt)}. Your boat is reserved.</p>{/if}
              {#if attempt.status === 'running'}<p>Expedition underway · {remaining(attempt.endsAt)} remaining. Your boat is reserved.</p>{/if}
              {#if attempt.kind === 'personal' && attempt.grade !== undefined}<p>Guaranteed at completion: <RaidArt kind="fillets" grade={attempt.grade} size={24} /> {attempt.baseFillets} fillets + <RaidArt kind="scales" grade={attempt.grade} size={24} /> {attempt.baseScales} scales; random bonus capped at {maximumBonus(attempt.baseFillets)} fillets + {maximumBonus(attempt.baseScales)} scales. Supplies are not refunded after deployment.</p>{/if}
              {#if attempt.kind === 'community'}<p>Community expeditions award completion statistics only: no fish, materials, XP, coins, or boat/net catch bonuses.</p>{/if}
              {#if attempt.awardedFillets || attempt.awardedScales}<p>Confirmed rewards: {#if attempt.kind === 'personal' && attempt.grade !== undefined}<RaidArt kind="fillets" grade={attempt.grade} size={24} /> <RaidArt kind="scales" grade={attempt.grade} size={24} /> {/if}{attempt.awardedFillets} fillets + {attempt.awardedScales} scales.</p>{/if}
            </div>
            <div class="attempt-actions">
              {#if attempt.status === 'prepared'}
                <button disabled={!game.ready || game.busy || !timeAvailable} onclick={() => deploy(attempt)}>Deploy boat</button>
                <button class="secondary" disabled={!game.ready || game.busy} onclick={() => requestWithdraw(attempt)}>Cancel preparation</button>
              {:else if (attempt.status === 'deployed' || attempt.status === 'running') && claimDue === true}
                <button disabled={!game.ready || game.busy || !timeAvailable} onclick={() => claim(attempt)}>Claim due rewards</button>
              {:else if (attempt.status === 'deployed' || attempt.status === 'running') && claimDue === null}
                <span role="status">{timeMessage}</span>
              {:else if attempt.status === 'deployed'}
                <button class="secondary" disabled={!game.ready || game.busy} onclick={() => requestWithdraw(attempt)}>Withdraw before launch</button>
              {:else if attempt.status === 'running'}
                <button class="secondary" disabled={!game.ready || game.busy} onclick={() => requestWithdraw(attempt)}>Withdraw (partial materials only)</button>
              {/if}
            </div>
          </article>
        {/each}
      {:else}<p class="empty">No active raid. Choose Personal Raids, Special Encounters or Community Expeditions to prepare an expedition.</p>{/if}

    {#if terminalAttempts.length}
      <details class="raid-history">
        <summary><span>Terminal raid history · {terminalAttempts.length}</span><svg class="history-chevron" viewBox="0 0 12 8" aria-hidden="true"><path d="m1 1 5 5 5-5" /></svg></summary>
        <div class="history-list">
          {#each terminalPage as attempt (attempt.id)}
            {@const result = raidResultSummary(attempt, game.species, game.communityRaids, game.inventory)}
            <article class="history-card" class:with-fish={!!result.speciesAsset} data-status={attempt.status}>
              {#if result.speciesAsset}<div class="raid-fish-art"><img src={result.speciesAsset} alt={result.targetName} loading="lazy" /></div>{/if}
              <div class="history-copy"><small>{result.gradeLabel ?? result.kindLabel}</small>
                <strong>{result.targetName}</strong>
                <span>{result.statusLabel} · {localDate(attempt.settledAt ?? attempt.preparedAt)}{#if attempt.kind === 'personal'} · {#if attempt.grade !== undefined}<RaidArt kind="fillets" grade={attempt.grade} size={20} /> <RaidArt kind="scales" grade={attempt.grade} size={20} /> {/if}{result.fillets} fillets + {result.scales} scales{/if}</span>
              </div>
              <button onclick={() => resultAttempt = attempt}>View result</button>
            </article>
          {/each}
        </div>
        <PageTurner bind:page={historyPage} total={terminalAttempts.length} pageSize={10} label="Terminal raid history pages" />
      </details>
    {/if}
    </div>
    {:else if raidSection === 'personal'}
    <div class="personal-raids raid-tab-panel" role="tabpanel" id="raid-panel-personal" aria-labelledby="raid-tab-personal" tabindex="0">
      <h3 id="personal-heading">Personal raids</h3>
      <p>Clear each grade in order. Repeating a clear earns materials again but does not unlock another seal. Withdrawn raids award only the floored time-scaled materials; prepared or missed attempts award nothing.</p>
      <div class="target-filters">
        <div class="filter-field"><label for="raid-fish-search">Search discovered fish</label><SearchInput id="raid-fish-search" label="Search discovered fish" value={search} oninput={value => {search = value; page = 1;}} /></div>
        <div class="filter-field"><label for="raid-biome-filter">Biome</label><GameSelect id="raid-biome-filter" label="Biome" value={biome} options={[{value:0,label:"All waters"},...game.biomes.map(row=>({value:row.biomeId,label:row.name}))]} onchange={value => {biome = value; page = 1;}} /></div>
      </div>
      <div class="raid-list">
        {#each personalPage as fish (fish.speciesId)}
          {@const clear = highestClear(fish.speciesId)}
          {@const grade = nextGrade(fish.speciesId)}
          {@const definition = gradeRow(grade)}
          <article class="raid-target with-fish">
            <div class="raid-fish-art"><img src={fish.spriteAsset} alt={fish.name} loading="lazy" /></div>
            <div class="target-copy"><p class="eyebrow">PERSONAL TARGET · {fish.biomeId === 0 ? 'UNKNOWN WATER' : game.biomes.find(row => row.biomeId === fish.biomeId)?.name}</p>
              <h4>{fish.name}</h4><p>Highest clear: {clear < 0 ? 'none' : gradeName(clear)} · {badgeFor(fish.speciesId)?.successfulClears ?? 0n} successful clears</p>
              {#if definition}<p>Next: {definition.name} · {Math.ceil(definition.durationSeconds / 3600)}h · {definition.supplyUnits} Expedition Chum · guaranteed <RaidArt kind="fillets" grade={definition.grade} size={24} /> <RaidArt kind="scales" grade={definition.grade} size={24} /> {mats(definition)} · random bonus capped at {maximumBonus(definition.fillets)} fillets + {maximumBonus(definition.scales)} scales.</p>{/if}
            </div>
            <div class="grade-actions">
              {#each game.raidGrades as row (row.grade)}
                {@const unlocked = row.grade <= grade}
                <button class:cleared={gradeCleared(badgeFor(fish.speciesId)?.clearedGrades, row.grade)}
                  disabled={!gradeSelectable(badgeFor(fish.speciesId)?.clearedGrades, row.grade) || !game.ready || game.busy || !!activeRaid || !boatReady || supplies < BigInt(row.supplyUnits)}
                  use:tooltip={!unlocked ? 'Clear the previous grade first' : activeRaid ? 'Settle or withdraw your active raid first' : !boatReady ? 'Equip a boat first' : supplies < BigInt(row.supplyUnits) ? 'Buy more Expedition Chum' : undefined}
                  onclick={() => prepare(fish.speciesId, row.grade)}><RaidArt kind="seal" grade={row.grade} size={24} /> Prepare {row.name}</button>
              {/each}
            </div>
          </article>
        {/each}
      </div>
      {#if personalPages > 1}<div class="pages"><button disabled={page <= 1} onclick={() => page--}>Previous fish</button><span>Page {page} of {personalPages}</span><button disabled={page >= personalPages} onclick={() => page++}>Next fish</button></div>{/if}
      {#if !ordinary.length}<p class="empty">{discoveredOrdinary.length ? 'No discovered targets match these filters.' : 'Discover ordinary fish with a normal catch before preparing their raids.'}</p>{/if}
    </div>

    {:else}
    <div class="special-raids raid-tab-panel" role="tabpanel" id="raid-panel-special" aria-labelledby="raid-tab-special" tabindex="0">
      <h3 id="special-heading">Special encounters</h3>
      <p>Each special raid is a two-day encounter for one fish. Fihs’s UUR perfect still requires the joint maximum roll and natural dimensions; the Sock’s maximum is F-rank and is never an official UUR perfect.</p>
      {#each specialSpecies as fish (fish.speciesId)}
        {@const found = discovered(fish.speciesId)}
        <article class="special-card with-fish">
          <div class="raid-fish-art"><img src={fish.spriteAsset} alt={fish.name} loading="lazy" /></div>
          <div class="target-copy"><p class="eyebrow">ONE EXCEPTIONAL ENCOUNTER</p><h4>{fish.name}</h4>
            <p>{found ? 'Discovered. One fish on a successful encounter.' : 'Discover this species in a normal catch to unlock its raid.'} · 48 hours · {policy.special.supplyUnits} Expedition Chum. One completed catch activity, never a bonus pull; no bait is used and the cast cooldown is unchanged. No grade ladder or graded materials.</p>
            {#if fish.speciesId === 74}<p>Maximum measurements use a dedicated 1-in-{policy.special.maximumRollOneIn} roll plus natural dimensions.</p>{/if}
          </div>
          <button disabled={!found || !!activeRaid || !boatReady || supplies < BigInt(policy.special.supplyUnits) || !game.ready || game.busy}
            onclick={() => prepare(fish.speciesId, 0)}>Prepare special encounter</button>
        </article>
      {/each}
    </div>

    {/if}
    {:else}
      {#if activeRaid}<button class="view-active" onclick={showActiveRaid}>View active expedition</button>{/if}
    <section class="community-raids" aria-labelledby="community-heading">
      <h3 id="community-heading">Community expeditions</h3>
      <p>Two UTC signup windows each month, four days underway, and a boat commitment. Only full completion increments your community count; no loot or fishing progress is awarded.</p>
      <p>Your completed community expeditions: {communityStats?.communityCompletions ?? 0n}</p>
      {#each game.communityRaids as event (event.eventId)}
        {@const signupIsOpen = signupOpen(event)}
        <article class="community-card">
          <div><p class="eyebrow">COMMUNITY CALENDAR</p><h4>{event.name}</h4>
            <p>Signup: {localDate(event.signupAt)} local ({utcDate(event.signupAt)}). Starts: {localDate(event.startsAt)} local ({utcDate(event.startsAt)}). Ends: {utcDate(event.endsAt)}.</p>
            {#if signupIsOpen}<p>Signup closes in {remaining(event.startsAt)}. The boat is committed only after deployment.</p>{/if}
          </div>
          <button disabled={!timeAvailable || !signupIsOpen || alreadyJoined(event.eventId) || !!activeRaid || !boatReady || supplies < BigInt(policy.community.supplyUnits) || !game.ready || game.busy}
            onclick={() => prepareCommunity(event.eventId)}>{!timeAvailable ? timeMessage : alreadyJoined(event.eventId) ? 'Already joined' : signupIsOpen ? 'Prepare community expedition' : 'Signup closed'}</button>
        </article>
      {/each}
    </section>
    {/if}
  {/if}
</section>

<RaidResultDialog {game} bind:attempt={resultAttempt} />

<dialog class="withdraw-dialog" bind:this={withdrawDialog} aria-labelledby="withdraw-title" onclose={() => withdrawAttempt = null}>
  {#if withdrawAttempt}
    <h2 id="withdraw-title">Withdraw from this expedition?</h2>
    <p>Prepared attempts use no supplies. A deployed expedition does not refund its supplies. A running personal raid pays only floored time-scaled materials and grants no badge; special and community expeditions grant no withdrawal rewards.</p>
    {#if game.error}<p class="error" role="alert">{game.error}</p>{/if}
    <div class="withdraw-actions"><button class="game-button game-button--secondary" disabled={withdrawBusy || game.busy} onclick={() => withdrawDialog.close()}>Keep expedition</button><button class="game-button" disabled={withdrawBusy || game.busy || !game.ready} onclick={confirmWithdraw}>{withdrawBusy ? 'Withdrawing…' : 'Confirm withdrawal'}</button></div>
  {/if}
</dialog>

<style>
  .raid-board{display:grid;gap:24px;margin:20px 0;padding:22px;background:var(--paper-light);border:2px solid var(--paper-edge);color:var(--ink)}
  .board-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.eyebrow{font-size:13px;letter-spacing:1.2px;color:var(--ink-muted);margin:0 0 6px}.board-head h2,.raid-board h3,.raid-board h4{font-family:var(--font-game);font-weight:400;margin:0}.board-head h2{font-size:34px}.raid-board h3{font-size:27px;margin-bottom:8px}.raid-board h4{font-size:23px;overflow-wrap:anywhere}.board-head p,.raid-board article p,.raid-board>section>p{font-size:15px;line-height:1.65;margin:8px 0 0;color:var(--ink-muted)}.supply{display:grid;grid-template-columns:auto minmax(0,1fr);grid-template-rows:auto auto;align-items:center;gap:4px 10px;min-width:150px;max-width:100%;padding:10px;background:var(--panel-inset);box-sizing:border-box;text-align:left}.supply-art{grid-column:1;grid-row:1 / span 2;display:grid;place-items:center;align-self:stretch}.supply small{grid-column:2;grid-row:1;font-size:12px;overflow-wrap:anywhere}.supply strong{grid-column:2;grid-row:2;font-family:var(--font-game);font-size:27px;text-align:right;white-space:nowrap}
  .raid-board section,.raid-board .raid-tab-panel{display:grid;gap:12px}.attempt-card,.raid-target,.special-card,.community-card{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:center;padding:16px;border:1px solid var(--paper-edge);background:var(--panel-inset)}.attempt-card[data-status="running"]{border-color:var(--success-ink)}
  .attempt-card.with-fish,.raid-target.with-fish,.special-card.with-fish{grid-template-columns:72px minmax(0,1fr) auto}.attempt-copy,.target-copy,.history-copy{min-width:0}.raid-fish-art{display:grid;place-items:center;width:72px;height:72px;background:var(--paper-light);border:1px solid var(--paper-edge);box-shadow:inset 0 0 0 2px var(--panel-highlight)}.raid-fish-art img{width:100%;height:100%;padding:5px;object-fit:contain;image-rendering:pixelated}
  .raid-history{border:1px solid var(--paper-edge);background:var(--panel-inset)}.raid-history summary{padding:12px;font-family:var(--font-game);cursor:pointer}.history-list{display:grid;gap:8px;padding:0 12px 12px}.history-card{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:10px;background:var(--paper-light);border:1px solid var(--paper-edge)}.history-card.with-fish{grid-template-columns:56px minmax(0,1fr) auto}.history-card .raid-fish-art{width:56px;height:56px}.history-copy{display:grid;gap:3px}.history-card small{font-size:12px;color:var(--ink-muted)}.history-card strong{font-family:var(--font-game);font-weight:400;overflow-wrap:anywhere}.history-card span{font-size:13px;color:var(--ink-muted)}.history-card button{flex:none}
  .attempt-actions,.grade-actions,.pages{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.grade-actions{justify-content:flex-end;max-width:460px}.raid-board button{font-family:var(--font-game);font-size:15px;padding:8px 11px;background:var(--selected-background);color:var(--selected-ink);border:1px solid var(--paper-edge)}.raid-board button.secondary{background:var(--paper-light);color:var(--ink)}.raid-board button:disabled{opacity:.5}.raid-board button.cleared{outline:2px solid var(--success-ink)}
  .target-filters{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;max-width:700px;min-width:0}.target-filters .filter-field{display:grid;gap:5px;min-width:0}.target-filters :global(.game-search),.target-filters :global(.game-select){width:100%;max-width:100%;min-width:0;box-sizing:border-box}.target-copy h4{overflow-wrap:anywhere}
  .pages{justify-content:center}.pages span{font-size:14px}.notice,.empty{padding:12px;background:var(--panel-inset);border:1px solid var(--paper-edge);line-height:1.6}.notice.error,.error{color:var(--danger-ink)}dialog{width:min(520px,calc(100% - 24px));padding:22px;background:var(--paper-light);color:var(--ink);border:3px solid var(--paper-edge)}dialog::backdrop{background:var(--dialog-backdrop)}
  @media(max-width:700px){.board-head{flex-direction:column}.attempt-card,.raid-target,.special-card,.community-card{grid-template-columns:1fr}.attempt-card.with-fish,.raid-target.with-fish,.special-card.with-fish{grid-template-columns:64px minmax(0,1fr)}.attempt-card.with-fish .attempt-actions,.raid-target.with-fish .grade-actions,.special-card.with-fish>button{grid-column:1 / -1}.raid-fish-art{width:64px;height:64px}.grade-actions{justify-content:flex-start}.target-filters{grid-template-columns:minmax(0,1fr)}.raid-board{padding:14px}.supply{grid-template-columns:auto minmax(0,1fr);width:100%}.supply strong{text-align:right}.history-card{grid-template-columns:1fr}.history-card.with-fish{grid-template-columns:56px minmax(0,1fr)}.history-card.with-fish button{grid-column:1 / -1}.history-card button{width:100%}}
  @media(max-width:700px){.attempt-card.with-fish,.raid-target.with-fish,.special-card.with-fish{row-gap:8px;column-gap:18px}.attempt-copy,.target-copy{display:contents}.attempt-copy>.eyebrow,.attempt-copy>h4,.target-copy>.eyebrow,.target-copy>h4{grid-column:2}.attempt-copy>p:not(.eyebrow),.target-copy>p:not(.eyebrow){grid-column:1 / -1}.attempt-card.with-fish>.raid-fish-art,.raid-target.with-fish>.raid-fish-art,.special-card.with-fish>.raid-fish-art{grid-row:1 / span 2;align-self:start}}
  .withdraw-dialog{width:min(640px,calc(100% - 32px));max-height:calc(100dvh - 32px);padding:28px;border:4px solid var(--wood-dark,#3c2d21);background:var(--paper,#f3e7c4);color:var(--ink,#354239);box-shadow:inset 0 0 0 3px var(--panel-highlight),0 12px 0 color-mix(in srgb,var(--wood-dark) 50%,transparent);overflow:auto;overscroll-behavior:contain;box-sizing:border-box}
  .withdraw-dialog::backdrop{background:var(--dialog-backdrop)}.withdraw-dialog h2{margin:0 0 18px;font-family:var(--font-game);font-size:30px;font-weight:400}.withdraw-dialog p{font-size:16px;line-height:1.6}
  .withdraw-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:22px}.withdraw-actions button{max-width:100%;overflow-wrap:anywhere}
  @media(max-width:600px){.withdraw-dialog{width:calc(100% - 20px);max-height:calc(100dvh - 20px);padding:18px}.withdraw-dialog h2{font-size:26px}.withdraw-actions{flex-direction:column-reverse}.withdraw-actions button{width:100%}}
.raid-history summary{display:flex;align-items:center;justify-content:space-between;gap:10px;list-style:none}.raid-history summary::-webkit-details-marker{display:none}.raid-history summary::marker{content:""}.history-chevron{width:14px;height:10px;flex:none;fill:none;stroke:currentColor;stroke-width:2;transition:transform .15s}.raid-history[open] .history-chevron{transform:rotate(180deg)}</style>
