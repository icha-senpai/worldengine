<script lang="ts">
  import type { Game } from './game.svelte';
  import { untrack } from 'svelte';
  import type { TideforgeIntent } from './tideforge-intents';
  import economy from '../../../../../content/tideforge-economy.json';
  import { specimenProcessingProtection } from './tideforge-processing';
  import { toggleProcessingSelection } from './tideforge-ui';
  import { processingProtectionLabel } from './tideforge-copy';
  import { formatLength, formatWeight } from './measurements';
  import { hasOrdinaryRaidClearForGrade } from './raid-progress';
  import { availableGuideBalances, processingActionProblem } from './ui-action-guidance';
  import { guideShortages, tideforgeCostGuide } from './tideforge-cost-guide';
  import CostGuide from './CostGuide.svelte';
  import InfoButton from './InfoButton.svelte';
  import GameSelect from './GameSelect.svelte';
  import NumberStepper from './NumberStepper.svelte';
  import PageTurner from './PageTurner.svelte';
  let { game, onchange, onpreview }: { game: Game; onchange: () => void; onpreview: (intent: TideforgeIntent) => void } = $props();
  let action = $state<'fillet' | 'convert_normal' | 'refine_material'>('fillet');
  let selected = $state<bigint[]>([]);
  let allowTrophies = $state(false);
  let material = $state(0);
  let from = $state(0);
  let to = $state(1);
  let batches = $state(1);
  let page = $state(1);
  const pageSize = 50;
  const currentPageFish = $derived(game.inventory.slice((page - 1) * pageSize, page * pageSize));
  const ordinary = $derived(new Set(game.species.filter(row => row.countsForOrdinaryCollectionCompletion).map(row => row.speciesId)));
  const clear = (grade: number) => hasOrdinaryRaidClearForGrade(game.raidBadges, ordinary, game.player?.playerId, grade);
  const grades = ['F', 'D', 'C', 'B', 'A', 'S', 'SS', 'SSS', 'UR', 'UUR'];
  const allowed = (fish: typeof game.inventory[number]) => {
    const protection = specimenProcessingProtection(fish, game.species.find(row => row.speciesId === fish.speciesId));
    return protection === 'ordinary' || protection === 'trophy' && allowTrophies;
  };
  const selectedValid = $derived(selected.filter(id => game.inventory.some(fish => fish.playerId === game.player?.playerId && fish.catchId === id && allowed(fish))));
  function structuralProblem() {
    const validCount = selected.filter(id => game.inventory.some(fish => fish.playerId === game.player?.playerId && fish.catchId === id && allowed(fish))).length;
    return processingActionProblem({
      ready: game.ready, busy: game.busy, pending: !!game.pendingTideforgeCommit,
      action, selectedCount: selected.length, validSelectedCount: validCount, material, batches, from, to, clear,
    });
  }
  function currentIntent(): TideforgeIntent {
    if (action === 'fillet') return { action, catch_ids: selected.map(String), allow_trophies: allowTrophies };
    if (action === 'convert_normal') return { action, material_id: material, batches };
    return { action, material_id: material, from_grade: from, to_grade: to, batches };
  }
  function currentGuide() { return action === 'fillet' ? null : tideforgeCostGuide(currentIntent()); }
  function currentProblem() {
    const structural = structuralProblem();
    if (structural || action === 'fillet') return structural;
    const balances = availableGuideBalances(game);
    if (!balances) return 'Cost details are still loading. Retry Tideforge details if needed.';
    const result = currentGuide();
    return result?.problem || (result?.plan ? guideShortages(result.plan, balances)[0] : '') || '';
  }
  const balances = $derived(availableGuideBalances(game));
  const guide = $derived(currentGuide());
  const blockedReason = $derived(currentProblem());
  $effect(() => {
    if (!game.ready) return;
    const valid = selectedValid;
    if (valid.length !== selected.length) { selected = valid; untrack(onchange); }
  });
  function toggle(id: bigint) {
    selected = toggleProcessingSelection(selected, id,
      game.inventory.some(fish => fish.playerId === game.player?.playerId && fish.catchId === id && allowed(fish)));
    onchange();
  }
  function preview() {
    if (currentProblem()) return;
    onpreview(currentIntent());
  }
  function selectPage() {
    const next = [...selected];
    for (const fish of currentPageFish) {
      if (next.length >= 50) break;
      if (fish.playerId === game.player?.playerId && allowed(fish) && !next.includes(fish.catchId)) next.push(fish.catchId);
    }
    if (next.length !== selected.length) { selected = next; onchange(); }
  }
