// Keep a short local event trail across reloads so tab restores and development
// reloads can be distinguished from database reconnects. Never record tokens.
const storageKey = "space.pageLifecycle";
// Store categories and numeric transport details, never raw errors/URLs/tokens.
export function recordConnectionLifecycle(
  event: string,
  error: unknown,
  nextAttempt?: number,
  delayMs?: number,
) {
  const value = error as
    { name?: string; message?: string; status?: number } | undefined;
  const message = typeof value?.message === "string" ? value.message : "";
  const close = /^WebSocket closed \(code (\d{4})(?:[:)]|$)/.exec(message);
  const errorTypes = [
    "Error",
    "TypeError",
    "DisconnectedError",
    "UnknownCallResultError",
    "IdentityChangedError",
    "WebSocketTokenError",
    "WebSocketProtocolError",
  ];
  const reason = close
    ? "socket_closed"
    : message === "WebSocket closed while suspended"
      ? "tab_suspended"
      : message === "Invalid server message" ||
          value?.name === "WebSocketProtocolError"
        ? "protocol_error"
        : value?.name === "IdentityChangedError"
          ? "identity_changed"
          : value?.name === "WebSocketTokenError"
            ? "authentication"
            : message === "WebSocket error" ||
                /failed to fetch|networkerror/i.test(message)
              ? "transport_error"
              : error
                ? "other_error"
                : "closed";
  recordPageLifecycle(event, {
    reason,
    errorType: errorTypes.includes(value?.name ?? "") ? value!.name : "Error",
    ...(close ? { closeCode: Number(close[1]) } : {}),
    ...(Number.isInteger(value?.status) &&
    value!.status! >= 100 &&
    value!.status! <= 599
      ? { status: value!.status }
      : {}),
    ...(Number.isFinite(nextAttempt) ? { retryAttempt: nextAttempt } : {}),
    ...(Number.isFinite(delayMs) ? { retryDelayMs: delayMs } : {}),
    online: typeof navigator === "undefined" ? null : navigator.onLine,
    visibility:
      typeof document === "undefined"
        ? "unknown"
        : document.hidden
          ? "hidden"
          : "visible",
  });
}
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
