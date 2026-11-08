import { Game as RealGame } from '../../../apps/web/src/lib/game/game.svelte';
import type { TideforgeIntent } from '../../../apps/web/src/lib/game/tideforge-intents';
import { tideforgeErrorMessage } from '../../../apps/web/src/lib/game/tideforge-copy';
import { gearNameValidationMessage, gearRenameErrorMessage, normalizeGearName } from '../../../apps/web/src/lib/game/gear-names';
import { rankSpreads } from '../../../apps/web/src/lib/game/compendium';
import world from '../../../content/world.json';
import crafting from '../../../content/crafting.json';
import catalog from '../../../content/species.json';
import raids from '../../../content/raids.json';
import haulEquipment from '../../../content/haul-equipment.json';
import questCatalog from '../../../content/quests.json';
import economy from '../../../content/tideforge-economy.json';

const model = catalog.species.find(row => row.countsForOrdinaryCollectionCompletion)!;
const identity = (value: string) => ({ toHexString: () => value });
const roll = { slot: 0, id: 'fortune', tier: 2, finishBp: 5000, secondaryBiomeId: null };
const gear = (instance: string) => ({ instance_id: instance, affixes: [roll], native_signature: 'echoed_line' });
const credits = { item: 'normal_fillets', before: 0, after: 10, consumed: 0, produced: 10 };

export class Game extends RealGame {
  calls: { action: string; nonce?: string; intent?: TideforgeIntent; instanceId?: string; name?: string }[] = [];
  delayNext = false;
  quoteLifetimeMs = 120000;
  unknownNext = false;
  holdCommit = false;
  unknownPreview = false;
  renameFailure: '' | 'invalid' | 'unknown' = '';
  holdNextRename = false;
  failNextEquip = false;
  holdNextUpgrade = false;
  private releaseUpgrade: (() => void) | null = null;
  private releaseRename: (() => void) | null = null;
  private serverNames: typeof this.gearInstanceNames = [];
  private savedFish: typeof this.inventory | null = null;
  private savedSpecies: typeof this.species | null = null;
  private release: (() => void) | null = null;
  private heldQuote: any = null;
  private rejectCommit: ((reason: Error) => void) | null = null;
  private lastIntent: TideforgeIntent | null = null;
  private lastPreview: Record<string, unknown> | null = null;
  private sequence = 77n;

