<script lang="ts">
  import { onDestroy } from 'svelte';
  import type { RaidAttempt } from '@fishing-game/generated/types';
  import type { Game } from './game.svelte.ts';
  import RaidArt from './RaidArt.svelte';
  import { formatLength, formatWeight } from './measurements';
  import { formatRaidDuration, raidResultSummary } from './raid-result';

  let { game, attempt = $bindable(null) }: { game: Game; attempt?: RaidAttempt | null } = $props();
  let dialog: HTMLDialogElement;
  let opener: HTMLElement | null = null;
  const current = $derived(attempt && game.player?.playerId === attempt.playerId
    ? game.raidAttempts.find(row => row.id === attempt?.id && row.playerId === game.player?.playerId) ?? null
    : null);
  const summary = $derived(current ? raidResultSummary(current, game.species, game.communityRaids, game.inventory) : null);
  const localDate = (value: { microsSinceUnixEpoch: bigint }) =>
    new Date(Number(value.microsSinceUnixEpoch / 1000n)).toLocaleString();

  $effect(() => {
    if (attempt && !current) attempt = null;
  });
  $effect(() => {
    if (current && dialog && !dialog.open) {
      opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      dialog.showModal();
    } else if (!current && dialog?.open) {
      dialog.close();
    }
  });
  $effect(() => {
    if (!current) return;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = overflow; };
  });
  function restoreFocus() {
    if (opener?.isConnected) opener.focus();
    opener = null;
  }
  function close() {
    attempt = null;
  }
  function dismissBackdrop(event: PointerEvent) {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close();
  }
  onDestroy(restoreFocus);
</script>

