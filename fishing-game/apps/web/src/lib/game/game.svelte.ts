import { DbConnection } from '@fishing-game/generated';
import type { SubscriptionHandle } from '@fishing-game/generated';
import { coreQueries, viewQueries } from './subscriptions';
import { connectionDiagnostics } from './connection-diagnostics';
import type { JournalPage } from '@fishing-game/generated/types';
export type JournalQuery = { page: number; pageSize: number; sort: string; descending: boolean; search: string; biomeId: number; rarity: string; outcome: string };
import type { ActionNonce, AnglerPortrait, AnglerStatistics, AnglerStanding, LegendaryFind, BiomeDefinition, RodDefinition, RarityDefinition, GameConfig, ItemStack, LinkChallenge, OwnedSpecimen, Player, PlayerSpeciesProgress, PublicProfile, RecentCatch, SpeciesDefinition, SpeciesRecord, ShopListing, ShopQuote, OwnedRod, OwnedBiomeLicence, RodBonuses } from '@fishing-game/generated/types';

export class Game {
  questDefinitions = $state.raw<import('@fishing-game/generated/types').QuestDefinition[]>([]);
  quests = $state.raw<import('@fishing-game/generated/types').QuestStatus[]>([]);
  questCalendar = $state.raw<import('@fishing-game/generated/types').QuestCalendar | null>(null);
  dockside = $state.raw<import('@fishing-game/generated/types').DocksideStatus | null>(null);

  achievements = $state.raw<import('@fishing-game/generated/types').AchievementDefinition[]>([]);
  achievementCollections = $state.raw<import('@fishing-game/generated/types').AchievementCollection[]>([]);
  earnedAchievements = $state.raw<import('@fishing-game/generated/types').EarnedAchievement[]>([]);
  achievementProgress = $state.raw<import('@fishing-game/generated/types').AchievementProgress[]>([]);
  rankMaxima = $state.raw<import('@fishing-game/generated/types').RankMaximum[]>([]);
  perfectSpecies = $state.raw<import('@fishing-game/generated/types').PerfectSpecies[]>([]);
  titles = $state.raw<import('@fishing-game/generated/types').AnglerTitle[]>([]);
  publicProfiles = $state.raw<PublicProfile[]>([]);
  portraits = $state.raw<AnglerPortrait[]>([]);
  baits = $state.raw<import('@fishing-game/generated/types').BaitDefinition[]>([]);
  rodRecipes = $state.raw<import('@fishing-game/generated/types').RodUpgradeRecipe[]>([]);
  qualities = $state.raw<import('@fishing-game/generated/types').QualityDefinition[]>([]);
  ownedBaits = $state.raw<import('@fishing-game/generated/types').BaitStack[]>([]);
  focusPreferences = $state.raw<import('@fishing-game/generated/types').CollectionFocusPreference[]>([]);
  baitLoadout = $state.raw<import('@fishing-game/generated/types').BaitLoadout | null>(null);
  upgradeQuote = $state.raw<import('@fishing-game/generated/types').UpgradeQuote | null>(null);
  status = $state('Not connected');
  ready = $state(false);
  reconnecting = $state(false);
  error = $state('');
  busy = $state(false);
  lastSync = $state<Date | null>(null);
  player = $state.raw<Player | null>(null);
  profile = $state.raw<PublicProfile | null>(null);
  inventory = $state.raw<OwnedSpecimen[]>([]);
  collection = $state.raw<PlayerSpeciesProgress[]>([]);
  recent = $state.raw<RecentCatch[]>([]);
  journal = $state.raw<JournalPage | null>(null);
  journalLoading = $state(false);
  journalError = $state('');
  private journalPending: JournalQuery | null = null;
  private journalLastQuery = '';
  species = $state.raw<SpeciesDefinition[]>([]);
  biomes = $state.raw<BiomeDefinition[]>([]);
  rods = $state.raw<RodDefinition[]>([]);
  rodBonuses = $state.raw<RodBonuses[]>([]);
  rarities = $state.raw<RarityDefinition[]>([]);
  records = $state.raw<SpeciesRecord[]>([]);
  standings = $state.raw<AnglerStanding[]>([]);
  statistics = $state.raw<AnglerStatistics[]>([]);
  legendaryFinds = $state.raw<LegendaryFind[]>([]);
  items = $state.raw<ItemStack[]>([]);
  config = $state.raw<GameConfig | null>(null);
  challenge = $state.raw<LinkChallenge | null>(null);
  action = $state.raw<ActionNonce | null>(null);
  shopQuote = $state.raw<ShopQuote | null>(null);
  listings = $state.raw<ShopListing[]>([]);
  ownedRods = $state.raw<OwnedRod[]>([]);
  licences = $state.raw<OwnedBiomeLicence[]>([]);
  private connection: DbConnection | null = null;
  private generation = 0;
  sectionLoading = $state(false);
  private coreReady = false;
  private sectionReady = false;
  private sectionSubscription: SubscriptionHandle | null = null;
  private sectionKey = '';
  private sectionRevision = 0;
  private selectedView = 'camp';
  private retry: ReturnType<typeof setTimeout> | null = null;
  private attempts = 0;
  private uri = '';
  private database = '';
  private tokenKey = '';
  private actionUnconfirmed = false;

