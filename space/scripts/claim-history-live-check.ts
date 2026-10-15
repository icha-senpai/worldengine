import { RelaySubscription } from "./bitcraft-relay";
import {
  claimHistoryQueries,
  ClaimHistoryProjection,
} from "./claim-history-projection";
import { CURRENT_REGIONS } from "../bitcraft/src/relay-trading";
import {
  isTrackedClaim,
  HISTORY_MIN_TIER,
} from "../bitcraft/src/claim-history";

const checks = await Promise.all(
  Object.keys(CURRENT_REGIONS).map(
    (id) =>
      new Promise<Record<string, unknown>>((resolve) => {
        const region = Number(id),
          projection = new ClaimHistoryProjection();
        let done = false;
        const finish = (result: Record<string, unknown>) => {
          if (done) return;
          done = true;
          clearTimeout(timeout);
          relay.stop();
          resolve({ region, name: CURRENT_REGIONS[region], ...result });
        };
        const relay = new RelaySubscription(
          "bitsync",
          region,
          claimHistoryQueries(),
          (initial) => {
            if (!initial) return;
            try {
              const discovered = projection
                .apply(true, relay.tables, [])
                .map((c) => c.after!);
              const claims = discovered.filter(isTrackedClaim);
              const supplies = [
                ...projection.supplies(relay.tables, true, []).values(),
              ].filter(Boolean);
              finish({
                minimumTier: HISTORY_MIN_TIER,
                discoveredClaims: discovered.length,
                tierOnlyClaims: discovered.filter(
                  (c) => c.tier !== null && c.tier >= HISTORY_MIN_TIER,
                ).length,
                claims: claims.length,
                members: claims.reduce((n, c) => n + c.members.length, 0),
                unknownTiers: claims.filter((c) => c.tier === null).length,
                baselineBytes: Buffer.byteLength(JSON.stringify(claims)),
                unknownUpkeep: supplies.filter((s) => s!.upkeepPerHour === null)
                  .length,
                upkeepSample:
                  supplies.find((s) => s!.claimId === "1297036692700056569") ??
                  undefined,
                observedAt: new Date().toISOString(),
              });
            } catch (e) {
              finish({ error: (e as Error).message });
            }
          },
          (error) => finish({ error }),
        );
        const timeout = setTimeout(
          () => finish({ error: "Timed out waiting for a complete snapshot" }),
          30000,
        );
        void relay.start().catch((e) => finish({ error: e.message }));
      }),
  ),
);
console.log(JSON.stringify(checks, null, 2));
if (checks.some((c) => c.error)) process.exitCode = 1;
