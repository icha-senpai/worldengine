export type DiagnosticSite = "space" | "fishbound";
export const connectionEvents = new Set([
  "page-loaded",
  "page-restored",
  "page-frozen",
  "page-resumed",
  "page-hidden",
  "tab-visible",
  "tab-hidden",
  "browser-online",
  "browser-offline",
  "development-reload",
  ...["bitcraft", "evergather", "fishbound"].flatMap((channel) => [
    `${channel}-connected`,
    `${channel}-reconnected`,
    `${channel}-ready`,
    `${channel}-disconnected`,
    `${channel}-connect-failed`,
    `${channel}-readiness-timeout`,
    `${channel}-subscription-failed`,
    `${channel}-probe-open`,
    `${channel}-probe-close`,
    `${channel}-probe-error`,
    `${channel}-probe-http`,
    `${channel}-probe-sample`,
  ]),
]);
export type ConnectionEvent = {
  at: string;
  event: string;
  sequence: number;
  release?: string;
  connectionId?: string;
  probeConnectionId?: string;
  requestId?: string;
  probeCfRay?: string;
  reason?: string;
  errorType?: string;
  closeCode?: number;
  status?: number;
  retryAttempt?: number;
  retryDelayMs?: number;
  recoveryMs?: number;
  online?: boolean;
  visibility?: string;
  navigation?: string;
  discarded?: boolean;
  wasClean?: boolean;
  socketAgeMs?: number;
  lastFrameAgoMs?: number;
  receivedFrames?: number;
  probeState?: number;
  probeDurationMs?: number;
  eventLoopLagMs?: number;
};
export type ConnectionReport = {
  version: 1;
  site: DiagnosticSite;
  session: string;
  release: string;
  browser: string;
  events: ConnectionEvent[];
};
const reasons = [
  "socket_closed",
  "tab_suspended",
  "session_busy",
  "protocol_error",
  "identity_changed",
  "authentication",
  "transport_error",
  "timeout",
  "other_error",
  "closed",
];
const errors = [
  "Error",
  "TypeError",
  "DisconnectedError",
  "UnknownCallResultError",
  "IdentityChangedError",
  "WebSocketTokenError",
  "WebSocketProtocolError",
];

export function connectionFailure(
  error: unknown,
  nextAttempt?: number,
  delayMs?: number,
) {
  const value = error as
    { name?: string; message?: string; status?: number } | undefined;
  const message = typeof value?.message === "string" ? value.message : "";
  const close = /^WebSocket closed \(code (\d{4})(?:[:)]|$)/.exec(message);
  const reason = close
    ? "socket_closed"
    : message === "Session busy"
      ? "session_busy"
      : message === "WebSocket closed while suspended"
        ? "tab_suspended"
        : message === "Invalid server message" ||
            value?.name === "WebSocketProtocolError"
          ? "protocol_error"
          : value?.name === "IdentityChangedError"
            ? "identity_changed"
            : value?.name === "WebSocketTokenError"
              ? "authentication"
              : /timed out/i.test(message)
                ? "timeout"
                : message === "WebSocket error" ||
                    /failed to fetch|networkerror/i.test(message)
                  ? "transport_error"
                  : error
                    ? "other_error"
                    : "closed";
  return {
    reason,
    errorType: errors.includes(value?.name ?? "") ? value!.name : "Error",
    ...(close ? { closeCode: Number(close[1]) } : {}),
    ...(Number.isInteger(value?.status) &&
    value!.status! >= 100 &&
    value!.status! <= 599
      ? { status: value!.status }
      : {}),
    ...(Number.isFinite(nextAttempt) ? { retryAttempt: nextAttempt } : {}),
    ...(Number.isFinite(delayMs) ? { retryDelayMs: delayMs } : {}),
  };
}

