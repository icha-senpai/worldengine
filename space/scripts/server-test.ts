import { runTemporaryDatabaseTest } from "./temporary-database";
import { execFileSync } from "node:child_process";
import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/evergather";

const cli =
  process.env.LOCALAPPDATA + "/SpacetimeDB/bin/current/spacetimedb-cli.exe";
const server = "http://127.0.0.1:3100";
const database = `space-evergather-checks-${Date.now()}`;
await runTemporaryDatabaseTest(database, async (scope) => {
  function command(args: string[]) {
    execFileSync(cli, args, { stdio: "pipe" });
  }
  command([
    "publish",
    database,
    "--module-path",
    "spacetimedb",
    "--server",
    server,
    "--yes",
    "--no-config",
  ]);
  command([
    "call",
    database,
    "configure_auth",
    '"localhost"',
    '"spacetimedb"',
    "--server",
    server,
    "--no-config",
  ]);
  command([
    "call",
    database,
    "refresh_content",
    "--server",
    server,
    "--no-config",
  ]);
  async function connect(token?: string, databaseName = database) {
    const account =
      token === ""
        ? { token: undefined }
        : token
          ? { token }
          : ((await (
              await fetch(`${server}/v1/identity`, { method: "POST" })
            ).json()) as { token: string });
    return await new Promise<DbConnection>((resolve, reject) => {
      DbConnection.builder()
        .withUri("ws://127.0.0.1:3100")
        .withDatabaseName(databaseName)
        .withToken(account.token)
        .onConnect((conn) =>
          conn
            .subscriptionBuilder()
            .onApplied(() => resolve(conn))
            .onError((error) => reject(error))
            .subscribe([
              "SELECT * FROM my_player",
              "SELECT * FROM my_skills",
              "SELECT * FROM my_inventory",
              "SELECT * FROM my_tools",
              "SELECT * FROM my_results",
              "SELECT * FROM my_contracts",
              "SELECT * FROM trade",
              "SELECT * FROM my_achievements",
              "SELECT * FROM listing",
              "SELECT * FROM market_page_listings WHERE page = 1",
              "SELECT * FROM my_market_listings",
              "SELECT * FROM market_summary",
              "SELECT * FROM market_revision",
              `SELECT * FROM my_catalog WHERE connection_key = '${conn.connectionId.toHexString()}'`,
              `SELECT * FROM market_listings WHERE connection_key = '${conn.connectionId.toHexString()}'`,
              `SELECT * FROM my_market_page WHERE connection_key = '${conn.connectionId.toHexString()}'`,
              "SELECT * FROM top_leaderboard",
            ]),
        )
        .onConnectError((_ctx, error) => reject(error))
        .build();
    });
  }
  const firstAccount = (await (
    await fetch(`${server}/v1/identity`, { method: "POST" })
  ).json()) as { token: string };
  const first = await connect(firstAccount.token);
  const second = await connect();
  let observer: DbConnection | undefined;
  async function eventually(check: () => boolean) {
    const deadline = Date.now() + 3000;
    while (!check()) {
      if (Date.now() > deadline)
        throw new Error("Subscription did not synchronize.");
      await new Promise((resolve) => setTimeout(resolve, 20));
    }
  }
  try {
    await first.reducers.createCharacter({ name: "First player" });
    await second.reducers.createCharacter({ name: "Second player" });
    observer = await connect(firstAccount.token);
    assert.equal(
      [...observer.db.myPlayer.iter()][0].name,
      "First player",
      "same identity reconnect retains the character",
    );
    assert.equal([...first.db.myPlayer.iter()].length, 1);
    assert.equal([...second.db.myPlayer.iter()][0].name, "Second player");
    assert.equal([...first.db.mySkills.iter()].length, 38);
    assert.ok(
      [...first.db.myCatalog.iter()].length < 2000,
      "gathering receives a scoped catalog",
    );
    assert.ok(
      ![...first.db.myCatalog.iter()].some(
        (row) => row.kind === "crafting_recipes",
      ),
    );
    await first.reducers.setUiScope({
      workspace: "craft",
      panel: "recipes",
      page: 1,
    });
    assert.equal(
      [...first.db.myCatalog.iter()].filter(
        (row) => row.kind === "crafting_recipes",
      ).length,
      650,
    );
    await first.reducers.setUiScope({
      workspace: "gather",
      panel: "actions",
      page: 1,
    });
    assert.ok(
      ![...first.db.myCatalog.iter()].some(
        (row) => row.kind === "crafting_recipes",
      ),
      "leaving crafting removes its catalog",
    );
    await observer.reducers.setUiScope({
      workspace: "craft",
      panel: "recipes",
      page: 1,
    });
    assert.equal(
      [...observer.db.myCatalog.iter()].filter(
        (row) => row.kind === "crafting_recipes",
      ).length,
      650,
    );
    assert.ok(
      ![...first.db.myCatalog.iter()].some(
        (row) => row.kind === "crafting_recipes",
      ),
      "two tabs for one character have independent catalog subscriptions",
    );
    const appearance = JSON.stringify({
      body_style: "tall",
      palette: "ember",
      hair_style: "braided",
      outfit: "artisan",
    });
    await first.reducers.customizeCharacter({
      name: "First player",
      title: "Dockside artisan",
      species: "sylvan",
      pronouns: "they/them",
      region: "moonwake_coast",
      appearance,
    });
    assert.equal([...first.db.myPlayer.iter()][0].appearance, appearance);
    assert.equal([...first.db.myPlayer.iter()][0].pronouns, "they/them");
    assert.equal(
      [...first.db.myPlayer.iter()][0].characterTitle,
      "Dockside artisan",
    );
    await assert.rejects(
      first.reducers.customizeCharacter({
        name: "First player",
        title: "",
        species: "human",
        pronouns: "",
        region: "moonwake_coast",
        appearance: JSON.stringify({
          body_style: "invalid",
          palette: "ember",
          hair_style: "short",
          outfit: "artisan",
        }),
      }),
      /Choose a listed/,
    );
    assert.equal(
      [...first.db.myPlayer.iter()][0].appearance,
      appearance,
      "invalid appearance leaves the saved character intact",
    );
    assert.equal(
      [...first.db.myTools.iter()].filter((row) => row.equipped).length,
      38,
      "every profession gets its current field tool",
    );
    await first.reducers.performAction({
      kind: "gathering_actions",
      key: "fishing_1",
    });
    await eventually(() => [...observer!.db.myInventory.iter()].length > 0);
    assert.deepEqual(
      [...observer.db.myInventory.iter()].map((row) => [
        row.itemKey,
        row.quantity,
      ]),
      [...first.db.myInventory.iter()].map((row) => [
        row.itemKey,
        row.quantity,
      ]),
      "a second client sees committed inventory without polling",
    );
    assert.ok([...first.db.myInventory.iter()].length > 0);
    assert.equal(
      [...second.db.myInventory.iter()].length,
      0,
      "private inventory must not leak",
    );
    assert.ok(
      [...first.db.mySkills.iter()].find((row) => row.skill === "fishing")!
        .experience > 0n,
    );
    const xpBeforeRepeat = [...first.db.mySkills.iter()].find(
      (row) => row.skill === "fishing",
    )!.experience;
    await first.reducers.performAction({
      kind: "gathering_actions",
      key: "fishing_1",
    });
    assert.ok(
      [...first.db.mySkills.iter()].find((row) => row.skill === "fishing")!
        .experience > xpBeforeRepeat,
    );
    assert.equal(
      [...first.db.myPlayer.iter()][0].nextActionAt,
      0n,
      "consecutive gathering has no cooldown",
    );
    const activity = [...first.db.myCatalog.iter()].find(
      (row) => row.kind === "gathering_actions",
    );
    assert.equal(JSON.parse(activity!.payload).cooldown_seconds, 0);
    const before = [...first.db.myInventory.iter()].map((row) => [
      row.key,
      row.quantity,
    ]);
    await assert.rejects(
      first.reducers.craft({ key: "smelting_1" }),
      /Not enough|Level 10/,
    );
    assert.deepEqual(
      [...first.db.myInventory.iter()].map((row) => [row.key, row.quantity]),
      before,
      "failed crafting must roll back",
    );
    await assert.rejects(
      second.reducers.configureAuth({
        issuer: "localhost",
        audience: "spacetimedb",
      }),
      /database owner/,
    );
    await assert.rejects(
      second.reducers.configureLocalPlay({ enabled: true }),
      /database owner/,
    );
    const firstTool = [...first.db.myTools.iter()][0];
    await assert.rejects(
      second.reducers.equipTool({ id: firstTool.id, equipped: true }),
      /not yours/,
    );
    await assert.rejects(
      first.reducers.equipTool({ id: firstTool.id, equipped: false }),
      /Field kit/,
    );
    await assert.rejects(
      first.reducers.claimAchievement({ key: "field_legend" }),
      /Complete this achievement/,
    );
    await first.reducers.claimAchievement({ key: "first_steps" });
    await assert.rejects(
      first.reducers.claimAchievement({ key: "first_steps" }),
      /already been claimed/,
    );
    await second.reducers.claimAchievement({ key: "account_level_1" });
    const fish = [...first.db.myInventory.iter()].find(
      (row) => row.itemKey === "fishing_1",
    )!;
    await assert.rejects(
      first.reducers.listItem({
        itemKey: fish.itemKey,
        quantity: 1,
        unitPrice: 999,
      }),
      /Price must be/,
    );
    const sellerGold = [...first.db.myPlayer.iter()][0].gold;
    await first.reducers.listItem({
      itemKey: fish.itemKey,
      quantity: 1,
      unitPrice: 12,
    });
    const listing = [...first.db.listing.iter()].find((row) =>
      row.seller.equals(first.identity!),
    )!;
    const indexed = JSON.parse(
      await second.procedures.readMarketListings({
        page: 1,
        after: "",
        revision: "",
      }),
    );
    assert.ok(
      indexed.rows.some((row: any) => row.id === String(listing.id)),
      "new listing appears in the indexed page",
    );
    await eventually(() =>
      [...first.db.myMarketListings.iter()].some(
        (row) => row.id === listing.id,
      ),
    );
    assert.equal(
      [...second.db.myMarketListings.iter()].length,
      0,
      "own escrow is caller-only",
    );
    assert.ok(
      [...first.db.myMarketListings.iter()].every((row) =>
        row.seller.equals(first.identity!),
      ),
    );
    await eventually(() =>
      [...second.db.marketPageListings.iter()].some(
        (row) => row.id === listing.id && row.page === 1,
      ),
    );
    const publicOffer = [...second.db.marketPageListings.iter()].find(
      (row) => row.id === listing.id,
    )!;
    assert.equal(
      JSON.parse(publicOffer.metadata).item_key,
      "fishing_1",
      "shared page retains item metadata",
    );
    await assert.rejects(
      second.reducers.cancelListing({ id: listing.id }),
      /not yours/,
    );
    await second.reducers.buyListing({ id: listing.id });
    const afterSale = JSON.parse(
      await second.procedures.readMarketListings({
        page: 1,
        after: "",
        revision: "",
      }),
    );
    assert.ok(
      !afterSale.rows.some((row: any) => row.id === String(listing.id)),
      "sold listing leaves the indexed page",
    );
    assert.ok(
      BigInt(afterSale.revision) > BigInt(indexed.revision),
      "sale invalidates market cursors",
    );
    await eventually(
      () =>
        ![
          ...first.db.myMarketListings.iter(),
          ...first.db.marketPageListings.iter(),
          ...second.db.marketPageListings.iter(),
        ].some((row) => row.id === listing.id),
    );
    await eventually(() =>
      [...first.db.trade.iter()].some(
        (row) =>
          row.sellerName === "First player" &&
          row.buyerName === "Second player",
      ),
    );
    const sale = [...first.db.trade.iter()].find(
      (row) =>
        row.sellerName === "First player" && row.buyerName === "Second player",
    )!;
    assert.equal(sale.totalPrice, 12n);
    assert.equal(sale.marketFee, 1n);
    await assert.rejects(
      second.reducers.buyListing({ id: listing.id }),
      /already sold/,
    );
    await new Promise((resolve) => setTimeout(resolve, 100));
    assert.equal(
      [...first.db.myPlayer.iter()][0].gold,
      sellerGold + 11n,
      "seller receives gross less rounded 5% fee",
    );
    assert.equal([...second.db.myPlayer.iter()][0].gold, 3n);
    await second.reducers.sellToVendor({ itemKey: "fishing_1", quantity: 1 });
    assert.equal(
      [...second.db.myPlayer.iter()][0].gold,
      4n,
      "vendor uses catalog buy price",
    );
    await first.reducers.setUiScope({
      workspace: "gather",
      panel: "activities",
      page: 1,
    });
    const activityXp = [...first.db.mySkills.iter()].find(
      (row) => row.skill === "smelting",
    )!.experience;
    await first.reducers.performAction({
      kind: "skill_activities",
      key: "training_smelting",
    });
    assert.equal(
      [...first.db.mySkills.iter()].find((row) => row.skill === "smelting")!
        .experience,
      activityXp + 55n,
    );
    await assert.rejects(
      first.reducers.resetItemEconomy({ version: "ten-tier-v1" }),
      /database owner/,
    );
    await assert.rejects(
      first.reducers.updateCharacter({
        name: "First player",
        species: "human",
        region: "moonwake_coast",
        title: "Made up title",
      }),
      /Claim this achievement/,
    );
    command([
      "call",
      database,
      "configure_auth",
      '""',
      '""',
      "--server",
      server,
      "--no-config",
    ]);
    await assert.rejects(
      connect(""),
      "anonymous gameplay is rejected while local play is off",
    );
    command([
      "call",
      database,
      "configure_local_play",
      "true",
      "--server",
      server,
      "--no-config",
    ]);
    const local = await connect("");
    try {
      await local.reducers.createCharacter({ name: "Local character" });
      assert.equal([...local.db.myPlayer.iter()][0].name, "Local character");
      assert.equal(
        [...local.db.myInventory.iter()].length,
        0,
        "local play retains caller isolation",
      );
      await assert.rejects(
        local.reducers.configureLocalPlay({ enabled: false }),
        /database owner/,
      );
    } finally {
      local.disconnect();
      command([
        "call",
        database,
        "configure_local_play",
        "false",
        "--server",
        server,
        "--no-config",
      ]);
      command([
        "call",
        database,
        "configure_auth",
        '"localhost"',
        '"spacetimedb"',
        "--server",
        server,
        "--no-config",
      ]);
    }
    const resetOwner = [...first.db.myPlayer.iter()][0].owner;
    const retainedGold = [...first.db.myPlayer.iter()][0].gold;
    const inventorySnapshot = [...first.db.myInventory.iter()]
      .map((row) => [row.itemKey, row.quantity.toString()])
      .sort();
    const otherSkills = [...second.db.mySkills.iter()]
      .map((row) => [row.skill, row.experience.toString()])
      .sort();
    await assert.rejects(
      first.reducers.resetPlayerLevels({ owner: resetOwner }),
      /database owner/,
    );
    await assert.rejects(
      first.reducers.clearLeaderboards({}),
      /database owner/,
    );
    command([
      "call",
      database,
      "reset_player_levels",
      JSON.stringify(resetOwner.toHexString()),
      "--server",
      server,
      "--no-config",
    ]);
    await eventually(() =>
      [...first.db.mySkills.iter()].every((row) => row.experience === 0n),
    );
    assert.equal([...first.db.mySkills.iter()].length, 38);
    assert.equal([...first.db.myPlayer.iter()][0].gold, retainedGold);
    assert.deepEqual(
      [...first.db.myInventory.iter()]
        .map((row) => [row.itemKey, row.quantity.toString()])
        .sort(),
      inventorySnapshot,
    );
    assert.deepEqual(
      [...second.db.mySkills.iter()]
        .map((row) => [row.skill, row.experience.toString()])
        .sort(),
      otherSkills,
    );
    assert.equal(
      [...first.db.myTools.iter()].filter((row) => row.equipped).length,
      38,
    );
    command([
      "call",
      database,
      "clear_leaderboards",
      "--server",
      server,
      "--no-config",
    ]);
    await eventually(() => [...first.db.topLeaderboard.iter()].length === 0);
    command([
      "call",
      database,
      "refresh_content",
      "--server",
      server,
      "--no-config",
    ]);
    await first.reducers.setUiScope({
      workspace: "gather",
      panel: "actions",
      page: 1,
    });
    assert.equal(
      [...first.db.topLeaderboard.iter()].length,
      0,
      "content refresh does not restore cleared standings",
    );
    await first.reducers.performAction({
      kind: "gathering_actions",
      key: "mining_1",
    });
    await eventually(() => [...first.db.topLeaderboard.iter()].length === 1);
    assert.ok([...first.db.topLeaderboard.iter()][0].owner.equals(resetOwner));
    assert.ok([...first.db.myAchievements.iter()].length > 0);
    assert.ok([...second.db.myAchievements.iter()].length > 0);
    const identitiesBefore = [first, second].map((conn) => {
      const row = [...conn.db.myPlayer.iter()][0];
      return [
        row.owner.toHexString(),
        row.name,
        row.appearance,
        row.characterTitle,
      ];
    });
    await assert.rejects(first.reducers.resetAllProgress({}), /database owner/);
    command([
      "call",
      database,
      "reset_all_progress",
      "--server",
      server,
      "--no-config",
    ]);
    await eventually(() =>
      [first, second].every(
        (conn) =>
          [...conn.db.myPlayer.iter()][0].gold === 0n &&
          [...conn.db.mySkills.iter()].every((row) => row.experience === 0n) &&
          [...conn.db.myAchievements.iter()].length === 0 &&
          [...conn.db.topLeaderboard.iter()].length === 0,
      ),
    );
    for (const conn of [first, second]) {
      const row = [...conn.db.myPlayer.iter()][0];
      assert.equal(row.metrics, "{}");
      assert.equal(row.title, "");
      assert.equal(row.nextActionAt, 0n);
      assert.equal([...conn.db.myResults.iter()].length, 0);
      assert.equal([...conn.db.myContracts.iter()].length, 0);
      assert.equal([...conn.db.mySkills.iter()].length, 38);
    }
    assert.deepEqual(
      [first, second].map((conn) => {
        const row = [...conn.db.myPlayer.iter()][0];
        return [
          row.owner.toHexString(),
          row.name,
          row.appearance,
          row.characterTitle,
        ];
      }),
      identitiesBefore,
    );
    command([
      "call",
      database,
      "refresh_content",
      "--server",
      server,
      "--no-config",
    ]);
    await assert.rejects(
      first.reducers.claimAchievement({ key: "first_steps" }),
      /Complete this achievement/,
    );
    assert.equal([...first.db.topLeaderboard.iter()].length, 0);
    await first.reducers.performAction({
      kind: "gathering_actions",
      key: "mining_1",
    });
    await first.reducers.claimAchievement({ key: "first_steps" });
    assert.equal(
      [...first.db.myAchievements.iter()].length,
      1,
      "achievements can be earned again from new gameplay after reset",
    );
    assert.equal(
      [...second.db.mySkills.iter()].reduce(
        (sum, row) => sum + row.experience,
        0n,
      ),
      0n,
    );
    console.log(
      "PASS: gameplay/privacy/market checks, owner-only individual and global progress reset, cleared gold/XP/achievements/titles/counters/jobs/history, identity preservation, persistent leaderboard clear, and fresh achievement progression.",
    );
  } finally {
    observer?.disconnect();
    first.disconnect();
    second.disconnect();
  }
});
