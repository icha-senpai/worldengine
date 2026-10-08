export const retryAfterSeconds = (response) => {
  const value = response.headers?.get("Retry-After");

  if (!value) return 60;
  if (/^\d+$/.test(value)) return Math.max(1, Number(value));

  const date = Date.parse(value);
  return Number.isFinite(date)
    ? Math.max(1, Math.ceil((date - Date.now()) / 1000))
    : 60;
};

export const createTrackerPoller = (
  refresh,
  interval,
  { pauseWhenHidden = true } = {},
) => {
  let timer = null;
  let inFlight = false;
  let notBefore = 0;
  let updateTimer = null;
  let running = false;

  const run = async () => {
    if (
      !running ||
      (pauseWhenHidden && document.hidden) ||
      inFlight ||
      Date.now() < notBefore
    )
      return;

    inFlight = true;
    try {
      const delay = await refresh();
      if (delay > 0) notBefore = Date.now() + delay * 1000;
    } finally {
      inFlight = false;
    }
  };
  const onUpdate = () => {
    window.clearTimeout(updateTimer);
    updateTimer = window.setTimeout(run, 250);
  };
  const onResume = () => {
    if (!document.hidden) void run();
  };

  return {
    start(delay = 0) {
      this.stop();
      running = true;
      notBefore = Date.now() + delay * 1000;
      timer = window.setInterval(run, interval);
      window.addEventListener("bitcraft-data-updated", onUpdate);
      document.addEventListener("visibilitychange", onResume);
      window.addEventListener("focus", onResume);
      window.addEventListener("pageshow", onResume);
    },
    stop() {
      running = false;
      window.clearInterval(timer);
      timer = null;
      window.clearTimeout(updateTimer);
      window.removeEventListener("bitcraft-data-updated", onUpdate);
      document.removeEventListener("visibilitychange", onResume);
      window.removeEventListener("focus", onResume);
      window.removeEventListener("pageshow", onResume);
    },
  };
};
