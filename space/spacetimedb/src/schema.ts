import { schema, table, t } from "spacetimedb/server";
export const catalog = table(
  { name: "catalog", public: true },
  {
    key: t.string().primaryKey(),
    kind: t.string().index("btree"),
    label: t.string(),
    skill: t.string(),
    requiredLevel: t.u32(),
    payload: t.string(),
  },
);
export const security = table(
  { name: "security" },
  {
    id: t.u32().primaryKey(),
    owner: t.identity(),
    issuer: t.string(),
    audience: t.string(),
    localPlay: t.bool().default(false),
  },
);
export const player = table(
  { name: "player" },
  {
    owner: t.identity().primaryKey(),
    name: t.string(),
    species: t.string(),
    region: t.string(),
    title: t.string(),
    appearance: t.string(),
    gold: t.u64(),
    nextActionAt: t.u64(),
    createdAt: t.timestamp(),
    metrics: t.string().default("{}"),
    pronouns: t.string().default(""),
    characterTitle: t.string().default(""),
  },
);
export const skill = table(
  {
    name: "skill",
    indexes: [{ accessor: "byOwner", algorithm: "btree", columns: ["owner"] }],
  },
  {
    key: t.string().primaryKey(),
    owner: t.identity(),
    skill: t.string(),
    experience: t.u64(),
  },
);
export const inventory = table(
  {
    name: "inventory",
    indexes: [{ accessor: "byOwner", algorithm: "btree", columns: ["owner"] }],
  },
  {
    key: t.string().primaryKey(),
    owner: t.identity(),
    itemKey: t.string(),
    name: t.string(),
    rarity: t.string(),
    quantity: t.u64(),
  },
);
export const tool = table(
  {
    name: "tool",
    indexes: [{ accessor: "byOwner", algorithm: "btree", columns: ["owner"] }],
  },
  {
    id: t.u64().primaryKey().autoInc(),
    owner: t.identity(),
    itemKey: t.string(),
    name: t.string(),
    skill: t.string(),
    rarity: t.string(),
    tierLevel: t.u32(),
    durability: t.u32(),
    maxDurability: t.u32(),
    equipped: t.bool(),
    origin: t.string(),
    bonuses: t.string(),
    upgrades: t.u32(),
    tierUpgrades: t.u32(),
    rarityAttempts: t.u32(),
    rarityProgress: t.u32().default(0),
    listed: t.bool().default(false),
    makerName: t.string().default(""),
  },
);
export const result = table(
  {
    name: "result",
    indexes: [{ accessor: "byOwner", algorithm: "btree", columns: ["owner"] }],
  },
  {
    id: t.u64().primaryKey().autoInc(),
    owner: t.identity(),
    kind: t.string(),
    label: t.string(),
    skill: t.string(),
    experience: t.u32(),
    gold: t.i64(),
    items: t.string(),
    createdAt: t.timestamp(),
    details: t.string().default("{}"),
  },
);
export const contract = table(
  {
    name: "contract",
    indexes: [{ accessor: "byOwner", algorithm: "btree", columns: ["owner"] }],
  },
  {
    key: t.string().primaryKey(),
    owner: t.identity(),
    jobKey: t.string(),
    acceptedAt: t.timestamp(),
    progress: t.u32(),
    completions: t.u32(),
    day: t.u64(),
    active: t.bool().default(false),
  },
);
export const listing = table(
  { name: "listing", public: true },
  {
    id: t.u64().primaryKey().autoInc(),
    seller: t.identity().index("btree"),
    sellerName: t.string(),
    itemKey: t.string(),
    name: t.string(),
    rarity: t.string(),
    quantity: t.u64(),
    unitPrice: t.u64(),
    createdAt: t.timestamp(),
    toolId: t.u64().default(0n),
    toolSnapshot: t.string().default(""),
  },
);
export const achievement = table(
  {
    name: "achievement",
    indexes: [{ accessor: "byOwner", algorithm: "btree", columns: ["owner"] }],
  },
  {
    key: t.string().primaryKey(),
    owner: t.identity(),
    achievementKey: t.string(),
    title: t.string(),
    claimedAt: t.timestamp(),
  },
);
export const leaderboard = table(
  { name: "leaderboard", public: true },
  {
    owner: t.identity().primaryKey(),
    name: t.string(),
    experience: t.u64(),
    gold: t.u64(),
    actions: t.u64(),
    skillExperience: t.string().default("{}"),
  },
);
export const trade = table(
  { name: "trade", public: true },
  {
    id: t.u64().primaryKey().autoInc(),
    sellerName: t.string(),
    buyerName: t.string(),
    itemKey: t.string(),
    itemName: t.string(),
    rarity: t.string(),
    quantity: t.u64(),
    totalPrice: t.u64(),
    marketFee: t.u64(),
    toolSnapshot: t.string(),
    createdAt: t.timestamp(),
  },
);
// Retained for non-destructive migration of the earlier per-identity scope.
export const uiScope = table(
  { name: "ui_scope" },
  {
    owner: t.identity().primaryKey(),
    workspace: t.string(),
    panel: t.string(),
    marketPage: t.u32(),
  },
);
export const uiSession = table(
  {
    name: "ui_session",
    indexes: [{ accessor: "byOwner", algorithm: "btree", columns: ["owner"] }],
  },
  {
    connectionKey: t.string().primaryKey(),
    owner: t.identity(),
    workspace: t.string(),
    panel: t.string(),
    marketPage: t.u32(),
  },
);
// Only definition membership is recorded here. Quantity and durability updates
// must not invalidate the player's definition feed.
export const catalogReference = table(
  {
    name: "catalog_reference",
    indexes: [{ accessor: "byOwner", algorithm: "btree", columns: ["owner"] }],
  },
  {
    key: t.string().primaryKey(),
    owner: t.identity(),
    kind: t.string(),
    definitionKey: t.string(),
  },
);
const db = schema({
  catalogReference,
  uiSession,
  uiScope,
  catalog,
  security,
  player,
  skill,
  inventory,
  tool,
  result,
  contract,
  listing,
  achievement,
  leaderboard,
  trade,
});
export default db;
