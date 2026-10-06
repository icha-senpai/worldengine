// Keep a short local event trail across reloads so tab restores and development
// reloads can be distinguished from database reconnects. Never record tokens.
const storageKey = "space.pageLifecycle";
export function recordPageLifecycle(
  event: string,
  details: Record<string, unknown> = {},
) {
  try {
    const saved = JSON.parse(sessionStorage.getItem(storageKey) ?? "[]");
    const entries = Array.isArray(saved) ? saved : [];
    sessionStorage.setItem(
      storageKey,
      JSON.stringify([
        ...entries.slice(-29),
        { at: new Date().toISOString(), event, ...details },
      ]),
    );
  } catch {
    // Diagnostics must not prevent the app from loading when storage is blocked.
  }
}

export function initializePageLifecycle() {
  const navigation = performance.getEntriesByType("navigation")[0] as
    PerformanceNavigationTiming | undefined;
  recordPageLifecycle("page-loaded", {
    navigation: navigation?.type ?? "unknown",
    discarded:
      (document as Document & { wasDiscarded?: boolean }).wasDiscarded ?? false,
  });
  const resumed = () => {
    if (!document.hidden) recordPageLifecycle("tab-visible");
  };
  const restored = (event: PageTransitionEvent) => {
    if (event.persisted) recordPageLifecycle("page-restored");
  };
  document.addEventListener("visibilitychange", resumed);
  window.addEventListener("pageshow", restored);
  import.meta.hot?.on("vite:beforeFullReload", (payload) => {
    recordPageLifecycle("development-reload", { file: payload.path ?? null });
  });
  import.meta.hot?.dispose(() => {
    document.removeEventListener("visibilitychange", resumed);
    window.removeEventListener("pageshow", restored);
  });
}
