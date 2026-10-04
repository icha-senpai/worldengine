import { describe, expect, it } from "vitest";
import {
  catalogKinds,
  pageWindow,
  referencedItems,
  topLeaders,
  newestListings,
} from "../spacetimedb/src/performance";

describe("bounded game data", () => {
  it("orders the public market by timestamp, using ids only to break ties", () => {
    const row = (id: bigint, micros: bigint) => ({
      id,
      createdAt: { microsSinceUnixEpoch: micros },
    });
    expect(
      newestListings([row(100n, 1n), row(3n, 2n), row(2n, 2n)]).map(
        (row) => row.id,
      ),
    ).toEqual([3n, 2n, 100n]);
  });
  it("keeps heavy recipes and inventory definitions out of gathering", () => {
    expect(catalogKinds("gather", "actions")).toContain("gathering_actions");
    expect(catalogKinds("gather", "actions")).not.toContain("crafting_recipes");
    expect(catalogKinds("trade", "inventory")).toContain("crafting_recipes");
    expect([
      ...referencedItems({
        ingredients: [{ item_key: "iron_ore" }],
        outputs: [{ item_key: "ingot" }],
      }),
    ]).toEqual(["iron_ore", "ingot"]);
  });
  it("clamps pages after sales shrink the marketplace", () => {
    expect(pageWindow(101, 3)).toEqual({
      page: 3,
      pages: 3,
      total: 101,
      offset: 100,
    });
    expect(pageWindow(50, 3).page).toBe(1);
    expect(pageWindow(0, 0)).toEqual({
      page: 1,
      pages: 1,
      total: 0,
      offset: 0,
    });
  });
  it("retains skill specialists who are outside the wealth and total-XP leaders", () => {
    const rows = Array.from({ length: 1000 }, (_, id) => ({
      owner: { toHexString: () => String(id).padStart(4, "0") },
      gold: BigInt(id),
      experience: BigInt(id),
      skillExperience: JSON.stringify({ fishing: id === 0 ? 5000 : 0 }),
    }));
    const selected = topLeaders(rows, ["fishing"]);
    expect(selected.some((row) => row.owner.toHexString() === "0000")).toBe(
      true,
    );
    expect(selected.length).toBeLessThanOrEqual(60);
    expect(new Set(selected.map((row) => row.owner.toHexString())).size).toBe(
      selected.length,
    );
  });
});
