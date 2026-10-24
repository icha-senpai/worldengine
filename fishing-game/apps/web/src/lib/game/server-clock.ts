// Anchor server time to a monotonic clock so changes to the PC clock cannot
// lengthen a cooldown. The midpoint estimate accounts for request latency.
export class ServerClock {
  private anchor: { serverMs: number; monotonicMs: number } | null = null;
  private monotonicNow: () => number;

  constructor(monotonicNow: () => number = () => performance.now()) {
    this.monotonicNow = monotonicNow;
  }

  synchronize(serverMs: number, sentMs: number, receivedMs: number) {
    if (!Number.isFinite(serverMs) || serverMs <= 0 || !Number.isFinite(sentMs)
      || !Number.isFinite(receivedMs) || receivedMs < sentMs) {
      throw new Error('Invalid server clock sample');
    }
    this.anchor = { serverMs: serverMs + (receivedMs - sentMs) / 2, monotonicMs: receivedMs };
  }

  now(): number | null {
    return this.anchor ? this.anchor.serverMs + this.monotonicNow() - this.anchor.monotonicMs : null;
  }
}

export function remainingCooldownSeconds(deadlineMicros: bigint | null, serverMs: number | null): number | null {
  if (deadlineMicros === null || serverMs === null) return null;
  return Math.max(0, Math.ceil((Number(deadlineMicros / 1000n) - serverMs) / 1000));
}
