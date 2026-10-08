// Exact US customary conversions; stored measurements remain integer mm/g.
export function formatLength(millimeters: number | bigint): string {
  const tenths = (BigInt(millimeters) * 100n + 127n) / 254n;
  return `${tenths / 10n}.${tenths % 10n} in`;
}

export function formatWeight(grams: number | bigint): string {
  // One avoirdupois ounce is exactly 28.349523125 grams. Round the total
  // ounces before splitting pounds, so the remainder never displays 16 oz.
  const hundredths = (BigInt(grams) * 100_000_000_000n + 14_174_761_562n) / 28_349_523_125n;
  const pounds = hundredths / 1600n;
  const remainder = hundredths % 1600n;
  const ounces = `${remainder / 100n}.${(remainder % 100n).toString().padStart(2, '0')} oz`;
  if (pounds === 0n) return ounces;
  return remainder === 0n ? `${pounds} lb` : `${pounds} lb ${ounces}`;
}
