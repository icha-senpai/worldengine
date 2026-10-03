export interface ToolValuation {
  tierLevel: number;
  durability: number;
  upgrades: number;
  tierUpgrades: number;
  bonuses: string;
}

/** Preserve ICHAA's tool valuation, including reduced value when broken. */
export function toolPrices(
  tool: ToolValuation,
  metadata: Record<string, unknown>,
  rarityMarketPremium: number,
) {
  const bonuses = JSON.parse(tool.bonuses) as Record<string, number>;
  const working = tool.durability > 0;
  const stats = working
    ? (bonuses.experience ?? 0) * 4 + (bonuses.yield ?? 0) * 26
    : 0;
  const effects = working
    ? rarityMarketPremium +
      tool.tierLevel * 2 +
      tool.upgrades * 12 +
      tool.tierUpgrades * 16
    : 0;
  const history =
    tool.tierLevel * 3 + tool.upgrades * 45 + tool.tierUpgrades * 30;
  const marketFloorPrice = Math.max(
    Number(metadata.market_floor_price ?? 1),
    Number(metadata.vendor_value ?? 25) + stats + history + effects,
  );
  return {
    marketFloorPrice,
    marketCeilingPrice: marketFloorPrice * 8,
    npcBuyPrice: Math.max(1, Math.floor(marketFloorPrice * 0.65)),
  };
}
