<script lang="ts">
  import type { Game } from './game.svelte';
  import { untrack } from 'svelte';
  import type { TideforgeIntent } from './tideforge-intents';
  import { affixDescription, affixName, familyMastery, forgeAffixChoices, forgeWaterChangeAllowed, gearLabel, rolls, waterOptions } from './tideforge-ui';
  import { masteryHelp, strengthHelp } from './tideforge-copy';
  import InfoButton from './InfoButton.svelte';
  import GameSelect from './GameSelect.svelte';
  import NumberStepper from './NumberStepper.svelte';
  let { game, onchange, onpreview }: { game: Game; onchange: () => void; onpreview: (intent: TideforgeIntent) => void } = $props();
  let gearId = $state('');
  let action = $state<'add_affix' | 'change_affix' | 'upgrade_affix' | 'refine_affix'>('add_affix');
  let slot = $state(0);
  let affix = $state('');
  let tier = $state(2);
  let water = $state(0);
  const item = $derived(game.gearInstances.find(row => row.instanceId.toString() === gearId));
  const itemRolls = $derived(item ? rolls(game, item) : []);
  const mastery = $derived(item ? familyMastery(game, item) : null);
  const selectedRoll = $derived(itemRolls?.find(row => row.slot === slot));
  const choices = $derived(item ? forgeAffixChoices(item.kind, game.tideforgeCodex, itemRolls ?? [], action, slot) : []);
  const waters = $derived(item ? waterOptions(game, item.primaryBiomeId) : []);
  const validAffix = $derived(choices.some(row => row.id === affix)
    && (affix !== 'secondary_affinity' || waters.some(row => row.value === water))
    && forgeWaterChangeAllowed(action, selectedRoll, affix, water));
  const valid = $derived(!!item && itemRolls !== null && !!mastery && !game.gearIsBooked(item.instanceId)
    && (action === 'add_affix' ? validAffix && Array.from({ length: mastery.slots }, (_, i) => i).some(i => !itemRolls.some(row => row.slot === i))
      : !!selectedRoll && slot < mastery.slots && (action === 'change_affix' ? validAffix
        : action === 'upgrade_affix' ? Number.isSafeInteger(tier) && tier > selectedRoll.tier && tier <= 10
          : selectedRoll.finishBp < 10000)));
  $effect(() => {
    if (action === 'upgrade_affix' && selectedRoll && selectedRoll.tier < 10 && tier <= selectedRoll.tier) {
      tier = selectedRoll.tier + 1;
      untrack(onchange);
    }
  });
  function chooseGear(value: string) { gearId = value; slot = 0; affix = ''; water = 0; onchange(); }
  function preview() {
    if (!item || !valid) return;
    const instance_id = item.instanceId.toString();
    if (action === 'add_affix') onpreview({ action, instance_id, affix_id: affix, secondary_biome_id: affix === 'secondary_affinity' ? water : null });
    else if (action === 'change_affix') onpreview({ action, instance_id, slot, affix_id: affix, secondary_biome_id: affix === 'secondary_affinity' ? water : null });
    else if (action === 'upgrade_affix') onpreview({ action, instance_id, slot, target_tier: tier });
    else onpreview({ action, instance_id, slot });
  }
</script>
<div class="tideforge-forge">
  <p>Add and improve your gear’s affixes. Mastery opens slots at levels 1, 10, 25 and 50. <InfoButton label="About mastery" message={masteryHelp} /></p>
  <div class="fields">
    <label>Gear <GameSelect label="Forge gear" value={gearId} options={[{ value: '', label: 'Choose gear' }, ...game.gearInstances.map(row => ({ value: row.instanceId.toString(), label: gearLabel(game, row) }))]} onchange={chooseGear} /></label>
    <label>Action <GameSelect label="Forge action" value={action} options={[{ value: 'add_affix', label: 'Add' }, { value: 'change_affix', label: 'Change' }, { value: 'upgrade_affix', label: 'Upgrade tier' }, { value: 'refine_affix', label: 'Improve strength' }]} onchange={value => { action = value; affix = ''; water = 0; onchange(); }} /></label>
  </div>
  {#if item && mastery}
    <p>Mastery {mastery.level} · {mastery.slots}/4 affix slots unlocked. At most two water affixes, including inactive slots.</p>
    {#if itemRolls === null}<p role="alert">Affixes could not be read.</p>
    {:else}
      <ol class="slots">{#each [0, 1, 2, 3] as index}
        {@const roll = itemRolls.find(row => row.slot === index)}
        <li>Slot {index + 1}: {roll ? `${affixName(roll.id)} · T${roll.tier} · Strength ${roll.finishBp / 100}%` : 'Empty'}{index >= mastery.slots ? roll ? ' · Inactive: needs more mastery' : ' · Locked' : ''}
          {#if roll}<InfoButton label={`About ${affixName(roll.id)}`} message={`${affixDescription(roll.id)} ${strengthHelp}`} />{/if}
        </li>
      {/each}</ol>
      <div class="fields">
        {#if action !== 'add_affix'}
          <label>Slot <GameSelect label="Affix slot" value={slot} options={itemRolls.map(row => ({ value: row.slot, label: `${row.slot + 1} · ${affixName(row.id)}`, disabled: row.slot >= mastery.slots }))} onchange={value => { slot = value; affix = ''; onchange(); }} /></label>
        {/if}
        {#if action === 'add_affix' || action === 'change_affix'}
          <label>Discovered affix <GameSelect label="Discovered affix" value={affix} options={[{ value: '', label: 'Choose affix' }, ...choices.map(row => ({ value: row.id, label: row.name }))]} onchange={value => { affix = value; water = 0; onchange(); }} /></label>
          {#if affix === 'secondary_affinity'}<label>Secondary water <GameSelect label="Secondary water" value={water} options={[{ value: 0, label: 'Choose water' }, ...waters]} onchange={value => { water = value; onchange(); }} /></label>{/if}
        {/if}
        {#if action === 'upgrade_affix' && selectedRoll && selectedRoll.tier < 10}<label>Target tier <NumberStepper label="Target tier" value={tier} min={selectedRoll.tier + 1} max={10} onchange={value => { tier = value; onchange(); }} /></label>{/if}
      </div>
      {#if affix && (action === 'add_affix' || action === 'change_affix')}<p>{affixDescription(affix)}</p>{/if}
      {#if action === 'add_affix' || action === 'change_affix'}<p>Only naturally discovered, compatible types are shown. Secondary waters do not grant access.</p>{/if}
      {#if action === 'upgrade_affix' && selectedRoll?.tier === 10}<p>This affix is already at tier 10; there is no higher tier.</p>{/if}
      {#if action === 'refine_affix'}<p>{selectedRoll?.finishBp === 10000 ? 'This affix strength is already fully improved.' : strengthHelp}</p>{/if}
      {#if game.gearIsBooked(item.instanceId)}<p>This gear is being used on a fishing trip or raid. Wait until it finishes.</p>{/if}
      <button class="tideforge-action" disabled={!valid || !game.ready || game.busy || !!game.pendingTideforgeCommit} onclick={preview}>Preview Forge action</button>
    {/if}
  {/if}
</div>
<style>
  .tideforge-forge{display:grid;gap:14px}.fields{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.fields label{display:grid;gap:6px;min-width:180px}.slots{margin:0;padding:14px 14px 14px 32px;background:var(--panel-inset);border:2px solid var(--paper-edge)}.slots li{padding:4px}button{justify-self:start;white-space:normal;max-width:100%}
</style>
