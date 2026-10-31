<script lang="ts">
  import type { Game } from './game.svelte.ts';
  import GameSelect from './GameSelect.svelte';
  import NumberStepper from './NumberStepper.svelte';
  import policy from '../../../../../content/raids.json';
  import RaidArt from './RaidArt.svelte';
  import { itemSlot } from './raid-items';
  import { hasOrdinaryRaidClearForGrade } from './raid-progress';

  let { game }: { game: Game } = $props();
  let materialId = $state(0);
  let fromGrade = $state(0);
  let batches = $state(1);
  let pending = $state(false);
  let pendingPlayerId = $state<bigint | null>(null);
  let dialog: HTMLDialogElement;
  const material = $derived(policy.materials[materialId]);
  const toGrade = $derived(fromGrade + 1);
  const sourceSlot = $derived(material.slotBase + fromGrade);
  const source = $derived(game.items.find(row => itemSlot(row.key) === sourceSlot));
  const validBatches = $derived(Number.isInteger(batches) && batches >= 1 && batches <= policy.refining.maximumBatches);
  const batchCount = $derived(validBatches ? batches : 0);
  const input = $derived(BigInt(policy.refining.inputQuantity) * BigInt(batchCount));
  const output = $derived(BigInt(policy.refining.outputQuantity) * BigInt(batchCount));
  const coins = $derived(BigInt(policy.refining.coinsPerBatch) * BigInt(batchCount));
  const ordinarySpeciesIds = $derived(new Set(game.species.filter(row => row.countsForOrdinaryCollectionCompletion).map(row => row.speciesId)));
  const targetUnlocked = $derived(hasOrdinaryRaidClearForGrade(
    game.raidBadges, ordinarySpeciesIds, game.player?.playerId, toGrade
  ));
  const gradeName = (grade: number) => game.raidGrades.find(row => row.grade === grade)?.name ?? String(grade);
  const problem = $derived(!validBatches ? `Choose 1–${policy.refining.maximumBatches} batches.`
    : !targetUnlocked ? `Clear ${gradeName(toGrade)} at least once before refining into it.`
    : (source?.quantity ?? 0n) < input ? `Need ${input} ${material.name.toLowerCase()} at ${gradeName(fromGrade)} grade.`
    : (game.player?.coins ?? 0n) < coins ? `Need ${coins} coins for this refinement.`
    : '');
  $effect(() => {
    if (pendingPlayerId !== null && (!game.ready || game.player?.playerId !== pendingPlayerId)) {
      pendingPlayerId = null;
      if (dialog?.open) dialog.close();
    }
  });

  async function confirm() {
    if (pending || problem || !game.ready || game.busy || !game.player || game.player.playerId !== pendingPlayerId) return;
    pending = true;
    try {
      if (await game.refineRaidMaterial(materialId, fromGrade, batches)) dialog.close();
    } finally {
      pending = false;
    }
  }
</script>

