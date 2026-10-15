import { describe, expect, it } from "vitest";
import { ConnectionId, Timestamp } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
  TestClock,
} from "spacetimedb/server/test-utils";
import db, * as module from "../spacetimedb/src/index";

function setup() {
  const test = createModuleTestHarness(db, module, {
    clock: new TestClock(new Timestamp(1_800_000_000_000_000n)),
    rngSeed: 42,
  });
  const owner = TestAuth.internal();
  const alice = TestAuth.fromJwtPayload(
    '{"iss":"test","sub":"alice"}',
    new ConnectionId(1n),
  );
  const bob = TestAuth.fromJwtPayload(
    '{"iss":"test","sub":"bob"}',
    new ConnectionId(2n),
  );
  test.withReducerTx(owner, (ctx) => {
    module.init(ctx, {});
    module.configureLocalPlay(ctx, { enabled: true });
  });
  for (const [auth, name] of [
    [alice, "Alice"],
    [bob, "Bob"],
  ] as const) {
    test.withReducerTx(auth, (ctx) => {
      module.createCharacter(ctx, { name });
      const p = ctx.db.player.owner.find(ctx.sender)!;
      ctx.db.player.owner.update({ ...p, gold: 1000n });
      ctx.db.inventory.insert({
        key: `${ctx.sender.toHexString()}:fishing_1`,
        owner: ctx.sender,
        itemKey: "fishing_1",
        name: "River Minnow",
        rarity: "common",
        quantity: 100n,
      });
    });
  }
  const player = (auth: typeof alice) =>
    module.myPlayer(test.viewContext(auth))!;
  const fish = (auth: typeof alice) =>
    module
      .myInventory(test.viewContext(auth))
      .find((r) => r.itemKey === "fishing_1")!.quantity;
  const list = () => {
    test.withReducerTx(alice, (ctx) =>
      module.listItem(ctx, {
        itemKey: "fishing_1",
        quantity: 3,
        unitPrice: 12,
      }),
    );
    return [...test.db.listing.iter()][0];
  };
  return { test, owner, alice, bob, player, fish, list };
}

describe("Evergather market transactions and views", () => {
  it("keeps the same character's tab scopes independent and hidden from other players", () => {
    const { test, alice, bob, list } = setup();
    const otherTab = TestAuth.fromJwtPayload(
      '{"iss":"test","sub":"alice"}',
      new ConnectionId(3n),
    );
    list();
    test.withReducerTx(alice, (ctx) =>
      module.setUiScope(ctx, {
        workspace: "trade",
        panel: "marketplace",
        page: 1,
      }),
    );
    test.withReducerTx(otherTab, (ctx) =>
      module.setUiScope(ctx, {
        workspace: "trade",
        panel: "inventory",
        page: 2,
      }),
    );
    expect([...test.db.uiSession.iter()]).toHaveLength(2);
    expect(module.myMarketPage(test.viewContext(bob))).toEqual([]);
    test.withReducerTx(alice, (ctx) =>
      module.setUiScope(ctx, {
        workspace: "gather",
        panel: "actions",
        page: 1,
      }),
    );
    expect(
      test.db.uiSession.connectionKey.find(new ConnectionId(3n).toHexString()),
    ).toMatchObject({ workspace: "trade", panel: "inventory", marketPage: 2 });
    expect(module.myMarketListings(test.viewContext(otherTab))).toHaveLength(1);
  });
  it("transfers escrow exactly once and charges the rounded fee", () => {
    const { test, alice, bob, player, fish, list } = setup();
    const listing = list();
    expect(fish(alice)).toBe(97n);
    test.withReducerTx(bob, (ctx) =>
      module.buyListing(ctx, { id: listing.id }),
    );
    expect(fish(bob)).toBe(103n);
    expect(player(alice).gold).toBe(1034n);
    expect(player(bob).gold).toBe(964n);
    expect([...test.db.trade.iter()][0].marketFee).toBe(2n);
    expect(test.db.listing.count()).toBe(0n);
    expect(test.db.marketOrder.count()).toBe(0n);
    expect(test.db.marketRevision.id.find(1)?.revision).toBe(2n);
    expect(() =>
      test.withReducerTx(bob, (ctx) =>
        module.buyListing(ctx, { id: listing.id }),
      ),
    ).toThrow("already sold");
    expect(fish(alice) + fish(bob)).toBe(200n);
    expect(test.db.trade.count()).toBe(1n);
  });

  it("rolls back rejected buys and foreign cancellations, then returns full escrow", () => {
    const { test, alice, bob, player, fish, list } = setup();
    const listing = list();
    test.withReducerTx(bob, (ctx) => {
      const p = ctx.db.player.owner.find(ctx.sender)!;
      ctx.db.player.owner.update({ ...p, gold: 1n });
    });
    expect(() =>
      test.withReducerTx(bob, (ctx) =>
        module.buyListing(ctx, { id: listing.id }),
      ),
    ).toThrow("gold");
    expect(() =>
      test.withReducerTx(bob, (ctx) =>
        module.cancelListing(ctx, { id: listing.id }),
      ),
    ).toThrow("not yours");
    expect(test.db.listing.id.find(listing.id)).toEqual(listing);
    expect(test.db.marketOrder.id.find(listing.id)).toBeDefined();
    expect(test.db.marketRevision.id.find(1)?.revision).toBe(1n);
    expect(player(alice).gold).toBe(1000n);
    expect(player(bob).gold).toBe(1n);
    expect(fish(bob)).toBe(100n);
    expect(test.db.trade.count()).toBe(0n);
    test.withReducerTx(alice, (ctx) =>
      module.cancelListing(ctx, { id: listing.id }),
    );
    expect(fish(alice)).toBe(100n);
    expect(test.db.listing.count()).toBe(0n);
    expect(() =>
      test.withReducerTx(alice, (ctx) =>
        module.cancelListing(ctx, { id: listing.id }),
      ),
    ).toThrow("not yours");
    expect(fish(alice)).toBe(100n);
  });

  it("keeps public pages bounded and own escrow independent of other viewers", () => {
    const { test, alice, bob } = setup();
    for (let i = 0; i < 51; i++)
      test.withReducerTx(alice, (ctx) =>
        module.listItem(ctx, {
          itemKey: "fishing_1",
          quantity: 1,
          unitPrice: 12,
        }),
      );
    const rows = module.marketPageListings(test.anonymousViewContext());
    expect(rows.filter((r) => r.page === 1)).toHaveLength(50);
    expect(rows.filter((r) => r.page === 2)).toHaveLength(1);
    expect(rows[0].id).toBeGreaterThan(rows.at(-1)!.id);
    expect(module.marketSummary(test.anonymousViewContext())).toEqual([
      { id: 0, total: 51, pages: 2 },
    ]);
    expect(module.myMarketListings(test.viewContext(alice))).toHaveLength(51);
    expect(module.myMarketListings(test.viewContext(bob))).toEqual([]);
    test.withReducerTx(alice, (ctx) =>
      module.cancelListing(ctx, { id: rows[0].id }),
    );
    expect(module.marketSummary(test.anonymousViewContext())).toEqual([
      { id: 0, total: 50, pages: 1 },
    ]);
    expect(
      module
        .marketPageListings(test.anonymousViewContext())
        .every((r) => r.page === 1),
    ).toBe(true);
  });
});