  setView(view: string) { this.selectedView = view; this.syncSection(); }
  private finishRecovery() {
    if (!this.coreReady || !this.sectionReady) return;
    if (this.reconnecting || !this.ready) connectionDiagnostics.record('fishbound-ready');
    this.ready = true; this.reconnecting = false; this.sectionLoading = false;
    this.status = 'Live'; this.attempts = 0;
    if (!this.actionUnconfirmed) this.error = '';
    this.refresh();
  }
  private syncSection() {
    const connection = this.connection;
    if (!connection?.isActive || !this.coreReady) return;
    const queries = viewQueries(this.selectedView, this.player?.playerId, this.config?.version);
    const key = JSON.stringify(queries);
    if (key === this.sectionKey) return;
    const revision = ++this.sectionRevision, generation = this.generation;
    this.sectionSubscription?.unsubscribe();
    this.sectionSubscription = null; this.sectionKey = key;
    this.ready = false; this.sectionReady = false; this.sectionLoading = true;
    this.status = 'Loading view…';
    if (!queries.length) { this.sectionReady = true; this.finishRecovery(); return; }
    this.sectionSubscription = connection.subscriptionBuilder().onApplied(() => {
      if (revision !== this.sectionRevision || generation !== this.generation) return;
      this.sectionReady = true; this.finishRecovery();
    }).onError(() => {
      if (revision !== this.sectionRevision || generation !== this.generation) return;
      this.sectionLoading = false; this.error = 'This view could not load. Reconnect to try again.';
      connectionDiagnostics.record('fishbound-subscription-failed');
    }).subscribe(queries);
  }

