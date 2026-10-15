import { describe, it, expect } from "vitest";
import {
  claimSnapshot,
  starHasUpdates,
  readClaimStars,
} from "../src/bitcraft-ui/claim-stars";

describe("personal claim stars", () => {
  it("notices activity even when the owner or roster later returns to its old state", () => {
    const claim = {
      name: "Home",
      ownerId: "1",
      memberCount: 2,
      supplies: 500,
      upkeepPerHour: 10,
    };
    const star = { seenSnapshot: claimSnapshot(claim), seenActivityAt: "100" };
    expect(starHasUpdates(star, { claim, lastActivityAt: "101" })).toBe(true);
    expect(
      starHasUpdates(star, {
        claim: { ...claim, supplies: 490 },
        lastActivityAt: "100",
      }),
    ).toBe(true);
    expect(starHasUpdates(star, { claim: null, lastActivityAt: "100" })).toBe(
      true,
    );
    expect(
      starHasUpdates(star, {
        claim: {
          ...claim,
          remainingHours: 1,
          supplyStatus: "stale",
          suppliesObservedAt: "999",
        },
        lastActivityAt: "100",
      }),
    ).toBe(false);
    expect(starHasUpdates(star, { claim, lastActivityAt: null })).toBe(false);
    expect(starHasUpdates({}, { claim, lastActivityAt: "100" })).toBe(false);
  });
  it("keeps stars through reload and rejects corrupted, duplicate or retired-region entries", () => {
    const star = {
      id: "10",
      regionId: 8,
      name: "Home",
      seenActivityAt: "100",
      seenSnapshot: "untracked",
    };
    expect(
      readClaimStars(
        {
          getItem: () =>
            JSON.stringify([
              star,
              star,
              { ...star, regionId: 3 },
              { ...star, id: 99 },
              { ...star, seenActivityAt: "bad" },
            ]),
        },
        "key",
      ),
    ).toEqual([star]);
    expect(readClaimStars({ getItem: () => "bad json" }, "key")).toEqual([]);
    expect(
      readClaimStars(
        {
          getItem: () => {
            throw Error("Unavailable");
          },
        },
        "key",
      ),
    ).toEqual([]);
  });
});
