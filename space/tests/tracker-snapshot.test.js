import { afterEach, describe, expect, it } from "vitest";
import { effectScope, ref } from "vue";
import { useTrackerSnapshot } from "../src/bitcraft-ui/useTrackerSnapshot";
import { passiveDeadlines } from "../src/bitcraft-ui/passiveCountdown";

let scope;
afterEach(() => scope?.stop());
const snapshot = {
  tracker: {
    player: { entityId: "1" },
    items: [{ quantity: 40 }],
    groups: [{ key: "craft", estimatedRemainingSeconds: 120 }],
  },
  options: [{ key: "item:1" }],
  sampledAt: "2026-10-16T18:00:00Z",
  refresh: { updatedAt: "2026-10-16T17:59:59Z" },
};
function setup() {
  scope = effectScope();
  const connected = ref(true);
  return {
    connected,
    ...scope.run(() => useTrackerSnapshot(snapshot, connected)),
  };
}
describe("Inventory and Passive Crafts interrupted snapshots", () => {
  it("retains quantities, craft deadlines, picker options and observation time on a failed page snapshot", () => {
    const state = setup();
    const deadline = passiveDeadlines(
      state.tracker.value.groups,
      new Map(),
      1000,
    );
    state.applySnapshot({
      tracker: null,
      options: [],
      error: "Unavailable",
      sampledAt: "2026-10-16T18:01:00Z",
      refresh: { updatedAt: "2026-10-16T18:01:00Z" },
    });
    expect(state.tracker.value.items[0].quantity).toBe(40);
    expect(
      passiveDeadlines(state.tracker.value.groups, deadline, 60000),
    ).toEqual(deadline);
    expect(state.options.value).toEqual(snapshot.options);
    expect(state.sampledAt.value).toBe(snapshot.sampledAt);
    expect(state.refreshStatus.value).toMatchObject({
      delayed: true,
      updatedAt: snapshot.refresh.updatedAt,
    });
    expect(state.error.value).toBe("Unavailable");
  });
  it("stays marked stale after socket recovery until a successful snapshot arrives", () => {
    const state = setup();
    state.connected.value = false;
    expect(state.refreshStatus.value.delayed).toBe(true);
    state.connected.value = true;
    expect(state.refreshStatus.value.delayed).toBe(true);
    state.applySnapshot({
      ...snapshot,
      tracker: { player: { entityId: "1" }, items: [], groups: [] },
      error: null,
    });
    expect(state.refreshStatus.value.delayed).toBe(false);
    expect(state.tracker.value.items).toEqual([]);
    expect(state.tracker.value.groups).toEqual([]);
    expect(state.error.value).toBeNull();
  });
  it("marks transport/rate-limit failures delayed without changing the successful data", () => {
    const state = setup();
    state.markDelayed("Refresh paused");
    expect(state.tracker.value).toEqual(snapshot.tracker);
    expect(state.sampledAt.value).toBe(snapshot.sampledAt);
    expect(state.refreshStatus.value.delayed).toBe(true);
    expect(state.error.value).toBe("Refresh paused");
  });
  it("accepts a different player and a genuinely empty successful result", () => {
    const state = setup();
    state.applySnapshot({
      tracker: { player: { entityId: "2" }, groups: [], items: [] },
      options: [],
      sampledAt: "new",
      refresh: {},
    });
    expect(state.tracker.value.player.entityId).toBe("2");
    expect(state.options.value).toEqual([]);
    expect(state.sampledAt.value).toBe("new");
    expect(state.refreshStatus.value.delayed).toBe(false);
  });
});
