// Match game-rules::progression. Use the subscribed version until activation.
export function xpToNextLevel(level: number, rulesVersion: number): bigint {
  const value = BigInt(level);
  return rulesVersion < 10 || level <= 10
    ? 80n + 25n * value + 5n * value * value
    : 830n + 35n * (value - 10n);
}
export function levelForXp(totalXp: bigint, levelCap: number, rulesVersion: number): number {
  let level = 1;
  while (level < levelCap && totalXp >= xpToNextLevel(level, rulesVersion)) {
    totalXp -= xpToNextLevel(level, rulesVersion);
    level += 1;
  }
  return level;
}
