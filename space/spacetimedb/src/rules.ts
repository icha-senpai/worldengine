export interface Item {
  item_key: string;
  item_name: string;
  quantity: number;
  rarity?: string;
  item_tier?: number;
  equipment_skill?: string;
  durability?: number;
  bonuses?: Record<string, number>;
}
export interface Loot extends Partial<Item> {
  key?: string;
  name?: string;
  min?: number;
  max?: number;
  chance?: number;
}
export interface Definition {
  label: string;
  skill?: string;
  category?: string;
  required_level?: number;
  location?: string;
  region?: string;
  description?: string;
  kind?: string;
  cooldown_seconds?: number;
  experience?: number | { min: number; max: number };
  gold?: number | { min: number; max: number };
  gold_cost?: number;
  price?: number;
  ingredients?: Item[];
  outputs?: Item[];
  loot?: Loot[];
  supplies?: Item[];
  requirements?: Item[];
  rewards?: Item[];
  item_key?: string;
  item_name?: string;
  rarity?: string;
  quantity?: number;
  durability?: number;
  bonuses?: Record<string, number>;
  completion_cap?: number;
  objective_type?: string;
  objective?: Record<string, unknown>;
  [key: string]: unknown;
}
const anchors = [
  [1, 0],
  [2, 200],
  [5, 450],
  [10, 1100],
  [20, 2500],
  [30, 5200],
  [40, 9000],
  [50, 15500],
  [65, 35000],
  [80, 70000],
  [100, 170000],
];
export function xpForLevel(level: number): number {
  level = Math.max(1, Math.min(100, Math.trunc(level)));
  for (let i = 1; i < anchors.length; i++) {
    const [high, xp] = anchors[i];
    const [low, previous] = anchors[i - 1];
    if (level <= high)
      return Math.round(
        previous + ((xp - previous) * (level - low)) / (high - low),
      );
  }
  return 170000;
}
export function levelForXp(xp: number): number {
  for (let level = 100; level > 1; level--)
    if (xp >= xpForLevel(level)) return level;
  return 1;
}
export const tierLevels = [1,5,10,20,30,40,50,65,80,100];
export function itemTier(level:number):number {
  return Math.max(1,tierLevels.filter(threshold=>threshold<=level).length);
}
export function maxDurability(level: number, rarity: string): number {
  const tier = itemTier(level);
  const rank = Math.max(
    0,
    ["common", "uncommon", "rare", "epic", "legendary", "mythic"].indexOf(
      rarity,
    ),
  );
  const raw = 1.125 ** (tier - 1) * 1.075 ** rank;
  return Math.max(
    100,
    Math.round((100 + ((raw - 1) / (1.125 ** 9 * 1.075 ** 5 - 1)) * 900) / 5) *
      5,
  );
}
export function assertQuantity(quantity: number): void {
  if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 1_000_000)
    throw new Error("Enter a whole quantity between 1 and 1,000,000.");
}