<dialog bind:this={dialog} aria-labelledby="raid-result-title" onclose={() => { if (!dialog.open) { close(); restoreFocus(); } }} oncancel={close} onpointerdown={dismissBackdrop}>
  {#if current && summary}
    <header>
      <div class="heading">
        <p class="eyebrow">EXPEDITION RECORD</p>
        <h2 id="raid-result-title">{summary.targetName}</h2>
        <p class="subtitle">{summary.kindLabel}{summary.gradeLabel ? ` · ${summary.gradeLabel}` : ''} · {summary.statusLabel}</p>
      </div>
      <button class="game-button game-button--secondary close" aria-label="Close raid result" onclick={close}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
    </header>
    <div class="contents">
      <div class="overview" class:with-art={!!summary.speciesAsset}>
        {#if summary.speciesAsset}
          <div class="art"><img src={summary.speciesAsset} alt={summary.targetName} /></div>
        {/if}
        <div class="outcome">
          <h3>{summary.statusLabel}</h3>
          <p>{summary.explanation}</p>
          <p>Boat: <strong>{current.boatName}</strong> · Quality {current.boatQualityLevel}</p>
          <p>Booked duration: <strong>{formatRaidDuration(current.durationSeconds)}</strong></p>
          <p>Expedition Chum consumed: <strong>{summary.suppliesConsumed}</strong></p>
        </div>
      </div>
      <section aria-labelledby="raid-result-rewards">
        <h3 id="raid-result-rewards">Saved rewards</h3>
        {#if current.kind === 'personal' && (current.status === 'completed' || (current.status === 'cancelled' && (summary.fillets > 0n || summary.scales > 0n)))}
          <div class="rewards">
            <div><RaidArt kind="fillets" grade={current.grade} size={40} /><strong>{summary.fillets.toString()}</strong> fillets</div>
            <div><RaidArt kind="scales" grade={current.grade} size={40} /><strong>{summary.scales.toString()}</strong> scales</div>
          </div>
        {:else if current.kind === 'community' && current.status === 'completed'}
          <p class="reward-note">+1 community completion credit and bragging rights.</p>
        {:else if current.kind === 'special' && current.status === 'completed'}
          {#if summary.caughtFish}
            <p class="reward-note">Saved catch #{summary.caughtFish.catchId.toString()}{summary.caughtFish.rarity ? ` · ${summary.caughtFish.rarity} rank` : ''}</p>
            {#if summary.caughtFish.specimen}
              <p>Length: <strong>{formatLength(summary.caughtFish.specimen.lengthMm)}</strong> · Weight: <strong>{formatWeight(summary.caughtFish.specimen.weightG)}</strong></p>
            {:else}
              <p>Catch measurements are unavailable in this view.</p>
            {/if}
          {:else}
            <p>No saved catch ID is recorded for this result. Catch details are unavailable.</p>
          {/if}
        {:else}
          <p>No rewards were recorded for this result.</p>
        {/if}
      </section>
      <section aria-labelledby="raid-result-timeline">
        <h3 id="raid-result-timeline">Expedition timeline</h3>
        <dl>
          <div><dt>Prepared</dt><dd>{localDate(current.preparedAt)}</dd></div>
          {#if current.deployedAt}<div><dt>Deployed</dt><dd>{localDate(current.deployedAt)}</dd></div>{/if}
          <div><dt>Scheduled departure</dt><dd>{localDate(current.startsAt)}</dd></div>
          {#if current.startedAt}<div><dt>Started</dt><dd>{localDate(current.startedAt)}</dd></div>{/if}
          <div><dt>Scheduled return</dt><dd>{localDate(current.endsAt)}</dd></div>
          {#if current.settledAt}<div><dt>Settled</dt><dd>{localDate(current.settledAt)}</dd></div>{/if}
        </dl>
        <p class="local-time">Times shown in your local timezone.</p>
      </section>
    </div>
  {/if}
</dialog>

<style>
  dialog{width:min(760px,calc(100% - 32px));max-height:calc(100dvh - 32px);padding:0;border:4px solid var(--wood-dark);background:var(--paper);color:var(--ink);box-shadow:inset 0 0 0 3px var(--panel-highlight),0 12px 0 color-mix(in srgb,var(--wood-dark) 50%,transparent);overflow:hidden;box-sizing:border-box}
  dialog[open]{display:flex;flex-direction:column}dialog::backdrop{background:var(--dialog-backdrop)}
  header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:20px 24px;border-bottom:2px solid var(--paper-edge);background:var(--panel-inset);flex-shrink:0}
  .heading{min-width:0}.eyebrow{margin:0 0 6px;font-size:13px;letter-spacing:.12em;color:var(--ink-muted);font-weight:800}h2{margin:0;font:34px var(--font-game);line-height:1.2;overflow-wrap:anywhere}.subtitle{margin:7px 0 0;line-height:1.5;overflow-wrap:anywhere}.close{flex:none;width:44px;height:44px;min-width:44px;padding:0;display:grid;place-items:center;box-sizing:border-box}.close svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2}.close:focus-visible{outline:3px solid var(--control-focus);outline-offset:3px}
  .contents{padding:22px 24px;overflow-y:auto;overscroll-behavior:contain;min-width:0}.overview{display:flex;align-items:center;gap:20px;min-width:0}.art{display:grid;place-items:center;flex:none;width:150px;height:130px;padding:12px;box-sizing:border-box;border:2px solid var(--paper-edge);background:var(--panel-inset);box-shadow:inset 0 0 0 3px var(--panel-highlight)}.art img{width:100%;height:100%;object-fit:contain;image-rendering:pixelated}.outcome{min-width:0}h3{margin:0 0 10px;font:25px var(--font-game)}p{line-height:1.55;overflow-wrap:anywhere}.outcome p{margin:6px 0}.contents section{margin-top:22px;padding-top:18px;border-top:1px dashed var(--paper-edge)}.rewards{display:flex;gap:12px;flex-wrap:wrap}.rewards>div{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--paper-edge);background:var(--panel-inset)}.reward-note{font-weight:800}dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:0}dl>div{min-width:0;padding:10px;border:1px solid var(--paper-edge);background:var(--panel-inset)}dt{font-size:13px;color:var(--ink-muted)}dd{margin:4px 0 0;overflow-wrap:anywhere}.local-time{font-size:13px;color:var(--ink-muted);margin-bottom:0}
  @media(max-width:600px){dialog{width:calc(100% - 20px);max-height:calc(100dvh - 20px)}header{padding:16px;gap:10px}h2{font-size:26px}.contents{padding:16px}.overview{display:grid;grid-template-columns:minmax(0,1fr);gap:8px 12px;align-items:center}.overview.with-art{grid-template-columns:90px minmax(0,1fr)}.outcome{display:contents}.outcome>h3{grid-column:1;margin:0}.with-art .outcome>h3{grid-column:2}.outcome>p{grid-column:1/-1;margin:0}.art{grid-column:1;grid-row:1;width:90px;height:90px;padding:8px}h3{font-size:22px}dl{grid-template-columns:1fr}.rewards>div{flex:1;min-width:0}}
</style>
