import { describe, expect, it, vi } from "vitest";
import { effectScope, nextTick, ref } from "vue";
import { usePanelFocus } from "../src/components/evergather/usePanelState";
import {
  nearestOffers,
  repairTarget,
  resultFeedback,
} from "../src/evergather/gameplayUi";
import {
  favorites,
  recentCommands,
  preferredEntries,
} from "../src/evergather/playSession";

describe("Evergather gameplay feedback and shortcuts", () => {
  it("reveals a late-loaded shortcut once and preserves later manual filters", async () => {
    const focus = ref({ key: "mine" });
    const entries = ref([]);
    const filter = ref("Fishing");
    const reveal = vi.fn(() => {
      filter.value = "Mining";
    });
    const scope = effectScope();
    scope.run(() =>
      usePanelFocus(
        () => focus.value,
        () => entries.value,
        reveal,
      ),
    );
    expect(reveal).not.toHaveBeenCalled();
    entries.value = [{ key: "mine" }];
    await nextTick();
    expect(filter.value).toBe("Mining");
    filter.value = "Fishing";
    entries.value = [{ key: "mine" }];
    await nextTick();
    expect(filter.value).toBe("Fishing");
    expect(reveal).toHaveBeenCalledTimes(1);
    focus.value = { key: "mine" };
    await nextTick();
    expect(filter.value).toBe("Mining");
    expect(reveal).toHaveBeenCalledTimes(2);
    scope.stop();
  });
  it("distinguishes a failed rarity roll from an actual rarity upgrade", () => {
    expect(
      resultFeedback({
        type: "tool_rarity_upgrade",
        label: "Tidehook Rod: 22% progress",
        gold: -45,
      }),
    ).toBe("Rarity attempt failed · progress now 22% · -45g");
    expect(
      resultFeedback({
        type: "tool_rarity_upgrade",
        label: "Tidehook Rod: uncommon",
        gold: -45,
      }),
    ).toBe("Rarity upgraded · Tidehook Rod: uncommon · -45g");
  });
  it("shows committed rewards and spending without losing item quantities", () => {
    expect(
      resultFeedback({
        type: "crafting",
        label: "Ingot",
        items_created: [{ item_name: "Candlemark Ingot", quantity: 2 }],
        experience_awarded: 44n,
        gold_spent: 7,
      }),
    ).toBe("Ingot · +2 Candlemark Ingot · +44 XP · -7g");
    expect(resultFeedback(null)).toBe("");
  });
  it("prioritizes reachable shop upgrades rather than expensive distant tiers", () => {
    const offers = [
      {
        label: "Mythic",
        price: 5200,
        required_level: 100,
        skill_level: 5,
        is_unlocked: false,
        can_buy: false,
      },
      {
        label: "Next",
        price: 80,
        required_level: 10,
        skill_level: 5,
        is_unlocked: false,
        can_buy: false,
      },
      {
        label: "Save for",
        price: 100,
        required_level: 5,
        skill_level: 5,
        is_unlocked: true,
        can_buy: false,
      },
      {
        label: "Buy now",
        price: 50,
        required_level: 5,
        skill_level: 5,
        is_unlocked: true,
        can_buy: true,
      },
    ];
    expect(nearestOffers(offers).map((value) => value.label)).toEqual([
      "Buy now",
      "Save for",
      "Next",
      "Mythic",
    ]);
    expect(offers[0].label).toBe("Mythic");
  });
  it("keeps a repair blocked until the live repair contract is ready", () => {
    const tool = {
      item_name: "Rod",
      tool_lifecycle: {
        repair: {
          materials: [],
          gold_cost: 20,
          can_repair: false,
          is_repairable: true,
          missing_durability: 10,
          missing_materials: false,
          has_gold: false,
        },
      },
    };
    expect(repairTarget(tool)).toMatchObject({
      ready: false,
      status: "Need 20g",
    });
    tool.tool_lifecycle.repair.has_gold = true;
    tool.tool_lifecycle.repair.can_repair = true;
    expect(repairTarget(tool)).toMatchObject({ ready: true, status: "Ready" });
    expect(repairTarget(null)).toBeNull();
  });
  it("keeps pinned and recent actions ahead of catalogue order within their action type", () => {
    favorites.value = [{ action: "gather", key: "fish", label: "Fishing" }];
    recentCommands.value = [{ action: "gather", key: "mine", label: "Mining" }];
    const entries = [{ key: "cut" }, { key: "mine" }, { key: "fish" }];
    expect(
      preferredEntries(entries, "gather").map((value) => value.key),
    ).toEqual(["fish", "mine", "cut"]);
    expect(
      preferredEntries(entries, "craft").map((value) => value.key),
    ).toEqual(["cut", "mine", "fish"]);
    favorites.value = [];
    recentCommands.value = [];
  });
});
