import { RelaySubscription } from "./bitcraft-relay";
import {
  claimHistoryQueries,
  ClaimHistoryProjection,
} from "./claim-history-projection";
import { CollectorCycle } from "./bitcraft-collector-cycle";
import type { DbConnection } from "../src/bindings/bitcraft";
import type { HistoryChange } from "../bitcraft/src/claim-history";
import type { ClaimSupply } from "../bitcraft/src/claim-supplies";

type Packet = {
  regionId: number;
  epoch: string;
  sequence: number;
  initial: boolean;
  complete: boolean;
  payload: string;
  observedAt: bigint;
};
type Scope = {
  supplies: Map<string, { supply: ClaimSupply | null; observedAt: bigint }>;
  supplyCache: Map<string, string>;
  relay: RelaySubscription;
  projection: ClaimHistoryProjection;
  queue: Packet[];
  bytes: number;
  sequence: number;
  heartbeat: number;
  error: string;
};
export class ClaimHistoryCollector {
  private scopes = new Map<number, Scope>();
  private stopped = false;
  constructor(
    private conn: DbConnection,
    private epoch: string,
    private cycle: CollectorCycle,
  ) {}
  private generation(scope: Scope) {
    return `${this.epoch}-claims-${scope.relay.region}-${scope.relay.generation}`;
  }
  async start(region: number) {
    const scope: Scope = {
      supplies: new Map(),
      supplyCache: new Map(),
      relay: null!,
      projection: new ClaimHistoryProjection(),
      queue: [],
      bytes: 0,
      sequence: 0,
      heartbeat: 0,
      error: "",
    };
    scope.relay = new RelaySubscription(
      "bitsync",
      region,
      claimHistoryQueries(),
      (initial, _tables, rows) => {
        if (this.stopped) return;
        if (initial) {
          scope.supplies.clear();
          scope.supplyCache.clear();
          scope.queue = [];
          scope.bytes = 0;
          scope.sequence = 0;
        }
        const changes = scope.projection.apply(
          initial,
          scope.relay.tables,
          rows,
        );
        for (const [id, supply] of scope.projection.supplies(
          scope.relay.tables,
          initial,
          rows,
        )) {
          const signature = JSON.stringify(supply);
          if (
            (supply && scope.supplyCache.get(id) !== signature) ||
            (!supply && scope.supplyCache.has(id))
          ) {
            scope.supplies.set(id, {
              supply,
              observedAt: BigInt(Date.now()) * 1000n,
            });
            if (supply) scope.supplyCache.set(id, signature);
            else scope.supplyCache.delete(id);
          }
        }
        const batches: HistoryChange[][] = [];
        let batch: HistoryChange[] = [],
          bytes = 0;
        for (const change of changes) {
          const size = JSON.stringify(change).length;
          if (batch.length && (batch.length >= 200 || bytes + size > 900_000)) {
            batches.push(batch);
            batch = [];
            bytes = 0;
          }
          batch.push(change);
          bytes += size;
        }
        if (batch.length || initial) batches.push(batch);
        batches.forEach((batch, i) => {
          const payload = JSON.stringify(batch);
          scope.bytes += Buffer.byteLength(payload);
          scope.queue.push({
            regionId: region,
            epoch: this.generation(scope),
            sequence: ++scope.sequence,
            initial,
            complete: initial && i === batches.length - 1,
            payload,
            observedAt: BigInt(Date.now()) * 1000n,
          });
        });
        scope.error = "";
        if (scope.bytes > 16_000_000 || scope.queue.length > 2000) {
          scope.error =
            "Claim recording backlog exceeded its limit; reconnect required.";
          this.cycle.stop(scope.error);
        }
      },
      (error) => {
        scope.supplies.clear();
        scope.supplyCache.clear();
        scope.error = error;
        scope.queue = [];
        scope.bytes = 0;
        scope.projection.reset();
        void this.status(scope).catch(() => {});
      },
    );
    this.scopes.set(region, scope);
    await scope.relay.start();
  }
  private async status(scope: Scope) {
    if (this.stopped) return;
    await this.cycle.wait(() =>
      this.conn.reducers.relayHeartbeat({
        key: `claims:${scope.relay.region}`,
        provider: "bitsync",
        regionId: scope.relay.region,
        epoch: this.generation(scope),
        ready: scope.relay.ready && !scope.error && !scope.queue.length,
        rows: [...scope.relay.tables.values()].reduce((n, t) => n + t.size, 0),
        error: scope.error.slice(0, 240),
      }),
    );
    scope.heartbeat = Date.now();
  }
  async flush() {
    if (this.stopped || this.cycle.stopped) return;
    for (const scope of this.scopes.values()) {
      // Preserve each upstream transaction rather than collapsing changes into
      // the last state of a polling interval. Bound work and buffered payloads.
      for (let n = 0; n < 32 && scope.queue.length; n++) {
        const packet = scope.queue[0];
        try {
          await this.cycle.wait(() =>
            this.conn.reducers.ingestClaimHistory(packet),
          );
          if (this.stopped) return;
          if (scope.queue[0] === packet) {
            scope.queue.shift();
            scope.bytes -= Buffer.byteLength(packet.payload);
          }
        } catch (error) {
          // Retire the generation on a failed write. The next baseline must
          // reconcile stored state and explicitly expose the recording gap.
          scope.error = (error as Error).message;
          this.cycle.stop("Claim recording publication failed; reconnecting.");
          throw error;
        }
      }
      if (!scope.queue.length && scope.relay.ready && scope.supplies.size) {
        const entries = [...scope.supplies.entries()];
        for (let i = 0; i < entries.length; i += 200) {
          const batch = entries.slice(i, i + 200),
            observedAt = batch.reduce(
              (at, [_id, value]) =>
                value.observedAt > at ? value.observedAt : at,
              0n,
            );
          try {
            await this.cycle.wait(() =>
              this.conn.reducers.ingestClaimSupplies({
                regionId: scope.relay.region,
                epoch: this.generation(scope),
                observedAt,
                payload: JSON.stringify(
                  batch.map(([claimId, value]) => ({
                    claimId,
                    supply: value.supply,
                  })),
                ),
              }),
            );
            if (this.stopped) return;
            for (const [id, value] of batch)
              if (scope.supplies.get(id) === value) scope.supplies.delete(id);
          } catch (error) {
            this.cycle.stop("Claim supply publication failed; reconnecting.");
            throw error;
          }
        }
      }
      if (Date.now() - scope.heartbeat >= 15000) await this.status(scope);
    }
  }
  stop() {
    this.stopped = true;
    for (const scope of this.scopes.values()) {
      scope.relay.stop();
      scope.queue = [];
      scope.supplies.clear();
      scope.supplyCache.clear();
      scope.projection.reset();
    }
    this.scopes.clear();
  }
}
