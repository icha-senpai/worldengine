import { afterEach, beforeEach, describe, it, expect, vi } from "vitest";
import { subscribeMap } from "../src/bitcraft-ui/fishing-map-relay";
const calls = vi.hoisted(() => ({ read: vi.fn() }));
vi.mock("../src/bitcraft", () => ({
  connectBitcraft: async () => ({
    procedures: { readFishLocations: calls.read },
  }),
}));
beforeEach(() => {
  vi.useFakeTimers();
  vi.resetAllMocks();
});
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});
const school = {
  entityId: "1297036692719788627",
  resourceId: 1110002,
  x: 100,
  z: 200,
};
const snapshot = (data, updatedAt = "2026-11-15T00:00:00Z", delayed = false) =>
  JSON.stringify({
    data,
    updatedAt,
    delayed,
    error: delayed ? "Refresh delayed" : null,
  });
describe("Collected fishing map", () => {
  it("reads the local collector without a relay socket or HTTP request", async () => {
    const fetch = vi.fn(),
      socket = vi.fn();
    vi.stubGlobal("fetch", fetch);
    vi.stubGlobal("WebSocket", socket);
    calls.read.mockResolvedValue(snapshot([school]));
    const rows = vi.fn(),
      status = vi.fn();
    const stop = subscribeMap(12, { rows, status });
    await vi.advanceTimersByTimeAsync(0);
    expect(calls.read).toHaveBeenCalledWith({ regionId: 12 });
    expect(rows).toHaveBeenCalledWith(
      [
        {
          table: "resource_state",
          action: "inserts",
          row: { entity_id: school.entityId, resource_id: 1110002 },
        },
        {
          table: "location_state",
          action: "inserts",
          row: { entity_id: school.entityId, x: 100, z: 200, dimension: 1 },
        },
      ],
      true,
      "2026-11-15T00:00:00Z",
    );
    expect(status).toHaveBeenLastCalledWith("live", undefined);
    expect(fetch).not.toHaveBeenCalled();
    expect(socket).not.toHaveBeenCalled();
    stop();
    await vi.advanceTimersByTimeAsync(10000);
    expect(calls.read).toHaveBeenCalledTimes(1);
  });
  it("replaces changed snapshots, retains markers during failures, and recovers", async () => {
    calls.read.mockResolvedValue(snapshot([school]));
    const rows = vi.fn(),
      status = vi.fn();
    const stop = subscribeMap(7, { rows, status });
    await vi.advanceTimersByTimeAsync(0);
    await vi.advanceTimersByTimeAsync(5000);
    expect(rows).toHaveBeenCalledTimes(1);
    calls.read.mockResolvedValueOnce(snapshot(null, null, true));
    await vi.advanceTimersByTimeAsync(5000);
    expect(rows).toHaveBeenCalledTimes(1);
    expect(status).toHaveBeenLastCalledWith("reconnecting", "Refresh delayed");
    calls.read.mockRejectedValueOnce(Error("Disconnected"));
    await vi.advanceTimersByTimeAsync(5000);
    expect(rows).toHaveBeenCalledTimes(1);
    calls.read.mockResolvedValue(snapshot([], "2026-11-15T00:01:00Z"));
    await vi.advanceTimersByTimeAsync(5000);
    expect(rows).toHaveBeenLastCalledWith([], true, "2026-11-15T00:01:00Z");
    expect(status).toHaveBeenLastCalledWith("live", undefined);
    stop();
  });
  it("ignores an unfinished read after changing region or unmounting", async () => {
    let resolve;
    calls.read.mockReturnValue(new Promise((r) => (resolve = r)));
    const rows = vi.fn(),
      status = vi.fn();
    const stop = subscribeMap(19, { rows, status });
    await vi.advanceTimersByTimeAsync(0);
    stop();
    resolve(snapshot([school]));
    await vi.advanceTimersByTimeAsync(10000);
    expect(rows).not.toHaveBeenCalled();
    expect(status).toHaveBeenCalledTimes(1);
    expect(calls.read).toHaveBeenCalledTimes(1);
  });
});
