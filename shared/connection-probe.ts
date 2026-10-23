// Independent native socket: does not wrap the SDK, send tokens, or subscribe to data.
type RecordEvent = (event: string, details?: Record<string, unknown>) => void;
export function startConnectionProbe(
  site: "space" | "fishbound",
  record: RecordEvent,
  channel: "bitcraft" | "evergather" | "fishbound" = site === "space"
    ? "bitcraft"
    : "fishbound",
) {
  if (
    typeof window === "undefined" ||
    !["https://bitcraft.ichaa.dev", "https://fishbound.ichaa.dev"].includes(
      window.location.origin,
    )
  )
    return { stop() {}, check() {} };
  const database =
    channel === "evergather"
      ? "space-evergather"
      : channel === "bitcraft"
        ? "space-bitcraft-tools"
        : "fishbound-dev-local";
  let stopped = false,
    deadline = 0,
    socket: WebSocket | undefined;
  let retry: ReturnType<typeof setTimeout> | undefined,
    sample: ReturnType<typeof setInterval> | undefined;
  let connecting: ReturnType<typeof setTimeout> | undefined;
  let expiry: ReturnType<typeof setTimeout> | undefined,
    lastHttp = 0,
    opened = 0,
    frames = 0,
    lastFrame = 0;
  let probeConnectionId = "",
    retryCount = 0;
  const abort = new AbortController();
  const emit = (event: string, extra: Record<string, unknown> = {}) =>
    record(`${channel}-probe-${event}`, {
      probeConnectionId,
      socketAgeMs: opened ? Date.now() - opened : 0,
      lastFrameAgoMs: lastFrame ? Date.now() - lastFrame : undefined,
      receivedFrames: frames,
      ...extra,
    });
  async function http(initial = false) {
    if (
      stopped ||
      (!initial &&
        (!deadline || Date.now() >= deadline || Date.now() - lastHttp < 30000))
    )
      return;
    lastHttp = Date.now();
    const started = Date.now();
    try {
      const response = await fetch("/_connection-health", {
        cache: "no-store",
        credentials: "omit",
        signal: AbortSignal.any([abort.signal, AbortSignal.timeout(5000)]),
      });
      if (stopped) return;
      const until = Date.parse(
        response.headers.get("X-Dataverse-Capture-Until") ?? "",
      );
      if (initial) {
        if (
          response.status !== 204 ||
          !Number.isFinite(until) ||
          until <= Date.now() ||
          until > Date.now() + 7 * 86400000
        )
          return;
        deadline = until;
        open();
        sample = setInterval(
          () => emit("sample", { probeState: socket?.readyState }),
          60000,
        );
        expiry = setTimeout(stop, deadline - Date.now());
      }
      emit("http", {
        status: response.status,
        probeDurationMs: Date.now() - started,
        requestId: response.headers.get("X-Dataverse-Request"),
        probeCfRay: response.headers.get("CF-Ray"),
      });
    } catch {
      if (!stopped && !initial)
        emit("http", {
          reason: "transport_error",
          probeDurationMs: Date.now() - started,
        });
    }
  }
  function open() {
    if (stopped || Date.now() >= deadline) return;
    probeConnectionId = crypto.randomUUID().replace(/-/g, "");
    opened = 0;
    frames = 0;
    lastFrame = 0;
    const target = new URL(
      `/v1/database/${database}/subscribe`,
      window.location.origin,
    );
    target.protocol = "wss:";
    target.searchParams.set("compression", "None");
    target.searchParams.set("connection_id", probeConnectionId);
    try {
      const current = (socket = new WebSocket(target, "v2.bsatn.spacetimedb"));
      current.binaryType = "arraybuffer";
      connecting = setTimeout(() => {
        if (
          socket !== current ||
          stopped ||
          current.readyState !== WebSocket.CONNECTING
        )
          return;
        emit("error", { reason: "timeout", probeState: current.readyState });
        current.close();
      }, 15000);
      current.addEventListener("open", () => {
        if (socket !== current || stopped) return;
        clearTimeout(connecting);
        opened = Date.now();
        retryCount = 0;
        emit("open");
      });
      current.addEventListener("message", () => {
        if (socket !== current || stopped) return;
        frames++;
        lastFrame = Date.now();
      });
      current.addEventListener("error", () => {
        if (socket !== current || stopped) return;
        emit("error", {
          reason: "transport_error",
          probeState: current.readyState,
        });
        void http();
      });
      current.addEventListener("close", (event) => {
        if (socket !== current || stopped) return;
        clearTimeout(connecting);
        emit("close", {
          closeCode: event.code,
          wasClean: event.wasClean,
          probeState: current.readyState,
        });
        void http();
        retry = setTimeout(open, Math.min(5000 * 2 ** retryCount++, 60000));
      });
    } catch {
      emit("error", { reason: "transport_error" });
      retry = setTimeout(open, Math.min(5000 * 2 ** retryCount++, 60000));
    }
  }
  function stop() {
    if (stopped) return;
    stopped = true;
    abort.abort();
    clearTimeout(retry);
    clearTimeout(expiry);
    clearTimeout(connecting);
    clearInterval(sample);
    socket?.close(1000);
  }
  // One bootstrap request, then event-triggered checks during a server-authorized capture.
  void http(true);
  return {
    stop,
    check: () => {
      void http();
    },
  };
}
