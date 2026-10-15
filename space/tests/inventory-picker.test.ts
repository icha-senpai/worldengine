import { describe, expect, it } from "vitest";
import { catalogResponse } from "../bitcraft/src/catalog";
import bundled from "../bitcraft/src/content/catalog.json";
import additions from "../bitcraft/src/content/catalog-additions.json";
import { inventoryTracker } from "../src/bitcraft-ui/trackers";

describe("inventory picker catalog", () => {
  it("includes every item and cargo beyond the usual search result limit", () => {
    const catalog = catalogResponse("inventory-items", "", "")!;
    const keys = new Set(
      [...bundled.items, ...additions.items].map(
        (row) => `${row.kind}:${row.id}`,
      ),
    );
    expect(catalog.length).toBe(keys.size);
    expect(new Set(catalog.map((row) => `${row.kind}:${row.id}`))).toEqual(
      keys,
    );
    expect(catalog.some((row) => row.kind === "cargo")).toBe(true);
    expect(catalog[0]).not.toHaveProperty("description");
    expect(catalogResponse("items", "", "")!.length).toBe(100);
  });

  it("keeps the full picker and selected unheld cargo when a saved search changes", () => {
    const catalog = catalogResponse("inventory-items", "", "")!;
    const cargo = catalog.findLast((row) => row.kind === "cargo")!;
    const key = `${cargo.kind}:${cargo.id}`;
    const result = inventoryTracker(
      { entityId: "1" },
      { inventories: [] },
      catalog,
      { itemKeys: [key], itemSearch: "Abyssal", need: 150 },
    );
    expect(result.options).toHaveLength(catalog.length);
    expect(result.tracker?.items[0]).toMatchObject({
      key,
      name: cargo.name,
      kind: "cargo",
      quantity: 0,
      need: 150,
    });
  });
});