<section class="workshop" aria-labelledby="raid-workshop-heading">
  <div><p class="eyebrow">MATERIALS &amp; REFINING</p><h3 id="raid-workshop-heading">Raid workshop</h3>
    <p>Graded fillets and scales stay separate from ordinary bait materials. Refining spends 60 from one grade for 50 at the next grade and costs 5 coins per batch.</p>
  </div>
  {#if game.raidsSupported}
    <div class="balances">
      {#each policy.materials as row (row.id)}
        <article><h4>{row.name}</h4><div class="grade-balances">
          {#each game.raidGrades as grade (grade.grade)}
          {@const stack = game.items.find(item => itemSlot(item.key) === row.slotBase + grade.grade)}
            <span><RaidArt kind={row.key} grade={grade.grade} size={32} /><small>{grade.name}</small><strong>{stack?.quantity.toLocaleString() ?? '0'}</strong></span>
          {/each}
        </div></article>
      {/each}
    </div>
    <form onsubmit={(event) => { event.preventDefault(); if (!problem && game.ready && !game.busy && game.player) { pendingPlayerId = game.player.playerId; dialog.showModal(); } }}>
      <label>Material<GameSelect label="Material" value={materialId} options={policy.materials.map(row=>({value:row.id,label:row.name}))} disabled={!game.ready || game.busy} onchange={value => { materialId = value; }} /></label>
      <label>Source grade<GameSelect label="Source grade" value={fromGrade} options={game.raidGrades.filter(row=>row.grade<9).map(grade=>({value:grade.grade,label:grade.name}))} disabled={!game.ready || game.busy} onchange={value => { fromGrade = value; }} /></label>
      <NumberStepper label="Batches" value={batches} min={1} max={policy.refining.maximumBatches} step={1} describedby={problem ? 'refine-error' : undefined} disabled={!game.ready || game.busy} onchange={value => { batches = value; }} />
      <p class="conversion"><RaidArt kind={material.key} grade={fromGrade} size={28} />{input} {material.name.toLowerCase()} at {gradeName(fromGrade)} → <RaidArt kind={material.key} grade={toGrade} size={28} />{output} at {gradeName(toGrade)} · {coins} coins</p>
      {#if problem}<p id="refine-error" class="error" role="status">{problem}</p>{/if}
      {#if game.error}<p class="error" role="alert">{game.error}</p>{/if}
      <button type="submit" disabled={!game.ready || game.busy || !!problem}>Review refinement</button>
    </form>
  {:else}
    <p class="notice">Raid materials and refining are awaiting the module update.</p>
  {/if}
</section>

<dialog bind:this={dialog} aria-labelledby="refine-title" onclose={() => { pending = false; pendingPlayerId = null; }}>
  <h4 id="refine-title">Confirm material refinement</h4>
  <p><RaidArt kind={material.key} grade={fromGrade} size={28} /> Spend {input} {material.name.toLowerCase()} at {gradeName(fromGrade)} grade and {coins} coins for <RaidArt kind={material.key} grade={toGrade} size={28} /> {output} at {gradeName(toGrade)} grade.</p>
  <div class="actions"><button class="secondary" disabled={pending || game.busy} onclick={() => dialog.close()}>Cancel</button>
    <button disabled={pending || game.busy || !game.ready || !!problem} onclick={confirm}>{pending ? 'Refining…' : 'Confirm refinement'}</button>
  </div>
</dialog>

<style>
  .workshop{display:grid;gap:18px;padding:18px;border:2px solid var(--paper-edge);background:var(--paper-light)}.workshop h3,.workshop h4,dialog h4{font-family:var(--font-game);font-weight:400;margin:0}.workshop h3{font-size:27px}.workshop h4{font-size:21px}.workshop p{font-size:15px;line-height:1.6;color:var(--ink-muted);margin:8px 0 0}.eyebrow{font-size:13px;letter-spacing:1.2px}.balances{display:grid;gap:12px}.balances article{padding:12px;background:var(--panel-inset);border:1px solid var(--paper-edge)}.grade-balances{display:grid;grid-template-columns:repeat(auto-fit,minmax(72px,1fr));gap:6px;margin-top:10px}.grade-balances span{display:grid;justify-items:center;gap:4px;min-width:0;padding:6px;text-align:center;border:1px solid var(--paper-edge);font-size:12px}.grade-balances small{font-size:12px;color:var(--ink)}.grade-balances strong{font-family:var(--font-game);font-size:18px}.workshop form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));align-items:end;gap:12px}.workshop label{display:grid;gap:5px;font-size:14px}.conversion,.error{grid-column:1/-1}.conversion{display:flex;align-items:center;flex-wrap:wrap;gap:4px}.error{color:var(--danger-ink)!important}.workshop button,dialog button{padding:8px 12px;border:1px solid var(--paper-edge);background:var(--selected-background);color:var(--selected-ink);font-family:var(--font-game)}button:disabled{opacity:.5}dialog{width:min(480px,calc(100% - 24px));padding:20px;background:var(--paper-light);color:var(--ink);border:3px solid var(--paper-edge)}dialog::backdrop{background:var(--dialog-backdrop)}.actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.actions .secondary{background:var(--panel-inset);color:var(--ink)}
</style>
