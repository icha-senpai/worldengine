import { existsSync, readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import {
  filterEmojis,
  selectedEmojis,
  toggleEmoji,
} from "../src/bitcraft-ui/emoji";

const catalog = JSON.parse(
  readFileSync(
    new URL("../public/assets/emoji/18.0.json", import.meta.url),
    "utf8",
  ),
);
const entries = catalog.entries.map(([emoji, name, category, subgroup]) => ({
  emoji,
  name,
  category,
  search: `${name} ${subgroup} ${catalog.groups[category]}`.toLowerCase(),
  tones: emoji.match(/[\u{1F3FB}-\u{1F3FF}]/gu) ?? [],
}));

describe("complete widget emoji catalog", () => {
  it("provides locally hosted artwork for every catalog sequence", () => {
    const missing = entries.filter((entry) => {
      const code = Array.from(entry.emoji)
        .map((character) => character.codePointAt(0))
        .filter((point) => point !== 0xfe0f)
        .map((point) => point.toString(16).padStart(4, "0"))
        .join("_");
      return !existsSync(
        new URL(
          `../public/assets/emoji/noto-18.0/${code}.svg`,
          import.meta.url,
        ),
      );
    });
    expect(missing.map((entry) => entry.name)).toEqual([]);
  });
  it("contains the pinned Unicode release, unique sequences, flags, and joined emoji", () => {
    expect(catalog.version).toBe("18.0");
    expect(entries.length).toBe(3972);
    expect(new Set(entries.map((entry) => entry.emoji)).size).toBe(
      entries.length,
    );
    for (const emoji of ["🇺🇸", "🏳️‍🌈", "👨‍👩‍👧‍👦", "🧑🏿‍🚀", "🐟", "🩷"]) {
      expect(entries.some((entry) => entry.emoji === emoji)).toBe(true);
    }
  });
  it("searches names, categories, and pasted sequences", () => {
    expect(
      filterEmojis(entries, { query: "TROPICAL fish" }).map(
        (entry) => entry.emoji,
      ),
    ).toContain("🐠");
    expect(
      filterEmojis(entries, { query: "united states" }).map(
        (entry) => entry.emoji,
      ),
    ).toEqual(["🇺🇸"]);
    expect(
      filterEmojis(entries, { query: "🏳️‍🌈" }).map((entry) => entry.emoji),
    ).toEqual(["🏳️‍🌈"]);
    const flags = String(catalog.groups.indexOf("Flags"));
    expect(
      filterEmojis(entries, { category: flags }).every(
        (entry) => catalog.groups[entry.category] === "Flags",
      ),
    ).toBe(true);
  });
  it("exposes every tone and mixed-tone sequence through All skin tones", () => {
    expect(filterEmojis(entries, { tone: "all" })).toHaveLength(entries.length);
    const astronauts = filterEmojis(entries, {
      query: "astronaut",
      tone: "🏿",
    });
    expect(astronauts.some((entry) => entry.emoji === "🧑🏿‍🚀")).toBe(true);
    expect(astronauts.some((entry) => entry.emoji === "🧑🏻‍🚀")).toBe(false);
    expect(filterEmojis(entries).some((entry) => entry.emoji === "🧑🏻‍🚀")).toBe(
      false,
    );
  });
  it("keeps joined sequences intact and prevents profiles exceeding their capacity", () => {
    expect(selectedEmojis("👨‍👩‍👧‍👦 🏳️‍🌈 👨‍👩‍👧‍👦")).toEqual(["👨‍👩‍👧‍👦", "🏳️‍🌈"]);
    const first = toggleEmoji("", "👨‍👩‍👧‍👦");
    const second = toggleEmoji(first, "🧑🏿‍🚀");
    expect(toggleEmoji(second, "👨‍👩‍👧‍👦")).toBe("🧑🏿‍🚀");
    expect(toggleEmoji("✨".repeat(40), "🏳️‍🌈")).toBe(null);
  });
  it("keeps recent choices in selection order without changing the catalog", () => {
    const recent = ["🐟", "🔥", "🩷"];
    expect(
      filterEmojis(entries, { category: "recent", recent }).map(
        (entry) => entry.emoji,
      ),
    ).toEqual(recent);
    expect(entries[0].emoji).toBe("😀");
  });
});
