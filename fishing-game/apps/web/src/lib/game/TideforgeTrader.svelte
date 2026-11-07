<script lang="ts">
  import economy from '../../../../../content/tideforge-economy.json';
  import type { Game } from './game.svelte';
  import type { TideforgeIntent, TideforgeKind } from './tideforge-intents';
  import { gearName, waterOptions } from './tideforge-ui';
  import GameSelect from './GameSelect.svelte';
  import InfoButton from './InfoButton.svelte';
  let { game, onchange, onpreview }: { game: Game; onchange: () => void; onpreview: (intent: TideforgeIntent) => void } = $props();
  let originalId = $state('');
  let water = $state(0);
  const originals = $derived(game.gearInstances.filter(row => row.protectedOriginal
    && economy.families.some(family => family.familyId === row.familyId)));
  const original = $derived(originals.find(row => row.instanceId.toString() === originalId));
  const waters = $derived(original ? waterOptions(game, original.primaryBiomeId) : []);
  const price = $derived(economy.families.find(row => row.familyId === original?.familyId));
  const valid = $derived(!!original && waters.some(row => row.value === water));
  function chooseOriginal(value: string) { originalId = value; water = 0; onchange(); }
  function preview(action: 'camp_copy' | 'wandering_gamble') {
    if (!original || !valid) return;
    onpreview({ action, kind: original.kind as TideforgeKind, definition_id: original.definitionId, secondary_biome_id: water });
  }
</script>

<div class="tideforge-trader">
  <p>Choose a model you already own. New gear starts at Common quality with no reinforcement; matching gear shares mastery.</p>
  <div class="fields">
    <label>Gear model <GameSelect label="Gear model" value={originalId} options={[{ value: '', label: 'Choose gear' }, ...originals.map(row => ({ value: row.instanceId.toString(), label: gearName(game, row) }))]} onchange={chooseOriginal} /></label>
    <label>Secondary water <GameSelect label="Secondary water" value={water} options={[{ value: 0, label: 'Choose water' }, ...waters]} disabled={!original} onchange={value => { water = value; onchange(); }} /></label>
    <InfoButton label="About secondary water" message={'A Secondary Affinity affix uses this water. Other affixes ignore it. Selecting it does not unlock or grant access to that water.'} />
  </div>
  <div class="cards">
    <article><h3>Camp outfitter</h3><p>Random affixes, with a chance of an item signature.</p>
      {#if price}<p>Guide: {price.coins.toLocaleString()} coins</p>{/if}
      <button class="tideforge-action" disabled={!valid || game.busy || !game.ready || !!game.pendingTideforgeCommit} onclick={() => preview('camp_copy')}>Preview gear purchase</button>
    </article>
    <article><h3>Wandering trader</h3><p>Visits days {economy.acquisition.wandering.utcStartDayOfMonth}–{economy.acquisition.wandering.utcStartDayOfMonth + economy.acquisition.wandering.durationDays - 1} UTC each month. Higher-tier random affixes and a chance of a signature.</p>
      {#if price}<p>Guide: {price.marks.toLocaleString()} Dredge Marks</p>{/if}
      <button class="tideforge-action" disabled={!valid || game.busy || !game.ready || !!game.pendingTideforgeCommit} onclick={() => preview('wandering_gamble')}>Preview trader offer</button>
    </article>
  </div>
  {#if !originals.length}<p>Buy eligible gear from Camp first to unlock another of that model here.</p>{/if}
</div>
<style>
  .tideforge-trader{display:grid;gap:14px}.fields{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.fields label{display:grid;gap:6px;min-width:180px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,250px),1fr));gap:14px}.cards article{padding:18px;border:2px solid var(--paper-edge);background:var(--panel-inset)}h3{font-family:var(--font-game);margin:0}button{white-space:normal;max-width:100%}
</style>
