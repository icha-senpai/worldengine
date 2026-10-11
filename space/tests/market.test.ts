import { expect, it } from "vitest";
import { toolPrices } from "../spacetimedb/src/market";
import reference from "./fixtures/legacy-tool-values.json";
import content from "../spacetimedb/src/content/evergather.json";

it("preserves live ICHAA valuation for working, broken, and upgraded tools across all rarities", () => {
  for (const row of reference) {
    expect(
      toolPrices(row.tool, row.metadata, row.rarityMarketPremium),
      `${row.skill}/${row.rarity}/${row.tool.tierLevel}, durability ${row.tool.durability}, upgrades ${row.tool.upgrades}`,
    ).toEqual(row.prices);
  }
});
it("prices current tools across every tier and quality within positive market bounds", () => {
  for (const upgrade of Object.values(content.tool_upgrades))
    for (const rarity of content.rarities) {
      const metadata =
        content.item_metadata[
          `${upgrade.output.item_key}:${rarity}` as keyof typeof content.item_metadata
        ];
      const prices = toolPrices(
        {
          tierLevel: upgrade.required_level,
          durability: 100,
          maxDurability: 100,
          upgrades: 0,
          tierUpgrades: 0,
          bonuses: JSON.stringify(upgrade.bonuses),
        },
        metadata,
        0,
      );
      expect(prices.marketFloorPrice).toBeGreaterThan(0);
      expect(prices.marketCeilingPrice).toBeGreaterThanOrEqual(
        prices.marketFloorPrice,
      );
      expect(prices.npcBuyPrice).toBeGreaterThan(0);
    }
});
