<script lang="ts">
  import type { Game } from './game.svelte';
  import { untrack } from 'svelte';
  import type { TideforgeIntent } from './tideforge-intents';
  import { affixDescription, affixName, familyMastery, forgeAffixChoices, forgeWaterChangeAllowed, gearLabel, rolls, waterOptions } from './tideforge-ui';
  import { masteryHelp, strengthHelp } from './tideforge-copy';
  import { availableGuideBalances, forgeActionProblem } from './ui-action-guidance';
  import { guideShortages, tideforgeCostGuide } from './tideforge-cost-guide';
  import CostGuide from './CostGuide.svelte';
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
  const ownedGear = $derived(game.gearInstances.filter(row => row.playerId === game.player?.playerId));
  const discoveries = $derived(game.tideforgeCodex.filter(row => row.playerId === game.player?.playerId));
  const item = $derived(ownedGear.find(row => row.instanceId.toString() === gearId));
  const itemRolls = $derived(item ? rolls(game, item) : []);
  const mastery = $derived(item ? familyMastery(game, item) : null);
  const selectedRoll = $derived(itemRolls?.find(row => row.slot === slot));
  const choices = $derived(item ? forgeAffixChoices(item.kind, discoveries, itemRolls ?? [], action, slot) : []);
  const waters = $derived(item ? waterOptions(game, item.primaryBiomeId) : []);
  function structuralProblem() {
    const currentItem = game.gearInstances.find(row => row.playerId === game.player?.playerId && row.instanceId.toString() === gearId);
    const currentRolls = currentItem ? rolls(game, currentItem) : [];
    const currentMastery = currentItem ? familyMastery(game, currentItem) : null;
    const currentRoll = currentRolls?.find(row => row.slot === slot);
    const currentWaters = currentItem ? waterOptions(game, currentItem.primaryBiomeId) : [];
    const currentChoices = currentItem ? forgeAffixChoices(currentItem.kind, game.tideforgeCodex.filter(row => row.playerId === game.player?.playerId), currentRolls ?? [], action, slot) : [];
    return forgeActionProblem({
      ready: game.ready, busy: game.busy, pending: !!game.pendingTideforgeCommit,
      hasItem: !!currentItem, detailsUnavailable: game.tideforgeDetailsUnavailable, rolls: currentRolls,
      mastery: currentMastery, booked: !!currentItem && game.gearIsBooked(currentItem.instanceId),
      action, slot, affix, tier,
      choices: currentChoices.filter(row => row.id !== 'secondary_affinity'
        || currentWaters.some(option => forgeWaterChangeAllowed(action, currentRoll, row.id, option.value))).map(row => row.id),
      waterValid: affix !== 'secondary_affinity' || currentWaters.some(row => row.value === water),
      changeAllowed: forgeWaterChangeAllowed(action, currentRoll, affix, water),
    });
  }
  function currentIntent(): TideforgeIntent | null {
    const currentItem = game.gearInstances.find(row => row.playerId === game.player?.playerId && row.instanceId.toString() === gearId);
    if (!currentItem) return null;
    const instance_id = currentItem.instanceId.toString();
    if (action === 'add_affix') return { action, instance_id, affix_id: affix, secondary_biome_id: affix === 'secondary_affinity' ? water : null };
    if (action === 'change_affix') return { action, instance_id, slot, affix_id: affix, secondary_biome_id: affix === 'secondary_affinity' ? water : null };
    if (action === 'upgrade_affix') return { action, instance_id, slot, target_tier: tier };
    return { action, instance_id, slot };
  }
  function currentGuide() {
    const intent = currentIntent();
    if (!intent || structuralProblem()) return null;
    const currentItem = game.gearInstances.find(row => row.playerId === game.player?.playerId && row.instanceId.toString() === gearId);
    const roll = currentItem ? rolls(game, currentItem)?.find(row => row.slot === slot) : undefined;
    return tideforgeCostGuide(intent, roll);
  }
  function currentProblem() {
    const structural = structuralProblem();
    if (structural) return structural;
    const balances = availableGuideBalances(game);
    if (!balances) return 'Cost details are still loading. Retry Tideforge details if needed.';
    const result = currentGuide();
    return result?.problem || (result?.plan ? guideShortages(result.plan, balances)[0] : '') || '';
  }
  const balances = $derived(availableGuideBalances(game));
  const guide = $derived(currentGuide());
  const blockedReason = $derived(currentProblem());
  $effect(() => {
    if (action === 'upgrade_affix' && selectedRoll && selectedRoll.tier < 10 && tier <= selectedRoll.tier) {
      tier = selectedRoll.tier + 1;
      untrack(onchange);
    }
  });
  function chooseGear(value: string) { gearId = value; slot = 0; affix = ''; water = 0; onchange(); }
  function preview() {
    const intent = currentIntent();
    if (!intent || currentProblem()) return;
    onpreview(intent);
  }
</script>
<div class="tideforge-forge">
  <p>Add and improve your gear’s affixes. Mastery opens slots at levels 1, 10, 25 and 50. <InfoButton label="About mastery" message={masteryHelp} /></p>
  <div class="fields">
    <label>Gear <GameSelect label="Forge gear" value={gearId} options={[{ value: '', label: 'Choose gear' }, ...ownedGear.map(row => ({ value: row.instanceId.toString(), label: gearLabel(game, row) }))]} onchange={chooseGear} /></label>
    <div class="field"><div class="field-heading">Action <InfoButton label="About Forge actions" message="Add fills an unlocked empty slot with a new T1 affix. Change keeps the slot, tier and strength while changing the type or water. Upgrade tier preserves the affix, strength and water. Improve strength preserves the affix, tier and water and uses the next effective step." /></div>
      <GameSelect label="Forge action" value={action} options={[{ value: 'add_affix', label: 'Add' }, { value: 'change_affix', label: 'Change' }, { value: 'upgrade_affix', label: 'Upgrade tier' }, { value: 'refine_affix', label: 'Improve strength' }]} onchange={value => { action = value; affix = ''; water = 0; onchange(); }} /></div>
  </div>
  {#if item && mastery}
    <p>Mastery {mastery.level} · {mastery.slots}/4 affix slots unlocked. At most two water affixes, including inactive slots.</p>
    {#if itemRolls !== null}
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
      {#if action === 'refine_affix' && selectedRoll && selectedRoll.finishBp < 10000}<p>{strengthHelp}</p>{/if}
    {/if}
  {/if}
  {#if guide && balances}<CostGuide result={guide} {balances} />{/if}
  {#if blockedReason}<p class="guidance" id="forge-blocked-reason">{blockedReason}</p>{/if}
  <button class="tideforge-action" disabled={!!blockedReason} aria-describedby={blockedReason ? 'forge-blocked-reason' : undefined} onclick={preview}>Preview Forge action</button>
</div>
<style>
  .tideforge-forge{display:grid;gap:14px}.fields{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.fields label,.field{display:grid;gap:6px;min-width:180px}.field-heading{display:flex;align-items:center;gap:4px}.slots{margin:0;padding:14px 14px 14px 32px;background:var(--panel-inset);border:2px solid var(--paper-edge)}.slots li{padding:4px}.guidance{color:var(--ink-muted);margin:0}button{justify-self:start;white-space:normal;max-width:100%}
</style>
