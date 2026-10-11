import { expect, it } from "vitest";
import c from "../spacetimedb/src/content/evergather.json";
import { referencedItems } from "../spacetimedb/src/performance";
import { maxDurability, tierLevels } from "../spacetimedb/src/rules";
import { toolPrices } from "../spacetimedb/src/market";

const metadata = Object.values(c.item_metadata);
const identities = new Set(metadata.map((item) => item.item_key));
const production = [
  ...Object.values(c.crafting_recipes),
  ...Object.values(c.skill_activities),
  ...Object.values(c.gathering_actions),
  ...Object.values(c.job_contracts),
  ...Object.values(c.expeditions),
];

it("ships exactly ten primary resources and equipment upgrades for every skill", () => {
  expect(Object.keys(c.skills)).toHaveLength(38);
  expect(identities.size).toBe(838);
  expect(Object.keys(c.consumables)).toHaveLength(30);
  for (const name of Object.keys(c.skills)) {
    for (let tier = 1; tier <= 10; tier++) {
      expect(identities.has(`${name}_${tier}`)).toBe(true);
      expect(identities.has(`tool_${name}_${tier}`)).toBe(true);
      const upgrade =
        c.tool_upgrades[`${name}:${tier * 10}` as keyof typeof c.tool_upgrades];
      expect(upgrade.required_level).toBe(tier * 10);
      expect(upgrade.output.equipment_skill).toBe(name);
    }
    expect(c.starter_tools[name as keyof typeof c.starter_tools].item_key).toBe(
      `starter_${name}`,
    );
    const intro =
      c.skill_activities[`training_${name}` as keyof typeof c.skill_activities];
    expect(intro.required_level).toBe(1);
    expect("max_level" in intro && intro.max_level).toBe(9);
  }
});

it("has no retired references, higher-tier ingredient requirements or unreachable materials", () => {
  for (const producer of production) {
    for (const key of referencedItems(producer))
      expect(identities.has(key), `${producer.label}: ${key}`).toBe(true);
    const inputs =
      "ingredients" in producer
        ? producer.ingredients
        : "requirements" in producer
          ? producer.requirements
          : "supplies" in producer
            ? producer.supplies
            : [];
    for (const input of inputs)
      expect(input.item_tier).toBeLessThanOrEqual(
        Math.max(1, Math.floor(producer.required_level / 10)),
      );
  }
  const reachable = new Set(
    Object.values(c.starter_tools).map((item) => item.item_key),
  );
  for (let pass = 0; pass < 50; pass++)
    for (const producer of production) {
      const inputs =
        "ingredients" in producer
          ? producer.ingredients
          : "requirements" in producer
            ? producer.requirements
            : "supplies" in producer
              ? producer.supplies
              : [];
      const outputs =
        "outputs" in producer
          ? producer.outputs
          : "rewards" in producer
            ? producer.rewards
            : "loot" in producer
              ? producer.loot
              : [];
      if (inputs.every((item) => reachable.has(item.item_key)))
        for (const output of outputs) reachable.add(output.item_key);
    }
  expect([...identities].filter((key) => !reachable.has(key))).toEqual([]);
});

it("gives every material a real gameplay consumer", () => {
  const consumed = new Set<string>();
  for (const producer of production) {
    const inputs =
      "ingredients" in producer
        ? producer.ingredients
        : "requirements" in producer
          ? producer.requirements
          : "supplies" in producer
            ? producer.supplies
            : [];
    inputs.forEach((item) => consumed.add(item.item_key));
  }
  Object.values(c.rarity_materials_by_tier)
    .flat()
    .forEach((item) => consumed.add(item.item_key));
  Object.keys(c.consumables).forEach((key) => consumed.add(key));
  expect(
    metadata.filter(
      (item) =>
        item.rarity === "common" &&
        item.item_class !== "tool" &&
        !consumed.has(item.item_key),
    ),
  ).toEqual([]);
});

it("keeps raw tier one gathering available at one and all production behind ten-level gates", () => {
  for (const action of Object.values(c.gathering_actions))
    expect(action.required_level).toBe(
      action.item_tier === 1 ? 1 : action.item_tier * 10,
    );
  for (const recipe of Object.values(c.crafting_recipes))
    expect(tierLevels).toContain(recipe.required_level);
  for (const upgrade of Object.values(c.tool_upgrades)) {
    expect(upgrade.ingredients.length).toBeGreaterThan(1);
    expect(
      upgrade.ingredients.reduce((sum, item) => sum + item.quantity, 0),
    ).toBeGreaterThan(1);
  }
});

it("cannot turn purchased supplies into guaranteed vendor or delivery profit", () => {
  const offers = Object.values(c.shop_offers);
  const price = (key: string) =>
    offers.find((offer) => offer.item_key === key)!.price;
  for (const recipe of Object.values(c.crafting_recipes)) {
    const cost =
      recipe.gold_cost +
      recipe.ingredients.reduce(
        (sum, item) => sum + price(item.item_key) * item.quantity,
        0,
      );
    let payout = 0;
    for (const output of recipe.outputs) {
      const meta =
        c.item_metadata[
          `${output.item_key}:common` as keyof typeof c.item_metadata
        ];
      payout +=
        ("equipment_skill" in output
          ? toolPrices(
              {
                tierLevel: recipe.required_level,
                durability: maxDurability(recipe.required_level, "common"),
                upgrades: 0,
                tierUpgrades: 0,
                bonuses: JSON.stringify(output.bonuses),
              },
              meta,
              0,
            ).npcBuyPrice
          : meta.npc_buy_price) * output.quantity;
    }
    // The strongest possible material-preservation refund remains unprofitable.
    const refund = Math.max(
      0,
      ...recipe.ingredients
        .filter((item) => item.quantity > 1)
        .map((item) => price(item.item_key)),
    );
    expect(payout + refund, recipe.label).toBeLessThan(cost);
  }
  for (const job of Object.values(c.job_contracts))
    expect(job.gold, job.label).toBeLessThan(
      job.requirements.reduce(
        (sum, item) => sum + price(item.item_key) * item.quantity,
        0,
      ),
    );
});
