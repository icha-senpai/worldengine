import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { experienceLevels } from "../src/bitcraft-ui/experienceLevels";
import { catalogResponse } from "../bitcraft/src/catalog";
import { levelProgress } from "../src/bitcraft-ui/trackers";
const calls = vi.hoisted(() => ({
  live: vi.fn(),
  catalog: vi.fn(),
  readEmpireDirectory: vi.fn(),
  readOpenCrafts: vi.fn(),
}));
vi.mock("../src/bitcraft-ui/data/client", () => ({
  ...calls,
  mapLimited: async (rows, fn) => Promise.all(rows.map(fn)),
}));
vi.mock("../src/bitcraft", () => ({
  connectBitcraft: async () => ({
    procedures: {
      collectionHistory: async () => "{}",
      readOpenCrafts: calls.readOpenCrafts,
      readEmpireDirectory: calls.readEmpireDirectory,
    },
  }),
}));
beforeEach(() => {
  vi.stubEnv("VITE_BITCRAFT_DATABASE", "space-bitcraft-release");
  vi.resetModules();
  vi.clearAllMocks();
});
afterEach(() => vi.unstubAllEnvs());
describe("release-world tracker dependencies", () => {
  it("accepts bounded recipe batches used by world craft discovery", () => {
    const ids = Array(128).fill("105009").join(",");
    expect(ids.length).toBeGreaterThan(80);
    expect(catalogResponse("craft-recipes", ids, "")[105009]).toMatchObject({
      actionsRequired: 100,
      experiencePerProgress: [{ skill_id: 6, quantity: 1.6 }],
    });
    expect(() =>
      catalogResponse("craft-recipes", "1".repeat(4001), ""),
    ).toThrow();
    expect(() =>
      catalogResponse("craft-recipes", Array(129).fill("1").join(","), ""),
    ).toThrow();
    expect(() => catalogResponse("item-info", "1".repeat(81), "")).toThrow();
  });
  it("uses server f32 thresholds and stops progression at the profession cap", () => {
    expect(experienceLevels).toHaveLength(110);
    expect(experienceLevels[99]).toEqual({ level: 100, xp: 229734400 });
    expect(experienceLevels[109]).toEqual({ level: 110, xp: 686845760 });
    expect(levelProgress(52801, experienceLevels)).toMatchObject({
      level: 24,
      nextLevel: 25,
      xpRemaining: 4939,
    });
    expect(levelProgress(686845760, experienceLevels)).toMatchObject({
      level: 110,
      nextLevel: null,
      xpRemaining: null,
    });
  });
  it("reads the shared collector snapshot without opening regional connections", async () => {
    const now = Date.now();
    calls.readOpenCrafts.mockResolvedValue(
      JSON.stringify({
        updatedAt: "2026-11-15T00:00:00Z",
        craftResults: [
          { entityId: "1", ownerEntityId: "other", lockExpiresAt: now - 1000 },
          { entityId: "2", ownerEntityId: "other", lockExpiresAt: now + 60000 },
          { entityId: "3", ownerEntityId: "me", lockExpiresAt: now + 60000 },
        ],
      }),
    );
    const { relayOpenCrafts } =
      await import("../src/bitcraft-ui/worldDirectories");
    const result = await relayOpenCrafts("me");
    expect(result.data.craftResults.map((row) => row.entityId)).toEqual([
      "1",
      "3",
    ]);
    expect(result.data.craftResults.map((row) => row.isPublic)).toEqual([
      true,
      false,
    ]);
    expect(result.refresh.updatedAt).toBe("2026-11-15T00:00:00Z");
    expect(calls.catalog).not.toHaveBeenCalled();
    calls.readOpenCrafts.mockRejectedValueOnce(Error("Collector unavailable"));
    await expect(relayOpenCrafts()).rejects.toThrow("Collector unavailable");
  });
  it("searches the release relay and preserves large character IDs", async () => {
    calls.live.mockResolvedValue({
      data: [{ entity_id: "648518346633892554", username: "Icha", region: 12 }],
    });
    const { searchPlayers } = await import("../src/bitcraft-ui/data/trackers");
    expect(await searchPlayers("Icha")).toMatchObject([
      { entityId: "648518346633892554", username: "Icha" },
    ]);
    expect(calls.live).toHaveBeenCalledWith("relayPlayers", "", "Icha");
  });
  it("retains the relay error instead of reporting a disabled legacy provider", async () => {
    calls.live.mockRejectedValue(Error("Relay temporarily reconnecting."));
    const { lookupPlayer } = await import("../src/bitcraft-ui/data/trackers");
    await expect(lookupPlayer("648518346633892554")).rejects.toThrow(
      "Relay temporarily reconnecting.",
    );
    expect(calls.live.mock.calls.map((c) => c[0])).toEqual(["relayPlayer"]);
  });
  it("resolves empire filters from the collector, with exact claim IDs", async () => {
    calls.readEmpireDirectory.mockResolvedValue(
      JSON.stringify({
        data: {
          empires: [{ entityId: "42", name: "Builders" }],
          settlements: [
            { empireEntityId: "42", claimEntityId: "1008806316831722377" },
            { empireEntityId: "99", claimEntityId: "other" },
          ],
        },
      }),
    );
    const { relayEmpireFilter } =
      await import("../src/bitcraft-ui/worldDirectories");
    const filters = { empire: "Builders" };
    await relayEmpireFilter(filters);
    expect(filters).toMatchObject({
      empireEntityId: "42",
      claimIds: ["1008806316831722377"],
    });
    expect(calls.live).not.toHaveBeenCalled();
    calls.readEmpireDirectory.mockResolvedValueOnce(
      JSON.stringify({ error: "Collector delayed", data: null }),
    );
    await expect(relayEmpireFilter({ empire: "Builders" })).rejects.toThrow(
      "Collector delayed",
    );
  });
  it("shows unlocked public work and the selected player’s own locked work", async () => {
    const { projectOpenCrafts } =
      await import("../src/bitcraft-ui/worldDirectories");
    const action = (id, owner, lock, progress = 10) => ({
      entity_id: id,
      building_entity_id: "building",
      owner_entity_id: owner,
      recipe_id: 42,
      craft_count: 2,
      progress,
      preparation: false,
      lock_expiration: { __timestamp_micros_since_unix_epoch__: lock },
    });
    const data = {
      progressive_action_state: [
        action("open", "other", 0),
        action("locked", "other", 2000000),
        action("mine", "me", 2000000),
        action("finished", "other", 0, 200),
      ],
      building_state: [{ entity_id: "building", claim_entity_id: "claim" }],
      claim_state: [{ entity_id: "claim", name: "Home" }],
      claim_local_state: [],
      player_username_state: [],
    };
    const recipes = {
      42: {
        actionsRequired: 100,
        experiencePerProgress: [{ skill_id: 6, quantity: 1.6 }],
        craftedItems: [],
      },
    };
    const rows = projectOpenCrafts(data, 12, recipes, "me", 1000);
    expect(rows.map((r) => r.entityId)).toEqual(["open", "mine"]);
    expect(rows[0]).toMatchObject({
      totalActionsRequired: 200,
      progress: 10,
      regionName: "Dalen",
      isPublic: true,
    });
    expect(rows[1].isPublic).toBe(false);
    expect(() => projectOpenCrafts(data, 12, {}, "me", 1000)).toThrow(
      "definition 42",
    );
  });
});
