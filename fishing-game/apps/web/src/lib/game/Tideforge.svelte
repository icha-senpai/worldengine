<script lang="ts">
  import { untrack } from 'svelte';
  import { deadlineExpired } from './server-clock';
  import type { Game } from './game.svelte';
  import type { TideforgeEconomyQuote } from '@fishing-game/generated/types';
  import type { TideforgeIntent, TideforgePreviewRoll } from './tideforge-intents';
  import { currentTideforgeForm, parseTideforgePreview } from './tideforge-intents';
  import { affixName, gearLabel, gearName, signatureName } from './tideforge-ui';
  import { formatLength, formatWeight } from './measurements';
  import { strengthHelp, tideforgeActionLabel, tideforgeConfirmed, tideforgeMaterialLabel, tideforgeUnconfirmed } from './tideforge-copy';
  import InfoButton from './InfoButton.svelte';
  import SectionTabs from './SectionTabs.svelte';
  import TideforgeGear from './TideforgeGear.svelte';
  import TideforgeForge from './TideforgeForge.svelte';
  import TideforgeProcessing from './TideforgeProcessing.svelte';
  import TideforgeTrader from './TideforgeTrader.svelte';
  import TideforgeRecords from './TideforgeRecords.svelte';
  import TideforgeReliquary from './TideforgeReliquary.svelte';
  import Trader from './Trader.svelte';
  type Section = 'gear' | 'forge' | 'processing' | 'vault' | 'codex' | 'reliquary' | 'camp' | 'additional' | 'wandering';
  let { game, active, presentation, requestedSection, onNavigate, pageSize = 12 }: {
    game: Game; active: boolean; presentation: 'workshop' | 'shop'; requestedSection?: string;
    onNavigate: (view: 'tideforge' | 'trader', section: string) => void; pageSize?: number;
  } = $props();
  const workshopTabs: { id: Section; label: string }[] = [
    { id: 'gear', label: 'Gear' }, { id: 'forge', label: 'Forge' }, { id: 'processing', label: 'Processing' },
    { id: 'vault', label: 'Materials' }, { id: 'codex', label: 'Codex' },
    { id: 'reliquary', label: 'Reliquary' },
  ];
  const shopTabs: { id: Section; label: string }[] = [
    { id: 'camp', label: 'Camp shop' }, { id: 'additional', label: 'Additional gear' }, { id: 'wandering', label: 'Wandering trader' },
  ];
  const tabs = $derived(presentation === 'shop' ? shopTabs : workshopTabs);
  const section = $derived.by(() => {
    const requested = requestedSection === 'materials' ? 'vault' : requestedSection;
    return tabs.find(tab => tab.id === requested)?.id ?? (presentation === 'shop' ? 'camp' : 'gear');
  });
  const routeKey = $derived(`${presentation}:${requestedSection ?? ''}`);
  let quote = $state.raw<TideforgeEconomyQuote | null>(null);
  let revision = 0;
  let quoteRevision = -1;
  let dialog: HTMLDialogElement;
  let cancelButton = $state<HTMLButtonElement>();
  let confirming = $state(false);
  let previousPlayer = $state<bigint | undefined>();
  let previousIdentity = $state<string | undefined>();
  const expired = $derived(deadlineExpired(quote?.expiresAt.microsSinceUnixEpoch ?? null, game.serverNowMs));
  const preview = $derived(quote ? parseTideforgePreview(quote.previewJson) : null);
  const result = $derived(game.tideforgeResult ? parseTideforgePreview(game.tideforgeResult.summaryJson) : null);
  function rollText(roll: TideforgePreviewRoll) {
    const water = roll.secondaryBiomeId == null ? ''
      : ` · ${game.biomes.find(row => row.biomeId === roll.secondaryBiomeId)?.name ?? 'Unknown water'}`;
    return `${affixName(roll.id)} · T${roll.tier} · Strength ${roll.finishBp / 100}%${water}`;
  }
  function invalidate() { revision++; quote = null; }
  function select(value: Section) {
    if (section === value) return;
    invalidate();
    onNavigate(presentation === 'shop' ? 'trader' : 'tideforge', value === 'vault' ? 'materials' : value);
  }
  async function request(intent: TideforgeIntent) {
    invalidate();
    const snapshot = { revision, identity: game.tideforgeIdentity, playerId: game.player?.playerId, routeKey };
    const next = await game.prepareTideforge(intent);
    if (!next || snapshot.routeKey !== routeKey || !currentTideforgeForm(snapshot, { revision, identity: game.tideforgeIdentity,
      playerId: game.player?.playerId, active, ready: game.ready })) return;
    if (!parseTideforgePreview(next.previewJson)) { game.tideforgeError = 'This preview could not be read safely. Open a new preview; do not confirm this one.'; return; }
    quoteRevision = snapshot.revision; quote = next;
  }
  async function confirm() {
    if (!quote || !active || deadlineExpired(quote.expiresAt.microsSinceUnixEpoch, game.serverNowMs) || confirming || quoteRevision !== revision || !game.ready || !preview) return;
    const currentQuote = quote;
    const snapshot = { revision, identity: game.tideforgeIdentity, playerId: game.player?.playerId };
    confirming = true;
    try {
      const success = await game.commitTideforge(currentQuote);
      const pending = game.pendingTideforgeCommit;
      if (quote === currentQuote && currentTideforgeForm(snapshot, { revision, identity: game.tideforgeIdentity,
        playerId: game.player?.playerId, active, ready: game.ready })
        && (success || pending?.quote.nonce === currentQuote.nonce && pending.identity === snapshot.identity
          && pending.playerId === snapshot.playerId)) invalidate();
    } finally { confirming = false; }
  }
  function dismiss(event?: Event) {
    if (confirming) { event?.preventDefault(); return; }
    invalidate();
  }
  function backdrop(event: PointerEvent) {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dismiss();
  }
  $effect(() => {
    if (!active || !game.ready || game.tideforgeAvailability !== 'active') untrack(invalidate);
  });
  $effect(() => { routeKey; untrack(invalidate); });
  $effect(() => {
    const saved = game.tideforgeResult;
    if (quote && saved && saved.nonce === quote.nonce && saved.playerId === game.player?.playerId
      && saved.identity.toHexString() === game.tideforgeIdentity) untrack(invalidate);
  });
  $effect(() => {
    const player = game.player?.playerId;
    const identity = game.tideforgeIdentity;
    if (previousPlayer !== undefined && previousPlayer !== player
      || previousIdentity !== undefined && previousIdentity !== identity) untrack(invalidate);
    previousPlayer = player;
    previousIdentity = identity;
  });
  $effect(() => {
    if (quote && dialog && !dialog.open) { dialog.showModal(); cancelButton?.focus(); }
    else if (!quote && dialog?.open) dialog.close();
  });
  $effect(() => {
    if (!quote) return;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = overflow; };
  });
