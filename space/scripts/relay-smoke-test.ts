import assert from "node:assert/strict";
import { RelaySubscription, tradingQueries } from "./bitcraft-relay";
import {
  assembleRelayTrading,
  validateRelayTrading,
} from "../bitcraft/src/relay-trading";
import { catalogResponse } from "../bitcraft/src/catalog";
async function snapshot(
  provider: "bitconnect" | "bitsync",
  region: number,
  queries: string[],
) {
  return await new Promise<RelaySubscription>((resolve, reject) => {
    const timeout = setTimeout(() => {
      relay.stop();
      reject(Error("Live snapshot timed out"));
    }, 50000);
    const relay = new RelaySubscription(
      provider,
      region,
      queries,
      (initial) => {
        if (initial) {
          clearTimeout(timeout);
          resolve(relay);
        }
      },
      (error) => {
        clearTimeout(timeout);
        relay.stop();
        reject(Error(error));
      },
    );
    void relay.start();
  });
}
const market = await snapshot("bitconnect", 8, tradingQueries());
try {
  const data = assembleRelayTrading(market.tables, 8, (kind, id) =>
    catalogResponse("item-info", `${kind}:${id}`, ""),
  );
  validateRelayTrading(8, data);
  assert(Object.keys(data.books).length > 0);
  assert(data.stalls.length > 0);
  console.log(
    JSON.stringify({
      provider: "BITCONNECT",
      region: 8,
      books: Object.keys(data.books).length,
      orders: Object.values(data.books).reduce(
        (n, b) => n + b.sellOrders.length + b.buyOrders.length,
        0,
      ),
      playerStalls: data.stalls.length,
      playerExchanges: data.stalls.reduce((n, s) => n + s.orderCount, 0),
    }),
  );
} finally {
  market.stop();
}
const players = (await fetch(
  "https://relay.bitcraftsync.app/player?name=icha",
).then((r) => r.json())) as any[];
const player =
  players.find((p) => String(p.username).toLowerCase() === "icha") ??
  players.find((p) =>
    [7, 8, 9, 12, 13, 14, 17, 18, 19].includes(Number(p.region)),
  );
assert(player);
const id = String(player.entity_id),
  relay = await snapshot("bitsync", Number(player.region), [
    `SELECT * FROM player_username_state WHERE entity_id = ${id}`,
    `SELECT * FROM player_state WHERE entity_id = ${id}`,
    `SELECT * FROM experience_state WHERE entity_id = ${id}`,
    `SELECT * FROM inventory_state WHERE player_owner_entity_id = ${id}`,
    `SELECT * FROM progressive_action_state WHERE owner_entity_id = ${id}`,
    `SELECT * FROM passive_craft_state WHERE owner_entity_id = ${id}`,
  ]);
try {
  assert(relay.tables.get("experience_state")?.has(id));
  console.log(
    JSON.stringify({
      provider: "BitCraftSync",
      region: player.region,
      player: player.username,
      rows: Object.fromEntries(
        [...relay.tables].map(([name, rows]) => [name, rows.size]),
      ),
      experience: relay.tables
        .get("experience_state")
        ?.get(id)
        ?.experience_stacks.slice(0, 1),
    }),
  );
} finally {
  relay.stop();
}