// An allowlist is shared by browser storage and the receiver. Never copy arbitrary
// SDK errors, URLs, account identifiers, cookies, tokens or caller-supplied fields.
export function cleanConnectionEvent(
  value: unknown,
  now = Date.now(),
): ConnectionEvent | null {
  if (!value || typeof value !== "object") return null;
  const row = value as Record<string, unknown>;
  if (
    typeof row.event !== "string" ||
    !connectionEvents.has(row.event) ||
    typeof row.at !== "string" ||
    !Number.isFinite(Date.parse(row.at)) ||
    Date.parse(row.at) < now - 86400000 ||
    Date.parse(row.at) > now + 300000 ||
    !Number.isSafeInteger(row.sequence) ||
    Number(row.sequence) < 1
  )
    return null;
  const result: ConnectionEvent = {
    at: new Date(row.at).toISOString(),
    event: row.event,
    sequence: Number(row.sequence),
  };
  if (
    typeof row.connectionId === "string" &&
    /^[a-f0-9]{32}$/.test(row.connectionId)
  )
    result.connectionId = row.connectionId;
  for (const key of ["probeConnectionId", "requestId"] as const)
    if (typeof row[key] === "string" && /^[a-f0-9]{32}$/.test(row[key]))
      result[key] = row[key];
  if (
    typeof row.probeCfRay === "string" &&
    /^[a-f0-9]{8,32}-[A-Z]{3}$/.test(row.probeCfRay)
  )
    result.probeCfRay = row.probeCfRay;
  if (
    typeof row.release === "string" &&
    /^(?:[a-f0-9]{7,40}|development|unknown)$/.test(row.release)
  )
    result.release = row.release;
  for (const [key, allowed] of [
    ["reason", reasons],
    ["errorType", errors],
    ["visibility", ["visible", "hidden", "unknown"]],
    [
      "navigation",
      ["navigate", "reload", "back_forward", "prerender", "unknown"],
    ],
  ] as const)
    if (typeof row[key] === "string" && allowed.includes(row[key] as never))
      (result as any)[key] = row[key];
  for (const [key, low, high] of [
    ["closeCode", 1000, 4999],
    ["status", 100, 599],
    ["retryAttempt", 0, 100000],
    ["retryDelayMs", 0, 86400000],
    ["recoveryMs", 0, 86400000],
    ["socketAgeMs", 0, 7 * 86400000],
    ["lastFrameAgoMs", 0, 7 * 86400000],
    ["receivedFrames", 0, 1000000000],
    ["probeState", 0, 3],
    ["probeDurationMs", 0, 86400000],
    ["eventLoopLagMs", 0, 86400000],
  ] as const)
    if (
      typeof row[key] === "number" &&
      Number.isFinite(row[key]) &&
      row[key] >= low &&
      row[key] <= high
    )
      (result as any)[key] = Math.round(row[key]);
  if (typeof row.online === "boolean") result.online = row.online;
  if (typeof row.discarded === "boolean") result.discarded = row.discarded;
  if (typeof row.wasClean === "boolean") result.wasClean = row.wasClean;
  return result;
}

export function cleanConnectionReport(
  value: unknown,
  now = Date.now(),
): ConnectionReport | null {
  if (!value || typeof value !== "object") return null;
  const row = value as Record<string, unknown>;
  if (
    row.version !== 1 ||
    !["space", "fishbound"].includes(String(row.site)) ||
    typeof row.session !== "string" ||
    !/^[0-9a-f]{8}-[0-9a-f-]{27}$/.test(row.session) ||
    !Array.isArray(row.events) ||
    row.events.length < 1 ||
    row.events.length > 10
  )
    return null;
  const events = row.events.map((event) => cleanConnectionEvent(event, now));
  if (events.some((event) => !event)) return null;
  const site = row.site as DiagnosticSite;
  if (
    events.some(
      (event) =>
        event!.event.startsWith(
          site === "space" ? "fishbound-" : "bitcraft-",
        ) ||
        (site === "fishbound" && event!.event.startsWith("evergather-")),
    )
  )
    return null;
  return {
    version: 1,
    site,
    session: row.session,
    release:
      typeof row.release === "string" &&
      /^(?:[a-f0-9]{7,40}|development|unknown)$/.test(row.release)
        ? row.release
        : "unknown",
    browser: ["edge", "chrome", "firefox", "safari", "other"].includes(
      String(row.browser),
    )
      ? String(row.browser)
      : "other",
    events: events as ConnectionEvent[],
  };
}
