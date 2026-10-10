import { describe, it, expect } from "vitest";
import {
  terrainCell,
  smallCell,
  waterDepth,
  spawnRing,
  viewportChunks,
  parseRelayJson,
  SPECIES,
} from "../src/bitcraft-ui/fishing-map";

function chunk(x, z, depth = 25) {
  return {
    chunk_x: x,
    chunk_z: z,
    dimension: 1,
    elevations: Array(1024).fill(0),
    water_levels: Array(1024).fill(depth),
  };
}
describe("Ocean fishing terrain", () => {
  it("locates a real relay school in the chunk observed from the server", () => {
    const cell = terrainCell(20768, 11495);
    expect(cell).toMatchObject({ x: 6923, z: 3832, chunk: 119217 });
    expect(cell.index).toBe(24 * 32 + 11);
  });
  it("round-trips terrain centers across odd rows and chunk boundaries", () => {
    for (const x of [0, 31, 32, 63, 64, 6923])
      for (const z of [0, 1, 31, 32, 3832]) {
        const small = smallCell(x, z);
        expect(terrainCell(small.x, small.z)).toMatchObject({ x, z });
      }
  });
  it("distinguishes unknown terrain, dry land and exact water depth", () => {
    const chunks = new Map();
    expect(waterDepth(chunks, 0, 0)).toBeNull();
    const row = chunk(0, 0, 13);
    chunks.set(1, row);
    expect(waterDepth(chunks, 0, 0)).toBe(13);
    row.elevations[0] = 20;
    expect(waterDepth(chunks, 0, 0)).toBe(0);
  });
  it("checks all 144 spawn positions and both depth limits without treating missing terrain as shallow", () => {
    const chunks = new Map([[1, chunk(0, 0, 13)]]);
    expect(spawnRing(chunks, 48, 48)).toEqual({
      eligible: 144,
      unknown: 0,
      total: 144,
    });
    chunks.get(1).water_levels.fill(12);
    expect(spawnRing(chunks, 48, 48).eligible).toBe(0);
    chunks.get(1).water_levels.fill(1000);
    expect(spawnRing(chunks, 48, 48).eligible).toBe(144);
    chunks.get(1).water_levels.fill(1001);
    expect(spawnRing(chunks, 48, 48).eligible).toBe(0);
    chunks.clear();
    expect(spawnRing(chunks, 48, 48)).toEqual({
      eligible: 0,
      unknown: 144,
      total: 144,
    });
  });
  it("bounds subscriptions rather than fetching terrain for a world overview", () => {
    expect(viewportChunks(0, 0, 38400, 38400)).toEqual([]);
    const ids = viewportChunks(20700, 11400, 20800, 11550);
    expect(ids).toContain(119217);
    expect(ids.length).toBeLessThanOrEqual(64);
    expect(new Set(ids).size).toBe(ids.length);
  });
  it("preserves neighboring 64-bit IDs in array and object relay rows", () => {
    const rows = parseRelayJson("[1297036692719788627,1297036692719788628]");
    expect(rows).toEqual(["1297036692719788627", "1297036692719788628"]);
    expect(
      parseRelayJson('{"entity_id":1297036692719788627,"x":20768}'),
    ).toEqual({ entity_id: "1297036692719788627", x: 20768 });
  });
  it("covers ordinary and chummed schools for all ten species with unique source IDs", () => {
    expect(SPECIES).toHaveLength(20);
    expect(new Set(SPECIES.map((s) => s.id)).size).toBe(20);
    for (let tier = 1; tier <= 10; tier++)
      expect(
        SPECIES.filter((s) => s.tier === tier).map((s) => s.chummed),
      ).toEqual([false, true]);
  });
});
