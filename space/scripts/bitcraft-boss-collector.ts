import { BossRelay } from "./boss-relay";
import { CollectorCycle } from "./bitcraft-collector-cycle";
import type { DbConnection } from "../src/bindings/bitcraft";
import type { BossDefeat } from "../bitcraft/src/bosses";

type Scope = {
  relay: BossRelay;
  queue: BossDefeat[];
  bytes: number;
  error: string;
  heartbeat: number;
  dirty: boolean;
};
export class BossCollector {
  private scopes = new Map<number, Scope>();
  private stopped = false;
  constructor(
    private conn: DbConnection,
    private epoch: string,
    private cycle: CollectorCycle,
  ) {}
  async start(region: number) {
    const scope: Scope = {
      relay: null!,
      queue: [],
      bytes: 0,
      error: "",
      heartbeat: 0,
      dirty: true,
    };
    scope.relay = new BossRelay(
      region,
      (defeats) => {
        for (const defeat of defeats) {
          scope.queue.push(defeat);
          scope.bytes += Buffer.byteLength(JSON.stringify(defeat));
        }
        if (scope.queue.length > 1000 || scope.bytes > 8_000_000) {
          scope.error =
            "Boss recording backlog exceeded its limit; recording gap.";
          scope.relay.stop();
          scope.dirty = true;
          this.cycle.stop(scope.error);
        }
      },
      (_ready, error) => {
        scope.error = error;
        scope.dirty = true;
      },
    );
    this.scopes.set(region, scope);
    await scope.relay.start();
  }
  async flush() {
    if (this.stopped || this.cycle.stopped) return;
    for (const [regionId, scope] of this.scopes) {
      // A confirmed encounter stays queued across source reconnects. Only an
      // acknowledged transactional write retires it. The database deduplicates.
      for (let n = 0; n < 8 && scope.queue.length; n++) {
        const batch: BossDefeat[] = [];
        let size = 0;
        for (const defeat of scope.queue) {
          const bytes = Buffer.byteLength(JSON.stringify(defeat));
          if (batch.length && (batch.length >= 100 || size + bytes > 850_000))
            break;
          batch.push(defeat);
          size += bytes;
        }
        try {
          await this.cycle.wait(() =>
            this.conn.reducers.ingestBossDefeats({
              regionId,
              epoch: `${this.epoch}-boss-${scope.relay.generation}`,
              payload: JSON.stringify(batch),
            }),
          );
          scope.queue.splice(0, batch.length);
          scope.bytes -= size;
        } catch (error) {
          scope.error =
            `Boss publication failed: ${(error as Error).message}`.slice(
              0,
              240,
            );
          scope.dirty = true;
          // Report the failure before the shared worker reconnects, if its
          // storage lease and local connection still permit a small write.
          await this.conn.reducers
            .bossHeartbeat({
              regionId,
              epoch: this.epoch,
              ready: false,
              error: scope.error,
            })
            .catch(() => {});
          this.cycle.stop(scope.error);
          throw error;
        }
      }
      if (scope.dirty || Date.now() - scope.heartbeat >= 15000) {
        await this.cycle.wait(() =>
          this.conn.reducers.bossHeartbeat({
            regionId,
            epoch: `${this.epoch}-boss-${scope.relay.generation}`,
            ready: scope.relay.ready && !scope.error && !scope.queue.length,
            error: scope.error,
          }),
        );
        scope.heartbeat = Date.now();
        scope.dirty = false;
      }
    }
  }
  stop() {
    this.stopped = true;
    for (const scope of this.scopes.values()) scope.relay.stop();
    this.scopes.clear();
  }
}
