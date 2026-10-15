import { permissionNames } from "./claim-members";
export const labels = {
  ownership_changed: "Ownership changed",
  member_joined: "Member joined",
  member_departed: "Member departed",
  permissions_changed: "Permissions changed",
  supplies_zero: "Supplies reached zero",
  supplies_refilled: "Supplies increased",
  claim_created: "Claim appeared",
  claim_removed: "Claim disappeared",
  claim_updated: "Claim updated",
  tracking_started: "Claim entered tracking",
  tracking_stopped: "Claim left tracking",
  coverage_started: "Recording started",
  coverage_gap: "Recording resumed after a gap",
};
export function date(at) {
  return at
    ? new Date(Number(BigInt(at) / 1000n)).toLocaleString()
    : "Not recorded yet";
}
export function eventSummary(e) {
  const d = e.detail;
  if (e.kind === "ownership_changed")
    return `${d.previousOwnerName} → ${d.ownerName}. New owner ${d.ownerWasMember ? "was" : "was not"} on the preceding roster.`;
  if (e.kind.startsWith("member_"))
    return `${d.playerName} (${d.playerId}) ${e.kind === "member_joined" ? "joined" : "departed"}.`;
  if (e.kind === "permissions_changed")
    return `${d.playerName}: ${permissionNames(d.before)} → ${permissionNames(d.after)}.`;
  if (e.kind.startsWith("supplies_"))
    return `${d.before.toLocaleString()} → ${d.after.toLocaleString()} supplies.`;
  if (e.kind.startsWith("coverage_")) return d.message;
  if (e.kind === "tracking_started")
    return `Tracking began: T${d.tier}, ${d.waystones} waystone(s), ${d.banks} bank(s). Owner: ${d.ownerName}.`;
  if (e.kind === "tracking_stopped")
    return d.reason === "tier_unknown"
      ? `Tracking paused because the tier is unavailable. Last known tier: T${d.previousTier}.`
      : `Tracking stopped: ${d.tier !== null ? `T${d.tier}` : "tier unknown"}, ${d.waystones ?? 0} waystone(s), ${d.banks ?? 0} bank(s). The claim no longer meets all three requirements.`;
  if (e.kind === "claim_updated") {
    const changes = [];
    if (d.previousName !== d.claimName)
      changes.push(`${d.previousName} → ${d.claimName}`);
    if (d.previousTier !== d.tier)
      changes.push(
        `Tier ${d.previousTier ?? "unknown"} → ${d.tier ?? "unknown"}`,
      );
    if (
      d.previousWaystones !== undefined &&
      d.previousWaystones !== null &&
      d.previousWaystones !== d.waystones
    )
      changes.push(`Waystones ${d.previousWaystones} → ${d.waystones}`);
    if (
      d.previousBanks !== undefined &&
      d.previousBanks !== null &&
      d.previousBanks !== d.banks
    )
      changes.push(`Banks ${d.previousBanks} → ${d.banks}`);
    if (d.previousX !== d.x || d.previousZ !== d.z)
      changes.push("Location changed");
    if (d.previousTotemId !== d.totemId) changes.push("Totem changed");
    return changes.join("; ") || "Claim details updated.";
  }
  return `Owner: ${d.ownerName} (${d.ownerId}).`;
}

export function remaining(claim) {
  if (claim.supplies == null) return "Unknown";
  if (claim.supplies <= 0) return "Out of supplies";
  if (claim.upkeepPerHour === 0) return "No upkeep";
  if (claim.remainingHours == null) return "Unknown";
  if (claim.remainingHours <= 0) return "Due now (estimate)";
  const minutes = Math.ceil(claim.remainingHours * 60);
  const days = Math.floor(minutes / 1440),
    hours = Math.floor((minutes % 1440) / 60),
    mins = minutes % 60;
  return days
    ? `${days}d ${hours}h`
    : hours
      ? `${hours}h ${mins}m`
      : `${mins}m`;
}

export function timestamp(ms) {
  return ms == null ? "Unknown" : new Date(ms).toLocaleString();
}
export function number(value) {
  return value === null || value === undefined
    ? "Unknown"
    : Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
}
