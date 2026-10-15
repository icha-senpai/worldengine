import { describe, expect, it, vi } from "vitest";
import type { DbConnection } from "../src/bindings/evergather";
import {
  decodeMarketPage,
  loadMarketPage,
  marketListingsFor,
} from "../src/evergather/market";

const payload = (revision = "0", page = 1, id = "9007199254740999") =>
  JSON.stringify({
    page,
    revision,
    total: 100,
    pages: 2,
    next: "cursor",
    rows: [
      {
        id,
        seller: "1".padStart(64, "0"),
        createdAt: "1800000000000000",
        quantity: "2",
        unitPrice: "12",
        toolId: "0",
        itemKey: "fishing_1",
        rarity: "common",
        page,
        metadata: "{}",
      },
    ],
  });
function connection() {
  let revision = 0n;
  const read = vi
    .fn<DbConnection["procedures"]["readMarketListings"]>()
    .mockResolvedValue(payload());
  const conn = {
    procedures: { readMarketListings: read },
    db: {
      marketRevision: { id: { find: () => ({ revision }) } },
      myMarketListings: { iter: () => [] },
      marketPageListings: { iter: () => [] },
    },
  } as unknown as DbConnection;
  return {
    conn,
    read,
    change: (value: bigint) => {
      revision = value;
    },
  };
}
describe("market page requests", () => {
  it("preserves large identifiers and SDK row types", () => {
    const row = decodeMarketPage(payload()).rows[0];
    expect(row.id).toBe(9007199254740999n);
    expect(row.seller.toHexString()).toBe("1".padStart(64, "0"));
    expect(row.createdAt.microsSinceUnixEpoch).toBe(1800000000000000n);
  });
  it("drops late replies when another page is requested", async () => {
    const { conn, read } = connection();
    let finish!: (value: string) => void;
    read.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    const earlier = loadMarketPage(conn, 1);
    read.mockResolvedValueOnce(payload("0", 2, "2"));
    await loadMarketPage(conn, 2);
    finish(payload());
    expect(await earlier).toBeNull();
    expect(marketListingsFor(conn)[0].id).toBe(2n);
  });
  it("retries stale replies and clears cursors when the market changes", async () => {
    const { conn, read, change } = connection();
    await loadMarketPage(conn, 1);
    await loadMarketPage(conn, 2);
    expect(read.mock.lastCall?.[0].after).toBe("cursor");
    change(1n);
    read
      .mockResolvedValueOnce(payload("0"))
      .mockResolvedValueOnce(payload("1", 2));
    expect((await loadMarketPage(conn, 2))?.revision).toBe("1");
    expect(read.mock.lastCall?.[0]).toEqual({
      page: 2,
      after: "",
      revision: "1",
    });
  });
});
