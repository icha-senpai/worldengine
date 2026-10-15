import { describe, expect, it } from "vitest";
import { ConnectionId } from "spacetimedb";
import {
  createModuleTestHarness,
  TestAuth,
} from "spacetimedb/server/test-utils";
import db, * as module from "../spacetimedb/src/index";

describe("Evergather module with the native datastore", () => {
  it("keeps characters private and rejects duplicate creation atomically", () => {
    const test = createModuleTestHarness(db, module, { rngSeed: 42 });
    const owner = TestAuth.internal();
    const alice = TestAuth.fromJwtPayload(
      '{"iss":"test","sub":"alice"}',
      new ConnectionId(1n),
    );
    const bob = TestAuth.fromJwtPayload(
      '{"iss":"test","sub":"bob"}',
      new ConnectionId(2n),
    );
    test.withReducerTx(owner, (ctx) => module.init(ctx));
    expect(() =>
      test.withReducerTx(alice, (ctx) =>
        module.configureLocalPlay(ctx, { enabled: true }),
      ),
    ).toThrow("database owner");
    test.withReducerTx(owner, (ctx) =>
      module.configureLocalPlay(ctx, { enabled: true }),
    );
    test.withReducerTx(alice, (ctx) =>
      module.createCharacter(ctx, { name: "Alice" }),
    );
    const tools = test.db.tool.count(),
      skills = test.db.skill.count();
    expect(module.myPlayer(test.viewContext(alice))?.name).toBe("Alice");
    expect(module.myPlayer(test.viewContext(bob))).toBeUndefined();
    expect(module.myInventory(test.viewContext(bob))).toEqual([]);
    expect(() =>
      test.withReducerTx(alice, (ctx) =>
        module.createCharacter(ctx, { name: "Duplicate" }),
      ),
    ).toThrow("already have");
    expect(test.db.tool.count()).toBe(tools);
    expect(test.db.skill.count()).toBe(skills);
    expect(test.db.player.count()).toBe(1n);
  });
});
