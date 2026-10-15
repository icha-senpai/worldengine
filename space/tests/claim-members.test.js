import { describe, expect, it } from "vitest";
import {
  permissionFlags,
  permissionNames,
  memberActivity,
  lastSeen,
} from "../src/bitcraft-ui/claim-members";
import { eventSummary } from "../src/bitcraft-ui/claim-history-display";

describe("claim roster display", () => {
  it("names each recorded permission in its original bit order", () => {
    expect(permissionNames("1000")).toBe("Storage");
    expect(permissionNames("0100")).toBe("Build");
    expect(permissionNames("0010")).toBe("Officer");
    expect(permissionNames("0001")).toBe("Co-Owner");
    expect(permissionNames("0000")).toBe("No permissions");
    expect(permissionFlags("bad").every((flag) => flag.granted === null)).toBe(
      true,
    );
    expect(
      eventSummary({
        kind: "permissions_changed",
        detail: { playerName: "Test", before: "1000", after: "1100" },
      }),
    ).toBe("Test: Storage → Storage, Build.");
  });
  it("joins activity by player id and rejects activity from a different claim", () => {
    const payload = {
      claim: { entity_id: "10" },
      members: [
        {
          player_entity_id: "20",
          claim_entity_id: "10",
          last_active_timestamp: 100,
        },
        {
          player_entity_id: "21",
          claim_entity_id: "11",
          last_active_timestamp: 200,
        },
      ],
    };
    expect(memberActivity(payload, "10")).toEqual({ 20: 100 });
    expect(() => memberActivity(payload, "11")).toThrow();
  });
  it("interprets Unix seconds without inventing dates for missing or invalid readings", () => {
    const now = Date.UTC(2026, 9, 14, 20);
    expect(lastSeen((now - 2 * 3600000) / 1000, now)).toMatchObject({
      label: "2h ago",
      at: now - 2 * 3600000,
    });
    expect(lastSeen(String((now - 30_000) / 1000), now).label).toBe("Just now");
    for (const value of [null, undefined, 0, -1, "bad", (now + 3600000) / 1000])
      expect(lastSeen(value, now).at).toBeNull();
  });
});