</script>

{#snippet capabilityStatus()}
  {#if game.tideforgeAvailability === 'loading'}<p role="status">Loading Tideforge…</p>
  {:else if game.tideforgeAvailability === 'inactive'}<p>Tideforge is not active on this server yet.</p>
  {:else if game.tideforgeAvailability === 'unavailable'}
    <p>Tideforge could not load. Retry Tideforge to reconnect this section; your saved gear is unchanged.</p><button class="tideforge-action secondary" onclick={() => game.retryTideforge()}>Retry Tideforge</button>
  {:else if game.tideforgeLoading}<p role="status">Loading Tideforge…</p>{/if}
{/snippet}

<section class="tideforge" aria-labelledby="tideforge-title">
  <header class:mobile-empty={!game.player || game.tideforgeAvailability !== 'active' || section === 'vault'}><div class="host-heading"><h2 id="tideforge-title">{presentation === 'shop' ? 'Shop' : 'Tideforge'}</h2><p>{presentation === 'shop' ? 'Find supplies and gear for your next adventure.' : 'Your gear, made your own.'}</p></div>
    {#if game.player && game.tideforgeAvailability === 'active' && section !== 'vault'}<p class="wallet">Coins {game.player.coins} · Dredge Marks {game.tideforgeWallet?.playerId === game.player.playerId ? game.tideforgeWallet.marks : 'unavailable'} <InfoButton label="About Dredge Marks" message="Earn Dredge Marks from regular-fish raid clears and newly earned achievements. Spend them at the wandering trader." /></p>{/if}
  </header>
  <SectionTabs {tabs} value={section} prefix={presentation === 'shop' ? 'shop' : 'tideforge'} label={presentation === 'shop' ? 'Shop sections' : 'Tideforge sections'} onselect={select} />
  {#if presentation === 'workshop'}<button class="tideforge-action secondary shop-shortcut" onclick={() => onNavigate('trader', 'camp')}>Visit shop</button>{/if}
  {#if game.player}
    {#if game.pendingTideforgeCommit}
      <div class="notice" role="status"><p>{game.tideforgeError === tideforgeConfirmed ? tideforgeConfirmed : tideforgeUnconfirmed}</p>
        <button class="tideforge-action" disabled={!game.ready || game.busy} onclick={() => game.retryTideforgeCommit()}>Check last action</button></div>
    {/if}
    {#if game.tideforgeError && game.tideforgeError !== tideforgeConfirmed}<p class="notice" role="alert">{game.tideforgeError}</p>{/if}
    {#if game.tideforgeDetailsUnavailable && section !== 'vault'}<button class="tideforge-action secondary" onclick={() => game.retryTideforgeDetails()}>Retry details</button>{/if}
    {#if result}
      <div class="notice" role="status"><strong>{result.gear ? 'Gear acquired' : 'Action confirmed'}</strong>
        {#if result.gear}
        <p>{result.gear.affixes.map(roll => `${affixName(roll.id)} T${roll.tier} (Strength ${roll.finishBp / 100}%)`).join(' · ') || 'No affixes'}</p>
        {#if result.gear.native_signature}<p>Item signature: {signatureName(result.gear.native_signature)}</p>{/if}
        {:else if result.learned_signature}<p>Learned {signatureName(result.learned_signature)}.</p>{/if}
        {#each result.materials ?? [] as change}{#if change.produced}<p>Received {change.produced} {tideforgeMaterialLabel(change.item)}.</p>{/if}{/each}
        {#each result.shards ?? [] as change}{#if change.produced}<p>Received {change.produced} {affixName(change.affix_id ?? '')} shards.</p>{/if}{/each}
      </div>
    {/if}
    <div role="tabpanel" id="tideforge-panel-gear" aria-labelledby="tideforge-tab-gear" tabindex="0" hidden={!active || presentation !== 'workshop' || section !== 'gear'}>
      <TideforgeGear {game} active={active && presentation === 'workshop' && section === 'gear'} onchange={invalidate} onpreview={request} />
    </div>
    <div role="tabpanel" id="tideforge-panel-vault" aria-labelledby="tideforge-tab-vault" tabindex="0" hidden={!active || presentation !== 'workshop' || section !== 'vault'}>
      <TideforgeRecords {game} section="vault" onchange={invalidate} {onNavigate} />
    </div>
  {/if}
  {#if presentation === 'shop'}
    {#each shopTabs as tab}
      {#if tab.id !== section}<div role="tabpanel" id={`shop-panel-${tab.id}`} aria-labelledby={`shop-tab-${tab.id}`} tabindex="0" hidden></div>{/if}
    {/each}
  {:else if game.player && (game.tideforgeAvailability !== 'active' || game.tideforgeLoading)}
    {#each workshopTabs as tab}
      {#if tab.id !== 'gear' && tab.id !== 'vault'}<div role="tabpanel" id={`tideforge-panel-${tab.id}`} aria-labelledby={`tideforge-tab-${tab.id}`} tabindex="0" hidden={section !== tab.id}>{#if section === tab.id}{@render capabilityStatus()}{/if}</div>{/if}
    {/each}
  {/if}
  {#if presentation === 'shop' && section === 'camp'}
    <div role="tabpanel" id="shop-panel-camp" aria-labelledby="shop-tab-camp" tabindex="0"><Trader {game} {pageSize} /></div>
  {:else if !game.player}
    {#if presentation === 'shop'}<div role="tabpanel" id={`shop-panel-${section}`} aria-labelledby={`shop-tab-${section}`} tabindex="0"><p role="status">Link Discord from Camp to access these gear offers.</p><a href="#camp">Open Camp</a></div>{/if}
  {:else}
    {#if presentation === 'shop' && (game.tideforgeAvailability !== 'active' || game.tideforgeLoading)}
      <div role="tabpanel" id={`shop-panel-${section}`} aria-labelledby={`shop-tab-${section}`} tabindex="0">{@render capabilityStatus()}</div>
    {:else if presentation === 'workshop' && section === 'gear'}{@render capabilityStatus()}{/if}
    {#if game.tideforgeAvailability === 'active' && !game.tideforgeLoading}
      {#if presentation === 'workshop'}
        <div role="tabpanel" id="tideforge-panel-forge" aria-labelledby="tideforge-tab-forge" tabindex="0" hidden={section !== 'forge'}><TideforgeForge {game} onchange={invalidate} onpreview={request} /></div>
        <div role="tabpanel" id="tideforge-panel-processing" aria-labelledby="tideforge-tab-processing" tabindex="0" hidden={section !== 'processing'}><TideforgeProcessing {game} onchange={invalidate} onpreview={request} /></div>
        <div role="tabpanel" id="tideforge-panel-codex" aria-labelledby="tideforge-tab-codex" tabindex="0" hidden={section !== 'codex'}><TideforgeRecords {game} section="codex" onchange={invalidate} {onNavigate} /></div>
        <div role="tabpanel" id="tideforge-panel-reliquary" aria-labelledby="tideforge-tab-reliquary" tabindex="0" hidden={section !== 'reliquary'}><TideforgeReliquary {game} onchange={invalidate} onpreview={request} /></div>
      {:else if section === 'additional' || section === 'wandering'}
        <div role="tabpanel" id={`shop-panel-${section}`} aria-labelledby={`shop-tab-${section}`} tabindex="0"><TideforgeTrader {game} offer={section === 'additional' ? 'camp' : 'wandering'} onchange={invalidate} onpreview={request} /></div>
      {/if}
    {/if}
  {/if}
  <dialog bind:this={dialog} aria-labelledby="tideforge-confirm-title" onclose={() => quote = null} oncancel={dismiss} onpointerdown={backdrop}>
    {#if quote && preview}
      <h2 id="tideforge-confirm-title">Confirm {tideforgeActionLabel(preview.action)}</h2>
      {#if preview.kind && preview.definition_id}
        {@const target = game.gearInstances.find(row => row.protectedOriginal && row.kind === preview.kind && row.definitionId === preview.definition_id)}
        <p>Receive <strong>{target ? gearName(game, target) : 'Selected gear'}</strong>.</p>
      {/if}
      {#if preview.gear_before}
        {@const target = game.gearInstances.find(row => row.instanceId.toString() === preview.gear_before?.instance_id)}
        <p>{target ? gearLabel(game, target) : 'Selected gear'}</p>
        <p>{preview.gear_after === null ? 'This item will be permanently removed.' : 'Affixes before:'}</p>
        <InfoButton label="About affix strength" message={strengthHelp} />
        {#if preview.action === 'dismantle'}<p>Past upgrade costs are not refunded.</p>{/if}
        {#if preview.gear_before.native_signature}
          <p>Item signature: {signatureName(preview.gear_before.native_signature)}{preview.action === 'dismantle' ? ' · lost when dismantled' : ''}</p>
        {/if}
        <ul>{#each preview.gear_before.affixes as roll}<li>{rollText(roll)}</li>{/each}</ul>
        {#if preview.gear_after}<p>After:</p><ul>{#each preview.gear_after.affixes as roll}<li>{rollText(roll)}</li>{/each}</ul>{/if}
      {/if}
      {#if preview.learned_signature}<p>Learn signature: {signatureName(preview.learned_signature)}</p>{/if}
      {#if preview.secondary_biome_id}<p>Secondary water: {game.biomes.find(row => row.biomeId === preview.secondary_biome_id)?.name ?? 'Unknown water'}. New gear receives {preview.affix_count} random affixes.</p>{/if}
      {#if preview.fish}<p>Uses up {preview.fish.length} selected fish. Sale value you give up: {preview.saved_sale_coins_forgone ?? 0} coins. No coins or XP are granted; collection and lifetime progress remain.</p>
        <ul>{#each preview.fish as fish}{@const specimen = game.inventory.find(row => row.catchId.toString() === fish.catch_id)}<li>{game.species.find(row => row.speciesId === fish.species_id)?.name ?? 'Fish'} · {fish.rarity}{#if specimen} · {formatLength(specimen.lengthMm)} · {formatWeight(specimen.weightG)}{/if}</li>{/each}</ul>
      {/if}
      <h3>Costs</h3>
      {#if preview.coins_cost}<p>{preview.coins_cost} coins</p>{/if}
      {#if preview.marks_cost}<p>{preview.marks_cost} Dredge Marks</p>{/if}
      {#each preview.materials ?? [] as change}{#if change.consumed}<p>{change.consumed} {tideforgeMaterialLabel(change.item)}</p>{/if}{/each}
      {#each preview.shards ?? [] as change}{#if change.consumed}<p>{change.consumed} {affixName(change.affix_id ?? '')} shards</p>{/if}{/each}
      {#if !preview.coins_cost && !preview.marks_cost && !(preview.materials ?? []).some(row => row.consumed) && !(preview.shards ?? []).some(row => row.consumed)}<p>No additional currency or material costs.</p>{/if}
      <h3>Receive</h3>
      {#each preview.materials ?? [] as change}{#if change.produced}<p>{change.produced} {tideforgeMaterialLabel(change.item)}</p>{/if}{/each}
      {#each preview.shards ?? [] as change}{#if change.produced}<p>{change.produced} {affixName(change.affix_id ?? '')} shards</p>{/if}{/each}
      {#if preview.gear_after}<p>Updated gear shown above.</p>{/if}
      {#if preview.kind}<p>New gear with {preview.affix_count} random affix{preview.affix_count === 1 ? '' : 'es'}.</p>{/if}
      {#if preview.learned_signature}<p>{signatureName(preview.learned_signature)} signature.</p>{/if}
      {#if !preview.gear_after && !preview.kind && !preview.learned_signature && !(preview.materials ?? []).some(row => row.produced) && !(preview.shards ?? []).some(row => row.produced)}<p>No items returned.</p>{/if}
      {#if game.serverNowMs === null}<p role="status">Syncing server time…</p>{:else if expired}<p role="alert">This preview expired. Open a new preview.</p>{/if}
      {#if game.tideforgeError}<p role="alert">{game.tideforgeError}</p>{/if}
      <div class="dialog-actions"><button class="tideforge-action secondary" bind:this={cancelButton} disabled={confirming} onclick={() => dismiss()}>Cancel</button><button class="tideforge-action" disabled={confirming || game.busy || !game.ready || expired} onclick={confirm}>{confirming ? 'Confirming…' : 'Confirm'}</button></div>
    {/if}
  </dialog>
</section>
<style>
  .tideforge{min-width:0;margin-top:28px;padding:32px;color:var(--ink);background:var(--panel-texture),var(--paper);border:3px solid var(--wood-dark);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 6px 0 var(--wood-dark);font-family:var(--font-body);box-sizing:border-box}
  .tideforge header{display:flex;align-items:end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}.tideforge header h2{font-family:var(--font-game);font-size:36px;font-weight:500;line-height:1.2;margin:0}.tideforge header p{margin:4px 0}.wallet,.notice{padding:10px 14px;background:var(--panel-inset);border:1px solid var(--paper-edge)}.notice{margin:0 0 14px}.notice p{margin:6px 0}
  .shop-shortcut{margin-bottom:18px}
  .tideforge :global(.tideforge-action){border:2px solid var(--wood-dark);padding:9px 14px;background:var(--gold);color:var(--button-ink);box-shadow:inset 0 0 0 2px var(--panel-highlight),0 3px 0 var(--wood-dark);font-family:var(--font-game);font-size:19px;line-height:1.3;cursor:pointer}
  .tideforge :global(.tideforge-action.secondary){color:var(--ink);background:var(--panel-inset);border-color:var(--paper-edge)}.tideforge :global(.tideforge-action:hover:not(:disabled)){background:var(--button-hover)}.tideforge :global(.tideforge-action.secondary:hover:not(:disabled)){background:var(--paper-light)}.tideforge :global(.tideforge-action:disabled){opacity:.55;cursor:default;box-shadow:none}
  .tideforge :global(.tideforge-action:focus-visible){outline:3px solid var(--site-focus);outline-offset:2px}
  .tideforge dialog{width:min(640px,calc(100% - 32px));max-height:calc(100dvh - 32px);padding:24px;border:4px solid var(--wood-dark);background:var(--paper);color:var(--ink);box-shadow:inset 0 0 0 3px var(--panel-highlight),0 12px 0 color-mix(in srgb,var(--wood-dark) 50%,transparent);overflow:auto;box-sizing:border-box}.tideforge dialog::backdrop{background:var(--dialog-backdrop)}.tideforge dialog h2{font-family:var(--font-game);font-weight:400;margin-top:0;text-transform:capitalize}.tideforge dialog p,.tideforge dialog li{overflow-wrap:anywhere}.dialog-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:20px}@media(max-width:600px){.tideforge{padding:18px}.tideforge header .host-heading{position:absolute}.tideforge header .host-heading h2{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}.tideforge header .host-heading p{display:none}.tideforge header.mobile-empty{margin-bottom:0}.tideforge header .wallet{margin:0;padding:6px 10px;font-size:14px;overflow-wrap:anywhere}.tideforge dialog{width:calc(100% - 20px);padding:18px}.dialog-actions{flex-direction:column-reverse}.dialog-actions button{width:100%}}
</style>