</script>
<div class="tideforge-processing">
  <label>Action <GameSelect label="Processing action" value={action} options={[{ value: 'fillet', label: 'Fillet fish' }, { value: 'convert_normal', label: 'Make F-grade materials' }, { value: 'refine_material', label: 'Refine materials' }]} onchange={value => { action = value; onchange(); }} /></label>
  {#if action === 'fillet'}
    <p>Filleting uses up the selected fish. You receive no coins or XP; collection and lifetime progress stay. The preview shows the sale value you give up and rank- and gear-adjusted yields.</p>
    <label class="check"><input type="checkbox" checked={allowTrophies} onchange={event => { allowTrophies = event.currentTarget.checked; selected = selected.filter(id => game.inventory.some(fish => fish.catchId === id && allowed(fish))); onchange(); }} /> Include rank maxima and perfect catches</label>
    <p>{selectedValid.length}/50 selected. Favorites, bonus fish and unrecognized specimens stay protected.</p>
    <div class="selection-actions"><button class="tideforge-action secondary" disabled={!selected.length} onclick={() => { selected = []; onchange(); }}>Clear selection</button><button class="tideforge-action secondary" disabled={selected.length >= 50} onclick={selectPage}>Select eligible on this page</button></div>
    {#if !game.inventory.length}<p>No kept fish to process.</p>{/if}
    <div class="fish">{#each currentPageFish as fish (fish.catchId)}
      {@const species = game.species.find(row => row.speciesId === fish.speciesId)}
      {@const protection = specimenProcessingProtection(fish, species)}
      <label><input type="checkbox" checked={selected.includes(fish.catchId)} disabled={!allowed(fish) || (!selected.includes(fish.catchId) && selected.length >= 50)} onchange={() => toggle(fish.catchId)} />
        <img src={species?.spriteAsset || '/fish/minnow.png'} alt="" width="48" height="48" loading="lazy" onerror={event => { if (event.currentTarget.getAttribute('src') !== '/fish/minnow.png') (event.currentTarget as HTMLImageElement).src = '/fish/minnow.png'; }} />
        <span>{species?.name ?? 'Unknown fish'} · {fish.rarity}<small>{formatLength(fish.lengthMm)} · {formatWeight(fish.weightG)} · {processingProtectionLabel(protection)}</small></span></label>
    {/each}</div>
    <PageTurner bind:page total={game.inventory.length} {pageSize} label="Processing fish pages" />
  {:else}
    {#if action === 'convert_normal'}<p>One batch: {economy.processing.normalToFInput} Basic → {economy.processing.normalToFOutput} F-grade · {economy.processing.normalToFCoinCost} coins.</p>
    {:else}<p>Each refinement step: {economy.processing.refiningInput} of one grade → {economy.processing.refiningOutput} of the next grade · {economy.processing.refiningCoinCost} coin per batch.</p>{/if}
    <div class="fields">
      <label>Material <GameSelect label="Processing material" value={material} options={[{ value: 0, label: 'Fillets' }, { value: 1, label: 'Scales' }]} onchange={value => { material = value; onchange(); }} /></label>
      {#if action === 'refine_material'}
        <label>From grade <GameSelect label="From grade" value={from} options={grades.slice(0, 9).map((label, value) => ({ value, label }))} onchange={value => { from = value; onchange(); }} /></label>
        <label>To grade <GameSelect label="Destination grade" value={to} options={grades.slice(1).map((label, index) => ({ value: index + 1, label, disabled: index + 1 <= from }))} onchange={value => { to = value; onchange(); }} /></label>
      {/if}
      <div class="batch-field"><div class="batch-heading">Batches <InfoButton label="About Processing batches" message="A batch is one run of the selected recipe. Review the input, output and coin cost before confirming. Multi-grade refining processes each grade in order." /></div>
        <NumberStepper label="Processing batches" value={batches} min={1} max={1000} onchange={value => { batches = value; onchange(); }} /></div>
    </div>
  {/if}
  {#if guide && balances}<CostGuide result={guide} {balances} />{/if}
  {#if blockedReason}<p class="guidance" id="processing-blocked-reason">{blockedReason}</p>{/if}
  <button class="tideforge-action" disabled={!!blockedReason} aria-describedby={blockedReason ? 'processing-blocked-reason' : undefined} onclick={preview}>Preview processing</button>
</div>
<style>
  .tideforge-processing{display:grid;gap:14px}.tideforge-processing>label{display:grid;gap:6px;max-width:280px}.tideforge-processing .check{display:flex;max-width:none;align-items:center;gap:8px}.fields,.selection-actions{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.fields label,.batch-field{display:grid;gap:6px;min-width:140px}.batch-heading{display:flex;align-items:center;gap:4px}.fish{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:8px}.fish label{display:flex;align-items:center;gap:8px;min-width:0;padding:10px;background:var(--panel-inset);border:1px solid var(--paper-edge)}.fish img{width:48px;height:48px;flex:none;object-fit:contain;image-rendering:pixelated}.fish span{min-width:0;overflow-wrap:anywhere}.guidance{color:var(--ink-muted);margin:0}small{display:block;color:var(--ink-muted)}button{justify-self:start;white-space:normal}
</style>
