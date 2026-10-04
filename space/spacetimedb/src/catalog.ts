import type {
  AnonymousViewCtx,
  InferSchema,
  ReducerCtx,
  ViewCtx,
} from "spacetimedb/server";
import type { Identity } from "spacetimedb";
import db from "./schema";
import { referencedItems } from "./performance";

type Schema = InferSchema<typeof db>;
type Reader = AnonymousViewCtx<Schema> | ViewCtx<Schema>;
const rarities = ["common", "uncommon", "rare", "epic", "legendary", "mythic"];

export function definitionRows(ctx: Reader, kinds: string[], allItems = false) {
  const selected = new Map<
    string,
    NonNullable<ReturnType<typeof ctx.db.catalog.key.find>>
  >();
  const items = new Set<string>();
  for (const kind of kinds)
    for (const row of ctx.db.catalog.kind.filter(kind)) {
      selected.set(row.key, row);
      referencedItems(JSON.parse(row.payload), items);
    }
  if (allItems)
    for (const row of ctx.db.catalog.kind.filter("item_metadata"))
      selected.set(row.key, row);
  else
    for (const item of items)
      for (const rarity of rarities) {
        const row = ctx.db.catalog.key.find(`item_metadata:${item}:${rarity}`);
        if (row) selected.set(row.key, row);
      }
  return [...selected.values()];
}

export function referenceRows(ctx: ViewCtx<Schema>) {
  const selected = new Map<
    string,
    NonNullable<ReturnType<typeof ctx.db.catalog.key.find>>
  >();
  for (const reference of ctx.db.catalogReference.byOwner.filter(ctx.sender)) {
    const keys =
      reference.kind === "item_metadata"
        ? rarities.map(
            (rarity) => `item_metadata:${reference.definitionKey}:${rarity}`,
          )
        : [`${reference.kind}:${reference.definitionKey}`];
    for (const key of keys) {
      const row = ctx.db.catalog.key.find(key);
      if (row) selected.set(key, row);
    }
  }
  return [...selected.values()];
}

// Run after a successful gameplay operation. Existing membership produces no
// writes, so spending or gathering another unit and tool wear leave views alone.
export function syncCatalogReferences(
  ctx: ReducerCtx<Schema>,
  owner: Identity = ctx.sender,
) {
  const desired = new Map<
    string,
    { key: string; owner: Identity; kind: string; definitionKey: string }
  >();
  const add = (kind: string, definitionKey: string) => {
    const key = `${owner.toHexString()}:${kind}:${definitionKey}`;
    desired.set(key, { key, owner, kind, definitionKey });
  };
  for (const row of ctx.db.inventory.byOwner.filter(owner))
    if (row.quantity > 0n) add("item_metadata", row.itemKey);
  for (const row of ctx.db.tool.byOwner.filter(owner))
    add("item_metadata", row.itemKey);
  for (const row of ctx.db.achievement.byOwner.filter(owner))
    add("achievements", row.achievementKey);
  for (const row of ctx.db.catalogReference.byOwner.filter(owner)) {
    if (desired.has(row.key)) desired.delete(row.key);
    else ctx.db.catalogReference.key.delete(row.key);
  }
  for (const row of desired.values()) ctx.db.catalogReference.insert(row);
}
