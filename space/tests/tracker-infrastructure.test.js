import { describe, expect, it, vi } from "vitest";
import { createTrackerSetup } from "../src/bitcraft-ui/trackerSetup";
import { createTrackerRefresh } from "../src/bitcraft-ui/trackerRefresh";
vi.mock("../src/bitcraft-ui/api", () => ({ bitcraftFetch: vi.fn() }));
describe("shared tracker infrastructure", () => {
  it("restores legacy setup while preserving profile edit permissions", () => {
    const store = { getItem: vi.fn(() => null), setItem: vi.fn() };
    const filters = { setup: true, profile: "owned", widgetEditable: false };
    const draft = vi.fn();
    const setup = createTrackerSetup({
      key: "settings",
      normalize: (x) => x,
      form: { title: "Saved" },
      filters: () => filters,
      payload: () => ({ title: "Saved" }),
      kind: "tasks",
      saveDraft: draft,
      legacy: () => ({ title: "Legacy" }),
      storage: () => store,
    });
    expect(setup.loadSetup()).toEqual({ title: "Legacy" });
    setup.enable();
    setup.saveSetup();
    expect(store.setItem).not.toHaveBeenCalled();
    filters.widgetEditable = true;
    setup.saveSetup();
    expect(draft).toHaveBeenCalledWith("tasks", { title: "Saved" }, "owned");
    expect(store.setItem).toHaveBeenCalledTimes(1);
  });
  it("ignores late snapshot responses after disposal and respects rate limits", async () => {
    const apply = vi.fn(),
      delayed = vi.fn();
    let finish;
    const reader = createTrackerRefresh({
      url: () => "/snapshot",
      apply,
      delayed,
      fetch: () =>
        new Promise((r) => {
          finish = r;
        }),
    });
    const pending = reader.refresh();
    reader.stop();
    finish(Response.json({ tracker: { id: 1 } }));
    await pending;
    expect(apply).not.toHaveBeenCalled();
    expect(delayed).not.toHaveBeenCalled();
    const throttled = createTrackerRefresh({
      url: () => "/snapshot",
      apply,
      delayed,
      fetch: async () =>
        new Response(null, { status: 429, headers: { "Retry-After": "12" } }),
    });
    expect(await throttled.refresh()).toBe(12);
    expect(delayed).toHaveBeenCalled();
  });
  it("rejects an old character response when the snapshot scope changes before another refresh", async () => {
    let url = "/snapshot?character=first";
    let finish;
    const apply = vi.fn();
    const reader = createTrackerRefresh({
      url: () => url,
      apply,
      delayed: vi.fn(),
      fetch: () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    });
    const pending = reader.refresh();
    url = "/snapshot?character=second";
    finish(Response.json({ tracker: { id: "first" } }));
    await pending;
    expect(apply).not.toHaveBeenCalled();
    reader.stop();
  });
});
