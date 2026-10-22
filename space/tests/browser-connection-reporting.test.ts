import { afterEach, expect, it, vi } from "vitest";
import { createConnectionDiagnostics } from "../../shared/connection-diagnostics";
import { cleanConnectionReport } from "../../shared/connection-events";
import { createConnectionEventReceiver } from "../../fishing-game/apps/web/src/lib/server/connection-events";

afterEach(() => {
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});
const session = "12345678-1234-1234-1234-123456789abc";
const event = (sequence = 1) => ({
  sequence,
  at: new Date().toISOString(),
  event: "bitcraft-disconnected",
  closeCode: 1006,
  online: true,
});
const report = (events = [event()]) => ({
  version: 1,
  site: "space",
  session,
  release: "2f13318b",
  browser: "edge",
  events,
});
const request = (
  input: unknown = report(),
  origin = "https://bitcraft.ichaa.dev",
  method = "POST",
) =>
  new Request("https://fishbound.ichaa.dev/api/connection-events", {
    method,
    headers: {
      Origin: origin,
      "Content-Type": "application/json",
      "CF-Ray": "123456789abcdef0-ORD",
    },
    ...(method === "POST" ? { body: JSON.stringify(input) } : {}),
  });
const health = async () => ({
  maintenance: false,
  space: "ok",
  fishbound: "unreachable",
});

it("strips private fields and only accepts recent, known connection events", () => {
  const result = cleanConnectionReport({
    ...report(),
    token: "private-token",
    url: "https://secret.invalid",
    events: [
      {
        ...event(),
        message: "private-player-id",
        cookies: "secret",
        identity: "secret",
        connectionId: "a".repeat(64),
      },
    ],
  });
  expect(result).not.toBeNull();
  expect(result!.events[0]).not.toHaveProperty("connectionId");
  expect(
    cleanConnectionReport(
      report([{ ...event(), connectionId: "b".repeat(32) } as any]),
    )!.events[0]!.connectionId,
  ).toBe("b".repeat(32));
  expect(JSON.stringify(result)).not.toMatch(/private|secret|cookies|identity/);
  expect(
    cleanConnectionReport(report([{ ...event(), at: "2000-01-01T00:00:00Z" }])),
  ).toBeNull();
  expect(
    cleanConnectionReport(report([{ ...event(), event: "arbitrary-secret" }])),
  ).toBeNull();
});

it("receives cross-site Space diagnostics with bounded context and no account data", async () => {
  const rows: Record<string, unknown>[] = [];
  const receive = createConnectionEventReceiver({
    health,
    write: (row) => rows.push(row),
  });
  const preflight = await receive(request(undefined, undefined, "OPTIONS"));
  expect(preflight.status).toBe(204);
  expect(preflight.headers.get("access-control-allow-origin")).toBe(
    "https://bitcraft.ichaa.dev",
  );
  expect(preflight.headers.has("access-control-allow-credentials")).toBe(false);
  expect((await receive(request())).status).toBe(204);
  expect(rows).toHaveLength(1);
  expect(rows[0]).toMatchObject({
    type: "browser-connection",
    site: "space",
    closeCode: 1006,
    cfRay: "123456789abcdef0-ORD",
    health: { fishbound: "unreachable" },
  });
  expect(rows[0]!.serverAt).toBeTruthy();
  expect(rows[0]).not.toHaveProperty("ip");
});

it("rejects untrusted origins, site mismatches, oversized bodies and large batches", async () => {
  const write = vi.fn();
  const receive = createConnectionEventReceiver({ health, write });
  expect(
    (await receive(request(report(), "https://untrusted.invalid"))).status,
  ).toBe(403);
  expect(
    (await receive(request(report(), "https://fishbound.ichaa.dev"))).status,
  ).toBe(400);
  expect((await receive(request({ padding: "x".repeat(17000) }))).status).toBe(
    413,
  );
  expect(
    (
      await receive(
        request(
          report(Array.from({ length: 11 }, (_, index) => event(index + 1))),
        ),
      )
    ).status,
  ).toBe(400);
  expect(write).not.toHaveBeenCalled();
});

