import { describe, expect, it } from "vitest";
import { catalogResponse } from "../bitcraft/src/catalog";
import {
  xpSourceAction,
  resolveXpSource,
  xpItemForSkill,
} from "../src/bitcraft-ui/xpSource";

const now = 1791585504000;
const action = (type = "Craft", recipe = 235701456, start = now - 1000) => ({
  action_type: type,
  recipe_id: recipe,
  start_time_ms: start,
  ends_at_ms: start + 2000,
  client_cancel: false,
});
const session = (actions = [action()]) => ({
  signed_in: true,
  actions,
  delayed: false,
});
const cooking = { id: 8, name: "Cooking" };
const source = () =>
  resolveXpSource(
    action(),
    catalogResponse("card", "235701456", "crafting"),
    "20",
  );

describe("XP action item attribution", () => {
  it("resolves a craft recipe to its real catalog item and matches the skill", () => {
    const item = xpItemForSkill(source(), cooking, session(), now, "20");
    expect(item?.name).toBe("Abyssal Gladius Experiment");
    expect(item?.iconAssetName).toBeTruthy();
    expect(
      xpItemForSkill(
        source(),
        { id: 3, name: "Carpentry" },
        session(),
        now,
        "20",
      ),
    ).toBeNull();
  });
  it("names a gathered resource instead of guessing which random output gave XP", () => {
    const gathering = action("Extract", 17);
    const resolved = resolveXpSource(
      gathering,
      catalogResponse("card", "17", "gathering"),
      "20",
    );
    const item = xpItemForSkill(
      resolved,
      { id: 2, name: "Forestry" },
      session([gathering]),
      now,
      "20",
    );
    expect(item?.name).toBe("Young Maple Tree");
    expect(item?.iconAssetName).toBe("GeneratedIcons/Other/MapleTreeYoung");
  });
  it("clears attribution for changed recipes, another player, stale and cancelled actions", () => {
    for (const activity of [
      session([action("Craft", 123)]),
      session([{ ...action(), client_cancel: true }]),
      session([action("None")]),
      { ...session(), signed_in: false },
      { ...session(), delayed: true },
    ]) {
      expect(xpItemForSkill(source(), cooking, activity, now, "20")).toBeNull();
    }
    expect(
      xpItemForSkill(source(), cooking, session(), now + 30000, "20"),
    ).toBeNull();
    expect(
      xpItemForSkill(source(), cooking, session(), now, "other"),
    ).toBeNull();
  });
  it("prioritizes the newest work action while allowing simultaneous movement", () => {
    const extracting = action("Extract", 17);
    expect(
      xpSourceAction(session([extracting, action("PlayerMove", 0, now)]), now),
    ).toBe(extracting);
    expect(
      xpSourceAction(session([action(), action("Attack", 0, now)]), now)
        ?.action_type,
    ).toBe("Attack");
  });
  it("leaves unidentified activities and missing recipes without an item", () => {
    expect(
      resolveXpSource(
        action("Attack"),
        catalogResponse("card", "235701456", "crafting"),
        "20",
      ),
    ).toBeNull();
    expect(resolveXpSource(action(), null, "20")).toBeNull();
    expect(
      resolveXpSource(
        action("Craft", 999),
        catalogResponse("card", "235701456", "crafting"),
        "20",
      ),
    ).toBeNull();
  });
});