  private clearPrivate() {
    this.quests = []; this.dockside = null;
    this.achievementProgress = [];
    this.focusPreferences = []; this.ownedBaits = []; this.baitLoadout = null; this.upgradeQuote = null;
    this.player = null; this.profile = null; this.inventory = []; this.collection = [];
    this.recent = []; this.items = []; this.challenge = null; this.action = null; this.shopQuote = null; this.ownedRods = []; this.licences = [];
    this.journal = null; this.journalLastQuery = ''; this.journalPending = null; this.journalError = '';
  }
  private refresh = () => {
    const connection = this.connection;
    if (!connection || !this.coreReady) return;
    this.questDefinitions = [...connection.db.questDefinition.iter()].sort((a,b) => a.questId-b.questId);
    this.quests = [...connection.db.myQuests.iter()];
    this.questCalendar = [...connection.db.questCalendar.iter()][0] ?? null;
    this.dockside = [...connection.db.myDockside.iter()][0] ?? null;
    this.achievements = [...connection.db.achievementDefinition.iter()].sort((a,b) => a.achievementId-b.achievementId);
    this.achievementCollections = [...connection.db.achievementCollection.iter()];
    this.earnedAchievements = [...connection.db.earnedAchievement.iter()];
    this.achievementProgress = [...connection.db.myAchievementProgress.iter()];
    this.rankMaxima = [...connection.db.rankMaximum.iter()];
    this.perfectSpecies = [...connection.db.perfectSpecies.iter()];
    this.titles = [...connection.db.anglerTitle.iter()];
    this.publicProfiles = [...connection.db.publicProfile.iter()];
    this.portraits = [...connection.db.anglerPortrait.iter()];
    this.player = [...connection.db.myPlayer.iter()][0] ?? null;
    this.profile = [...connection.db.myProfile.iter()][0] ?? null;
    this.inventory = [...connection.db.myInventory.iter()].sort((a, b) => a.catchId > b.catchId ? -1 : 1);
    this.collection = [...connection.db.myCollection.iter()];
    this.focusPreferences = [...connection.db.myCollectionFocus.iter()];
    this.recent = [...connection.db.myRecentCatches.iter()].sort((a, b) => a.recentId > b.recentId ? -1 : 1);
    this.journal = [...connection.db.myJournal.iter()][0] ?? null;
    this.species = [...connection.db.speciesDefinition.iter()].sort((a, b) => a.speciesId - b.speciesId);
    this.records = [...connection.db.speciesRecord.iter()];
    this.standings = [...connection.db.anglerStanding.iter()];
    this.statistics = [...connection.db.anglerStatistics.iter()];
    this.legendaryFinds = [...connection.db.legendaryFind.iter()];
    this.biomes = [...connection.db.biomeDefinition.iter()].sort((a, b) => a.biomeId - b.biomeId);
    this.rods = [...connection.db.rodDefinition.iter()].sort((a, b) => a.rodId - b.rodId);
    this.rodBonuses = [...connection.db.rodBonuses.iter()];
    this.rarities = [...connection.db.rarityDefinition.iter()].sort((a, b) => a.ordinal - b.ordinal);
    this.items = [...connection.db.myItems.iter()];
    this.config = [...connection.db.gameConfig.iter()].sort((a,b) => b.version-a.version)[0] ?? null;
    this.challenge = [...connection.db.myLinkChallenge.iter()][0] ?? null;
    this.action = [...connection.db.myAction.iter()][0] ?? null;
    this.shopQuote = [...connection.db.myShopQuote.iter()][0] ?? null;
    this.listings = [...connection.db.shopListing.iter()].sort((a,b) => a.listingId - b.listingId);
    this.ownedRods = [...connection.db.myRods.iter()];
    this.licences = [...connection.db.myLicences.iter()];
    this.baits = [...connection.db.baitDefinition.iter()].sort((a,b) => a.baitId-b.baitId);
    this.rodRecipes = [...connection.db.rodUpgradeRecipe.iter()];
    this.qualities = [...connection.db.qualityDefinition.iter()].sort((a,b) => a.qualityLevel-b.qualityLevel);
    this.ownedBaits = [...connection.db.myBaits.iter()];
    this.baitLoadout = [...connection.db.myBaitLoadout.iter()][0] ?? null;
    this.upgradeQuote = [...connection.db.myUpgradeQuote.iter()][0] ?? null;
    this.lastSync = new Date();
    this.syncSection();
  };
  start(uri: string, database: string) {
    this.stop(); this.uri = uri; this.database = database; this.attempts = 0;
    if (!uri || !database) { this.status = 'The pond is being prepared'; return; }
    this.tokenKey = `fishbound:${uri}:${database}:identity`;
    this.connect();
  }
  private connect() {
    const generation = ++this.generation;
    this.coreReady = false; this.sectionReady = false; this.sectionKey = '';
    this.sectionSubscription = null; this.sectionRevision++;
    this.connection?.disconnect(); this.connection = null;
    let token: string | undefined;
    try { token = localStorage.getItem(this.tokenKey) ?? undefined; } catch { /* Storage may be disabled. */ }
    this.status = this.attempts ? 'Reconnecting…' : 'Connecting…';
    let connectedOnce = false;
    const lost = (event: 'fishbound-disconnected' | 'fishbound-connect-failed', _context: unknown, _error: unknown, nextAttempt?: number, delayMs?: number) => {
      if (generation !== this.generation) return;
      connectionDiagnostics.failure(event, _error, nextAttempt, delayMs);
      this.ready = false;
      this.coreReady = false; this.sectionReady = false;
      this.reconnecting = nextAttempt !== undefined;
      this.status = this.reconnecting ? 'Reconnecting…' : 'Connection lost';
      // Established connections retain their cache and are retried by the SDK.
      // Application retries cover failed first connections only.
      if (nextAttempt !== undefined) return;
      if (connectedOnce) {
        if (_error instanceof Error && _error.name === 'IdentityChangedError') this.clearPrivate();
        this.error = 'The connection ended. Reconnect or sign in again to continue.';
        return;
      }
      if (this.retry) return;
      if (this.attempts < 5) {
        this.retry = setTimeout(() => {
          this.retry = null;
          if (generation === this.generation) this.connect();
        }, Math.min(1000 * 2 ** this.attempts++, 15000));
      } else { this.error = 'Could not reconnect. Try reconnecting below.'; }
    };
    const connection = DbConnection.builder().withUri(this.uri).withDatabaseName(this.database).withToken(token).withAutomaticReconnect()
      .onConnect((connection, _identity, newToken) => {
        if (generation !== this.generation) { connection.disconnect(); return; }
        connectedOnce = true;
        connectionDiagnostics.record('fishbound-connected', { connectionId: connection.connectionId.toHexString() });
        try { localStorage.setItem(this.tokenKey, newToken); } catch { /* This session still works. */ }
        connection.subscriptionBuilder().onApplied(() => {
          if (generation !== this.generation) return;
          this.coreReady = true;
          this.refresh();
          this.finishRecovery();
        }).onError(() => { connectionDiagnostics.record('fishbound-subscription-failed'); this.error = 'The pond could not load your data.'; connection.disconnect(); })
          .subscribe(coreQueries);
      }).onAutomaticReconnect((_connection, _identity, newToken) => {
        if (generation !== this.generation) return;
        connectionDiagnostics.record('fishbound-reconnected', { connectionId: _connection.connectionId.toHexString() });
        try { localStorage.setItem(this.tokenKey, newToken); } catch { /* This session still works. */ }
        this.status = 'Restoring live data…';
        // Existing subscriptions and callbacks are replayed by the SDK.
        // Their onApplied callback enables actions only after reconciliation.
      }).onConnectError((...args) => lost('fishbound-connect-failed', ...args))
      .onDisconnect((...args) => lost('fishbound-disconnected', ...args)).build();
    this.connection = connection;
    for (const table of [connection.db.questDefinition, connection.db.questCalendar, connection.db.myQuests, connection.db.myDockside, connection.db.achievementDefinition, connection.db.achievementCollection, connection.db.earnedAchievement, connection.db.perfectSpecies, connection.db.rankMaximum, connection.db.anglerTitle, connection.db.myAchievementProgress, connection.db.publicProfile, connection.db.anglerPortrait, connection.db.myPlayer, connection.db.myProfile, connection.db.myInventory, connection.db.myCollection, connection.db.myCollectionFocus,
      connection.db.myBaits, connection.db.myBaitLoadout, connection.db.myUpgradeQuote, connection.db.baitDefinition, connection.db.rodUpgradeRecipe, connection.db.qualityDefinition, connection.db.myRods, connection.db.myLicences, connection.db.myShopQuote, connection.db.shopListing, connection.db.myRecentCatches, connection.db.myJournal, connection.db.myLinkChallenge, connection.db.myAction, connection.db.myItems,
      connection.db.speciesDefinition, connection.db.speciesRecord, connection.db.anglerStanding, connection.db.anglerStatistics, connection.db.legendaryFind, connection.db.gameConfig, connection.db.biomeDefinition, connection.db.rodBonuses, connection.db.rodDefinition, connection.db.rarityDefinition]) {
      table.onInsert(this.refresh); table.onDelete(this.refresh);
    }
    for (const table of [connection.db.myBaits, connection.db.myBaitLoadout, connection.db.myUpgradeQuote, connection.db.myRods, connection.db.baitDefinition, connection.db.rodUpgradeRecipe, connection.db.qualityDefinition]) table.onUpdate(this.refresh);
    connection.db.questDefinition.onUpdate(this.refresh);
    connection.db.questCalendar.onUpdate(this.refresh);
    connection.db.rodBonuses.onUpdate(this.refresh);
    connection.db.shopListing.onUpdate(this.refresh);
    connection.db.myItems.onUpdate(this.refresh);
    connection.db.speciesRecord.onUpdate(this.refresh);
    connection.db.anglerStanding.onUpdate(this.refresh);
    connection.db.anglerStatistics.onUpdate(this.refresh);
    connection.db.legendaryFind.onUpdate(this.refresh);
    connection.db.speciesDefinition.onUpdate(this.refresh);
    connection.db.gameConfig.onUpdate(this.refresh);
    connection.db.achievementDefinition.onUpdate(this.refresh);
    connection.db.achievementCollection.onUpdate(this.refresh);
    connection.db.myAchievementProgress.onUpdate(this.refresh);
    connection.db.anglerTitle.onUpdate(this.refresh);
    connection.db.publicProfile.onUpdate(this.refresh);
    connection.db.anglerPortrait.onUpdate(this.refresh);
    connection.db.myPlayer.onUpdate(this.refresh);
    connection.db.myProfile.onUpdate(this.refresh);
    connection.db.myCollection.onUpdate(this.refresh);
    connection.db.myCollectionFocus.onUpdate(this.refresh);
    connection.db.myInventory.onUpdate(this.refresh);
  }
  stop() {
    this.generation++; if (this.retry) clearTimeout(this.retry);
    this.coreReady = false; this.sectionReady = false; this.sectionLoading = false;
    this.sectionKey = ''; this.sectionSubscription = null; this.sectionRevision++;
    this.retry = null; this.connection?.disconnect(); this.connection = null; this.ready = false; this.reconnecting = false; this.actionUnconfirmed = false; this.clearPrivate();
  }
  reconnect() { if (!this.connection?.isReconnecting) this.start(this.uri, this.database); }
  async queryJournal(query: JournalQuery) {
    if (!this.ready || !this.connection || !this.player) return;
    if (JSON.stringify(query) === this.journalLastQuery && !this.journalLoading) return;
    this.journalPending = query;
    if (this.journalLoading) return;
    const connection = this.connection;
    const generation = this.generation;
    this.journalLoading = true; this.journalError = '';
    try {
      while (this.journalPending && this.ready && generation === this.generation) {
        const next = this.journalPending; this.journalPending = null;
        await connection.reducers.selectJournal(next);
        if (generation !== this.generation) break;
        this.journalLastQuery = JSON.stringify(next); this.refresh();
      }
    } catch {
      if (generation === this.generation) this.journalError = 'The journal could not turn to that page. Try again.';
    } finally {
      this.journalLoading = false;
      // A reconnect or newer selection may arrive while the old request settles.
      if (this.journalPending) void this.queryJournal(this.journalPending);
    }
  }
  async run(action: (connection: DbConnection) => Promise<void>) {
    if (!this.ready || !this.connection) { this.error = 'Wait for the connection to recover before taking an action.'; return; }
    if (this.busy) return;
    this.busy = true; this.error = ''; this.actionUnconfirmed = false;
    try { await action(this.connection); this.refresh(); }
    catch (error) {
      if (error instanceof Error && error.name === 'UnknownCallResultError') {
        this.actionUnconfirmed = true;
        this.error = 'The connection dropped before this action was confirmed. Check your updated inventory and coins before trying again.';
        return;
      }
      const message = String(error);
      this.error = message.includes('INSUFFICIENT_MATERIALS') ? 'Gather more rusted tin and scrap before upgrading. Resource bait is available at the Trader.'
        : message.includes('MAX_QUALITY') ? 'This rod has already reached Prismatic quality.'
        : message.includes('BAIT_NOT_OWNED') ? 'This slot needs stocked bait. Matching bait in both slots requires two shared uses.'
        : message.includes('INSUFFICIENT_COINS') ? 'Your coin pouch is a little short. Sell catches or collect /daily, then visit the trader again.'
        : message.includes('ALREADY_OWNED') ? 'You already own this. Your collection of gear has refreshed.'
        : message.includes('QUOTE_CHANGED') ? 'This offer changed. Check the updated price and try again.'
        : message.includes('PREVIOUS_LICENCE_REQUIRED') ? 'Buy the previous biome licence before continuing your journey.'
        : message.includes('BIOME_LICENCE_REQUIRED') ? 'Visit the camp trader for this biome licence first.'
        : message.includes('ROD_NOT_OWNED') ? 'Buy that rod from the camp trader before equipping it.'
        : message.includes('SHOP_LEVEL_REQUIRED') ? 'Reach the required angler level before buying this item.'
        : message.includes('FAVORITE_PROTECTED') ? 'Favorites are protected. Unfavorite that catch before handing it over.'
        : message.includes('QUEST_RUN_CHANGED') ? 'This job was already claimed. Review the new run before claiming again.'
        : message.includes('QUEST_WEEK_CHANGED') ? 'A new contract week started. Review the refreshed board.'
        : message.includes('QUEST_WEEK_ALREADY_CLAIMED') ? 'You already collected a contract reward this week.'
        : message.includes('QUEST_REWARD_CHOICE_REQUIRED') ? 'Choose one of the listed bait rewards.'
        : message.includes('QUEST_LOCKED') ? 'Finish and claim the previous story step first.'
        : message.includes('QUEST_WRONG_FISH') ? 'Choose an F, D or C rank specimen from the listed species.'
        : message.includes('QUEST_NOT_READY') ? 'That objective is not complete yet.'
        : message.includes('QUEST_DELIVERY_REQUIRED') ? 'Choose the fish you want to deliver.'
        : message.includes('BIOME_LEVEL_REQUIRED') || message.includes('ROD_LOCKED') ? 'Reach the required angler level to unlock this destination or rod.'
        : message.includes('BIOME_POWER_REQUIRED') ? 'Equip a stronger rod for this biome, or return to an earlier biome first.'
        : message.includes('ACTION_RATE_LIMITED') ? 'Give the previous action a moment before trying again.'
        : message.includes('MULTI_BAIT_UNAVAILABLE') ? 'The second bait slot is awaiting the game update.'
        : message.includes('INVALID_BAIT_SLOT') ? 'Choose bait slot 1 or 2.'
        : message.includes('COLLECTION_FOCUS_LOCKED') ? 'Discover every ordinary species in this biome to unlock Collection Focus.'
        : message.includes('COLLECTION_FOCUS_UNAVAILABLE') ? 'Collection Focus is awaiting the game update.'
        : message.includes('CATCH_UNAVAILABLE') ? 'A selected catch changed. Review your inventory and try again.'
        : message.includes('ACTION_EXPIRED') ? 'That preview expired. Prepare a new offer.'
        : 'The action could not be confirmed. Your saved state will refresh when connected.';
    } finally { this.busy = false; }
  }
  async prepareLink() {
    if (!this.ready) { this.error = 'Wait for the connection to recover before linking your account.'; return; }
    if (this.challenge && !this.challenge.consumed && this.challenge.expiresAt.microsSinceUnixEpoch > BigInt(Date.now()) * 1000n) {
      // An interrupted request may already have created this challenge.
      this.error = ''; this.actionUnconfirmed = false;
      return;
    }
    await this.run(connection => connection.reducers.beginLinkChallenge({}));
  }
  async changeLoadout(biomeId?: number, rodId?: number) {
    await this.run(connection => connection.reducers.changeLoadout({ biomeId, rodId }));
  }
  async previewPurchase(listingId: number) { await this.run(connection => connection.reducers.prepareShopPurchase({ listingId })); }
  async claimQuest(questId: number, baitId: number, catchId: bigint | undefined, expectedWeek: bigint, expectedCycle: bigint) {
    if (!this.ready || !this.connection || this.busy) return false;
    await this.run(connection => connection.reducers.claimQuest({ questId, baitId, catchId, expectedWeek, expectedCycle }));
    return !this.error;
  }
  async claimDockside() {
    await this.run(connection => connection.reducers.claimDocksideDelivery({}));
  }
  async purchase(listingId: number, quantity = 1) {
    if (!this.ready || !this.connection || this.busy) return false;
    const listing = this.listings.find(row => row.listingId === listingId);
    if (!listing) { this.error = 'That item is no longer available. Check the trader again.'; return false; }
    await this.run(async connection => {
      await connection.reducers.prepareShopPurchase({ listingId });
      const quote = [...connection.db.myShopQuote.iter()].find(row => row.listingId === listingId && !row.consumed);
      if (!quote) throw new Error('ACTION_NOT_FOUND');
      if (quote.quotedCoins !== listing.priceCoins || quote.catalogVersion !== listing.catalogVersion) throw new Error('QUOTE_CHANGED');
      await connection.reducers.commitShopPurchaseQuantity({ nonce: quote.nonce, quantity });
    });
    return !this.error;
  }
  async setCollectionFocus(biomeId: number, enabled: boolean) { await this.run(connection => connection.reducers.setCollectionFocus({ biomeId, enabled })); }
  async equipBait(baitId: number, slot = 1) { await this.run(connection => connection.reducers.setBaitSlot({ baitId, slot })); }
  async equipTitle(achievementId: number) { await this.run(connection => connection.reducers.equipTitle({ achievementId })); }
  async previewUpgrade(rodId: number) { await this.run(connection => connection.reducers.prepareRodUpgrade({ rodId })); }
  async confirmUpgrade(nonce: bigint) { await this.run(connection => connection.reducers.commitRodUpgrade({ nonce })); }
  rodStats(rodId: number) {
    const rod = this.rods.find(row => row.rodId === rodId);
    const base = this.rodBonuses.find(row => row.rodId === rodId);
    const level = this.ownedRods.find(row => row.rodId === rodId)?.upgradeLevel ?? 0;
    const quality = this.qualities.find(row => row.qualityLevel === level);
    return { quality, power: (rod?.power ?? 0) + (quality?.powerBonus ?? 0), luckBp: (base?.luckBp ?? 0) + (quality?.luckBp ?? 0), xpBonusBp: (base?.xpBonusBp ?? 0) + (quality?.xpBonusBp ?? 0) };
  }
  async confirmPurchase(nonce: bigint) { await this.run(connection => connection.reducers.commitShopPurchase({ nonce })); }
  async favorite(fish: OwnedSpecimen) {
    await this.run(connection => connection.reducers.setCatchFavorite({ catchId: fish.catchId, favorite: !fish.favorite }));
  }
  async previewSale(ids: bigint[]) {
    await this.run(connection => connection.reducers.prepareInventoryAction({ kind: 'sell', catchIds: ids, favorite: false }));
  }
  async confirmSale(nonce: bigint) { await this.run(connection => connection.reducers.commitInventoryAction({ nonce })); }
  async logout() {
    await this.run(async connection => { await connection.reducers.unlinkBrowser({}); localStorage.removeItem(this.tokenKey); });
    if (!this.error) { this.stop(); this.status = 'Signed out'; }
  }
}
