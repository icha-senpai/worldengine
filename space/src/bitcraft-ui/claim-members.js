export const CLAIM_PERMISSIONS = ["Storage", "Build", "Officer", "Co-Owner"];
export function permissionFlags(code) {
  const valid = typeof code === "string" && /^[01]{4}$/.test(code);
  return CLAIM_PERMISSIONS.map((name, index) => ({
    name,
    granted: valid ? code[index] === "1" : null,
  }));
}
export function permissionNames(code) {
  const flags = permissionFlags(code);
  if (flags[0].granted === null) return "Unknown permissions";
  return (
    flags
      .filter((flag) => flag.granted)
      .map((flag) => flag.name)
      .join(", ") || "No permissions"
  );
}
export function memberActivity(payload, claimId) {
  if (
    String(payload?.claim?.entity_id) !== String(claimId) ||
    !Array.isArray(payload.members) ||
    payload.members.length > 500
  )
    throw Error("Member activity does not match this claim.");
  return Object.fromEntries(
    payload.members
      .filter(
        (member) =>
          /^\d{1,24}$/.test(String(member.player_entity_id)) &&
          String(member.claim_entity_id) === String(claimId),
      )
      .map((member) => [
        String(member.player_entity_id),
        member.last_active_timestamp,
      ]),
  );
}
// The joined endpoint supplies Unix seconds, not the recorder's microseconds.
export function lastSeen(seconds, now = Date.now()) {
  const value = Number(seconds),
    ms = value * 1000;
  if (
    seconds == null ||
    !Number.isInteger(value) ||
    value <= 0 ||
    !Number.isFinite(ms) ||
    ms > now + 60_000
  )
    return {
      label: "Unknown",
      exact: "No activity timestamp available",
      at: null,
    };
  const minutes = Math.floor(Math.max(0, now - ms) / 60_000);
  return {
    label:
      minutes < 1
        ? "Just now"
        : minutes < 60
          ? `${minutes}m ago`
          : minutes < 1440
            ? `${Math.floor(minutes / 60)}h ago`
            : `${Math.floor(minutes / 1440)}d ago`,
    exact: new Date(ms).toLocaleString(),
    at: ms,
  };
}
