import { DbConnection } from '@fishing-game/generated';
import type { ActionNonce, AnglerStanding, LegendaryFind, BiomeDefinition, RodDefinition, RarityDefinition, GameConfig, ItemStack, LinkChallenge, OwnedSpecimen, Player, PlayerSpeciesProgress, PublicProfile, RecentCatch, SpeciesDefinition, SpeciesRecord, ShopListing, ShopQuote, OwnedRod, OwnedBiomeLicence } from '@fishing-game/generated/types';

export class Game {
  status = $state('Not connected');
  ready = $state(false);
  error = $state('');
  busy = $state(false);
  lastSync = $state<Date | null>(null);
  player = $state.raw<Player | null>(null);
  profile = $state.raw<PublicProfile | null>(null);
  inventory = $state.raw<OwnedSpecimen[]>([]);
  collection = $state.raw<PlayerSpeciesProgress[]>([]);
  recent = $state.raw<RecentCatch[]>([]);
  species = $state.raw<SpeciesDefinition[]>([]);
  biomes = $state.raw<BiomeDefinition[]>([]);
  rods = $state.raw<RodDefinition[]>([]);
  rarities = $state.raw<RarityDefinition[]>([]);
  records = $state.raw<SpeciesRecord[]>([]);
  standings = $state.raw<AnglerStanding[]>([]);
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
  private retry: ReturnType<typeof setTimeout> | null = null;
  private attempts = 0;
  private uri = '';
  private database = '';
  private tokenKey = '';

