import { runTemporaryDatabaseTest } from "./temporary-database";
import { execFileSync } from "node:child_process";
import { cpSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import assert from "node:assert/strict";
import { DbConnection } from "../src/bindings/evergather";
import content from "../spacetimedb/src/content/evergather.json";
import { definitionRowsFor, definitionTables } from "../src/evergather/catalog";
import { readCatalog, presentEvergather } from "../src/evergather/presentation";
import { sourcesForMaterial } from "../src/evergather/materialSources";
import { xpForLevel, levelForXp } from "../spacetimedb/src/rules";

const cli = `${process.env.LOCALAPPDATA}/SpacetimeDB/bin/current/spacetimedb-cli.exe`;
const server = "http://127.0.0.1:3100";
const database = `space-evergather-economy-checks-${Date.now()}`;
await runTemporaryDatabaseTest(database, async (scope) => {
  function command(args: string[]) {
    return execFileSync(cli, [...args, "--server", server, "--no-config"], {
      encoding: "utf8",
      windowsHide: true,
      maxBuffer: 32 * 1024 * 1024,
      stdio: "pipe",
    });
  }
  mkdirSync(".runtime", { recursive: true });
  const fixture = scope.fixture("economy-checks-module-");
  cpSync("spacetimedb/src", join(fixture, "src"), { recursive: true });
  for (const file of ["package.json", "tsconfig.json"])
    cpSync(join("spacetimedb", file), join(fixture, file));
  // Test setup exists ONLY in this ignored fixture module, never in production.
  const testSetup = `
import {xpForLevel as fixtureXp} from './rules';
export const testSeed = db.reducer({name:t.string(),level:t.u32(),materials:t.bool()},(ctx,{name,level,materials})=>{
 const current=character(ctx);
 const names=name?[name]:Object.keys(content.skills);
 for(const skillName of names){const row=ctx.db.skill.key.find(ownerKey(ctx,skillName))!;ctx.db.skill.key.update({...row,experience:BigInt(fixtureXp(level))});}
 if(materials)for(const value of Object.values(content.item_metadata))if(value.rarity==='common' && value.item_class!=='tool')grantItem(ctx,{item_key:value.item_key!,item_name:value.item_name!,rarity:'common',quantity:100});
 ctx.db.player.owner.update({...current,gold:500000n});syncCatalogReferences(ctx);refreshLeaderboard(ctx,ctx.sender);
});
export const testDamage = db.reducer({id:t.u64()},(ctx,{id})=>{const row=ownedTool(ctx,id);ctx.db.tool.id.update({...row,durability:row.maxDurability-35});});
export const testEmpty = db.reducer({itemKey:t.string()},(ctx,{itemKey})=>{const row=ctx.db.inventory.key.find(ownerKey(ctx,itemKey))!;ctx.db.inventory.key.update({...row,quantity:0n});syncCatalogReferences(ctx);});
export const testOldVersion = db.reducer((ctx)=>{if(!ctx.db.security.id.find(1)!.owner.equals(ctx.sender))throw new SenderError('Owner only');const row=ctx.db.economyState.id.find(1)!;ctx.db.economyState.id.update({...row,version:'old-fixture'});});
`;
  writeFileSync(
    join(fixture, "src/index.ts"),
    readFileSync(join(fixture, "src/index.ts"), "utf8") + testSetup,
  );
  command(["publish", database, "--module-path", fixture, "--yes"]);
  command(["call", database, "configure_local_play", "true"]);
  const tokens = new Map<DbConnection, string>();
  const clients: DbConnection[] = [];
  async function connect(definitions = true) {
    return new Promise<DbConnection>((resolve, reject) => {
      const conn = DbConnection.builder()
        .withUri("ws://127.0.0.1:3100")
        .withDatabaseName(database)
        .onConnect((conn, _identity, token) => {
          tokens.set(conn, token);
          conn
            .subscriptionBuilder()
            .onApplied(() => resolve(conn))
            .onError(reject)
            .subscribe(
              [
                "my_player",
                "my_skills",
                "my_inventory",
                "my_tools",
                "my_results",
                "my_contracts",
                "my_achievements",
                "my_reference_catalog",
                "my_market_listings",
                "my_buffs",
                ...(definitions
                  ? [
                      "core_definitions",
                      "gathering_definitions",
                      "activity_definitions",
                      "recipe_definitions",
                      "equipment_definitions",
                      "job_definitions",
                      "expedition_definitions",
                      "shop_definitions",
                      "achievement_definitions",
                    ]
                  : []),
              ].map((name) => `SELECT * FROM ${name}`),
            );
        })
        .onConnectError((_ctx, error) => reject(error))
        .build();
      clients.push(conn);
    });
  }
  async function eventually(check: () => boolean) {
    const end = Date.now() + 5000;
    while (!check()) {
      if (Date.now() > end) throw Error("Subscription did not synchronize");
      await new Promise((resolve) => setTimeout(resolve, 10));
    }
  }
  async function setup(conn: DbConnection, reducer: string, args: unknown[]) {
    const response = await fetch(
      `${server}/v1/database/${database}/call/${reducer}`,
      {
        method: "POST",
        headers: {
          Authorization: `Bearer ${tokens.get(conn)}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify(args),
      },
    );
    if (!response.ok) throw Error(`${reducer}: ${await response.text()}`);
  }
  const first = await connect();
  const second = await connect(false);
  const quantity = (key: string, conn = first) =>
    [...conn.db.myInventory.iter()].find((row) => row.itemKey === key)
      ?.quantity ?? 0n;
  const skillLevel = (name: string, conn = first) =>
    [...conn.db.mySkills.iter()].find((row) => row.skill === name)!.experience;
  const hasReference = (key: string, conn = first) =>
    Boolean(conn.db.myReferenceCatalog.key.find(key));
  const equipped = (name: string, conn = first) =>
    [...conn.db.myTools.iter()].find(
      (row) => row.skill === name && row.equipped,
    )!;
  try {
    await first.reducers.createCharacter({ name: "Economy artisan" });
    await second.reducers.createCharacter({ name: "Economy buyer" });
    assert.equal([...first.db.myTools.iter()].length, 38);
    const definitions = readCatalog(definitionRowsFor(first));
    const view = () =>
      presentEvergather(
        {
          player: [...first.db.myPlayer.iter()][0],
          skills: [...first.db.mySkills.iter()],
          inventory: [...first.db.myInventory.iter()],
          tools: [...first.db.myTools.iter()],
          buffs: [...first.db.myBuffs.iter()],
          results: [...first.db.myResults.iter()],
          contracts: [...first.db.myContracts.iter()],
          claims: [...first.db.myAchievements.iter()],
          listings: [],
          leaders: [],
          trades: [],
        },
        definitions,
      );
    assert.equal(view().crafting_recipes.length, 650);
    await assert.rejects(
      first.reducers.craft({ key: "smelting_1" }),
      /Level 10/,
    );
    await assert.rejects(
      first.reducers.performAction({
        kind: "gathering_actions",
        key: "mining_2",
      }),
      /Level 20/,
    );
    for (const name of Object.keys(content.skills)) {
      while (skillLevel(name) < BigInt(xpForLevel(10)))
        await first.reducers.performAction({
          kind: "skill_activities",
          key: `training_${name}`,
        });
      assert.equal(levelForXp(Number(skillLevel(name))), 10);
      await assert.rejects(
        first.reducers.performAction({
          kind: "skill_activities",
          key: `training_${name}`,
        }),
        /training ends/,
      );
    }
    assert.ok(
      [...first.db.myInventory.iter()].every((row) =>
        [
          "mining_1",
          "fishing_1",
          "woodcutting_1",
          "foraging_1",
          "hunting_1",
          "farming_1",
          "excavation_1",
        ].includes(row.itemKey),
      ),
      "training never bypasses processing",
    );
    await first.reducers.craft({ key: "survival_1" });
    await first.reducers.craft({ key: "smelting_1" });
    assert.equal(quantity("smelting_1"), 1n);
    assert.equal(
      [...second.db.myInventory.iter()].length,
      0,
      "private stacks remain isolated",
    );
    await setup(first, "test_seed", ["", 100, true]);
    await eventually(() => skillLevel("smithing") === 170000n);
    let sharedEvents = 0;
    for (const name of definitionTables.filter(
      (name) => name !== "myReferenceCatalog",
    )) {
      first.db[name].onInsert(() => sharedEvents++);
      first.db[name].onUpdate(() => sharedEvents++);
      first.db[name].onDelete(() => sharedEvents++);
    }
    // All 380 assembly recipes and destination skill gates work with real reducers.
    for (const tier of content.tiers)
      for (const name of Object.keys(content.skills)) {
        await first.reducers.craft({ key: `tool_${name}_${tier.tier}` });
        assert.equal(equipped(name).tierLevel, tier.level);
      }
    assert.equal([...first.db.myTools.iter()].length, 418);
    // A profession can craft a tradeable tool before its owner can equip it.
    await setup(first, "test_seed", ["fishing", 1, false]);
    await eventually(() => skillLevel("fishing") === 0n);
    const previous = equipped("fishing").id;
    await first.reducers.craft({ key: "tool_fishing_10" });
    const crafted = [...first.db.myTools.iter()]
      .filter((row) => row.itemKey === "tool_fishing_10")
      .sort((a, b) => Number(b.id - a.id))[0];
    assert.equal(crafted.equipped, false);
    assert.equal(equipped("fishing").id, previous);
    await assert.rejects(
      first.reducers.equipTool({ id: crafted.id, equipped: true }),
      /Level 100/,
    );
    await setup(first, "test_seed", ["fishing", 100, false]);
    await eventually(() => skillLevel("fishing") === 170000n);
    // Tier upgrade gate checks destination AND assembly professions.
    const rod = [...first.db.myTools.iter()].find(
      (row) => row.itemKey === "tool_fishing_1",
    )!;
    await first.reducers.equipTool({ id: rod.id, equipped: true });
    await setup(first, "test_seed", ["fishing", 10, false]);
    await eventually(() => skillLevel("fishing") === 1100n);
    await assert.rejects(
      first.reducers.upgradeToolTier({ id: rod.id }),
      /Level 20 fishing/,
    );
    await setup(first, "test_seed", ["fishing", 100, false]);
    await eventually(() => skillLevel("fishing") === 170000n);
    await first.reducers.upgradeToolTier({ id: rod.id });
    assert.equal(equipped("fishing").tierLevel, 20);
    const rarity = view().tool_rarity_upgrades.options.find(
      (row: { slot: string }) => row.slot === "tool_fishing",
    )!;
    assert.deepEqual(
      rarity.materials.map((row: { item_key: string }) => row.item_key),
      ["alchemy_2", "enchanting_2", "dungeoneering_2", "reputation_2"],
    );
    const permitSources = sourcesForMaterial(
      "reputation_2",
      {
        jobs: view().jobs,
        recipes: view().crafting_recipes,
        activities: view().skill_activities,
      },
      view().player,
    );
    assert.ok(
      permitSources.some((row) => row.action === "job" && row.ready),
      "rarity permits have an actionable commission source",
    );
    const mats = content.rarity_materials_by_tier["2"];
    const beforeRarity = mats.map((item) => quantity(item.item_key));
    await first.reducers.upgradeToolRarity({ id: rod.id });
    mats.forEach((item, index) =>
      assert.equal(quantity(item.item_key), beforeRarity[index] - 1n),
    );
    for (
      let attempts = 0;
      equipped("fishing").rarity === "common" && attempts < 30;
      attempts++
    )
      await first.reducers.upgradeToolRarity({ id: rod.id });
    assert.equal(equipped("fishing").rarity, "uncommon");
    await assert.rejects(
      first.reducers.upgradeToolRarity({ id: rod.id }),
      /Tier up/,
    );
    // Repairs consume exactly the stated component; starters use only gold.
    await setup(first, "test_damage", [Number(rod.id)]);
    await eventually(
      () => equipped("fishing").durability < equipped("fishing").maxDurability,
    );
    const plankBefore = quantity("carpentry_2");
    await first.reducers.repairTool({ id: rod.id });
    assert.equal(quantity("carpentry_2"), plankBefore - 1n);
    const starter = [...first.db.myTools.iter()].find(
      (row) => row.origin === "starter" && row.skill === "smelting",
    )!;
    await setup(first, "test_damage", [Number(starter.id)]);
    await eventually(
      () => first.db.myTools.id.find(starter.id)!.durability === 65,
    );
    const inventoryBefore = [...first.db.myInventory.iter()]
      .map((row) => [row.itemKey, row.quantity.toString()])
      .sort();
    await first.reducers.repairTool({ id: starter.id });
    assert.deepEqual(
      [...first.db.myInventory.iter()]
        .map((row) => [row.itemKey, row.quantity.toString()])
        .sort(),
      inventoryBefore,
    );
    // Activity supplies and failure rollback, including buff charges.
    await first.reducers.useConsumable({ itemKey: "cooking_1" });
    await first.reducers.useConsumable({ itemKey: "tonic_1" });
    assert.equal([...first.db.myBuffs.iter()].length, 2);
    assert.equal([...second.db.myBuffs.iter()].length, 0);
    const bandages = quantity("bandage_1");
    await first.reducers.performAction({
      kind: "skill_activities",
      key: "healing_1",
    });
    assert.equal(quantity("bandage_1"), bandages - 1n);
    assert.ok([...first.db.myBuffs.iter()].every((row) => row.charges === 9));
    await setup(first, "test_empty", ["bandage_1"]);
    await eventually(() => quantity("bandage_1") === 0n);
    const charges = [...first.db.myBuffs.iter()].map((row) => row.charges);
    await assert.rejects(
      first.reducers.performAction({
        kind: "skill_activities",
        key: "healing_1",
      }),
      /Not enough/,
    );
    assert.deepEqual(
      [...first.db.myBuffs.iter()].map((row) => row.charges),
      charges,
    );
    await first.reducers.useConsumable({ itemKey: "cooking_2" });
    assert.equal(
      [...first.db.myBuffs.iter()].find((row) => row.family === "food")!
        .charges,
      10,
    );
    await first.reducers.useConsumable({ itemKey: "arrows_1" });
    await first.reducers.performAction({
      kind: "gathering_actions",
      key: "mining_1",
    });
    assert.equal(
      [...first.db.myBuffs.iter()].find((row) => row.family === "arrows")!
        .charges,
      10,
    );
    await first.reducers.performAction({
      kind: "skill_activities",
      key: "ranged_1",
    });
    assert.equal(
      [...first.db.myBuffs.iter()].find((row) => row.family === "arrows")!
        .charges,
      9,
    );
    for (let count = 0; count < 10; count++)
      await first.reducers.performAction({
        kind: "gathering_actions",
        key: "mining_1",
      });
    assert.ok(
      ![...first.db.myBuffs.iter()].some((row) =>
        ["food", "tonic"].includes(row.family),
      ),
      "charges expire after successful eligible actions",
    );
    // Real item rewards from commissions; provisions consumed by expeditions.
    const permitBefore = quantity("reputation_1");
    const crateBefore = quantity("trading_1");
    await first.reducers.completeJob({ key: "reputation_1" });
    assert.equal(quantity("reputation_1"), permitBefore + 1n);
    assert.equal(quantity("trading_1"), crateBefore - 1n);
    for (let count = 0; count < 4; count++)
      await first.reducers.completeJob({ key: "delivery_1" });
    assert.equal(
      [...first.db.myContracts.iter()].find(
        (row) => row.jobKey === "delivery_1",
      )!.completions,
      4,
    );
    const orders = quantity("leadership_1"),
      rations = quantity("cooking_1");
    await first.reducers.runExpedition({ key: "expedition_1" });
    assert.equal(quantity("leadership_1"), orders - 1n);
    assert.equal(quantity("cooking_1"), rations - 2n);
    // Stored tools may be traded, but buying never bypasses equip gates.
    await setup(second, "test_seed", ["", 1, false]);
    await eventually(() => [...second.db.myPlayer.iter()][0].gold === 500000n);
    await first.reducers.equipTool({ id: rod.id, equipped: false });
    const stored = first.db.myTools.id.find(rod.id)!;
    await first.reducers.listTool({
      id: rod.id,
      unitPrice: stored.marketFloorPrice,
    });
    const listing = [...first.db.myMarketListings.iter()].find(
      (row) => row.toolId === rod.id,
    )!;
    await second.reducers.buyListing({ id: listing.id });
    await eventually(() => Boolean(second.db.myTools.id.find(rod.id)));
    await assert.rejects(
      second.reducers.equipTool({ id: rod.id, equipped: true }),
      /Level 20/,
    );
    assert.ok(hasReference("item_metadata:tool_fishing_2:uncommon", second));
    const salvageBefore = quantity("carpentry_2", second);
    await second.reducers.removeTool({ id: rod.id, salvage: true });
    assert.equal(quantity("carpentry_2", second), salvageBefore + 1n);
    assert.equal(
      sharedEvents,
      0,
      "gameplay does not resend static definitions",
    );
    // Publishing and refreshing preserve possessions; only explicit versioned reset clears them.
    const savedSkills = [...first.db.mySkills.iter()]
      .map((row) => [row.skill, row.experience.toString()])
      .sort();
    const savedPlayer = [...first.db.myPlayer.iter()][0];
    const stacksBeforeRefresh = [...first.db.myInventory.iter()]
      .map((row) => [row.itemKey, row.quantity.toString()])
      .sort();
    command(["call", database, "refresh_content"]);
    await eventually(
      () =>
        first.db.myPlayer.owner.find(savedPlayer.owner)?.gold ===
        savedPlayer.gold,
    );
    assert.deepEqual(
      [...first.db.myInventory.iter()]
        .map((row) => [row.itemKey, row.quantity.toString()])
        .sort(),
      stacksBeforeRefresh,
    );
    await assert.rejects(
      second.reducers.resetItemEconomy({ version: "ten-tier-v1" }),
      /database owner/,
    );
    assert.throws(
      () => command(["call", database, "reset_item_economy", '"ten-tier-v1"']),
      /Command failed/,
    );
    command(["call", database, "test_old_version"]);
    command(["call", database, "reset_item_economy", '"ten-tier-v1"']);
    await eventually(
      () =>
        [...first.db.myTools.iter()].length === 38 &&
        [...first.db.myInventory.iter()].length === 0,
    );
    assert.ok(
      [...first.db.myTools.iter()].every(
        (row) => row.origin === "starter" && row.equipped,
      ),
    );
    assert.equal([...first.db.myBuffs.iter()].length, 0);
    assert.equal([...first.db.myContracts.iter()].length, 0);
    assert.deepEqual(
      [...first.db.mySkills.iter()]
        .map((row) => [row.skill, row.experience.toString()])
        .sort(),
      savedSkills,
    );
    assert.equal([...first.db.myPlayer.iter()][0].gold, savedPlayer.gold);
    assert.equal([...first.db.myPlayer.iter()][0].name, savedPlayer.name);
    assert.throws(
      () => command(["call", database, "reset_item_economy", '"ten-tier-v1"']),
      /Command failed/,
    );
    // Level reset stores advanced gear, removes active buffs, and equips starters.
    await setup(first, "test_seed", ["", 20, true]);
    await first.reducers.craft({ key: "tool_fishing_2" });
    const advanced = equipped("fishing");
    assert.equal(advanced.tierLevel, 20);
    await first.reducers.useConsumable({ itemKey: "cooking_1" });
    assert.ok([...first.db.myBuffs.iter()].length > 0);
    const beforeLevels = [...first.db.myPlayer.iter()][0];
    const retainedStacks = [...first.db.myInventory.iter()]
      .map((row) => [row.itemKey, row.quantity.toString()])
      .sort();
    command([
      "call",
      database,
      "reset_player_levels",
      JSON.stringify(beforeLevels.owner.toHexString()),
    ]);
    await eventually(() =>
      [...first.db.mySkills.iter()].every((row) => row.experience === 0n),
    );
    assert.equal([...first.db.myPlayer.iter()][0].gold, beforeLevels.gold);
    assert.deepEqual(
      [...first.db.myInventory.iter()]
        .map((row) => [row.itemKey, row.quantity.toString()])
        .sort(),
      retainedStacks,
    );
    assert.equal(first.db.myTools.id.find(advanced.id)?.equipped, false);
    assert.equal(equipped("fishing").tierLevel, 0);
    assert.equal(
      [...first.db.myTools.iter()].filter((row) => row.equipped).length,
      38,
    );
    assert.equal([...first.db.myBuffs.iter()].length, 0);
    console.log(
      "PASS: all 38 real starter training paths, 380 equipment assemblies, destination/crafter gates, rarity commission chains and caps, exact repairs, starter gold-only repairs, supplies, buff isolation/charges/expiry/replacement/rollback, unlimited deliveries, expeditions, traded-tool gates, salvage, static definition feeds, non-destructive refresh, owner-only idempotent reset preserving characters/XP/gold.",
    );
  } finally {
    for (const conn of clients) conn.disconnect();
  }
});
