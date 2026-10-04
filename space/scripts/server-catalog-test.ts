import { execFileSync } from "node:child_process";
import {
  cpSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  writeFileSync,
} from "node:fs";
import { join } from "node:path";
import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/evergather";
import { definitionRowsFor, definitionTables } from "../src/evergather/catalog";
import { readCatalog, presentEvergather } from "../src/evergather/presentation";

const cli = `${process.env.LOCALAPPDATA}/SpacetimeDB/bin/current/spacetimedb-cli.exe`;
const server = "http://127.0.0.1:3100";
const database = `space-evergather-catalog-checks-${Date.now()}`;
function command(args: string[]) {
  return execFileSync(cli, [...args, "--server", server, "--no-config"], {
    encoding: "utf8",
    windowsHide: true,
    stdio: "pipe",
  });
}
command(["publish", database, "--module-path", "spacetimedb", "--yes"]);
command(["call", database, "configure_local_play", "true"]);
const feeds = [
  "core_definitions",
  "gathering_definitions",
  "activity_definitions",
  "recipe_definitions",
  "equipment_definitions",
  "job_definitions",
  "expedition_definitions",
  "shop_definitions",
  "achievement_definitions",
];
const live = [
  "my_player",
  "my_skills",
  "my_inventory",
  "my_tools",
  "my_results",
  "my_contracts",
  "my_achievements",
  "my_reference_catalog",
  "my_market_listings",
];
const clients: DbConnection[] = [];
async function connect(token?: string, definitions = feeds) {
  return new Promise<DbConnection>((resolve, reject) => {
    const conn = DbConnection.builder()
      .withUri("ws://127.0.0.1:3100")
      .withDatabaseName(database)
      .withToken(token)
      .onConnect((conn) =>
        conn
          .subscriptionBuilder()
          .onApplied(() => resolve(conn))
          .onError(reject)
          .subscribe(
            [...definitions, ...live].map((name) => `SELECT * FROM ${name}`),
          ),
      )
      .onConnectError((_ctx, error) => reject(error))
      .build();
    clients.push(conn);
  });
}
async function eventually(check: () => boolean) {
  const deadline = Date.now() + 5000;
  while (!check()) {
    if (Date.now() > deadline) throw Error("State did not synchronize");
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
}
const first = await connect();
const second = await connect(undefined, ["core_definitions"]);
const quantity = (key: string) =>
  [...first.db.myInventory.iter()].find((row) => row.itemKey === key)
    ?.quantity ?? 0n;
const hasReference = (conn: DbConnection, key: string) =>
  [...conn.db.myReferenceCatalog.iter()].some((row) => row.key === key);
let sharedEvents = 0,
  referenceEvents = 0;
try {
  await first.reducers.createCharacter({ name: "Catalog artisan" });
  await second.reducers.createCharacter({ name: "Catalog buyer" });
  assert.equal(
    [...first.db.recipeDefinitions.iter()].filter(
      (row) => row.kind === "crafting_recipes",
    ).length,
    570,
  );
  assert.equal([...first.db.mySkills.iter()].length, 38);
  for (const name of definitionTables.filter(
    (name) => name !== "myReferenceCatalog",
  )) {
    first.db[name].onInsert(() => sharedEvents++);
    first.db[name].onUpdate(() => sharedEvents++);
    first.db[name].onDelete(() => sharedEvents++);
  }
  first.db.myReferenceCatalog.onInsert(() => referenceEvents++);
  first.db.myReferenceCatalog.onUpdate(() => referenceEvents++);
  first.db.myReferenceCatalog.onDelete(() => referenceEvents++);
  // Keep this exact definition object throughout the actions. Only live rows
  // are replaced, proving eligibility does not depend on catalog reloads.
  const definitions = readCatalog(definitionRowsFor(first));
  const view = () =>
    presentEvergather(
      {
        player: [...first.db.myPlayer.iter()][0],
        skills: [...first.db.mySkills.iter()],
        inventory: [...first.db.myInventory.iter()],
        tools: [...first.db.myTools.iter()],
        results: [...first.db.myResults.iter()],
        contracts: [...first.db.myContracts.iter()],
        claims: [...first.db.myAchievements.iter()],
        listings: [],
        leaders: [],
        trades: [],
      },
      definitions,
    );
  const recipe = () =>
    view().crafting_recipes.find(
      (row) => row.key === "smelting_candlemark_ingot",
    )!;
  assert.equal(recipe().can_craft, false);
  const beforeRejectedCraft = referenceEvents;
  await assert.rejects(
    first.reducers.craft({ key: "smelting_candlemark_ingot" }),
    /Not enough/,
  );
  assert.equal(
    referenceEvents,
    beforeRejectedCraft,
    "failed actions leave definition membership intact",
  );
  for (const conn of [first, second])
    for (const row of conn.db.mySkills.iter())
      await conn.reducers.claimAchievement({
        key: `skill_milestone_${row.skill}_1`,
      });
  assert.ok(hasReference(first, "achievements:skill_milestone_fishing_1"));
  assert.equal(hasReference(second, "achievements:first_steps"), false);
  await first.reducers.buyShopOffer({ key: "bundle_ore_crate" });
  assert.equal(recipe().can_craft, true);
  assert.ok(hasReference(first, "item_metadata:iron_ore:common"));
  assert.equal(
    hasReference(second, "item_metadata:iron_ore:common"),
    false,
    "definition membership is private",
  );
  const beforeQuantity = referenceEvents;
  await first.reducers.buyShopOffer({ key: "bundle_ore_crate" });
  assert.equal(
    referenceEvents,
    beforeQuantity,
    "another quantity of the same item sends no definition changes",
  );
  assert.equal(quantity("iron_ore"), 12n);
  for (let index = 0; index < 5; index++)
    await first.reducers.craft({ key: "smelting_candlemark_ingot" });
  assert.equal(recipe().can_craft, true);
  await first.reducers.craft({ key: "smelting_candlemark_ingot" });
  assert.equal(
    recipe().can_craft,
    false,
    "spending the last ingredients updates eligibility against cached recipes",
  );
  assert.equal(hasReference(first, "item_metadata:iron_ore:common"), false);
  assert.ok(
    hasReference(first, "item_metadata:smelting_candlemark_ingot:common"),
  );

  const expedition = () =>
    view().expeditions.find((row) => row.key === "combat_starter_expedition")!;
  assert.equal(expedition().can_start, false);
  await first.reducers.buyShopOffer({ key: "bundle_ore_crate" });
  await first.reducers.craft({ key: "smithing_candlemark_armament" });
  assert.equal(expedition().can_start, true);
  await first.reducers.runExpedition({ key: "combat_starter_expedition" });
  assert.equal(expedition().can_start, false);
  assert.ok(
    hasReference(
      first,
      "item_metadata:expedition_combat_tier_1_combat_badge:common",
    ),
  );
  assert.equal(
    hasReference(first, "item_metadata:smithing_candlemark_armament:common"),
    false,
  );

  await first.reducers.buyShopOffer({ key: "bundle_fishers_icebox" });
  const job = () =>
    view().jobs.find((row) => row.key === "fishing_starter_contract")!;
  assert.equal(job().can_complete, true);
  await first.reducers.completeJob({ key: "fishing_starter_contract" });
  assert.equal(job().completed_in_rotation, 1);
  assert.equal(job().completion_cap, null);

  while (quantity("ashwood_log") < 10n)
    await first.reducers.performAction({
      kind: "gathering_actions",
      key: "chop",
    });
  for (let index = 0; index < 4; index++)
    await first.reducers.craft({ key: "milling_candlemark_board" });
  const rod = [...first.db.myTools.iter()].find(
    (row) => row.skill === "fishing" && row.equipped,
  )!;
  await first.reducers.upgradeToolTier({ id: rod.id });
  assert.ok(
    hasReference(first, "item_metadata:candlemark_tidehook_rod:common"),
  );
  assert.equal(
    [...first.db.myTools.iter()].find((row) => row.id === rod.id)!.tierLevel,
    1,
  );
  // Warm all possible fishing drops first, then wear only the same tool.
  while (!quantity("silver_scale"))
    await first.reducers.performAction({
      kind: "gathering_actions",
      key: "fish",
    });
  const beforeWear = referenceEvents;
  const beforeDurability = [...first.db.myTools.iter()].find(
    (row) => row.id === rod.id,
  )!.durability;
  await first.reducers.performAction({
    kind: "gathering_actions",
    key: "fish",
  });
  assert.equal(
    referenceEvents,
    beforeWear,
    "quantity and durability changes do not reload descriptions",
  );
  assert.equal(
    [...first.db.myTools.iter()].find((row) => row.id === rod.id)!.durability,
    beforeDurability - 1,
  );
  await first.reducers.repairTool({ id: rod.id });
  assert.equal(
    [...first.db.myTools.iter()].find((row) => row.id === rod.id)!.durability,
    [...first.db.myTools.iter()].find((row) => row.id === rod.id)!
      .maxDurability,
  );
  await first.reducers.claimAchievement({ key: "first_steps" });
  assert.ok(hasReference(first, "achievements:first_steps"));
  assert.equal(hasReference(second, "achievements:first_steps"), false);

  await first.reducers.equipTool({ id: rod.id, equipped: false });
  const stored = [...first.db.myTools.iter()].find((row) => row.id === rod.id)!;
  await first.reducers.listTool({
    id: rod.id,
    unitPrice: stored.marketFloorPrice,
  });
  const offer = [...first.db.myMarketListings.iter()].find(
    (row) => row.toolId === rod.id,
  )!;
  await second.reducers.buyListing({ id: offer.id });
  await eventually(
    () => !hasReference(first, "item_metadata:candlemark_tidehook_rod:common"),
  );
  assert.ok(
    hasReference(second, "item_metadata:candlemark_tidehook_rod:common"),
    "tool transfer refreshes both owners' membership",
  );
  assert.ok(![...first.db.myTools.iter()].some((row) => row.id === rod.id));
  await second.reducers.equipTool({ id: rod.id, equipped: true });
  await second.reducers.removeTool({ id: rod.id, salvage: true });
  assert.equal(
    hasReference(second, "item_metadata:candlemark_tidehook_rod:common"),
    false,
  );
  assert.ok(
    hasReference(second, "item_metadata:milling_candlemark_board:common"),
  );
  assert.equal(
    sharedEvents,
    0,
    "all game systems keep shared definitions unchanged during gameplay",
  );

  const beforeRefresh = [...first.db.myInventory.iter()]
    .map((row) => [row.key, row.quantity.toString()])
    .sort();
  command(["call", database, "refresh_content"]);
  await first.reducers.setUiScope({
    workspace: "craft",
    panel: "recipes",
    page: 1,
  });
  assert.deepEqual(
    [...first.db.myInventory.iter()]
      .map((row) => [row.key, row.quantity.toString()])
      .sort(),
    beforeRefresh,
  );
  // Publish changed content only into this fresh fixture. It exercises actual
  // catalog invalidation and newly referenced metadata without a test reducer.
  mkdirSync(".runtime", { recursive: true });
  const fixtureModule = mkdtempSync(join(".runtime", "catalog-content-"));
  cpSync("spacetimedb/src", join(fixtureModule, "src"), { recursive: true });
  for (const file of ["package.json", "tsconfig.json"])
    cpSync(join("spacetimedb", file), join(fixtureModule, file));
  const contentPath = join(fixtureModule, "src/content/evergather.json");
  const updated = JSON.parse(readFileSync(contentPath, "utf8"));
  updated.skills.fishing.label = "Fishing content refresh check";
  updated.crafting_recipes.smelting_candlemark_ingot.ingredients[0].item_key =
    "catalog_content_check";
  updated.item_metadata["catalog_content_check:common"] = {
    ...updated.item_metadata["iron_ore:common"],
    item_key: "catalog_content_check",
    item_name: "Catalog content check",
  };
  writeFileSync(contentPath, JSON.stringify(updated));
  const cache = new Map();
  type SkillCatalog = { skills: Record<string, { label: string }> };
  const cachedBefore = readCatalog(
    definitionRowsFor(first),
    cache,
  ) as SkillCatalog;
  command(["publish", database, "--module-path", fixtureModule, "--yes"]);
  command(["call", database, "refresh_content"]);
  await eventually(
    () =>
      first.db.coreDefinitions.key.find("skills:fishing")?.label ===
      "Fishing content refresh check",
  );
  assert.ok(
    first.db.recipeDefinitions.key.find(
      "item_metadata:catalog_content_check:common",
    ),
    "new references come from the refreshed catalog payload",
  );
  const cachedAfter = readCatalog(
    definitionRowsFor(first),
    cache,
  ) as SkillCatalog;
  assert.equal(
    cachedAfter.skills.fishing.label,
    "Fishing content refresh check",
  );
  assert.strictEqual(
    cachedAfter.skills.mining,
    cachedBefore.skills.mining,
    "unchanged payloads retain their parsed cache objects",
  );
  assert.deepEqual(
    [...first.db.myInventory.iter()]
      .map((row) => [row.key, row.quantity.toString()])
      .sort(),
    beforeRefresh,
  );
  console.log(
    "PASS: whole-game cached eligibility, static feeds, quantity and durability isolation, zero-quantity membership, recipe/expedition/job rewards, tier upgrades, repair, achievements, private membership, both sides of tool ownership transfers, salvage, non-destructive content refresh and changed content/cache invalidation.",
  );
} finally {
  for (const conn of clients) conn.disconnect();
}