  private clearPrivate() {
    this.player = null; this.profile = null; this.inventory = []; this.collection = [];
    this.recent = []; this.items = []; this.challenge = null; this.action = null; this.shopQuote = null; this.ownedRods = []; this.licences = [];
  }
  private refresh = () => {
    const connection = this.connection;
    if (!connection || !this.ready) return;
    this.player = [...connection.db.myPlayer.iter()][0] ?? null;
    this.profile = [...connection.db.myProfile.iter()][0] ?? null;
    this.inventory = [...connection.db.myInventory.iter()].sort((a, b) => a.catchId > b.catchId ? -1 : 1);
    this.collection = [...connection.db.myCollection.iter()];
    this.recent = [...connection.db.myRecentCatches.iter()].sort((a, b) => a.recentId > b.recentId ? -1 : 1);
    this.species = [...connection.db.speciesDefinition.iter()].sort((a, b) => a.speciesId - b.speciesId);
    this.records = [...connection.db.speciesRecord.iter()];
    this.standings = [...connection.db.anglerStanding.iter()];
    this.legendaryFinds = [...connection.db.legendaryFind.iter()];
    this.biomes = [...connection.db.biomeDefinition.iter()].sort((a, b) => a.biomeId - b.biomeId);
    this.rods = [...connection.db.rodDefinition.iter()].sort((a, b) => a.minimumLevel - b.minimumLevel);
    this.rarities = [...connection.db.rarityDefinition.iter()].sort((a, b) => a.ordinal - b.ordinal);
    this.items = [...connection.db.myItems.iter()];
    this.config = [...connection.db.gameConfig.iter()][0] ?? null;
    this.challenge = [...connection.db.myLinkChallenge.iter()][0] ?? null;
    this.action = [...connection.db.myAction.iter()][0] ?? null;
    this.shopQuote = [...connection.db.myShopQuote.iter()][0] ?? null;
    this.listings = [...connection.db.shopListing.iter()].sort((a,b) => a.listingId - b.listingId);
    this.ownedRods = [...connection.db.myRods.iter()];
    this.licences = [...connection.db.myLicences.iter()];
    this.lastSync = new Date();
  };
  start(uri: string, database: string) {
    this.stop(); this.uri = uri; this.database = database; this.attempts = 0;
    if (!uri || !database) { this.status = 'The pond is being prepared'; return; }
    this.tokenKey = `fishbound:${uri}:${database}:identity`;
    this.connect();
  }
  private connect() {
    const generation = ++this.generation;
    this.connection?.disconnect(); this.connection = null;
    let token: string | undefined;
    try { token = localStorage.getItem(this.tokenKey) ?? undefined; } catch { /* Storage may be disabled. */ }
    this.status = this.attempts ? 'Reconnecting…' : 'Connecting…';
    const lost = () => {
      if (generation !== this.generation) return;
      this.ready = false; this.clearPrivate(); this.status = 'Connection lost';
      if (this.retry) return;
      if (this.attempts < 5) {
        this.retry = setTimeout(() => {
          this.retry = null;
          if (generation === this.generation) this.connect();
        }, Math.min(1000 * 2 ** this.attempts++, 15000));
      } else { this.error = 'Could not reconnect. Try reconnecting below.'; }
    };
    const connection = DbConnection.builder().withUri(this.uri).withDatabaseName(this.database).withToken(token)
      .onConnect((connection, _identity, newToken) => {
        if (generation !== this.generation) { connection.disconnect(); return; }
        try { localStorage.setItem(this.tokenKey, newToken); } catch { /* This session still works. */ }
        connection.subscriptionBuilder().onApplied(() => {
          if (generation !== this.generation) return;
          this.ready = true; this.status = 'Live'; this.attempts = 0; this.error = ''; this.refresh();
        }).onError(() => { this.error = 'The pond could not load your data.'; connection.disconnect(); })
          .subscribe(['SELECT * FROM my_player', 'SELECT * FROM my_profile', 'SELECT * FROM my_inventory', 'SELECT * FROM my_collection',
            'SELECT * FROM my_recent_catches', 'SELECT * FROM my_link_challenge', 'SELECT * FROM my_action', 'SELECT * FROM my_items',
            'SELECT * FROM species_definition', 'SELECT * FROM species_record', 'SELECT * FROM angler_standing', 'SELECT * FROM legendary_find', 'SELECT * FROM game_config', 'SELECT * FROM biome_definition', 'SELECT * FROM my_rods', 'SELECT * FROM my_licences', 'SELECT * FROM my_shop_quote', 'SELECT * FROM shop_listing', 'SELECT * FROM rod_definition', 'SELECT * FROM rarity_definition']);
      }).onConnectError(lost).onDisconnect(lost).build();
    this.connection = connection;
    for (const table of [connection.db.myPlayer, connection.db.myProfile, connection.db.myInventory, connection.db.myCollection,
      connection.db.myRods, connection.db.myLicences, connection.db.myShopQuote, connection.db.shopListing, connection.db.myRecentCatches, connection.db.myLinkChallenge, connection.db.myAction, connection.db.myItems,
      connection.db.speciesDefinition, connection.db.speciesRecord, connection.db.anglerStanding, connection.db.legendaryFind, connection.db.gameConfig, connection.db.biomeDefinition, connection.db.rodDefinition, connection.db.rarityDefinition]) {
      table.onInsert(this.refresh); table.onDelete(this.refresh);
    }
    connection.db.shopListing.onUpdate(this.refresh);
    connection.db.myItems.onUpdate(this.refresh);
    connection.db.speciesRecord.onUpdate(this.refresh);
    connection.db.anglerStanding.onUpdate(this.refresh);
    connection.db.legendaryFind.onUpdate(this.refresh);
    connection.db.speciesDefinition.onUpdate(this.refresh);
    connection.db.gameConfig.onUpdate(this.refresh);
  }
  stop() {
    this.generation++; if (this.retry) clearTimeout(this.retry);
    this.retry = null; this.connection?.disconnect(); this.connection = null; this.ready = false; this.clearPrivate();
  }
  reconnect() { this.start(this.uri, this.database); }
  async run(action: (connection: DbConnection) => Promise<void>) {
    if (!this.ready || !this.connection || this.busy) return;
    this.busy = true; this.error = '';
    try { await action(this.connection); this.refresh(); }
    catch (error) {
      const message = String(error);
      this.error = message.includes('INSUFFICIENT_COINS') ? 'Your coin pouch is a little short. Sell catches or collect /daily, then visit the trader again.'
        : message.includes('ALREADY_OWNED') ? 'You already own this. Your collection of gear has refreshed.'
        : message.includes('QUOTE_CHANGED') ? 'The trader changed this price. Review a new offer before buying.'
        : message.includes('PREVIOUS_LICENCE_REQUIRED') ? 'Buy the previous biome licence before continuing your journey.'
        : message.includes('BIOME_LICENCE_REQUIRED') ? 'Visit the camp trader for this biome licence first.'
        : message.includes('ROD_NOT_OWNED') ? 'Buy that rod from the camp trader before equipping it.'
        : message.includes('SHOP_LEVEL_REQUIRED') ? 'Reach the required angler level before buying this item.'
        : message.includes('FAVORITE_PROTECTED') ? 'Favorites are protected. Unfavorite that catch before selling.'
        : message.includes('BIOME_LEVEL_REQUIRED') || message.includes('ROD_LOCKED') ? 'Reach the required angler level to unlock this destination or rod.'
        : message.includes('BIOME_POWER_REQUIRED') ? 'Equip a stronger rod for this biome, or return to an earlier biome first.'
        : message.includes('ACTION_RATE_LIMITED') ? 'Give the previous action a moment before trying again.'
        : message.includes('CATCH_UNAVAILABLE') ? 'A selected catch changed. Review your inventory and try again.'
        : message.includes('ACTION_EXPIRED') ? 'That preview expired. Prepare a new offer.'
        : 'The action could not be confirmed. Your saved state will refresh when connected.';
    } finally { this.busy = false; }
  }
  async prepareLink() {
    if (this.challenge && !this.challenge.consumed && this.challenge.expiresAt.microsSinceUnixEpoch > BigInt(Date.now()) * 1000n) return;
    await this.run(connection => connection.reducers.beginLinkChallenge({}));
  }
  async changeLoadout(biomeId?: number, rodId?: number) {
    await this.run(connection => connection.reducers.changeLoadout({ biomeId, rodId }));
  }
  async previewPurchase(listingId: number) { await this.run(connection => connection.reducers.prepareShopPurchase({ listingId })); }
  async confirmPurchase(nonce: bigint) { await this.run(connection => connection.reducers.commitShopPurchase({ nonce })); }
  async favorite(fish: OwnedSpecimen) {
    await this.run(async connection => {
      await connection.reducers.prepareInventoryAction({ kind: 'favorite', catchIds: [fish.catchId], favorite: !fish.favorite });
      const action = [...connection.db.myAction.iter()][0];
      if (!action || action.kind !== 'favorite' || action.catchIds[0] !== fish.catchId) throw new Error('Missing action quote');
      await connection.reducers.commitInventoryAction({ nonce: action.nonce });
    });
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
