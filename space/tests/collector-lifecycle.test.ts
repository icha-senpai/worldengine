import { afterEach, expect, test, vi } from "vitest";
import { NativeRelayCollector } from "../scripts/bitcraft-relay-collector";
import { RelaySubscription } from "../scripts/bitcraft-relay";
import { pruneSchedule } from "../scripts/collector-scheduler";

const workers: NativeRelayCollector[] = [];
const settle = async () => {
  for (let i = 0; i < 15; i++) await Promise.resolve();
};
const watches = (ids: number[]) =>
  ids.map((id) => ({
    resource: "relaySkills",
    entityId: String(id),
    expiresAt: BigInt(Date.now() + 60000) * 1000n,
  }));
function worker(
  requestData = vi.fn(async () => ({ payload: '{"region":8}', error: "" })),
) {
  vi.spyOn(RelaySubscription.prototype, "start").mockResolvedValue();
  const conn = {
    procedures: { requestData },
    reducers: {
      relayHeartbeat: vi.fn(async () => {}),
      collectionFailure: vi.fn(async () => {}),
      ingestCollection: vi.fn(async () => {}),
    },
  };
  const native = new NativeRelayCollector(conn as any, "test", new Map());
  workers.push(native);
  return { native, conn, state: native as any };
}
afterEach(() => {
  workers.splice(0).forEach((w) => w.stop());
  vi.restoreAllMocks();
  vi.useRealTimers();
});

test("reordered active watches retain at most 30 admitted players", async () => {
  const { native, state } = worker();
  const ids = Array.from({ length: 60 }, (_, i) => i + 1);
  await native.tick(watches(ids));
  await settle();
  await native.tick(watches([...ids.slice(30), ...ids.slice(0, 30)]));
  await settle();
  expect(state.scopes.size).toBe(30);
  expect(state.admitted.size).toBe(30);
  await native.tick(watches(ids.slice(30)));
  await settle();
  expect(state.scopes.size).toBe(30);
  expect([...state.scopes.keys()]).toEqual(
    ids.slice(30).map((id) => `player:${id}`),
  );
});
test("expired discovery cannot create a socket on a late response", async () => {
  let done!: (value: any) => void;
  const { native, state } = worker(
    vi.fn(() => new Promise((resolve) => (done = resolve))) as any,
  );
  await native.tick(watches([1]));
  await settle();
  await native.tick([]);
  done({ payload: '{"region":8}', error: "" });
  await settle();
  expect(state.scopes.size).toBe(0);
  expect(state.lastDiscovery.size).toBe(0);
  expect(state.discovering.size).toBe(0);
});

test("player turnover closes old sockets before an awaited trading heartbeat", async () => {
  const { native, conn, state } = worker();
  const trading = state.add("trading:8", "bitconnect", 8, []);
  await native.tick(watches(Array.from({ length: 30 }, (_, i) => i + 1)));
  await settle();
  trading.heartbeat = 0;
  let release!: () => void;
  conn.reducers.relayHeartbeat.mockImplementationOnce(
    () => new Promise<void>((done) => (release = done)),
  );
  const tick = native.tick(
    watches(Array.from({ length: 30 }, (_, i) => i + 31)),
  );
  await settle();
  expect([...state.scopes.values()].filter((s: any) => s.playerId).length).toBe(
    30,
  );
  expect(state.scopes.has("player:1")).toBe(false);
  release();
  await tick;
});
test("hung discovery retires its cycle instead of accumulating abandoned SDK calls", async () => {
  vi.useFakeTimers();
  const request = vi.fn(() => new Promise(() => {}));
  const { native, state } = worker(request as any);
  await native.tick(watches([1]));
  await settle();
  await vi.advanceTimersByTimeAsync(15001);
  expect(state.discovering.size).toBe(0);
  expect(state.cycle.stopped).toBe(true);
  await vi.advanceTimersByTimeAsync(45000);
  await native.tick(watches([1]));
  await settle();
  expect(request).toHaveBeenCalledTimes(1);
});

test("an expired hung request still has a connection deadline", async () => {
  vi.useFakeTimers();
  const { native, state } = worker(vi.fn(() => new Promise(() => {})) as any);
  await native.tick(watches([1]));
  await settle();
  await native.tick([]);
  await settle();
  expect(state.discovering.size).toBe(0);
  await vi.advanceTimersByTimeAsync(15001);
  expect(state.cycle.stopped).toBe(true);
  expect(state.scopes.size).toBe(0);
});
test("a stopped publication does not issue failure writes or update scheduling", async () => {
  let reject!: (error: Error) => void;
  const { native, conn, state } = worker();
  conn.reducers.ingestCollection.mockImplementation(
    () => new Promise((_, fail) => (reject = fail)),
  );
  state.admitted.set("1", BigInt(Date.now() + 60000) * 1000n);
  state.lastDiscovery.set("1", Date.now() + 60000);
  const scope = state.add("player:1", "bitsync", 8, [], "1");
  scope.relay.ready = true;
  scope.dirty = true;
  for (const table of [
    "experience_state",
    "player_state",
    "player_username_state",
  ])
    scope.relay.tables.set(table, new Map([["1", { experience_stacks: [] }]]));
  const tick = native.tick(watches([1]));
  await settle();
  native.stop();
  reject(new Error("disconnected"));
  await tick;
  expect(conn.reducers.collectionFailure).not.toHaveBeenCalled();
  expect(state.due.size).toBe(0);
  expect(state.scopes.size).toBe(0);
});
test("scheduler removes historical keys and enforces its size bound", () => {
  const due = new Map([
    ["seed", 1],
    ["expired", 2],
    ["active", 3],
  ]);
  pruneSchedule(due, new Set(["seed", "active"]));
  expect([...due.keys()]).toEqual(["seed", "active"]);
  pruneSchedule(due, new Set(["seed", "active"]), 1);
  expect(due.size).toBe(1);
});