  constructor() {
    super();
    Object.assign(this, { identityKey: '0xabc', tideforgeCoreReady: true, tideforgeViewReady: true, tideforgeKnownActive: true });
    this.ready = true; this.status = 'Fixture live'; this.tideforgeAvailability = 'active';
    this.gearNamesAvailability = 'ready';
    this.player = { playerId: 9n, equippedRodId: 2, selectedBiomeId: 1, coins: 50000n, totalXp: 0n,
      displayName: 'Fixture Angler', nextCastAt: { microsSinceUnixEpoch: 0n } } as typeof this.player;
    this.profile = { displayName: 'Fixture Angler', level: 50 } as typeof this.profile;
    this.config = { version: 17, levelCap: 500 } as typeof this.config;
    this.rods = world.rods as typeof this.rods;
    this.rodBonuses = world.rods as typeof this.rodBonuses;
    this.biomes = world.biomes as typeof this.biomes;
    this.qualities = crafting.qualities as typeof this.qualities;
    this.rodRecipes = crafting.rodRecipes.map(row => ({ ...row, tinCost: BigInt(row.tinCost), scrapCost: BigInt(row.scrapCost) })) as typeof this.rodRecipes;
    this.ownedRods = [{ rodId: 2, upgradeLevel: 2 }] as typeof this.ownedRods;
    this.raidGrades = raids.grades.map(row => ({ ...row, fillets: BigInt(row.fillets), scales: BigInt(row.scales), reinforceCoins: BigInt(row.reinforceCoins) })) as typeof this.raidGrades;
    this.gearInstances = [
      { playerId: 9n, instanceId: 100n, kind: 'rod', definitionId: 2, familyId: 2, primaryBiomeId: 1, qualityLevel: 2, reinforcementLevel: 3, favorite: false, protectedOriginal: true },
      { playerId: 9n, instanceId: 101n, kind: 'rod', definitionId: 2, familyId: 2, primaryBiomeId: 1, qualityLevel: 0, reinforcementLevel: 0, favorite: false, protectedOriginal: false },
    ] as typeof this.gearInstances;
    this.gearLoadout = { playerId: 9n, rodInstanceId: 101n, boatInstanceId: 102n, netInstanceId: 103n } as typeof this.gearLoadout;
    this.gearFamilies = [{ playerId: 9n, kind: 'rod', familyId: 2, masteryXp: 0n }] as typeof this.gearFamilies;
    this.tideforgeGear = [
      { playerId: 9n, instanceId: 100n, affixesJson: JSON.stringify([roll]), nativeSignature: undefined },
      { playerId: 9n, instanceId: 101n, affixesJson: JSON.stringify([roll, { ...roll, slot: 3, id: 'secondary_affinity', secondaryBiomeId: 2 }]), nativeSignature: 'echoed_line' },
    ] as typeof this.tideforgeGear;
    this.tideforgeWallet = { playerId: 9n, marks: 20n } as typeof this.tideforgeWallet;
    this.tideforgeLoadout = { playerId: 9n, goal: 'ranks', rodSignature: 'echoed_line' } as typeof this.tideforgeLoadout;
    this.tideforgeCodex = [
      { playerId: 9n, affixId: 'fortune', naturalCount: 2n, highestTier: 2, bestFinishBp: 5000 },
      { playerId: 9n, affixId: 'secondary_affinity', naturalCount: 1n, highestTier: 8, bestFinishBp: 7000 },
    ] as typeof this.tideforgeCodex;
    this.tideforgeLibrary = [{ playerId: 9n, signatureId: 'echoed_line' }] as typeof this.tideforgeLibrary;
    const species = { ...model, typicalWeightG: BigInt(model.typicalWeightG), minLengthMm: Math.floor(model.typicalLengthMm * .55),
      maxLengthMm: Math.floor(model.typicalLengthMm * 1.9), minWeightG: BigInt(model.typicalWeightG) / 8n || 1n,
      maxWeightG: BigInt(model.typicalWeightG) * 8n, spriteAsset: `/fish/${model.spriteKey}.png` };
    this.species = [species] as typeof this.species;
    this.inventory = Array.from({ length: 62 }, (_, index) => ({
      playerId: 9n, catchId: BigInt(index + 1), speciesId: model.speciesId, rarity: 'F', lengthMm: species.minLengthMm,
      weightG: species.minWeightG, favorite: false, sizeGrade: 0, saleValueCoins: 4n,
    })) as typeof this.inventory;
    (window as unknown as { tideforgeFixture: Game }).tideforgeFixture = this;
  }

