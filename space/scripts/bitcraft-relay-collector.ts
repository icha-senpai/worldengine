import { tradingDelta } from "./bitcraft-trading-delta";
import { RelaySubscription, tradingQueries } from "./bitcraft-relay";
import {
  assembleRelayTrading,
  CURRENT_REGIONS,
} from "../bitcraft/src/relay-trading";
import { catalogResponse } from "../bitcraft/src/catalog";
import { collectionKey } from "../bitcraft/src/collection";
import type { DbConnection } from "../src/bindings/bitcraft";
type Watch = { resource: string; entityId: string; expiresAt: bigint };
type Scope = {
  key: string;
  relay: RelaySubscription;
  dirty: boolean;
  revision: number;
  published: number;
  heartbeat: number;
  error: string;
  pending: boolean;
  playerId?: string;
  trading?: ReturnType<typeof tradingDelta>["next"];
  tradingGeneration?: string;
};
export class NativeRelayCollector {
  private scopes = new Map<string, Scope>();
  private discovering = new Set<string>();
  private stopped = false;
  private lastDiscovery = new Map<string, number>();
  constructor(
    private conn: DbConnection,
    private epoch: string,
    private due: Map<string, number>,
  ) {}
  private generation(scope: Scope) {
    return `${this.epoch}-native-${scope.relay.generation}`;
  }
  private async status(scope: Scope) {
    if (this.stopped) return;
    await this.conn.reducers.relayHeartbeat({
      key: scope.key,
      provider: scope.relay.provider,
      regionId: scope.relay.region,
      epoch: this.generation(scope),
      ready: scope.relay.ready,
      rows: [...scope.relay.tables.values()].reduce((n, t) => n + t.size, 0),
      error: scope.error.slice(0, 240),
    });
    scope.heartbeat = Date.now();
  }
  private add(
    key: string,
    provider: "bitconnect" | "bitsync",
    region: number,
    queries: string[],
    playerId?: string,
  ) {
    const scope: Scope = {
      key,
      relay: null!,
      dirty: false,
      revision: 0,
      published: 0,
      heartbeat: 0,
      error: "",
      pending: false,
      playerId,
    };
    scope.relay = new RelaySubscription(
      provider,
      region,
      queries,
      (initial, tables) => {
        scope.dirty = true;
        scope.revision++;
        scope.error = "";
        if (!initial && playerId) {
          if (tables.includes("inventory_state"))
            this.due.set(
              collectionKey("relayInventories", `player/${playerId}/inventory`),
              0,
            );
          if (
            tables.some((t) =>
              ["passive_craft_state", "progressive_action_state"].includes(t),
            )
          )
            this.due.set(
              collectionKey("relayCrafts", `player/${playerId}/crafts`),
              0,
            );
        }
      },
      (error) => {
        if (this.stopped) return;
        scope.error = error;
        scope.dirty = false;
        scope.published = 0;
        if (playerId)
          void this.conn.reducers
            .collectionFailure({
              key: collectionKey("relaySkills", `player/${playerId}/skills`),
            })
            .catch(() => {});
        console.error(`Native relay ${key}: ${error}`);
        void this.status(scope).catch(() => {});
      },
    );
    this.scopes.set(key, scope);
    return scope;
  }
  async start() {
    const configured = (
      process.env.BITCRAFT_RELAY_REGIONS ??
      Object.keys(CURRENT_REGIONS).join(",")
    )
      .split(",")
      .map(Number);
    if (!configured.length || configured.some((r) => !CURRENT_REGIONS[r]))
      throw Error("Configure only current region IDs for native relays.");
    for (const region of [...new Set(configured)]) {
      if (this.stopped) break;
      const scope = this.add(
        `trading:${region}`,
        "bitconnect",
        region,
        tradingQueries(),
      );
      await scope.relay.start();
      // Stagger connection handshakes; each scope still waits for a complete Applied.
      await new Promise((r) => setTimeout(r, 350));
    }
  }
  private async player(id: string) {
    if (
      this.discovering.has(id) ||
      Date.now() < (this.lastDiscovery.get(id) ?? 0)
    )
      return;
    this.discovering.add(id);
    this.lastDiscovery.set(id, Date.now() + 60000);
    try {
      const response = await this.conn.procedures.requestData({
        resource: "relayPlayer",
        id,
        query: "",
        page: 1,
        options: '{"collector":true}',
      });
      if (!response.payload || response.error)
        throw Error("Player region resolution delayed.");
      const player = JSON.parse(response.payload),
        region = Number(player.region ?? player.regionId);
      if (!CURRENT_REGIONS[region])
        throw Error("Player region is outside current regions.");
      if (this.stopped) return;
      const previous = this.scopes.get(`player:${id}`);
      if (previous?.relay.region === region) return;
      previous?.relay.stop();
      const scope = this.add(
        `player:${id}`,
        "bitsync",
        region,
        [
          `SELECT * FROM player_username_state WHERE entity_id = ${id}`,
          `SELECT * FROM player_state WHERE entity_id = ${id}`,
          `SELECT * FROM experience_state WHERE entity_id = ${id}`,
          `SELECT * FROM inventory_state WHERE player_owner_entity_id = ${id}`,
          `SELECT * FROM progressive_action_state WHERE owner_entity_id = ${id}`,
          `SELECT * FROM passive_craft_state WHERE owner_entity_id = ${id}`,
        ],
        id,
      );
      await scope.relay.start();
    } catch (error) {
      console.error(
        `Native player discovery delayed: ${(error as Error).message}`,
      );
    } finally {
      this.discovering.delete(id);
    }
  }
  async tick(watches: Watch[]) {
    if (this.stopped) return;
    const active = new Set(
      watches
        .filter(
          (w) =>
            w.expiresAt / 1000n > BigInt(Date.now()) &&
            /^relay(Player|Skills|Inventories|Housing|Crafts|Session)$/.test(
              w.resource,
            ),
        )
        .map((w) => w.entityId)
        .filter((id) => /^\d{1,24}$/.test(id)),
    );
    // Bound private companion connection fan-out; remaining users retain shared APIs.
    for (const id of [...active].slice(0, 30)) void this.player(id);
    for (const [key, scope] of this.scopes) {
      if (scope.playerId && !active.has(scope.playerId)) {
        scope.relay.stop();
        this.scopes.delete(key);
        continue;
      }
      if (scope.pending) continue;
      const due =
        scope.relay.ready &&
        ((scope.dirty && Date.now() - scope.published >= 3000) ||
          Boolean(scope.playerId && Date.now() - scope.published >= 10000));
      if (due || Date.now() - scope.heartbeat >= 15000) {
        scope.pending = true;
        try {
          await this.status(scope);
          if (this.stopped) return;
          if (due && scope.relay.ready) {
            const revision = scope.revision,
              generation = this.generation(scope),
              observedAt = BigInt(Date.now()) * 1000n;
            if (scope.playerId) {
              const id = scope.playerId,
                experience = scope.relay.tables
                  .get("experience_state")
                  ?.get(id),
                username = scope.relay.tables
                  .get("player_username_state")
                  ?.get(id),
                state = scope.relay.tables.get("player_state")?.get(id);
              if (!experience || !username || !state)
                throw Error("Complete player XP snapshot is unavailable.");
              const stacks = experience.experience_stacks ?? [];
              const payload = {
                player: { ...state, ...username, region: scope.relay.region },
                skills: stacks.map((s: any) => ({
                  skill_id: Number(s["0"] ?? s.skill_id),
                  xp: Number(s["1"] ?? s.quantity),
                })),
              };
              await this.conn.reducers.ingestCollection({
                key: collectionKey("relaySkills", `player/${id}/skills`),
                source: "relay",
                epoch: generation,
                payload: JSON.stringify(payload),
                observedAt,
              });
              this.due.set(
                collectionKey("relaySkills", `player/${id}/skills`),
                Date.now() + 15000,
              );
            } else {
              const data = assembleRelayTrading(
                scope.relay.tables,
                scope.relay.region,
                (kind, id) => catalogResponse("item-info", `${kind}:${id}`, ""),
              );
              const delta = tradingDelta(
                scope.tradingGeneration === generation
                  ? scope.trading
                  : undefined,
                data,
              );
              if (delta.changed) {
                await this.conn.reducers.ingestRelayTrading({
                  regionId: scope.relay.region,
                  epoch: generation,
                  payload: JSON.stringify(delta.patch),
                  observedAt,
                });
                scope.trading = delta.next;
                scope.tradingGeneration = generation;
              }
            }
            scope.published = Date.now();
            scope.dirty = scope.revision !== revision;
          }
        } catch (error) {
          scope.error = (error as Error).message;
          console.error(`Native relay publication ${key}: ${scope.error}`);
          if (scope.playerId)
            await this.conn.reducers
              .collectionFailure({
                key: collectionKey(
                  "relaySkills",
                  `player/${scope.playerId}/skills`,
                ),
              })
              .catch(() => {});
        } finally {
          scope.pending = false;
        }
      }
    }
  }
  owns(resource: string, id: string) {
    const scope = this.scopes.get(`player:${id}`);
    return (
      resource === "relaySkills" &&
      Boolean(scope?.relay.ready && scope.published && !scope.error)
    );
  }
  stop() {
    this.stopped = true;
    for (const scope of this.scopes.values()) scope.relay.stop();
  }
}
