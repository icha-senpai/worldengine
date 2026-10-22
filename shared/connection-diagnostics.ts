import {
  cleanConnectionEvent,
  connectionFailure,
  type ConnectionEvent,
  type DiagnosticSite,
} from "./connection-events";

export function createConnectionDiagnostics(
  site: DiagnosticSite,
  release: string,
  storageKey: string,
) {
  let session = "",
    sequence = 0,
    initialized = false;
  let queue: ConnectionEvent[] = [],
    timer: ReturnType<typeof setTimeout> | undefined,
    sending = false;
  const lost = new Map<string, number>();
  const transports = new Map<string, string>();
  const read = (key: string) => {
    try {
      return sessionStorage.getItem(key);
    } catch {
      return null;
    }
  };
  const save = (key: string, value: unknown) => {
    try {
      sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
      /* Storage is optional. */
    }
  };
  const pendingKey = storageKey + ".pending";
  const endpoint = () =>
    typeof window === "undefined"
      ? ""
      : window.location.origin === "https://bitcraft.ichaa.dev"
        ? "https://fishbound.ichaa.dev/api/connection-events"
        : window.location.origin === "https://fishbound.ichaa.dev"
          ? "/api/connection-events"
          : "";
  function initializeStorage() {
    if (initialized) return;
    initialized = true;
    try {
      const saved = JSON.parse(read(storageKey + ".session") ?? "null");
      session =
        typeof saved?.id === "string" &&
        /^[0-9a-f]{8}-[0-9a-f-]{27}$/.test(saved.id)
          ? saved.id
          : crypto.randomUUID();
      sequence = Number.isSafeInteger(saved?.sequence) ? saved.sequence : 0;
      const pending = JSON.parse(read(pendingKey) ?? "[]");
      queue = Array.isArray(pending)
        ? pending
            .slice(-100)
            .map((row) => cleanConnectionEvent(row))
            .filter((row): row is ConnectionEvent => !!row)
        : [];
    } catch {
      session = crypto.randomUUID();
    }
  }
  const browser = () => {
    const agent =
      typeof navigator === "undefined" ? "" : (navigator.userAgent ?? "");
    return /Edg\//.test(agent)
      ? "edge"
      : /Firefox\//.test(agent)
        ? "firefox"
        : /Chrome\//.test(agent)
          ? "chrome"
          : /Safari\//.test(agent)
            ? "safari"
            : "other";
  };
  function schedule(delay = 1000) {
    if (!timer && endpoint() && queue.length)
      timer = setTimeout(() => {
        timer = undefined;
        void flush();
      }, delay);
  }
  async function flush() {
    initializeStorage();
    queue = queue
      .map((row) => cleanConnectionEvent(row))
      .filter((row): row is ConnectionEvent => !!row);
    save(pendingKey, queue);
    if (sending || !queue.length || !endpoint()) return;
    sending = true;
    const events = queue.slice(0, 10);
    try {
      const response = await fetch(endpoint(), {
        method: "POST",
        credentials: "omit",
        keepalive: true,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          version: 1,
          site,
          release,
          session,
          browser: browser(),
          events,
        }),
        signal: AbortSignal.timeout(5000),
      });
      if (response.ok) {
        queue = queue.filter(
          (row) => row.sequence > events[events.length - 1]!.sequence,
        );
        save(pendingKey, queue);
      }
    } catch {
      /* Keep the bounded queue for recovery, reload or manual export. */
    } finally {
      sending = false;
      schedule(30000);
    }
  }
  function record(event: string, details: Record<string, unknown> = {}) {
    initializeStorage();
    const channel = event.split("-")[0]!;
    if (
      typeof details.connectionId === "string" &&
      /^[a-f0-9]{32}$/.test(details.connectionId)
    )
      transports.set(channel, details.connectionId);
    if (event.endsWith("-disconnected") || event.endsWith("-connect-failed")) {
      if (!lost.has(channel)) lost.set(channel, Date.now());
    }
    const recovered = event.endsWith("-ready") ? lost.get(channel) : undefined;
    const row = cleanConnectionEvent({
      ...details,
      connectionId: transports.get(channel),
      at: new Date().toISOString(),
      event,
      sequence: ++sequence,
      release,
      online: typeof navigator === "undefined" ? undefined : navigator.onLine,
      visibility:
        typeof document === "undefined"
          ? "unknown"
          : document.hidden
            ? "hidden"
            : "visible",
      ...(recovered === undefined
        ? {}
        : { recoveryMs: Date.now() - recovered }),
    });
    if (!row) return;
    if (recovered !== undefined) lost.delete(channel);
    try {
      const rows = JSON.parse(read(storageKey) ?? "[]");
      save(storageKey, [...(Array.isArray(rows) ? rows.slice(-199) : []), row]);
    } catch {
      save(storageKey, [row]);
    }
    save(storageKey + ".session", { id: session, sequence });
    queue = [...queue.slice(-99), row];
    save(pendingKey, queue);
    schedule();
  }
  function initialize() {
    if (typeof window === "undefined") return () => {};
    const navigation = performance.getEntriesByType("navigation")[0] as
      PerformanceNavigationTiming | undefined;
    record("page-loaded", {
      navigation: navigation?.type ?? "unknown",
      discarded:
        (document as Document & { wasDiscarded?: boolean }).wasDiscarded ??
        false,
    });
    const visibility = () =>
      record(document.hidden ? "tab-hidden" : "tab-visible");
    const online = () => {
      record("browser-online");
      void flush();
    };
    const offline = () => record("browser-offline");
    const pagehide = () => {
      record("page-hidden");
      void flush();
    };
    const pageshow = (event: PageTransitionEvent) => {
      if (event.persisted) record("page-restored");
    };
    document.addEventListener("visibilitychange", visibility);
    window.addEventListener("online", online);
    window.addEventListener("offline", offline);
    window.addEventListener("pagehide", pagehide);
    window.addEventListener("pageshow", pageshow);
    return () => {
      document.removeEventListener("visibilitychange", visibility);
      window.removeEventListener("online", online);
      window.removeEventListener("offline", offline);
      window.removeEventListener("pagehide", pagehide);
      window.removeEventListener("pageshow", pageshow);
      if (timer) clearTimeout(timer);
      timer = undefined;
    };
  }
  return {
    record,
    failure: (
      event: string,
      error: unknown,
      attempt?: number,
      delay?: number,
    ) => record(event, connectionFailure(error, attempt, delay)),
    initialize,
    flush,
  };
}