it("acknowledges retried batches without duplicating log entries and caps report rates", async () => {
  const write = vi.fn();
  const receive = createConnectionEventReceiver({ health, write });
  for (let index = 0; index < 12; index++)
    expect((await receive(request())).status).toBe(204);
  expect(write).toHaveBeenCalledTimes(1);
  expect((await receive(request())).status).toBe(429);
});

it("caps the total report rate even when callers keep changing session IDs", async () => {
  const receive = createConnectionEventReceiver({ health, write: () => {} });
  for (let index = 0; index < 120; index++) {
    const input = {
      ...report(),
      session: `12345678-1234-1234-1234-${index.toString(16).padStart(12, "0")}`,
    };
    expect((await receive(request(input))).status).toBe(204);
  }
  expect((await receive(request())).status).toBe(429);
});

function browser() {
  vi.useFakeTimers();
  const storage = new Map<string, string>();
  vi.stubGlobal("sessionStorage", {
    getItem: (key: string) => storage.get(key) ?? null,
    setItem: (key: string, value: string) => storage.set(key, value),
  });
  vi.stubGlobal("window", {
    location: { origin: "https://bitcraft.ichaa.dev" },
  });
  vi.stubGlobal("navigator", { onLine: true, userAgent: "Edg/100" });
  vi.stubGlobal("document", { hidden: false });
  return storage;
}

it("retains failed uploads across reloads, then sends without credentials or raw errors", async () => {
  const storage = browser();
  const fetch = vi
    .fn()
    .mockRejectedValueOnce(new Error("offline"))
    .mockResolvedValue({ ok: true });
  vi.stubGlobal("fetch", fetch);
  const first = createConnectionDiagnostics("space", "2f13318b", "test.trail");
  first.failure(
    "bitcraft-disconnected",
    new Error("WebSocket closed (code 1006: token=private-secret)"),
    1,
    1000,
  );
  await first.flush();
  expect(JSON.parse(storage.get("test.trail.pending")!)).toHaveLength(1);
  const restored = createConnectionDiagnostics(
    "space",
    "1234567a",
    "test.trail",
  );
  await restored.flush();
  expect(JSON.parse(storage.get("test.trail.pending")!)).toEqual([]);
  const [url, options] = fetch.mock.calls[1]!;
  expect(url).toBe("https://fishbound.ichaa.dev/api/connection-events");
  expect(options.credentials).toBe("omit");
  expect(options.body).not.toContain("secret");
  expect(JSON.parse(options.body).events[0]).toMatchObject({
    closeCode: 1006,
    online: true,
    visibility: "visible",
  });
  expect(JSON.parse(options.body).events[0].release).toBe("2f13318b");
});

it("bounds queues and local history, records actual ready time, and expires undeliverable reports", async () => {
  const storage = browser();
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => ({ ok: true })),
  );
  const diagnostics = createConnectionDiagnostics(
    "space",
    "2f13318b",
    "test.trail",
  );
  diagnostics.failure("bitcraft-disconnected", new Error("WebSocket error"));
  await vi.advanceTimersByTimeAsync(250);
  diagnostics.record("bitcraft-ready");
  expect(JSON.parse(storage.get("test.trail")!).at(-1).recoveryMs).toBe(250);
  for (let index = 0; index < 250; index++) diagnostics.record("tab-visible");
  expect(JSON.parse(storage.get("test.trail")!)).toHaveLength(200);
  expect(JSON.parse(storage.get("test.trail.pending")!)).toHaveLength(100);
  vi.setSystemTime(Date.now() + 2 * 86400000);
  await diagnostics.flush();
  expect(JSON.parse(storage.get("test.trail.pending")!)).toEqual([]);
});
