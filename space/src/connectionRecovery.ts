// Recheck lost sockets on tab/network resume without rebuilding healthy ones.
export function createConnectionRecovery(
  reconnect: () => void,
  needsRecovery: () => boolean,
) {
  let timer: ReturnType<typeof setTimeout> | undefined;
  let attempt = 0;
  const cancel = () => {
    clearTimeout(timer);
    timer = undefined;
  };
  const resume = () => {
    if (!needsRecovery()) return;
    cancel();
    reconnect();
  };
  const visibility = () => {
    if (!document.hidden) resume();
  };
  window.addEventListener("focus", resume);
  window.addEventListener("online", resume);
  window.addEventListener("pageshow", resume);
  document.addEventListener("visibilitychange", visibility);
  return {
    schedule() {
      if (timer !== undefined || !needsRecovery()) return;
      const delay = Math.min(1000 * 2 ** attempt++, 30000);
      timer = setTimeout(() => {
        timer = undefined;
        if (needsRecovery()) reconnect();
      }, delay);
    },
    reset() {
      cancel();
      attempt = 0;
    },
    cancel,
    stop() {
      cancel();
      window.removeEventListener("focus", resume);
      window.removeEventListener("online", resume);
      window.removeEventListener("pageshow", resume);
      document.removeEventListener("visibilitychange", visibility);
    },
  };
}
