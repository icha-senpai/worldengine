import { afterEach, expect, it, vi } from "vitest";
import { startConnectionProbe } from "../../shared/connection-probe";

class Socket extends EventTarget {
  static CONNECTING = 0;
  static instances: Socket[] = [];
  readyState = 0;
  binaryType = "";
  constructor(
    public url: URL,
    public protocol: string,
  ) {
    super();
    Socket.instances.push(this);
  }
  close(code = 1006) {
    this.readyState = 3;
    this.dispatchEvent(
      Object.assign(new Event("close"), { code, wasClean: code === 1000 }),
    );
  }
}
let stop: (() => void) | undefined;
afterEach(() => {
  stop?.();
  stop = undefined;
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
  Socket.instances = [];
});
function setup(enabled = true) {
  vi.useFakeTimers();
  vi.stubGlobal("window", {
    location: { origin: "https://bitcraft.ichaa.dev" },
  });
  vi.stubGlobal("WebSocket", Socket);
  const fetch = vi.fn(
    async () =>
      new Response(null, {
        status: enabled ? 204 : 404,
        headers: enabled
          ? {
              "X-Dataverse-Capture-Until": new Date(
                Date.now() + 120000,
              ).toISOString(),
              "X-Dataverse-Request": "a".repeat(32),
              "CF-Ray": "123456789abcdef0-ORD",
            }
          : {},
      }),
  );
  vi.stubGlobal("fetch", fetch);
  const record = vi.fn();
  const probe = startConnectionProbe("space", record);
  stop = probe.stop;
  return { fetch, record, probe };
}
it("requires explicit server capture authorization before opening a socket", async () => {
  const { probe, fetch, record } = setup(false);
  await vi.advanceTimersByTimeAsync(0);
  probe.check();
  expect(Socket.instances).toHaveLength(0);
  expect(fetch).toHaveBeenCalledTimes(1);
  expect(record).not.toHaveBeenCalled();
});
it("records a raw socket failure and a rate-limited HTTP check without auth or payload data", async () => {
  const { probe, fetch, record } = setup();
  await vi.advanceTimersByTimeAsync(0);
  const socket = Socket.instances[0]!;
  expect([...socket.url.searchParams.keys()]).toEqual([
    "compression",
    "connection_id",
  ]);
  expect(socket.protocol).toBe("v2.bsatn.spacetimedb");
  socket.readyState = 1;
  socket.dispatchEvent(new Event("open"));
  socket.dispatchEvent(
    Object.assign(new Event("message"), { data: "secret-token-payload" }),
  );
  await vi.advanceTimersByTimeAsync(31000);
  socket.close();
  probe.check();
  probe.check();
  await vi.advanceTimersByTimeAsync(0);
  expect(fetch).toHaveBeenCalledTimes(2);
  expect(fetch.mock.calls[1]![1]).toMatchObject({
    credentials: "omit",
    cache: "no-store",
  });
  expect(record.mock.calls).toContainEqual([
    "bitcraft-probe-close",
    expect.objectContaining({
      closeCode: 1006,
      wasClean: false,
      receivedFrames: 1,
    }),
  ]);
  expect(JSON.stringify(record.mock.calls)).not.toContain(
    "secret-token-payload",
  );
  await vi.advanceTimersByTimeAsync(5000);
  expect(Socket.instances).toHaveLength(2);
  stop!();
  await vi.advanceTimersByTimeAsync(180000);
  expect(Socket.instances).toHaveLength(2);
});
it("expires the capture and closes a connection that never finishes its handshake", async () => {
  const { record } = setup();
  await vi.advanceTimersByTimeAsync(15000);
  expect(record.mock.calls).toContainEqual([
    "bitcraft-probe-error",
    expect.objectContaining({ reason: "timeout" }),
  ]);
  await vi.advanceTimersByTimeAsync(120000);
  const count = Socket.instances.length;
  await vi.advanceTimersByTimeAsync(120000);
  expect(Socket.instances).toHaveLength(count);
  expect(vi.getTimerCount()).toBe(0);
});
