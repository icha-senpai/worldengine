import { describe, it, expect } from "vitest";
import {
  storeBook,
  loadBook,
  storeStall,
  loadStall,
  clearTradeScope,
} from "../bitcraft/src/trade-storage";
import { orderDelta, applyOrderDelta } from "../bitcraft/src/trade-delta";
function table() {
  const rows = new Map<string, any>();
  let writes = 0;
  return {
    rows,
    get writes() {
      return writes;
    },
    count: () => BigInt(rows.size),
    key: {
      find: (key: string) => rows.get(key),
      delete: (key: string) => {
        writes++;
        rows.delete(key);
      },
      update: (row: any) => {
        writes++;
        rows.set(row.key, row);
      },
    },
    insert: (row: any) => {
      writes++;
      rows.set(row.key, row);
    },
    scope: {
      filter: (scope: string) =>
        [...rows.values()].filter((row) => row.scope === scope),
    },
  };
}
const book = () => ({
  item: { id: "7", name: "Plank" },
  sellOrders: [1, 2].map((id) => ({
    entityId: String(id),
    quantity: 10,
    regionId: 8,
    claimEntityId: "9007199254740993",
    claimName: "Claim",
    ownerEntityId: "99",
    ownerUsername: "Player",
    claimLocationX: 1,
    claimLocationZ: 2,
  })),
  buyOrders: [],
  packageSellOrders: [],
  packageBuyOrders: [],
});
describe("canonical trading storage", () => {
  it("round trips large IDs and shared labels, writes only a changed order and removes cancellations", () => {
    const ctx = { db: { tradeRecord: table(), tradeReference: table() } };
    const first = storeBook(ctx, "native:8:item:7", book());
    expect(loadBook(ctx, first.payload)).toEqual(book());
    expect(ctx.db.tradeReference.rows.size).toBe(2);
    expect(JSON.parse(first.payload).sellOrders).toEqual([]);
    const writes = ctx.db.tradeRecord.writes;
    expect(storeBook(ctx, "native:8:item:7", book()).changed).toBe(false);
    expect(ctx.db.tradeRecord.writes).toBe(writes);
    const next = book();
    next.sellOrders[1].quantity = 20;
    storeBook(ctx, "native:8:item:7", next);
    expect(ctx.db.tradeRecord.writes - writes).toBe(1);
    next.sellOrders.pop();
    storeBook(ctx, "native:8:item:7", next);
    expect(loadBook(ctx, first.payload).sellOrders).toHaveLength(1);
    clearTradeScope(ctx, "native:8:item:7");
    expect(ctx.db.tradeRecord.rows.size).toBe(0);
  });
  it("round trips compact barter legs through catalog metadata", () => {
    const ctx = { db: { tradeRecord: table(), tradeReference: table() } };
    const metadata = (kind: string, id: string) => ({
      id,
      kind,
      name: "Thing",
      tier: 3,
    });
    const stall = {
      entityId: "10",
      orders: [
        {
          entityId: "20",
          remainingStock: 5,
          offerItems: [{ ...metadata("item", "7"), quantity: 2 }],
          requiredItems: [],
          offerCargo: [],
          requiredCargo: [{ ...metadata("cargo", "8"), quantity: 1 }],
        },
      ],
    };
    const stored = storeStall(ctx, "barter:8:10", stall);
    expect(loadStall(ctx, stored.payload, metadata)).toEqual(stall);
    expect([...ctx.db.tradeRecord.rows.values()][0].payload).not.toContain(
      "Thing",
    );
  });
  it("transmits one changed order and a removal while reconstructing the full book", () => {
    const previous = book(),
      next = book();
    next.sellOrders.shift();
    next.sellOrders[0].quantity = 20;
    const patch = orderDelta(previous, next);
    expect(patch.sellOrders).toHaveLength(1);
    expect(patch._removedOrders.sellOrders).toEqual(["1"]);
    expect(applyOrderDelta(previous, patch)).toEqual(next);
    expect(() => applyOrderDelta(null, patch)).toThrow(/baseline/);
    expect(() =>
      applyOrderDelta(previous, {
        ...patch,
        _removedOrders: { sellOrders: ["bad"] },
      }),
    ).toThrow(/removals/);
  });
});
