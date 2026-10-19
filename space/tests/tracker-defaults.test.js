import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { filtersFor } from "../src/bitcraft-ui/data/filters";

const page = vi.hoisted(() => ({ props: { bitcraft: { player: null } } }));
vi.mock("../src/bitcraft-ui/navigation", () => ({ pageState: page }));

beforeEach(() => {
  page.props.bitcraft.player = null;
  vi.stubGlobal("localStorage", { getItem: () => null });
});
afterEach(() => vi.unstubAllGlobals());

describe("tracker character defaults", () => {
  it("starts without a character for a new visitor", () => {
    for (const tool of ["activity", "inventory", "passive-crafts"])
      expect(filtersFor(tool, {}, true).character).toBe("");
  });
  it("uses the visitor's chosen site player and respects an explicit character", () => {
    vi.stubGlobal("localStorage", { getItem: () => "saved-player" });
    expect(filtersFor("activity", {}, true).character).toBe("saved-player");
    page.props.bitcraft.player = { entityId: "site-player" };
    expect(filtersFor("activity", {}, true).character).toBe("site-player");
    expect(
      filtersFor("activity", { character: "chosen-player" }, true).character,
    ).toBe("chosen-player");
    expect(filtersFor("activity", { character: "" }, true).character).toBe("");
  });
});
