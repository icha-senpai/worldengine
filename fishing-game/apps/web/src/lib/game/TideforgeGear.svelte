<script lang="ts">
  import { tick } from 'svelte';
  import type { Game } from './game.svelte';
  import type { GearInstance } from '@fishing-game/generated/types';
  import type { TideforgeIntent } from './tideforge-intents';
  import { affixes, affixDescription, affixName, affixSlotState, affixValues, canConsume, familyMastery, gearDefaultLabel, gearLabel, gearName, gearNickname, gearSprite, gearTitle, gearProtectionMessage, rolls, signatureDescription, signatureName } from './tideforge-ui';
  import { gearNameValidationMessage, normalizeGearName } from './gear-names';
  import { masteryHelp, strengthHelp } from './tideforge-copy';
  import raidPolicy from '../../../../../content/raids.json';
  import GameSelect from './GameSelect.svelte';
  import SearchInput from './SearchInput.svelte';
  import PageTurner from './PageTurner.svelte';
  import InfoButton from './InfoButton.svelte';
  import { tooltip } from './tooltip';
  let { game, onchange, onpreview }: {
    game: Game; onchange: () => void; onpreview: (intent: TideforgeIntent) => void;
  } = $props();
  let query = $state('');
  let kind = $state('');
  let page = $state(1);
  let editor = $state.raw<{ identity: string; playerId: bigint; instanceId: bigint } | null>(null);
  let draft = $state('');
  let saving = $state(false);
  let renameInput = $state<HTMLInputElement>();
  let opener: HTMLButtonElement | undefined;
  const filtered = $derived(game.gearInstances.filter(item =>
    (!kind || item.kind === kind) && (gearName(game, item).toLowerCase().includes(query.trim().toLowerCase())
      || gearNickname(game, item).toLowerCase().includes(query.trim().toLowerCase()))));
  const goalOptions = [
    { value: 'ranks', label: 'Ranks' }, { value: 'maxima', label: 'Rank maxima' }, { value: 'perfects', label: 'Perfects' },
  ] as const;
  function waterCondition(id: string, primary: number, secondary?: number | null): string {
    const condition = affixes.find(row => row.id === id)?.condition;
    if (!condition) return '';
    const water = condition === 'primary' ? primary : secondary;
    return `${condition === 'primary' ? 'Primary' : 'Secondary'} water: ${game.biomes.find(row => row.biomeId === water)?.name ?? 'Unknown water'}`;
  }
  $effect(() => {
    if (editor && (game.tideforgeIdentity !== editor.identity || game.player?.playerId !== editor.playerId
      || !game.gearInstances.some(row => row.instanceId === editor!.instanceId && row.playerId === editor!.playerId))) {
      editor = null; draft = ''; saving = false; opener = undefined; game.gearNameError = '';
    }
  });
  async function openRename(item: GearInstance, button: HTMLButtonElement) {
    if (!game.player || !game.tideforgeIdentity || !game.ready || game.busy || game.gearNamesAvailability !== 'ready') return;
    onchange();
    opener = button;
    editor = { identity: game.tideforgeIdentity, playerId: game.player.playerId, instanceId: item.instanceId };
    const current = editor;
    draft = gearNickname(game, item); game.gearNameError = '';
    await tick();
    if (editor === current) { renameInput?.focus(); renameInput?.select(); }
  }
  function closeRename(restoreFocus: boolean) {
    const button = opener;
    editor = null; draft = ''; saving = false; opener = undefined; game.gearNameError = '';
    if (restoreFocus) void tick().then(() => button?.focus());
  }
  function renameKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && !saving) { event.preventDefault(); closeRename(true); }
  }
  async function saveRename(name: string) {
    const current = editor;
    if (!current || saving || gearNameValidationMessage(name) || !game.ready || game.busy
      || game.gearNamesAvailability !== 'ready') return;
    saving = true;
    const saved = await game.renameGearInstance(current.instanceId, name);
    if (editor !== current || game.tideforgeIdentity !== current.identity || game.player?.playerId !== current.playerId) return;
    saving = false;
    if (saved) closeRename(false);
  }
</script>

