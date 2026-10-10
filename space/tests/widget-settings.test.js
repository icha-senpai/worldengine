import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  bitcraftWidgetThemes,
  normalizeWidgetTheme,
  widgetThemePayload,
  widgetThemeStyle,
} from "../src/bitcraft-ui/widgetTheme";

const fake = vi.hoisted(() => ({
  save: vi.fn(),
  read: vi.fn(),
  page: { error: "" },
}));
vi.mock("../src/bitcraft", () => ({
  connectBitcraft: async () => ({
    reducers: { saveWidget: fake.save },
    procedures: { readWidget: fake.read },
  }),
}));
vi.mock("../src/bitcraft-ui/navigation", () => ({ pageState: fake.page }));

const token = "widget-test-profile-123456789";
const key = `space.bitcraft.widgetDraft:tasks:${token}`;
let storage, widgets;
beforeEach(async () => {
  vi.resetModules();
  vi.useFakeTimers();
  storage = new Map();
  vi.stubGlobal("localStorage", {
    getItem: (key) => storage.get(key) ?? null,
    setItem: (key, value) => storage.set(key, value),
    removeItem: (key) => storage.delete(key),
  });
  vi.stubGlobal("location", new URL("https://space.ichaa.dev"));
  fake.page.error = "";
  fake.save.mockReset().mockResolvedValue(undefined);
  fake.read.mockReset().mockResolvedValue(
    JSON.stringify({
      settings: { title: "Committed", width: 450 },
      editable: true,
    }),
  );
  widgets = await import("../src/bitcraft-ui/widgets");
});
afterEach(() => {
  vi.clearAllTimers();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe("widget refresh persistence", () => {
  it("resumes a draft's background save after a page refresh", async () => {
    storage.set(key, JSON.stringify({ title: "Survived refresh" }));
    expect(await widgets.readWidget("tasks", token)).toMatchObject({
      title: "Survived refresh",
    });
    await vi.advanceTimersByTimeAsync(300);
    expect(JSON.parse(fake.save.mock.calls[0][0].settings).title).toBe(
      "Survived refresh",
    );
    expect(storage.has(key)).toBe(false);
  });
  it("restores pending edits before a background save completes, without mixing profiles", async () => {
    widgets.persistWidgetDraft("tasks", { title: "Latest", width: 620 }, token);
    expect(await widgets.readWidget("tasks", token)).toMatchObject({
      title: "Latest",
      width: 620,
    });
    expect(await widgets.readWidget("tasks", "another-profile")).toMatchObject({
      title: "Committed",
    });
    expect(
      await widgets.readWidget("tasks", token, { draft: false }),
    ).toMatchObject({ title: "Committed" });
    await vi.advanceTimersByTimeAsync(300);
    expect(JSON.parse(fake.save.mock.calls[0][0].settings)).toEqual({
      title: "Latest",
      width: 620,
    });
    expect(storage.has(key)).toBe(false);
  });

  it("never applies an owner's local draft to a read-only shared widget", async () => {
    storage.set(key, JSON.stringify({ title: "Wrong local draft" }));
    fake.read.mockResolvedValue(
      JSON.stringify({ settings: { title: "Shared" }, editable: false }),
    );
    expect(await widgets.readWidget("tasks", token)).toEqual({
      title: "Shared",
      widgetEditable: false,
    });
  });

  it("keeps failed saves recoverable and clears the error after a successful retry", async () => {
    fake.save.mockRejectedValueOnce(Error("offline"));
    widgets.persistWidgetDraft(
      "tasks",
      { title: "Pending offline edit" },
      token,
    );
    await vi.advanceTimersByTimeAsync(300);
    expect(storage.has(key)).toBe(true);
    expect(fake.page.error).toContain("could not be saved");
    expect(await widgets.readWidget("tasks", token)).toMatchObject({
      title: "Pending offline edit",
    });
    await widgets.saveWidget("tasks", { title: "Pending offline edit" }, token);
    expect(storage.has(key)).toBe(false);
    expect(fake.page.error).toBe("");
  });

  it("serializes saves and preserves newer edits while an older write is in flight", async () => {
    let finish;
    fake.save.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    widgets.persistWidgetDraft("tasks", { title: "First" }, token);
    const first = widgets.saveWidget("tasks", { title: "First" }, token);
    await vi.advanceTimersByTimeAsync(0);
    widgets.persistWidgetDraft("tasks", { title: "Newest" }, token);
    const second = widgets.saveWidget("tasks", { title: "Newest" }, token);
    await vi.advanceTimersByTimeAsync(0);
    expect(fake.save).toHaveBeenCalledTimes(1);
    finish();
    await Promise.all([first, second]);
    expect(
      fake.save.mock.calls.map(([args]) => JSON.parse(args.settings).title),
    ).toEqual(["First", "Newest"]);
    expect(storage.has(key)).toBe(false);
  });

  it("does not clear a newer draft when an older save finishes", async () => {
    let finish;
    fake.save.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    widgets.persistWidgetDraft("tasks", { title: "First" }, token);
    const first = widgets.saveWidget("tasks", { title: "First" }, token);
    await vi.advanceTimersByTimeAsync(0);
    widgets.persistWidgetDraft("tasks", { title: "Newest" }, token);
    finish();
    await first;
    expect(JSON.parse(storage.get(key)).title).toBe("Newest");
    await vi.advanceTimersByTimeAsync(300);
    expect(JSON.parse(fake.save.mock.calls.at(-1)[0].settings).title).toBe(
      "Newest",
    );
    expect(storage.has(key)).toBe(false);
  });

  it("preserves cleared fields, false toggles, and zero values when creating a widget link", async () => {
    const link = await widgets.widgetUrl(
      "/bitcraft/tasks/widget?icons=old&radius=18",
      {
        token,
        settings: {
          icons: "",
          showGoals: false,
          radius: 0,
          user: "private",
          profile: token,
          widgetEditable: true,
        },
      },
    );
    const saved = JSON.parse(fake.save.mock.calls[0][0].settings);
    expect(saved).toEqual({ icons: "", showGoals: false, radius: 0 });
    expect(new URL(link).searchParams.get("profile")).toBe(token);
  });
});

describe("widget theme round trips", () => {
  it.each(bitcraftWidgetThemes.map((theme) => theme.key))(
    "keeps custom colors for %s",
    (theme) => {
      const settings = {
        theme,
        accentColor: "#ff2bd6",
        panelColor: "#123456",
        radius: 0,
        width: 620,
      };
      const saved = widgetThemePayload(settings);
      expect(
        normalizeWidgetTheme(JSON.parse(JSON.stringify(saved))),
      ).toMatchObject(settings);
      expect(widgetThemeStyle(saved)["--tracker-accent"]).toBe("#ff2bd6");
    },
  );
  it("falls back to preset colors for malformed values", () => {
    expect(
      normalizeWidgetTheme({ theme: "dataverse", accentColor: "invalid" })
        .accentColor,
    ).toBe(bitcraftWidgetThemes[0].accentColor);
  });
});
