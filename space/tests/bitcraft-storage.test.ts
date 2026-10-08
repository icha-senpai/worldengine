import { describe, it, expect } from "vitest";
import {
  canonicalState,
  safeReplicaPath,
  retainedStorageBytes,
} from "../scripts/bitcraft-storage";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { tradingDelta } from "../scripts/bitcraft-trading-delta";
describe("bounded BitCraft storage", () => {
  it("counts each database's artifacts independently", async () => {
    const root = await mkdtemp(join(tmpdir(), "bitcraft-budget-"));
    try {
      await Promise.all([
        writeFile(join(root, "space-bitcraft-tools.bundle.js"), "main"),
        writeFile(join(root, "space-bitcraft-tools.bundle.js.tmp"), "temp"),
        writeFile(join(root, "space-bitcraft-tools-123.json"), "backup"),
        writeFile(join(root, "space-bitcraft-checks.bundle.js"), "test"),
        writeFile(join(root, "space-bitcraft-checks.json"), "binding"),
        writeFile(join(root, "space-bitcraft-checks-456.json.sha256"), "hash"),
        writeFile(
          join(root, "space-bitcraft-checks-other.bundle.js"),
          "unrelated",
        ),
      ]);
      expect(await retainedStorageBytes("space-bitcraft-tools", root)).toBe(14);
      expect(await retainedStorageBytes("space-bitcraft-checks", root)).toBe(
        15,
      );
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });
  it("rejects unsafe replica targets", () => {
    for (const value of ["", "..", "../7", "7/other", "C:\\other", "7\\.."])
      expect(() => safeReplicaPath(value)).toThrow();
    expect(safeReplicaPath("7")).toMatch(/[\\/]replicas[\\/]7$/);
  });
  it("compares every retained private field independent of iteration order", () => {
    const state = {
      version: 2,
      widget_profile: [
        { token: "a", owner: "first" },
        { token: "b", owner: "second" },
      ],
    };
    expect(canonicalState(state)).toBe(
      canonicalState({
        widget_profile: [...state.widget_profile].reverse(),
        version: 2,
      }),
    );
    expect(canonicalState(state)).not.toBe(
      canonicalState({
        ...state,
        widget_profile: [{ token: "a", owner: "second" }],
      }),
    );
  });
  it("sends removals and changed books without unchanged regional data", () => {
    const first = tradingDelta(undefined, {
      books: {
        "item:1": { sellOrders: [{ entityId: "10", quantity: 1 }] },
        "item:2": {},
      },
      stalls: [{ entityId: "5", orders: [] }],
    });
    const next = tradingDelta(first.next, {
      books: { "item:1": { sellOrders: [{ entityId: "10", quantity: 2 }] } },
      stalls: [],
    });
    expect(first.patch.reset).toBe(true);
    expect(next.patch.reset).toBe(false);
    expect(Object.keys(next.patch.books)).toEqual(["item:1"]);
    expect(next.patch.removedBooks).toEqual(["item:2"]);
    expect(next.patch.removedStalls).toEqual(["5"]);
    expect(tradingDelta(next.next, next.next).changed).toBe(false);
  });
  it("ignores order iteration changes", () => {
    const book = { sellOrders: [{ entityId: "10" }, { entityId: "20" }] };
    const first = tradingDelta(undefined, {
      books: { "item:1": book },
      stalls: [],
    });
    expect(
      tradingDelta(first.next, {
        books: { "item:1": { sellOrders: [...book.sellOrders].reverse() } },
        stalls: [],
      }).changed,
    ).toBe(false);
  });
});
