// Enemy definition IDs, not the generic monster tier or dungeon trash types.
export const BOSSES: Record<
  number,
  { name: string; tier: number; wire: string }
> = {
  32: { name: "Feral Sentinel", tier: 3, wire: "sentinel" },
  36: { name: "Giant Skitch", tier: 1, wire: "crabDungeonCrabBoss" },
  40: { name: "Araknir Nest", tier: 7, wire: "spiderDungeonSpiderNest" },
};
export const BOSS_DETAIL_DAYS = 30;
export const BOSS_DETAIL_LIMIT = 20_000;
export const BOSS_DETAIL_BYTES = 32_000_000;
export const BOSS_SCORE_LIMIT = 40_000;
export type BossParticipant = {
  id: string;
  name: string;
  contribution: number;
};
export type BossDefeat = {
  enemyId: string;
  enemyType: number;
  diedAt: string; // Actual server death timestamp, in seconds.
  participants: BossParticipant[];
  killerId: string; // Only a player attack in the SAME transaction as death.
};
export function bossKey(region: number, defeat: BossDefeat) {
  return `${region}:${defeat.enemyId}:${defeat.diedAt}`;
}
