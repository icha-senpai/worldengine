// Retired island regions still appear in upstream directories and old snapshots.
export const RETIRED_REGION_IDS = [3, 11, 15, 23] as const;
export function isRetiredRegion(id: unknown, name: unknown = "") {
  return (
    RETIRED_REGION_IDS.some((retired) => String(retired) === String(id)) ||
    /^(eastern|northern|southern|western) islands?$/i.test(String(name).trim())
  );
}
export function isVisibleRegionRecord(row: Record<string, any>) {
  return !isRetiredRegion(
    row.regionId ?? row.region ?? row.claim?.regionId ?? row.claim?.region,
    row.regionName ?? row.claim?.regionName,
  );
}
export function visibleMarketBook(book: Record<string, any>) {
  const result = { ...book };
  for (const field of [
    "sellOrders",
    "buyOrders",
    "packageSellOrders",
    "packageBuyOrders",
  ])
    result[field] = (book[field] ?? []).filter(isVisibleRegionRecord);
  return result;
}