<div class="tideforge-gear">
  {#if game.gearNamesAvailability === 'unavailable'}
    <p role="status">Gear names are unavailable on this server. Your gear still works. <button type="button" class="tideforge-action secondary" onclick={() => game.retryGearNames()}>Retry names</button></p>
  {/if}
  <div class="filters">
    <label>Find gear <SearchInput label="Find Tideforge gear" value={query} oninput={value => { query = value; page = 1; onchange(); }} /></label>
    <label>Kind <GameSelect label="Gear kind" value={kind} options={[{ value: '', label: 'All gear' }, { value: 'rod', label: 'Rods' }, { value: 'boat', label: 'Boats' }, { value: 'net', label: 'Nets' }]} onchange={value => { kind = value; page = 1; onchange(); }} /></label>
    <label>Collection goal <GameSelect label="Collection goal" value={game.tideforgeLoadout?.goal ?? 'ranks'} options={goalOptions} onchange={value => { onchange(); void game.setTideforgeGoal(value as 'ranks' | 'maxima' | 'perfects'); }} /></label>
    <InfoButton label="About collection goals" message={'Your goal changes eligible Tideforge effects. The fish species is still random.'} />
  </div>
  {#if !filtered.length}<p>No gear matches these filters.</p>{/if}
  <div class="cards">
    {#each filtered.slice((page - 1) * 12, page * 12) as item (item.instanceId)}
      {@const mastery = familyMastery(game, item)}
      {@const itemRolls = rolls(game, item)}
      {@const signature = game.tideforgeGear.find(row => row.instanceId === item.instanceId)?.nativeSignature}
      {@const sprite = gearSprite(game, item)}
      <article class="card">
        <div class="gear-art">
          {#if sprite}<img src={sprite} alt={gearName(game, item)} width="112" height="112" loading="lazy" />{:else}<span>Image unavailable</span>{/if}
        </div>
        <h3>{gearTitle(game, item)}</h3>
        {#if gearNickname(game, item)}<p class="gear-base-name">{gearDefaultLabel(game, item)}</p>{/if}
        {#if game.gearIsEquipped(item.instanceId) || item.favorite || gearProtectionMessage(game, item)}
          <p>{game.gearIsEquipped(item.instanceId) ? 'Equipped' : ''}{item.favorite ? `${game.gearIsEquipped(item.instanceId) ? ' · ' : ''}Favorite` : ''}
            {#if gearProtectionMessage(game, item)}<button type="button" class="tideforge-action secondary" aria-label={`Why ${gearLabel(game, item)} is protected`} use:tooltip={gearProtectionMessage(game, item)}>Protected</button>{/if}</p>
        {/if}
        <p>Quality {game.qualities.find(row => row.qualityLevel === item.qualityLevel)?.name ?? item.qualityLevel}
          · {item.reinforcementLevel ? `${raidPolicy.grades.find(row => row.grade === item.reinforcementLevel - 1)?.name ?? 'Unknown grade'} reinforcement` : 'Unreinforced'}</p>
        <p>Mastery {mastery.level} · {mastery.slots}/4 affix slots unlocked <InfoButton label="About mastery" message={masteryHelp} /></p>
        {#if itemRolls === null}<p role="alert">Affixes could not be read.</p>
        {:else if itemRolls.length}
          <ul>{#each itemRolls as roll (roll.slot)}<li>{affixName(roll.id)} · T{roll.tier} · Strength {roll.finishBp / 100}%
            <InfoButton label={`About ${affixName(roll.id)}`} message={`${affixDescription(roll.id)} ${strengthHelp}`} />
            {affixSlotState(roll.slot, mastery.slots, true) === 'dormant' ? ' · Inactive: needs more mastery' : ' · Active slot'}
            <small>{affixValues(roll.id, roll.tier, roll.finishBp) || affixDescription(roll.id)}{waterCondition(roll.id, item.primaryBiomeId, roll.secondaryBiomeId) ? ` · ${waterCondition(roll.id, item.primaryBiomeId, roll.secondaryBiomeId)}` : ''}</small></li>{/each}</ul>
        {:else}<p>No affixes yet.</p>{/if}
        {#if signature}<p>Item signature: {signatureName(signature)} <InfoButton label={`About ${signatureName(signature)}`} message={signatureDescription(signature)} /></p>{/if}
        <div class="actions">
          <button class="tideforge-action" disabled={game.busy || !game.ready || game.gearIsEquipped(item.instanceId)} onclick={() => { onchange(); void game.equipGearInstance(item.instanceId); }}>Equip</button>
          <button class="tideforge-action secondary" disabled={game.busy || !game.ready} onclick={() => { onchange(); void game.setGearInstanceFavorite(item.instanceId, !item.favorite); }}>{item.favorite ? 'Unfavorite' : 'Favorite'}</button>
          <button type="button" class="tideforge-action secondary" disabled={game.busy || !game.ready || game.gearNamesAvailability !== 'ready'} onclick={event => void openRename(item, event.currentTarget)}>Rename</button>
          <button class="tideforge-action secondary" disabled={game.busy || !game.ready || !canConsume(game, item) || itemRolls === null} onclick={() => onpreview({ action: 'dismantle', instance_id: item.instanceId.toString() })}>Dismantle</button>
        </div>
        {#if editor?.instanceId === item.instanceId}
          {@const validation = gearNameValidationMessage(draft)}
          <form class="rename-form" onsubmit={event => { event.preventDefault(); void saveRename(draft); }}>
            <label>Name for {gearDefaultLabel(game, item)}
              <input bind:this={renameInput} bind:value={draft} aria-label={`Name for ${gearDefaultLabel(game, item)}`} disabled={saving} oninput={() => game.gearNameError = ''} onkeydown={renameKeydown} />
            </label>
            <p>Free · up to 32 characters · leave blank to restore the default name.</p>
            <p>{Array.from(normalizeGearName(draft)).length} / 32 characters</p>
            {#if validation || game.gearNameError}<p role="alert" class="rename-error">{validation || game.gearNameError}</p>{/if}
            <div class="actions">
              <button type="submit" class="tideforge-action" disabled={saving || game.busy || !game.ready || game.gearNamesAvailability !== 'ready' || !!validation} onkeydown={renameKeydown}>Save name</button>
              <button type="button" class="tideforge-action secondary" disabled={saving} onclick={() => closeRename(true)} onkeydown={renameKeydown}>Cancel</button>
              {#if gearNickname(game, item)}<button type="button" class="tideforge-action secondary" disabled={saving || game.busy || !game.ready || game.gearNamesAvailability !== 'ready'} onclick={() => void saveRename('')} onkeydown={renameKeydown}>Use default name</button>{/if}
            </div>
          </form>
        {/if}
      </article>
    {/each}
  </div>
  <PageTurner bind:page total={filtered.length} pageSize={12} label="Tideforge gear pages" />
</div>

<style>
  .tideforge-gear{display:grid;gap:18px;min-width:0}.filters,.actions{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.filters label{display:grid;gap:6px;min-width:150px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card{padding:16px;border:2px solid var(--paper-edge);background:var(--panel-inset);min-width:0;overflow-wrap:anywhere}.gear-art{display:flex;align-items:center;justify-content:center;min-width:0;height:132px;box-sizing:border-box;margin-bottom:12px;border:1px solid var(--paper-edge);background:var(--paper)}.gear-art img{width:112px;height:112px;max-width:100%;object-fit:contain;image-rendering:pixelated}.card h3{font-family:var(--font-game);margin:0}.card p{margin:8px 0}.card ul{padding-left:20px}.card li{margin:7px 0}.card small,.gear-base-name{display:block;color:var(--ink-muted)}.actions button{max-width:100%;white-space:normal}.rename-form{display:grid;gap:8px;min-width:0;margin-top:14px}.rename-form label{display:grid;gap:6px;min-width:0}.rename-form input{width:100%;min-width:0;box-sizing:border-box}.rename-form p{margin:0}.rename-error{color:var(--error-ink,var(--ink))}@media(max-width:1199px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:699px){.cards{grid-template-columns:repeat(1,minmax(0,1fr))}}@media(pointer:coarse){.rename-form input,.tideforge-gear button{min-height:44px}}
</style>
