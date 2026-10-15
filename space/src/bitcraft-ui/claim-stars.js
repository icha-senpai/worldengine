export const CLAIM_STAR_LIMIT = 200;
export const claimKey = (claim) => `${claim.regionId}:${claim.id}`;

// Ignore countdown/freshness changes: those are not new claim activity.
export function claimSnapshot(claim) {
  if (!claim) return "untracked";
  return JSON.stringify([
    claim.name,
    claim.ownerId,
    claim.tier,
    claim.waystones,
    claim.banks,
    claim.memberCount,
    claim.supplies,
    claim.upkeepPerHour,
    claim.tileCount,
    claim.buildingMaintenance,
    claim.treasury,
    claim.totemId,
    claim.x,
    claim.z,
  ]);
}
export function starHasUpdates(star, status) {
  if (!status || star.seenSnapshot == null) return false;
  return (
    claimSnapshot(status.claim) !== star.seenSnapshot ||
    BigInt(status.lastActivityAt ?? "0") > BigInt(star.seenActivityAt ?? "0")
  );
}
export function readClaimStars(storage, key) {
  try {
    const data = JSON.parse(storage.getItem(key) ?? "[]");
    if (!Array.isArray(data)) return [];
    const seen = new Set();
    return data
      .filter((star) => {
        if (
          !star ||
          typeof star.id !== "string" ||
          !/^\d{1,24}$/.test(star.id) ||
          ![7, 8, 9, 12, 13, 14, 17, 18, 19].includes(star.regionId) ||
          typeof star.name !== "string" ||
          star.name.length > 200 ||
          (star.seenActivityAt != null &&
            !/^\d{1,24}$/.test(star.seenActivityAt)) ||
          (star.seenSnapshot != null &&
            (typeof star.seenSnapshot !== "string" ||
              star.seenSnapshot.length > 4000))
        )
          return false;
        const identity = claimKey(star);
        if (seen.has(identity)) return false;
        seen.add(identity);
        return true;
      })
      .slice(0, CLAIM_STAR_LIMIT);
  } catch {
    return [];
  }
}