  start() { this.startServerClock(); }
  stop() { super.stop(); }
  setView() {}
  retryTideforge() { this.tideforgeAvailability = 'active'; }
  retryGearNames() { this.gearNamesAvailability = 'ready'; this.replayGearNames(); }
  showQuestState() {
    this.questDefinitions = questCatalog.map(row => ({
      ...Object.fromEntries(Object.entries(row).map(([key, value]) => [key.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase()), value])),
      target: BigInt(row.target), coins: BigInt(row.coins), tin: BigInt(row.tin), scrap: BigInt(row.scrap),
    })) as typeof this.questDefinitions;
    this.quests = this.questDefinitions.map(row => ({
      questId: row.questId, unlocked: true, claimed: false, current: row.target, week: 10n, cycle: 2n,
      deliverySpeciesIds: [model.speciesId],
    })) as typeof this.quests;
  }
  async claimQuest(questId: number) {
    this.calls.push({ action: 'claimQuest' });
    this.quests = this.quests.map(row => row.questId === questId ? { ...row, claimed: true } : row);
    await Promise.resolve();
    return true;
  }
  releaseHeldRename() { this.releaseRename?.(); this.releaseRename = null; }
  replayGearNames() {
    this.gearInstanceNames = this.serverNames.filter(row => row.playerId === this.player?.playerId
      && this.gearInstances.some(item => item.playerId === row.playerId && item.instanceId === row.instanceId));
  }
  enableHaulNames() {
    this.haulEquipment = haulEquipment.equipment as typeof this.haulEquipment;
    this.haulLoadout = { playerId: this.player!.playerId, boatId: 102, netId: 201 } as typeof this.haulLoadout;
    this.ownedHaulEquipment = [{ equipmentId: 102, upgradeLevel: 0 }, { equipmentId: 201, upgradeLevel: 0 }] as typeof this.ownedHaulEquipment;
    this.gearInstances = [...this.gearInstances,
      { playerId: this.player!.playerId, instanceId: 102n, kind: 'boat', definitionId: 102, familyId: 2, primaryBiomeId: 1, qualityLevel: 0, reinforcementLevel: 0, favorite: false, protectedOriginal: true },
      { playerId: this.player!.playerId, instanceId: 103n, kind: 'net', definitionId: 201, familyId: 1, primaryBiomeId: 1, qualityLevel: 0, reinforcementLevel: 0, favorite: false, protectedOriginal: true },
    ] as typeof this.gearInstances;
  }
  showGearGrid() {
    this.enableHaulNames();
    this.gearInstances = [...this.gearInstances,
      { playerId: 9n, instanceId: 104n, kind: 'rod', definitionId: 3, familyId: 3, primaryBiomeId: 1, qualityLevel: 0, reinforcementLevel: 0, favorite: false, protectedOriginal: false },
      { playerId: 9n, instanceId: 105n, kind: 'rod', definitionId: 4, familyId: 4, primaryBiomeId: 1, qualityLevel: 0, reinforcementLevel: 0, favorite: false, protectedOriginal: false },
    ] as typeof this.gearInstances;
    this.tideforgeGear = [...this.tideforgeGear, ...[102n, 103n, 104n, 105n].map(instanceId => ({
      playerId: 9n, instanceId, affixesJson: '[]', nativeSignature: undefined,
    }))] as typeof this.tideforgeGear;
  }
  showTackleGear() {
    this.enableHaulNames();
    this.gearLoadout = { ...this.gearLoadout!, rodInstanceId: 100n };
    this.rods = this.rods.map(row => row.rodId === 7 ? { ...row, minimumLevel: 99 } : row);
    this.gearInstances = this.gearInstances.map(row => row.instanceId === 100n ? { ...row, reinforcementLevel: 0 } : row);
    const boat = this.gearInstances.find(row => row.instanceId === 102n)!;
    const net = this.gearInstances.find(row => row.instanceId === 103n)!;
    this.gearInstances = [...this.gearInstances,
      { ...boat, instanceId: 9007199254740993n, qualityLevel: 1, protectedOriginal: false },
      { ...net, instanceId: 9007199254740995n, qualityLevel: 2, protectedOriginal: false },
      { ...this.gearInstances[0], instanceId: 107n, definitionId: 7, familyId: 7, protectedOriginal: false },
    ];
    this.serverNames = [
      { playerId: 9n, instanceId: 101n, name: 'Rod Buddy' },
      { playerId: 9n, instanceId: 9007199254740993n, name: 'Boat Buddy' },
      { playerId: 9n, instanceId: 9007199254740995n, name: 'Net Buddy' },
    ];
    this.replayGearNames();
    this.items = [
      { key: 1n, item: 'rusted_tin', quantity: 100n }, { key: 2n, item: 'scrap', quantity: 100n },
      { key: 1000n, item: 'raid_fillets_f', quantity: 100n }, { key: 1100n, item: 'raid_scales_f', quantity: 100n },
    ] as typeof this.items;
    this.raidBadges = [{ playerId: 9n, speciesId: model.speciesId, clearedGrades: 1 }] as typeof this.raidBadges;
  }
  async equipGearInstance(instanceId: bigint): Promise<boolean> {
    this.calls.push({ action: 'equip', instanceId: instanceId.toString() });
    if (this.failNextEquip) { this.failNextEquip = false; this.tideforgeError = 'This gear is busy on a trip.'; return false; }
    const item = this.gearInstances.find(row => row.playerId === this.player?.playerId && row.instanceId === instanceId);
    if (!item || !this.gearLoadout || !this.player) return false;
    if (item.kind === 'rod') {
      this.gearLoadout = { ...this.gearLoadout, rodInstanceId: instanceId };
      this.player = { ...this.player, equippedRodId: item.definitionId };
    } else if (item.kind === 'boat' || item.kind === 'net') {
      this.gearLoadout = { ...this.gearLoadout, [item.kind === 'boat' ? 'boatInstanceId' : 'netInstanceId']: instanceId };
      if (this.haulLoadout) this.haulLoadout = { ...this.haulLoadout, [item.kind === 'boat' ? 'boatId' : 'netId']: item.definitionId };
    }
    return true;
  }
  async changeLoadout(_biomeId?: number, rodId?: number) {
    this.calls.push({ action: 'legacyRod', instanceId: String(rodId) });
    if (this.player && rodId !== undefined) this.player = { ...this.player, equippedRodId: rodId };
  }
  async equipHaulEquipment(equipmentId: number) {
    this.calls.push({ action: 'legacyHaul', instanceId: String(equipmentId) });
    const equipment = this.haulEquipment.find(row => row.equipmentId === equipmentId);
    if (equipment && this.haulLoadout) this.haulLoadout = { ...this.haulLoadout,
      [equipment.kind === 'boat' ? 'boatId' : 'netId']: equipmentId };
  }
  async previewUpgrade(rodId: number) {
    this.calls.push({ action: 'previewUpgrade' });
    if (this.holdNextUpgrade) {
      this.holdNextUpgrade = false; this.busy = true;
      await new Promise<void>(resolve => { this.releaseUpgrade = resolve; });
      this.busy = false;
    }
    const now = this.serverNowMicros ?? 0n;
    this.upgradeQuote = { rodId, fromQuality: this.rodStats(rodId).quality?.qualityLevel ?? 0,
      tinCost: 1n, scrapCost: 1n, nonce: 77n, consumed: false,
      expiresAt: { microsSinceUnixEpoch: now + 120000000n } } as typeof this.upgradeQuote;
  }
  releaseHeldUpgrade() { this.releaseUpgrade?.(); this.releaseUpgrade = null; }
  async previewHaulUpgrade(equipmentId: number) {
    this.calls.push({ action: 'previewHaulUpgrade' });
    const now = this.serverNowMicros ?? 0n;
    this.haulUpgradeQuote = { equipmentId, rodId: this.haulEquipment.find(row => row.equipmentId === equipmentId)?.rodId ?? 0,
      fromQuality: this.haulStats(equipmentId).quality?.qualityLevel ?? 0, tinCost: 1n, scrapCost: 1n,
      nonce: 78n, consumed: false, expiresAt: { microsSinceUnixEpoch: now + 120000000n } } as typeof this.haulUpgradeQuote;
  }
  async renameGearInstance(instanceId: bigint, name: string): Promise<boolean> {
    const validation = gearNameValidationMessage(name);
    if (validation) { this.gearNameError = validation; return false; }
    const playerId = this.player?.playerId, identityKey = this.tideforgeIdentity;
    if (!this.ready || this.busy || this.gearNamesAvailability !== 'ready' || !playerId || !identityKey
      || !this.gearInstances.some(row => row.playerId === playerId && row.instanceId === instanceId)) return false;
    const normalized = normalizeGearName(name);
    this.calls.push({ action: 'rename', instanceId: instanceId.toString(), name: normalized });
    this.busy = true; this.gearNameError = '';
    try {
      if (this.holdNextRename) {
        this.holdNextRename = false;
        await new Promise<void>(resolve => { this.releaseRename = resolve; });
      }
      if (this.player?.playerId !== playerId || this.tideforgeIdentity !== identityKey) return false;
      const failure = this.renameFailure; this.renameFailure = '';
      if (failure) {
        this.gearNameError = failure === 'unknown'
          ? 'We could not confirm this name. Reconnect to check before trying again.'
          : gearRenameErrorMessage('GEAR_NAME_INVALID');
        return false;
      }
      this.serverNames = this.serverNames.filter(row => row.instanceId !== instanceId || row.playerId !== playerId);
      if (normalized) this.serverNames.push({ playerId, instanceId, name: normalized });
      this.replayGearNames();
      return true;
    } finally {
      if (this.player?.playerId === playerId && this.tideforgeIdentity === identityKey) this.busy = false;
    }
  }
  releasePreview() { this.release?.(); this.release = null; }
  setAvailability(value: 'active' | 'inactive' | 'unavailable') { this.tideforgeAvailability = value; }
  setGearUnavailable(knownActive: boolean) {
    Object.assign(this, { tideforgeCoreReady: false, tideforgeKnownActive: knownActive });
    this.tideforgeAvailability = 'unavailable';
  }
  setGearReady() {
    Object.assign(this, { tideforgeCoreReady: true, tideforgeKnownActive: true });
    this.tideforgeAvailability = 'active';
  }
  setOriginalRoll(tier: number, finishBp: number) {
    this.tideforgeGear = this.tideforgeGear.map(row => row.instanceId === 100n
      ? { ...row, affixesJson: JSON.stringify([{ ...roll, tier, finishBp }]) } : row);
  }
  showUnknownLanguage() {
    this.tideforgeGear = this.tideforgeGear.map(row => row.instanceId === 100n
      ? { ...row, affixesJson: JSON.stringify([{ ...roll, id: 'mystery_internal_affix', secondaryBiomeId: 999999 }]), nativeSignature: 'mystery_internal_signature' } : row);
    this.tideforgeVault = [{ key: 'fixture', affixId: 'mystery_internal_affix', count: 1n }] as typeof this.tideforgeVault;
    this.tideforgeError = tideforgeErrorMessage('TIDEFORGE_MYSTERY_INTERNAL');
  }
  clearUnknownLanguage() {
    this.tideforgeGear = this.tideforgeGear.map(row => row.instanceId === 100n
      ? { ...row, affixesJson: JSON.stringify([roll]), nativeSignature: undefined } : row);
    this.tideforgeVault = []; this.tideforgeError = ''; this.unknownPreview = false;
  }
  showFishProtections() {
    this.savedFish = this.inventory; this.savedSpecies = this.species;
    const maximum = rankSpreads(model).find(row => row.rarity === 'F')!;
    this.species = [...this.species, { ...this.species[0], speciesId: 9999, countsForOrdinaryCollectionCompletion: false }];
    this.inventory = this.inventory.map((fish, index) => index === 1 ? { ...fish, favorite: true }
      : index === 2 ? { ...fish, speciesId: 9999 } : index === 3 ? { ...fish, speciesId: 9998 }
        : index === 4 ? { ...fish, lengthMm: maximum.maxLengthMm, weightG: BigInt(maximum.maxWeightG) } : fish);
  }
  clearFishProtections() {
    if (this.savedFish) this.inventory = this.savedFish;
    if (this.savedSpecies) this.species = this.savedSpecies;
    this.savedFish = null; this.savedSpecies = null;
  }
  saveHeldReceipt() { if (this.heldQuote) this.applyReceipt(this.heldQuote); }
  rejectHeldCommit() {
    this.rejectCommit?.(Object.assign(new Error('fixture lost reply'), { name: 'UnknownCallResultError' }));
    this.rejectCommit = null; this.heldQuote = null;
  }
  changeOwner() {
    Object.assign(this, { identityKey: '0xdef' });
    if (this.player) this.player = { ...this.player, playerId: 10n };
    this.gearInstances = this.gearInstances.map(row => ({ ...row, playerId: 10n, instanceId: row.instanceId + 1000n }));
    this.tideforgeGear = this.tideforgeGear.map(row => ({ ...row, playerId: 10n, instanceId: row.instanceId + 1000n }));
    if (this.gearLoadout) this.gearLoadout = { ...this.gearLoadout, playerId: 10n,
      rodInstanceId: this.gearLoadout.rodInstanceId + 1000n };
    this.inventory = this.inventory.map(row => ({ ...row, playerId: 10n, catchId: row.catchId + 1000n }));
    this.pendingTideforgeCommit = null; this.tideforgeResult = null;
    this.tideforgeNotice.reset(); this.raidNotice.reset();
    this.gearInstanceNames = []; this.gearNameError = ''; this.busy = false;
  }
  async prepareTideforge(intent: TideforgeIntent): Promise<any> {
    const nowMicros = this.serverNowMicros;
    if (nowMicros === null) { this.tideforgeError = 'Syncing server time…'; return null; }
    this.tideforgeError = '';
    this.tideforgeResult = null; this.tideforgeNotice.dismiss();
    this.calls.push({ action: 'prepare', intent });
    if (this.delayNext) { this.delayNext = false; await new Promise<void>(resolve => { this.release = resolve; }); }
    let preview: Record<string, unknown>;
    if (intent.action === 'camp_copy' || intent.action === 'wandering_gamble') {
      preview = { ...intent, affix_count: intent.action === 'camp_copy' ? 1 : 4,
        coins_before: 50000, coins_cost: 500, coins_after: 49500, marks_before: 20, marks_cost: 0, marks_after: 20 };
    } else if (intent.action === 'fillet') {
      preview = { action: intent.action, allow_trophies: intent.allow_trophies, saved_sale_coins_forgone: 200,
        fish: intent.catch_ids.map(catch_id => ({ catch_id, species_id: model.speciesId, rarity: 'F' })), materials: [credits] };
    } else if ('instance_id' in intent) {
      const stored = this.tideforgeGear.find(row => row.instanceId.toString() === intent.instance_id);
      const before = stored ? { instance_id: intent.instance_id, affixes: JSON.parse(stored.affixesJson),
        native_signature: stored.nativeSignature ?? null } : gear(intent.instance_id);
      const shards = intent.action === 'dismantle' ? before.affixes.map((affix: typeof roll) => {
        const prior = Number(this.tideforgeVault.find(row => row.affixId === affix.id)?.count ?? 0n);
        const produced = economy.recovery.shardsByTier[affix.tier - 1];
        return { affix_id: affix.id, before: prior, consumed: 0, produced, after: prior + produced };
      }) : [];
      preview = { action: intent.action, gear_before: before,
        gear_after: intent.action === 'dismantle' || intent.action === 'extract_signature' ? null : before,
        learned_signature: intent.action === 'extract_signature' ? 'echoed_line' : null,
        coins_before: 50000, coins_cost: intent.action === 'dismantle' ? 0 : 500,
        coins_after: intent.action === 'dismantle' ? 50000 : 49500, materials: [], shards };
    } else {
      preview = { action: intent.action, coins_before: 50000, coins_cost: 0, coins_after: 50000, materials: [credits] };
    }
    if (this.unknownPreview) {
      preview.materials = [{ ...credits, item: 'raid_mystery_internal' }];
      if (preview.gear_before) preview.gear_before = {
        ...(preview.gear_before as object), affixes: [{ ...roll, id: 'mystery_internal_affix', secondaryBiomeId: 999999 }],
        native_signature: 'mystery_internal_signature',
      };
    }
    this.lastIntent = intent; this.lastPreview = preview;
    return { nonce: this.sequence++, identity: identity(this.tideforgeIdentity!), playerId: this.player!.playerId,
      intentJson: JSON.stringify(intent), previewJson: JSON.stringify(preview), catalogVersion: 1,
      createdAt: { microsSinceUnixEpoch: nowMicros },
      expiresAt: { microsSinceUnixEpoch: nowMicros + BigInt(this.quoteLifetimeMs) * 1000n }, consumed: false };
  }
  async commitTideforge(quote: any) {
    const now = this.serverNowMicros;
    if (now === null) { this.tideforgeError = 'Syncing server time…'; return false; }
    if (quote.expiresAt.microsSinceUnixEpoch <= now) { this.tideforgeError = 'This preview is no longer current. Open a new preview.'; return false; }
    this.calls.push({ action: 'commit', nonce: String(quote.nonce) });
    if (this.holdCommit) {
      this.holdCommit = false; this.heldQuote = quote;
      try { return await new Promise<boolean>((_resolve, reject) => { this.rejectCommit = reject; }); }
      catch { return this.tideforgeResult?.nonce === quote.nonce; }
    }
    if (this.unknownNext) {
      this.unknownNext = false;
      this.pendingTideforgeCommit = { quote, identity: this.tideforgeIdentity!, playerId: this.player!.playerId, fingerprint: 'fixture' };
      return false;
    }
    this.applyReceipt(quote);
    return true;
  }
  async retryTideforgeCommit() {
    const pending = this.pendingTideforgeCommit;
    if (!pending) return false;
    this.calls.push({ action: 'retry', nonce: String(pending.quote.nonce) });
    this.applyReceipt(pending.quote);
    return true;
  }
  private applyReceipt(quote: any) {
    if (this.lastIntent?.action === 'fillet') {
      const selected = new Set(this.lastIntent.catch_ids);
      this.inventory = this.inventory.filter(fish => !selected.has(String(fish.catchId)));
    }
    const summary = { ...this.lastPreview };
    if (this.lastIntent?.action === 'camp_copy' || this.lastIntent?.action === 'wandering_gamble') {
      summary.gear = this.unknownPreview
        ? { instance_id: '101', affixes: [{ ...roll, id: 'mystery_internal_affix' }], native_signature: 'mystery_internal_signature' }
        : gear('101');
    }
    this.recordTideforgeResult({ identity: identity(this.tideforgeIdentity!), playerId: this.player!.playerId,
      nonce: quote.nonce, summaryJson: JSON.stringify(summary) } as NonNullable<typeof this.tideforgeResult>);
    this.pendingTideforgeCommit = null;
  }
}
