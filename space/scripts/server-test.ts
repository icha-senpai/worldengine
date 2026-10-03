import { execFileSync } from "node:child_process";
import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/evergather";

const cli =
  process.env.LOCALAPPDATA + "/SpacetimeDB/bin/current/spacetimedb-cli.exe";
const server = "http://127.0.0.1:3100";
const database = "space-evergather-checks";
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
    key: "fish",
  });
  await eventually(() => [...observer!.db.myInventory.iter()].length > 0);
  assert.deepEqual(
    [...observer.db.myInventory.iter()].map((row) => [
      row.itemKey,
      row.quantity,
    ]),
    [...first.db.myInventory.iter()].map((row) => [row.itemKey, row.quantity]),
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
  await assert.rejects(
    first.reducers.performAction({ kind: "gathering_actions", key: "fish" }),
    /cooling down/,
  );
  const before = [...first.db.myInventory.iter()].map((row) => [
    row.key,
    row.quantity,
  ]);
  await assert.rejects(
    first.reducers.craft({ key: "smelting_candlemark_ingot" }),
    /Not enough/,
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
    (row) => row.itemKey === "river_minnow",
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
  await assert.rejects(
    second.reducers.cancelListing({ id: listing.id }),
    /not yours/,
  );
  await second.reducers.buyListing({ id: listing.id });
  await eventually(() =>
    [...first.db.trade.iter()].some(
      (row) =>
        row.sellerName === "First player" && row.buyerName === "Second player",
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
  await second.reducers.sellToVendor({ itemKey: "river_minnow", quantity: 1 });
  assert.equal(
    [...second.db.myPlayer.iter()][0].gold,
    4n,
    "vendor uses catalog buy price",
  );
  for (const key of [
    "account_level_1",
    "skill_milestone_fishing_1",
    "skill_milestone_mining_1",
    "skill_milestone_foraging_1",
  ])
    await first.reducers.claimAchievement({ key });
  await first.reducers.buyShopOffer({ key: "bundle_fishers_icebox" });
  await first.reducers.completeJob({ key: "fishing_starter_contract" });
  assert.equal([...first.db.myContracts.iter()][0].completions, 1);
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
    undefined,
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
  console.log(
    "PASS: private inventory, 38 skills and tools, gathering/cooldown, transactional craft rejection, caller and owner checks, achievement locking/duplicate claims, market price bands/ownership/tax/double-buy, vendor pricing, job turn-in, claimed titles, and reversible owner-controlled local play.",
  );
} finally {
  observer?.disconnect();
  first.disconnect();
  second.disconnect();
}
