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

  reset() {
    this.anchor = null;
  }
}

export function observeServerClock(clock: ServerClock, onChange: () => void): () => void {
  let stopped = false;
  let hidden = document.visibilityState !== 'visible';
  let request: { controller: AbortController; timeout: ReturnType<typeof setTimeout> } | null = null;
  function cancelRequest() {
    const current = request;
    request = null;
    if (current) { clearTimeout(current.timeout); current.controller.abort(); }
  }
  async function synchronize() {
    if (stopped || request || document.visibilityState !== 'visible') return;
    const controller = new AbortController();
    const timeout = setTimeout(() => {
      if (request?.controller === controller) { cancelRequest(); onChange(); }
    }, 5000);
    request = { controller, timeout };
    const sent = performance.now();
    try {
      const response = await fetch('/api/time', { cache: 'no-store', signal: controller.signal });
      if (!response.ok) throw new Error('Server clock unavailable');
      const sample = await response.json();
      if (stopped || request?.controller !== controller || controller.signal.aborted) return;
      clock.synchronize(sample.serverTimeMs, sent, performance.now());
      onChange();
    } catch {
    } finally {
      clearTimeout(timeout);
      if (request?.controller === controller) request = null;
    }
  }
  function resume() {
    cancelRequest();
    clock.reset();
    onChange();
    void synchronize();
  }
  function freeze() {
    cancelRequest();
    clock.reset();
    onChange();
  }
  function wake() {
    if (document.visibilityState === 'visible') resume();
  }
  function visibility() {
    if (document.visibilityState !== 'visible') { hidden = true; return; }
    if (hidden) { hidden = false; resume(); }
  }
  function pageshow(event: PageTransitionEvent) {
    if (document.visibilityState !== 'visible') return;
    if (event.persisted || hidden) { hidden = false; resume(); }
    else void synchronize();
  }
  void synchronize();
  const ticker = setInterval(onChange, 1000);
  const syncTimer = setInterval(() => { void synchronize(); }, 60_000);
  document.addEventListener('visibilitychange', visibility);
  document.addEventListener('freeze', freeze);
  document.addEventListener('resume', wake);
  window.addEventListener('pageshow', pageshow);
  window.addEventListener('focus', wake);
  return () => {
    stopped = true;
    cancelRequest();
    clearInterval(ticker);
    clearInterval(syncTimer);
    document.removeEventListener('visibilitychange', visibility);
    document.removeEventListener('freeze', freeze);
    document.removeEventListener('resume', wake);
    window.removeEventListener('pageshow', pageshow);
    window.removeEventListener('focus', wake);
  };
}

export function deadlineExpired(deadlineMicros: bigint | null, serverMs: number | null): boolean {
  return deadlineMicros === null || serverMs === null || deadlineMicros <= BigInt(Math.floor(serverMs)) * 1000n;
}

export function remainingCooldownSeconds(deadlineMicros: bigint | null, serverMs: number | null): number | null {
  if (deadlineMicros === null || serverMs === null) return null;
  return Math.max(0, Math.ceil((Number(deadlineMicros / 1000n) - serverMs) / 1000));
}
