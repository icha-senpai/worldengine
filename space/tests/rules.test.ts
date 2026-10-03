import { describe, it, expect } from "vitest";
import {
  xpForLevel,
  levelForXp,
  maxDurability,
  assertQuantity,
} from "../spacetimedb/src/rules";
import { upstreamRequest } from "../bitcraft/src/requests";
import reference from './fixtures/legacy-rules.json';
describe("legacy Evergather progression", () => {
  it('matches the live Laravel export at every XP threshold and durability tier',()=>{
    for(const row of reference.experience)expect(xpForLevel(row.level),`Level ${row.level} XP`).toBe(row.experience);
    for(const row of reference.durability)expect(maxDurability(row.level,row.rarity),`${row.level}/${row.rarity} durability`).toBe(row.maximum);
  });
  it("preserves calibrated level anchors and exact unlock boundaries", () => {
    for (const [level, xp] of [
      [1, 0],
      [2, 200],
      [10, 1100],
      [50, 15500],
      [100, 170000],
    ]) {
      expect(xpForLevel(level)).toBe(xp);
      expect(levelForXp(xp)).toBe(level);
      if (level > 1) expect(levelForXp(xp - 1)).toBe(level - 1);
    }
  });
  it("has strictly increasing thresholds through mastery", () => {
    for (let level = 2; level <= 100; level++)
      expect(xpForLevel(level)).toBeGreaterThan(xpForLevel(level - 1));
    expect(levelForXp(1_000_000)).toBe(100);
  });
  it("preserves durability endpoints", () => {
    expect(maxDurability(1, "common")).toBe(100);
    expect(maxDurability(100, "mythic")).toBe(1000);
  });
  it("rejects invalid trading quantities", () => {
    for (const value of [0, -1, 1.5, NaN, Infinity, 1_000_001])
      expect(() => assertQuantity(value)).toThrow();
  });
});
describe("public upstream requests", () => {
  it("encodes searches and produces canonical cache keys", () => {
    expect(upstreamRequest("market", "", "iron & coal", 1).key).toBe(
      "api/market?q=iron%20%26%20coal",
    );
  });
  it("rejects paths, URLs, and malformed player IDs", () => {
    for (const id of ["../secrets", "https://example.com", "1?token=x", ""])
      expect(() => upstreamRequest("player", id, "", 1)).toThrow();
    expect(() => upstreamRequest("http://example.com", "", "", 1)).toThrow();
    expect(() => upstreamRequest("market", "", "", 101)).toThrow();
  });
});
