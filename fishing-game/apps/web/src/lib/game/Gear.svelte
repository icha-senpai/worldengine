<script lang="ts">
  import { untrack } from 'svelte';
  import { equippedBaits, stackedBaitBonuses, stackedMaterials } from './bait-loadout';
  import { baitEffect } from './bait-effects';
  import RaidArt from './RaidArt.svelte';
  import { deadlineExpired } from './server-clock';
  import type { Game } from './game.svelte.ts';
  import raidPolicy from '../../../../../content/raids.json';
  import { itemSlot } from './raid-items';
  import GameSelect from './GameSelect.svelte';
  import { hasOrdinaryRaidClearForGrade } from './raid-progress';
  import { gearDefaultLabel, gearNickname, gearTitle } from './tideforge-ui';
  import { equipTackleGear, tackleGearOptions, tackleGearValue } from './tackle-gear';
  import GearActionButton from './GearActionButton.svelte';
  import InfoButton from './InfoButton.svelte';
  let { game, active = true }: { game: Game; active?: boolean } = $props();
  const rod = $derived(game.rods.find(row => row.rodId === game.player?.equippedRodId));
  const equippedRod = $derived(game.equippedGearInstance('rod'));
  const bonuses = $derived(game.rodBonuses.find(row => row.rodId === rod?.rodId));
  const stats = $derived(game.rodStats(rod?.rodId ?? 0));
  const selectedBaits = $derived(equippedBaits(game).map(row => row.bait));
  const baitBonuses = $derived(stackedBaitBonuses(selectedBaits));
  const materialBonuses = $derived(stackedMaterials(selectedBaits));
  const baitSlots = [1, 2];
  const haulSlots = [{ kind: 'boat', label: 'boat' }, { kind: 'net', label: 'net' }] as const;
  const haulId = (kind: 'boat' | 'net') => kind === 'boat' ? game.haulLoadout?.boatId : game.haulLoadout?.netId;
  const rodOptions = $derived(tackleGearOptions(game, 'rod'));
  const next = $derived(game.qualities.find(row => row.qualityLevel === (stats.quality?.qualityLevel ?? 0)+1));
  const recipe = $derived(game.rodRecipes.find(row => row.rodId === rod?.rodId && row.qualityLevel === next?.qualityLevel) ?? ((game.config?.version ?? 0) < 9 ? next : undefined));
  const tin = $derived(game.items.find(row => row.item === 'rusted_tin')?.quantity ?? 0n);
  const scrap = $derived(game.items.find(row => row.item === 'scrap')?.quantity ?? 0n);
  let previewing = $state(false);
  let preparing = $state(false);
  let previewKind = $state<'rod' | 'haul'>('rod');
  let confirming = $state(false);
  let previewPlayerId = $state<bigint | null>(null);
  let previewInstanceId = $state<bigint | undefined>();
  let previewDefinitionId = $state<number | undefined>();
  let previewGearKind = $state<'rod' | 'boat' | 'net'>('rod');
  let previewRevision = 0;
  let selectionRevision = 0;
  let selectionError = $state('');
  let dialog: HTMLDialogElement;
  let reinforceDialog: HTMLDialogElement;
  let cancelButton = $state<HTMLButtonElement>();
  let reinforcing = $state(false);
  let reinforcePending = $state<{ rodId: number; expectedLevel: number; playerId: bigint; instanceId: bigint | undefined } | null>(null);
  const pendingQuote = $derived(previewKind === 'haul' ? game.haulUpgradeQuote : game.upgradeQuote);
  const quote = $derived(previewing && pendingQuote && !pendingQuote.consumed ? pendingQuote : null);
  const upgradeTarget = $derived(quote ? 'equipmentId' in quote ? game.haulEquipment.find(row => row.equipmentId === quote.equipmentId) : game.rods.find(row => row.rodId === quote.rodId) : undefined);
  const upgradeKind = $derived(upgradeTarget && 'kind' in upgradeTarget ? upgradeTarget.kind : 'rod');
  const expired = $derived(deadlineExpired(quote?.expiresAt.microsSinceUnixEpoch ?? null, game.serverNowMs));
  const reinforcementLevel = (rodId: number) => game.rodStats(rodId).reinforcementLevel;
  const selectedCopy = (kind: 'rod' | 'boat' | 'net') => {
    const instance = game.equippedGearInstance(kind);
    return !!instance && !instance.protectedOriginal;
  };
  const previewDefinition = () => previewGearKind === 'rod' ? game.player?.equippedRodId : haulId(previewGearKind);
  const ordinarySpeciesIds = $derived(new Set(game.species.filter(row => row.countsForOrdinaryCollectionCompletion).map(row => row.speciesId)));
  const reinforcementGrade = (rodId: number) => reinforcementLevel(rodId) > 0
    ? game.raidGrades.find(row => row.grade === reinforcementLevel(rodId) - 1)
    : undefined;
  const nextReinforcement = (rodId: number) => game.raidGrades.find(row => row.grade === reinforcementLevel(rodId));
  const reinforcementMaterials = (rodId: number) => {
    const grade = reinforcementLevel(rodId);
    return {
      fillets: game.items.find(row => itemSlot(row.key) === raidPolicy.materials[0].slotBase + grade)?.quantity ?? 0n,
      scales: game.items.find(row => itemSlot(row.key) === raidPolicy.materials[1].slotBase + grade)?.quantity ?? 0n
    };
  };
  const reinforcementClear = (rodId: number) => {
    const next = nextReinforcement(rodId);
    return !!next && hasOrdinaryRaidClearForGrade(
      game.raidBadges, ordinarySpeciesIds, game.player?.playerId, next.grade
    );
  };
  const canReinforce = (rodId: number) => {
    const next = nextReinforcement(rodId);
    const materials = reinforcementMaterials(rodId);
    return !game.equipmentInformationUnavailable && !selectedCopy('rod') && game.raidsSupported && !!next && reinforcementClear(rodId) && materials.fillets >= next.fillets
      && materials.scales >= next.scales && (game.player?.coins ?? 0n) >= next.reinforceCoins;
  };
  const reinforcementName = (rodId: number) => reinforcementGrade(rodId)?.name ?? 'Unreinforced';
  function requestReinforce(rodId: number) {
    if (!game.player || !game.ready || game.busy || !canReinforce(rodId)) return;
    reinforcePending = { rodId, expectedLevel: reinforcementLevel(rodId), playerId: game.player.playerId,
      instanceId: game.equippedGearInstance('rod')?.instanceId };
    reinforceDialog.showModal();
  }
  async function confirmReinforce() {
    if (!reinforcePending || reinforcing || game.busy || !game.ready
      || game.player?.playerId !== reinforcePending.playerId
      || game.player?.equippedRodId !== reinforcePending.rodId
      || game.equippedGearInstance('rod')?.instanceId !== reinforcePending.instanceId
      || reinforcementLevel(reinforcePending.rodId) !== reinforcePending.expectedLevel
      || !canReinforce(reinforcePending.rodId)) return;
    reinforcing = true;
    try {
      if (await game.reinforceRod(reinforcePending.rodId, reinforcePending.expectedLevel)) reinforceDialog.close();
    } finally {
      reinforcing = false;
    }
  }
  $effect(() => {
    if (reinforcePending && (!game.ready || game.equipmentInformationUnavailable || game.player?.playerId !== reinforcePending.playerId
      || game.player?.equippedRodId !== reinforcePending.rodId
      || game.equippedGearInstance('rod')?.instanceId !== reinforcePending.instanceId || selectedCopy('rod')
      || reinforcementLevel(reinforcePending.rodId) !== reinforcePending.expectedLevel)) {
      reinforcePending = null;
      if (reinforceDialog?.open) reinforceDialog.close();
    }
  });
  $effect(() => {
    if ((previewing || preparing) && (!game.ready || game.equipmentInformationUnavailable || game.player?.playerId !== previewPlayerId
      || previewDefinition() !== previewDefinitionId || game.equippedGearInstance(previewGearKind)?.instanceId !== previewInstanceId
      || selectedCopy(previewGearKind))) {
      previewRevision++;
      previewing = false;
      preparing = false;
    }
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
  const percent = (bp: number | undefined) => (bp ?? 0)/100;
  function clearPreviews() {
    previewRevision++;
    previewing = false;
    preparing = false;
    reinforcePending = null;
    if (dialog?.open) dialog.close();
    if (reinforceDialog?.open) reinforceDialog.close();
  }
  $effect(() => { if (!active) untrack(clearPreviews); });
  async function equip(kind: 'rod' | 'boat' | 'net', value: string) {
    clearPreviews();
    selectionError = '';
    const revision = ++selectionRevision;
    const playerId = game.player?.playerId, identity = game.authenticatedIdentity;
    const success = await equipTackleGear(game, kind, value);
    if (!success && revision === selectionRevision && playerId === game.player?.playerId && identity === game.authenticatedIdentity) {
      selectionError = value.startsWith('instance:') ? game.tideforgeError || 'Could not equip this gear. Try again.'
        : game.error || 'Could not equip this gear. Try again.';
    }
  }
  let selectionOwner = $state<string | undefined>();
  $effect(() => {
    const owner = `${game.authenticatedIdentity ?? ''}:${game.player?.playerId ?? ''}`;
    if (selectionOwner !== undefined && selectionOwner !== owner) { selectionError = ''; selectionRevision++; }
    selectionOwner = owner;
  });
  const baitIdInSlot = (slot: number) => slot === 1 ? game.baitLoadout?.baitId ?? 0 : game.baitLoadout?.secondaryBaitId ?? 0;
  async function equipBait(baitId: number, slot: number) { await game.equipBait(baitId, slot); }
  async function previewUpgrade() {
    if (!rod || !game.player || game.busy || !game.ready || game.equipmentInformationUnavailable || selectedCopy('rod')) return;
    const rodId = rod.rodId;
    const revision = ++previewRevision, instanceId = game.equippedGearInstance('rod')?.instanceId;
    previewPlayerId = game.player.playerId;
    previewGearKind = 'rod'; previewInstanceId = instanceId; previewDefinitionId = rodId;
    previewing = false;
    preparing = true;
    previewKind = 'rod';
    await game.previewUpgrade(rodId);
    previewing = revision === previewRevision && !game.error && game.ready && game.player?.playerId === previewPlayerId
      && game.player?.equippedRodId === rodId && game.equippedGearInstance('rod')?.instanceId === instanceId
      && !selectedCopy('rod') && game.upgradeQuote?.rodId === rodId;
    if (revision === previewRevision) preparing = false;
  }
  async function confirmUpgrade() {
    if (!quote || deadlineExpired(quote.expiresAt.microsSinceUnixEpoch, game.serverNowMs) || confirming || game.busy || !game.ready || game.equipmentInformationUnavailable) return;
    if (game.player?.playerId !== previewPlayerId || game.equippedGearInstance(previewGearKind)?.instanceId !== previewInstanceId
      || previewDefinition() !== previewDefinitionId || selectedCopy(previewGearKind)) return;
    confirming = true;
    try {
      if ('equipmentId' in quote) await game.confirmHaulUpgrade(quote.nonce);
      else await game.confirmUpgrade(quote.nonce);
      if (!game.error) previewing = false;
    }
    finally { confirming = false; }
  }
  async function previewHaulUpgrade(equipmentId: number) {
    const kind = game.haulEquipment.find(row => row.equipmentId === equipmentId)?.kind;
    if (!game.player || game.busy || !game.ready || game.equipmentInformationUnavailable
      || (kind !== 'boat' && kind !== 'net') || selectedCopy(kind)) return;
    const revision = ++previewRevision, instanceId = game.equippedGearInstance(kind)?.instanceId;
    previewPlayerId = game.player.playerId;
    previewGearKind = kind; previewInstanceId = instanceId; previewDefinitionId = equipmentId;
    previewing = false;
    preparing = true;
    previewKind = 'haul';
    await game.previewHaulUpgrade(equipmentId);
    previewing = revision === previewRevision && !game.error && game.ready && game.player?.playerId === previewPlayerId
      && haulId(kind) === equipmentId && game.equippedGearInstance(kind)?.instanceId === instanceId
      && !selectedCopy(kind) && game.haulUpgradeQuote?.equipmentId === equipmentId;
    if (revision === previewRevision) preparing = false;
  }
  function cancelUpgrade(event?: Event) {
    if (confirming) { event?.preventDefault(); return; }
    previewing = false;
  }
  function dismissBackdrop(event: PointerEvent) {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) cancelUpgrade();
  }
  function actionAvailability() {
    return !game.ready ? ' Wait for the connection to recover.' : game.busy ? ' Wait for the current action to finish.' : '';
  }
  function rodUpgradeMessage() {
    if (game.equipmentInformationUnavailable) return 'Equipment details are unavailable. Reconnect before upgrading.';
    if (selectedCopy('rod')) return 'Quality upgrades are not available for this copy here yet.';
    if (!rod) return 'Your rod details are loading.';
    if (!next || !recipe) return stats.quality?.qualityLevel === 6 ? 'This rod is fully upgraded.' : 'Upgrade details are loading.';
    return `Next quality: ${next.name}. Rusted tin: ${tin} / ${recipe.tinCost}. Scrap: ${scrap} / ${recipe.scrapCost}. `
      + `Power +${next.powerBonus - (stats.quality?.powerBonus ?? 0)} · luck +${percent(next.luckBp - (stats.quality?.luckBp ?? 0))}% `
      + `· XP +${percent(next.xpBonusBp - (stats.quality?.xpBonusBp ?? 0))}%.${actionAvailability()}`;
  }
  function haulUpgradeMessage(kind: 'boat' | 'net', equipmentId: number | undefined) {
    if (game.equipmentInformationUnavailable) return 'Equipment details are unavailable. Reconnect before upgrading.';
    if (selectedCopy(kind)) return 'Quality upgrades are not available for this copy here yet.';
    const equipment = game.haulEquipment.find(row => row.equipmentId === equipmentId && row.kind === kind);
    if (!equipment) return 'Your gear details are loading.';
    const current = game.haulStats(equipment.equipmentId);
    const nextQuality = game.qualities.find(row => row.qualityLevel === (current.quality?.qualityLevel ?? 0) + 1);
    const cost = game.rodRecipes.find(row => row.rodId === equipment.rodId && row.qualityLevel === nextQuality?.qualityLevel);
    if (!nextQuality || !cost) return current.quality?.qualityLevel === 6 ? 'This gear is fully upgraded.' : 'Upgrade details are loading.';
    return `Next quality: ${nextQuality.name}. Rusted tin: ${tin} / ${cost.tinCost}. Scrap: ${scrap} / ${cost.scrapCost}. `
      + `Luck +${percent((nextQuality.luckBp - (current.quality?.luckBp ?? 0)) * current.qualityMultiplier)}% `
      + `· XP +${percent((nextQuality.xpBonusBp - (current.quality?.xpBonusBp ?? 0)) * current.qualityMultiplier)}%.${actionAvailability()}`;
  }
  function reinforceMessage(rodId: number) {
    if (game.equipmentInformationUnavailable) return 'Equipment details are unavailable. Reconnect before reinforcing.';
    if (selectedCopy('rod')) return 'Reinforcement is not available for this copy here yet.';
    if (!game.raidsSupported) return 'Rod reinforcement is not available here yet.';
    const current = reinforcementGrade(rodId), nextGrade = nextReinforcement(rodId), mats = reinforcementMaterials(rodId);
    const focus = `Reinforcement is separate from quality. Collection Focus ${current ? `has a ${current.focusChanceBp / 100}% chance` : 'uses its unreinforced base chance'} `
      + `when enabled in a mastered biome. ${current ? `${current.perfectBonusRolls} bonus rolls toward perfect size apply` : 'Bonus rolls toward perfect size begin at the first raid grade and apply'} `
      + 'to selected ordinary UUR fish only after all ten ranks are recorded; special species are excluded.';
    if (!nextGrade) return `${focus} ${reinforcementLevel(rodId) === raidPolicy.grades.length ? 'This rod is fully reinforced.' : 'Reinforcement grades are loading.'}`;
    const clear = reinforcementClear(rodId) ? '' : ` Clear ${nextGrade.name} on an ordinary raid boss first.`;
    return `${focus} Next: ${nextGrade.name}. ${nextGrade.name} fillets: ${mats.fillets} / ${nextGrade.fillets}. `
      + `${nextGrade.name} scales: ${mats.scales} / ${nextGrade.scales}. Coins: ${game.player?.coins ?? 0n} / ${nextGrade.reinforceCoins}. `
      + `Next Focus: ${nextGrade.focusChanceBp / 100}% and ${nextGrade.perfectBonusRolls} UUR bonus rolls.${clear}${actionAvailability()}`;
  }
</script>
<div class="gear" role="region" aria-labelledby="gear-title">
  <div class="heading"><div class="heading-title"><h3 id="gear-title">Gear</h3><InfoButton label="About gear stats" message="Base equipment and equipped bait bonuses are shown. Tideforge effects are separate. Power contributes to bonus pulls; Luck affects fishing outcomes; XP boosts fishing XP. Boats and nets use no bait." /></div><p>Ready for the next cast.</p></div>
  {#if game.tideforgeEffectsActive}<p class="note">Base equipment and bait stats only. Tideforge bonuses are not included.</p>{/if}
  {#if game.equipmentInformationUnavailable}<p class="note" role="status">Equipment details are unavailable. Reconnect before upgrading.</p>{/if}
  {#if selectionError}<p class="note" role="alert">{selectionError}</p>{/if}
  <div class="slots">
    <div class="equipment-slots">
      <article class="equipment-slot rod-slot" aria-label="Equipped rod" data-quality={stats.quality?.qualityLevel ?? 0}>
        <div class="art">{#if bonuses}<img src={bonuses.spriteAsset} alt={rod?.name ?? 'Fishing rod'} width="160" height="160" />{:else}<span aria-hidden="true">🎣</span>{/if}</div>
        <div class="details"><small>EQUIPPED ROD</small><span class="quality">{stats.quality?.name ?? 'Common'}</span>{#if rod}<span class="reinforcement-grade">{reinforcementLevel(rod.rodId) > 0 ? `${reinforcementName(rod.rodId)} reinforcement` : 'Unreinforced'}</span>{/if}<h4>{equippedRod ? gearTitle(game, equippedRod) : rod?.name ?? 'Loading your rod…'}</h4>
          {#if equippedRod && gearNickname(game, equippedRod)}<p class="note gear-base-name">{gearDefaultLabel(game, equippedRod)}</p>{/if}
          {#if rod}<dl><div><dt>Power</dt><dd>{stats.power}</dd></div><div><dt>Bonus pull</dt><dd>{stats.power/2}%</dd></div><div><dt>Luck with bait</dt><dd>+{percent(stats.luckBp+baitBonuses.luckBp)}%</dd></div><div><dt>Fishing XP</dt><dd>+{percent(stats.xpBonusBp+baitBonuses.xpBonusBp)}%</dd></div></dl>{/if}
          <label for="gear-rod">Switch rod</label><GameSelect id="gear-rod" label="Switch rod" value={tackleGearValue(game, 'rod')} options={rodOptions} disabled={!game.ready || game.busy || game.equipmentInformationUnavailable || !rodOptions.length} onchange={value => equip('rod', value)} />
          <GearActionButton label="Upgrade" message={rodUpgradeMessage()} controls="gear-upgrade-dialog"
            disabled={!game.ready || game.busy || game.equipmentInformationUnavailable || selectedCopy('rod') || !rod || !next || !recipe || tin < recipe.tinCost || scrap < recipe.scrapCost}
            onclick={previewUpgrade} />
          {#if stats.quality?.qualityLevel === 6}<p class="mastered">Prismatic · fully upgraded</p>{/if}
          {#if rod}
            <section class="rod-reinforcement-upgrade" aria-label={`${rod.name} rod reinforcement`} data-rod-id={rod.rodId}>
              <GearActionButton label="Reinforce" message={reinforceMessage(rod.rodId)}
                disabled={!game.ready || game.busy || !canReinforce(rod.rodId)} onclick={() => requestReinforce(rod.rodId)} />
              {#if reinforcementLevel(rod.rodId) === raidPolicy.grades.length}<p class="mastered">UUR fully reinforced</p>{/if}
            </section>
          {/if}
        </div>
      </article>
      {#each haulSlots as slot (slot.kind)}
        {@const selected = game.haulEquipment.find(row => row.equipmentId === haulId(slot.kind))}
        {@const equipped = game.equippedGearInstance(slot.kind)}
        {@const options = tackleGearOptions(game, slot.kind)}
        {@const haulStats = game.haulStats(selected?.equipmentId ?? 0)}
        {@const trip = game.haulTrips.filter(row => row.kind === slot.kind).sort((a,b) => Number(b.startedAt.microsSinceUnixEpoch - a.startedAt.microsSinceUnixEpoch))[0]}
        {@const nextQuality = game.qualities.find(row => row.qualityLevel === (haulStats.quality?.qualityLevel ?? 0) + 1)}
        {@const haulRecipe = game.rodRecipes.find(row => row.rodId === selected?.rodId && row.qualityLevel === nextQuality?.qualityLevel)}
        <article class="equipment-slot haul-slot" aria-label={`Equipped ${slot.label}`} data-quality={haulStats.quality?.qualityLevel ?? 0}>
          <div class="art">{#if selected}<img src={selected.spriteAsset} alt={selected.name} width="160" height="160" />{:else}<span aria-hidden="true">—</span>{/if}</div>
          <div class="details"><small>EQUIPPED {slot.label.toUpperCase()}</small><span class="quality">{haulStats.quality?.name ?? 'Common'}</span><h4>{equipped ? gearTitle(game, equipped) : selected?.name ?? `Loading your ${slot.label}…`}</h4>
            {#if equipped && gearNickname(game, equipped)}<p class="note gear-base-name">{gearDefaultLabel(game, equipped)}</p>{/if}
            {#if selected}<dl><div><dt>Luck</dt><dd>+{percent(haulStats.luckBp)}%</dd></div><div><dt>Fishing XP</dt><dd>+{percent(haulStats.xpBonusBp)}%</dd></div></dl>{/if}
            <p class="note">No bait required.</p>
            <label for={`gear-${slot.kind}`}>Switch {slot.label}</label>
            <GameSelect id={`gear-${slot.kind}`} label={`Switch ${slot.label}`} value={tackleGearValue(game, slot.kind)} {options} disabled={!game.ready || game.busy || game.equipmentInformationUnavailable || !options.length} onchange={value => equip(slot.kind, value)} />
            {#if trip?.status === 'running'}
              <p class="haul-status" role="status">{trip.equipmentName} in {trip.biome.name} · {#if game.serverNowMs === null}Syncing server time…{:else}{Math.max(0, Math.ceil((Number(trip.dueAt.microsSinceUnixEpoch / 1000n) - game.serverNowMs) / 60000)) || 'Finishing'}{trip.dueAt.microsSinceUnixEpoch > BigInt(Math.floor(game.serverNowMs)) * 1000n ? ' min remaining' : '…'}{/if} · {slot.kind === 'boat' ? 10 : 4} catches. Your trip keeps its starting gear.</p>
            {:else if trip?.completedAt}
              <p class="haul-status">Last haul: {trip.results.length} catches · +{trip.totalXp.toString()} XP · +{trip.totalCoins.toString()} coins. Start another with /fish{slot.kind} in Discord.</p>
            {:else}<p class="haul-status">Use /fish{slot.kind} in Discord · {slot.kind === 'boat' ? '10 minutes, 10' : '4 minutes, 4'} catches.</p>{/if}
            <GearActionButton label="Upgrade" message={haulUpgradeMessage(slot.kind, selected?.equipmentId)} controls="gear-upgrade-dialog"
              disabled={!game.ready || game.busy || game.equipmentInformationUnavailable || selectedCopy(slot.kind) || !selected || !nextQuality || !haulRecipe || tin < haulRecipe.tinCost || scrap < haulRecipe.scrapCost}
              onclick={() => selected && previewHaulUpgrade(selected.equipmentId)} />
            {#if haulStats.quality?.qualityLevel === 6}<p class="mastered">Prismatic · fully upgraded</p>{/if}
          </div>
        </article>
      {/each}
    </div>
    <div class="bait-slots">
      {#each baitSlots as slot}
        {@const bait = game.baits.find(row => row.baitId === baitIdInSlot(slot))}
        {@const uses = game.ownedBaits.find(row => row.baitId === bait?.baitId)?.usesLeft ?? 0n}
        <article class="bait-slot" aria-label={`Bait slot ${slot}`}>
          <div class="art bait-art">{#if bait}<img src={bait.spriteAsset} alt={bait.name} width="100" height="100" />{:else}<span aria-hidden="true">—</span>{/if}</div>
          <div class="details"><small>BAIT SLOT {slot}</small><h4>{bait?.name ?? 'No bait equipped'}</h4>
            {#if bait}<p class="uses">{uses.toString()} shared uses remaining</p><p>{baitEffect(bait)}.</p>{/if}
            <label for={`gear-bait-${slot}`}>Switch bait in slot {slot}</label><GameSelect id={`gear-bait-${slot}`} label={`Switch bait in slot ${slot}`} value={baitIdInSlot(slot)} options={[{value:0,label:"No bait"},...game.baits.map(option=>{const stock=game.ownedBaits.find(row=>row.baitId===option.baitId)?.usesLeft??0n;const required=baitIdInSlot(slot===1?2:1)===option.baitId?2n:1n;return {value:option.baitId,label:option.name+" · "+stock.toString()+" shared uses",disabled:stock<required};})]} disabled={!game.ready || game.busy || slot === 2 && (game.config?.version ?? 0) < 14} onchange={value => equipBait(value, slot)} />
          </div>
        </article>
      {/each}
      <p class="note">Both slots are available from level 1. Any pairing works, including matching bait. Each active slot spends one use per accepted cast, including bonus pulls. Depleted slots clear automatically; shared stock goes to slot 1 first.</p>
      <p class="note">Combined bait: +{percent(baitBonuses.luckBp)}% luck · +{percent(baitBonuses.xpBonusBp)}% XP · {baitBonuses.sizeRolls} size {baitBonuses.sizeRolls === 1 ? 'attempt' : 'attempts'}{materialBonuses ? ' · ' + materialBonuses : ''}.</p><a href="#trader/camp">Buy bait at the Camp shop →</a>
    </div>
  </div>
  <p class="note">Power adds a chance of one extra fish, junk or treasure pull. Luck favors higher fish ranks. Any owned rod can fish in waters unlocked by your level and licence.</p>
  <p class="note"><a href="#trader/camp">Find more boats and nets at the Camp shop →</a></p>
</div>
<dialog class="upgrade-dialog" bind:this={reinforceDialog} aria-labelledby="reinforce-title" onclose={() => reinforcePending = null}>
  {#if reinforcePending}
    {@const selectedReinforcement = reinforcePending!}
    {@const nextGrade = nextReinforcement(selectedReinforcement.rodId)}
    {@const materials = reinforcementMaterials(selectedReinforcement.rodId)}
    {@const name = game.rods.find(row => row.rodId === selectedReinforcement.rodId)?.name ?? 'Rod'}
    <h2 id="reinforce-title">Confirm per-rod reinforcement</h2>
    <h3>{name} → {nextGrade?.name ?? 'Mastered'} reinforced</h3>
    <p>Quality stays unchanged. Confirming spends materials and coins for this rod only. The server verifies the captured rod, expected level, ownership, and resources.</p>
    {#if nextGrade}<dl><div><dt class="reinforcement-material-title"><RaidArt kind="fillets" grade={nextGrade.grade} size={24} />{nextGrade.name} fillets</dt><dd>{nextGrade.fillets} · owned {materials.fillets}</dd></div><div><dt class="reinforcement-material-title"><RaidArt kind="scales" grade={nextGrade.grade} size={24} />{nextGrade.name} scales</dt><dd>{nextGrade.scales} · owned {materials.scales}</dd></div><div><dt>Coins</dt><dd>{nextGrade.reinforceCoins}</dd></div><div><dt>Next Focus benefit</dt><dd>{nextGrade.focusChanceBp / 100}% · {nextGrade.perfectBonusRolls} endpoint rolls for eligible ordinary UUR fish</dd></div></dl>{/if}
    {#if nextGrade && !reinforcementClear(reinforcePending!.rodId)}<p class="upgrade-error">Clear {nextGrade.name} on any ordinary raid boss before reinforcing to this level.</p>{/if}
    {#if game.error}<p class="upgrade-error" role="alert">{game.error}</p>{/if}
    <div class="upgrade-actions"><button class="secondary" disabled={reinforcing || game.busy} onclick={() => reinforceDialog.close()}>Cancel</button>
      <button disabled={reinforcing || game.busy || !game.ready || !nextGrade || !canReinforce(selectedReinforcement.rodId)} onclick={confirmReinforce}>{reinforcing ? 'Reinforcing…' : 'Confirm reinforcement'}</button>
    </div>
  {/if}
</dialog>
<dialog id="gear-upgrade-dialog" class="upgrade-dialog" bind:this={dialog} aria-labelledby="gear-upgrade-title" aria-describedby="gear-upgrade-description" aria-busy={confirming} onclose={() => previewing = false} oncancel={cancelUpgrade} onpointerdown={dismissBackdrop}>
  {#if quote}
    {@const quality = game.qualities.find(row => row.qualityLevel === quote.fromQuality+1)}
    <h2 id="gear-upgrade-title">Confirm {upgradeKind} upgrade</h2>
    <h3>Upgrade to {quality?.name} {upgradeTarget?.name}?</h3>
    <p id="gear-upgrade-description">Guaranteed success. This permanent upgrade stays with this {upgradeKind}. Materials are spent when you confirm.</p>
    <dl><div><dt>Rusted tin</dt><dd>{quote.tinCost.toLocaleString()}</dd></div><div><dt>Scrap</dt><dd>{quote.scrapCost.toLocaleString()}</dd></div></dl>
    {#if expired || game.error}<p class="upgrade-error" role="alert">{game.serverNowMs === null ? 'Syncing server time…' : expired ? 'This preview expired. Cancel and preview the upgrade again.' : game.error}</p>{/if}
    <div class="upgrade-actions">
      <button class="secondary" bind:this={cancelButton} disabled={confirming} onclick={() => cancelUpgrade()}>Cancel</button>
      <button disabled={confirming || game.busy || !game.ready || expired} onclick={confirmUpgrade}>{confirming ? 'Upgrading…' : 'Confirm upgrade'}</button>
    </div>
  {/if}
</dialog>
<style>
  .equipment-slots{display:grid;grid-auto-rows:1fr;gap:22px;min-width:0}.haul-slot .note{margin:0 0 14px}.note a{color:var(--success-ink)}.details h4,.gear-base-name{overflow-wrap:anywhere}
  .rod-reinforcement-upgrade{display:grid;gap:8px;width:100%;min-width:0;margin-top:14px;padding:10px;background:var(--panel-inset);border:1px dashed var(--paper-edge);box-sizing:border-box}.reinforcement-grade{display:inline-block;margin:4px 0 0 10px;padding:3px 7px;border:1px solid var(--paper-edge);font-size:13px;background:var(--panel-inset)}.reinforcement-material-title{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .bait-slots{display:grid;gap:16px;align-content:start;min-width:0}.bait-slots>a{font-size:16px;color:var(--success-ink)}
  .gear{margin-bottom:26px;padding-bottom:24px;border-bottom:1px dashed var(--paper-edge);color:light-dark(var(--wood-dark,#493126),var(--ink))}
  .heading{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:14px}.heading-title{display:flex;align-items:center}h3,h4,p{margin:0}h3,h4{font-family:var(--font-game);font-weight:400}h3{font-size:30px}h4{font-size:32px;margin:6px 0 12px;overflow-wrap:anywhere}.heading p,.note{font-size:16px;color:var(--ink-muted);line-height:1.8}.note{margin-top:15px}
  .slots{display:grid;align-items:start;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:22px}article{display:flex;gap:18px;align-items:center;padding:24px;background:var(--paper-light);border:2px solid var(--paper-edge);box-shadow:2px 3px 0 var(--panel-highlight);min-width:0} .art{color:var(--art-ink);display:grid;place-items:center;flex:none;width:160px;height:160px;background:var(--art-background);border:3px solid var(--paper-edge);box-shadow:inset 0 0 0 3px var(--panel-highlight)}.art img{width:100%;height:100%;padding:8px;object-fit:contain;image-rendering:pixelated}.art span{font-size:64px}.details{flex:1;min-width:0}.details small{font-size:14px;letter-spacing:1px;color:var(--ink)}dl{display:flex;gap:16px;flex-wrap:wrap;margin:0 0 16px}dl div{display:grid;gap:4px}dt{font-size:14px;color:var(--ink)}dd{font-family:var(--font-game);font-size:27px;margin:0;color:var(--success-ink)}label{display:block;font-size:14px;margin-bottom:6px}.gear :global(.game-select){width:100%;max-width:340px}.bait-slot{background:var(--paper-light);border-style:dashed;box-shadow:none}.bait-slot p{font-size:16px;line-height:1.8;margin-top:12px;color:var(--ink-muted)}
  @media(max-width:1000px){.slots{grid-template-columns:1fr 1fr}article{flex-direction:column;align-items:flex-start}.art{align-self:center}.bait-slot{justify-content:center}.details{width:100%}}
  @media(max-width:600px){.slots{grid-template-columns:1fr}.heading{align-items:flex-start;flex-direction:column;gap:4px}article{flex-direction:row;gap:12px;padding:12px}.art{width:110px;height:110px}h4{font-size:27px}dl{gap:10px}dd{font-size:22px}dt{font-size:12px}.note{font-size:13px}}
  @media(max-width:600px){.bait-slot{flex-direction:column;align-items:stretch}.bait-art{align-self:center}}
  @media(max-width:360px){.equipment-slot,.bait-slot{flex-direction:column}.bait-art{align-self:center}.art{width:140px;height:140px}}

  .quality{display:inline-block;margin-left:10px;padding:3px 7px;border:1px solid var(--paper-edge);font-size:14px;background:var(--panel-inset)}:is(.rod-slot,.haul-slot)[data-quality="1"] .quality{background:light-dark(#c8deaf, #2b422b)}:is(.rod-slot,.haul-slot)[data-quality="2"] .quality{background:light-dark(#b5d6e8, #253d4d)}:is(.rod-slot,.haul-slot)[data-quality="3"] .quality{background:light-dark(#d6b8e0, #40314b)}:is(.rod-slot,.haul-slot)[data-quality="4"] .quality{background:light-dark(#edc988, #4e3d27)}:is(.rod-slot,.haul-slot)[data-quality="5"] .quality{background:light-dark(#e8a7b5, #4b3038)}:is(.rod-slot,.haul-slot)[data-quality="6"] .quality{color:#382f25;background:linear-gradient(110deg,#c6e6de,#ddc8ec,#edd8ae)}.bait-art{width:100px;height:100px}.uses,.mastered{font-family:var(--font-game);font-size:26px;color:var(--success-ink)}.bait-slot p{margin:8px 0}button{font-family:var(--font-game);font-size:20px;background:var(--selected-background);color:var(--selected-ink);border:2px solid var(--paper-edge);padding:9px 12px;cursor:pointer}button:disabled{opacity:.5;cursor:default}button:focus-visible,a:focus-visible{outline:3px solid var(--control-focus);outline-offset:3px}.secondary{background:var(--panel-inset);color:var(--ink)}@media(max-width:700px){.slots{grid-template-columns:1fr}.equipment-slots{grid-auto-rows:auto}.equipment-slot{flex-direction:column}.art{align-self:center}.details{width:100%}}
  .upgrade-dialog{width:min(640px,calc(100% - 32px));max-height:calc(100dvh - 32px);padding:28px;border:4px solid var(--wood-dark,#3c2d21);background:var(--paper,#f3e7c4);color:var(--ink,#354239);box-shadow:inset 0 0 0 3px var(--panel-highlight),0 12px 0 color-mix(in srgb,var(--wood-dark) 50%,transparent);overflow:auto;overscroll-behavior:contain;box-sizing:border-box}
  .upgrade-dialog::backdrop{background:var(--dialog-backdrop)}.upgrade-dialog h2{margin:0 0 18px;font-family:var(--font-game);font-size:30px;font-weight:400}.upgrade-dialog h3{font-size:26px;margin:0 0 12px;overflow-wrap:anywhere}.upgrade-dialog p{font-size:16px;line-height:1.6}.upgrade-dialog dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:22px 0}.upgrade-dialog dd{margin-top:6px;font-size:26px;overflow-wrap:anywhere}.upgrade-error{padding:12px;background:var(--panel-inset);color:var(--ink);border:1px solid var(--paper-edge)}.upgrade-actions{display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:22px}.upgrade-actions button{max-width:100%;overflow-wrap:anywhere}
  @media(max-width:600px){.upgrade-dialog{width:calc(100% - 20px);max-height:calc(100dvh - 20px);padding:18px}.upgrade-dialog h2{font-size:26px}.upgrade-dialog h3{font-size:23px}.upgrade-dialog dl{grid-template-columns:minmax(0,1fr);gap:14px}.upgrade-dialog dd{font-size:22px;line-height:1.4}.upgrade-actions{flex-direction:column-reverse}.upgrade-actions button{width:100%}}

</style>
