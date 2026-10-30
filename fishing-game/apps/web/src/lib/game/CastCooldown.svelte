<script lang="ts">
  import { onMount } from 'svelte';
  import { ServerClock, remainingCooldownSeconds } from './server-clock';
  import { formatCooldownSeconds } from './activity-timers';

  let { nextCastAt, zeroLabel = 'Ready!', formatMinutes = false }: { nextCastAt: bigint | null; zeroLabel?: string; formatMinutes?: boolean } = $props();
  const clock = new ServerClock();
  let revision = $state(0);
  let clockFailed = $state(false);
  const remaining = $derived.by(() => {
    revision;
    // Read time here, rather than storing a once-per-second timestamp: a new
    // catch must not be compared with the previous tick's stale time.
    return remainingCooldownSeconds(nextCastAt, clock.now());
  });

  onMount(() => {
    let request: AbortController | null = null;
    let disposed = false;
    async function synchronize() {
      if (request || document.visibilityState !== 'visible') return;
      const controller = new AbortController();
      request = controller;
      const timeout = setTimeout(() => controller.abort(), 5000);
      const sent = performance.now();
      try {
        const response = await fetch('/api/time', { cache: 'no-store', signal: controller.signal });
        if (!response.ok) throw new Error('Server clock unavailable');
        const sample = await response.json();
        const received = performance.now();
        if (disposed) return;
        clock.synchronize(sample.serverTimeMs, sent, received);
        clockFailed = false;
        revision++;
      } catch {
        if (!disposed) clockFailed = true;
      } finally {
        clearTimeout(timeout);
        request = null;
      }
    }
    function resume() {
      if (document.visibilityState !== 'visible') return;
      revision++;
      void synchronize();
    }
    void synchronize();
    const ticker = setInterval(() => { revision++; }, 1000);
    const syncTimer = setInterval(() => { void synchronize(); }, 60_000);
    document.addEventListener('visibilitychange', resume);
    window.addEventListener('pageshow', resume);
    return () => {
      disposed = true;
      request?.abort();
      clearInterval(ticker);
      clearInterval(syncTimer);
      document.removeEventListener('visibilitychange', resume);
      window.removeEventListener('pageshow', resume);
    };
  });
</script>

{#if remaining === null}{clockFailed ? 'Time unavailable' : 'Syncing…'}{:else}{remaining ? formatCooldownSeconds(remaining, formatMinutes) : zeroLabel}{/if}
