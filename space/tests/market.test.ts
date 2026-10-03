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
    // Verify the shipped metadata, not just the pricing function's inputs.
    const upgrade =
      content.tool_upgrades[
        `${row.skill}:${row.tool.tierLevel}` as keyof typeof content.tool_upgrades
      ];
    const metadata =
      content.item_metadata[
        `${upgrade.output.item_key}:${row.rarity}` as keyof typeof content.item_metadata
      ];
    expect(toolPrices(row.tool, metadata, row.rarityMarketPremium)).toEqual(
      row.prices,
    );
  }
});
