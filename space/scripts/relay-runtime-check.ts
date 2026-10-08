// Read-only checks against the managed local app; player requests establish a normal watch.
import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/bitcraft";
const conn = await new Promise<DbConnection>((resolve, reject) =>
  DbConnection.builder()
    .withUri("ws://127.0.0.1:3100")
    .withDatabaseName("space-bitcraft-tools")
    .onConnect((c) => resolve(c))
    .onConnectError((_c, e) => reject(e))
    .build(),
);
try {
  await new Promise<void>((resolve, reject) =>
    conn
      .subscriptionBuilder()
      .onApplied(() => resolve())
      .onError((ctx) => reject(ctx.event))
      .subscribe(["SELECT * FROM relay_status"]),
  );
  const players = (await fetch(
    "https://relay.bitcraftsync.app/player?name=icha",
  ).then((r) => r.json())) as any[];
  const player = players.find(
    (p) => String(p.username).toLowerCase() === "icha",
  );
  assert(player);
  const deadline = Date.now() + 55000;
  let xp;
  while (Date.now() < deadline) {
    xp = await conn.procedures.requestData({
      resource: "relaySkills",
      id: String(player.entity_id),
      query: "",
      page: 1,
      options: "{}",
    });
    const ready = [...conn.db.relayStatus.iter()].filter(
      (r) => r.key.startsWith("trading:") && r.ready && !r.error,
    );
    const collectorEpoch = xp.key.split("|")[2]?.replace(/-native-\d+$/, "");
    if (
      ready.length === 9 &&
      ready.every(
        (r) =>
          r.epoch.startsWith(collectorEpoch + "-native-") && r.observedAt > 0n,
      ) &&
      xp.key.includes("-native-") &&
      !xp.error
    )
      break;
    await new Promise((r) => setTimeout(r, 3000));
  }
  const statuses = [...conn.db.relayStatus.iter()];
  const market = JSON.parse(
    await conn.procedures.readMarket({ filters: "{}" }),
  );
  const barter = JSON.parse(await conn.procedures.readRelayBarter({}));
  const result = {
    regions: statuses
      .filter((r) => r.key.startsWith("trading:"))
      .map((r) => ({
        region: r.regionId,
        ready: r.ready,
        error: r.error,
        rows: r.rows,
      })),
    marketItems: market.items.length,
    marketCoverage: market.storage.relay,
    marketError: market.storage.error,
    barterComplete: barter.complete,
    barterStalls: barter.stalls.length,
    player: player.username,
    xpNative: Boolean(xp?.key.includes("-native-")),
    xpError: xp?.error,
    xpSkills: xp?.payload ? JSON.parse(xp.payload).skills?.length : 0,
  };
  console.log(JSON.stringify(result));
  assert.equal(result.marketCoverage.ready, 9);
  assert(
    statuses
      .filter((r) => r.key.startsWith("trading:"))
      .every((r) =>
        r.epoch.startsWith(
          xp!.key.split("|")[2].replace(/-native-\d+$/, "") + "-native-",
        ),
      ),
    "Every region must be reconciled by the current managed collector generation",
  );
  assert(result.barterComplete);
  assert(result.barterStalls > 0);
  assert(result.xpNative);
  assert(!result.xpError);
} finally {
  conn.disconnect();
}
