import { describe, expect, it } from "vitest";
import { summarizeResources } from "../scripts/bitcraft-resources";

const dictionary = {
  ready: true,
  region: 9,
  dict_version: 4,
  entries: [
    {
      index: 1,
      resource_id: 12,
      name: "Tree",
      paving: false,
      harvestable: true,
    },
  ],
};
function window() {
  const bytes = new Uint8Array(320024),
    view = new DataView(bytes.buffer);
  bytes.set([66, 77, 82, 49]);
  view.setUint16(4, 1, true);
  view.setUint16(6, 400, true);
  view.setUint32(16, 9, true);
  view.setUint32(20, 4, true);
  return {
    bytes,
    tile: (x: number, z: number, word: number) =>
      view.setUint16(24 + (z * 400 + x) * 2, word, true),
  };
}
describe("resource windows", () => {
  it("counts each origin once, excludes paving and finds the closest node", () => {
    const map = window();
    map.tile(200, 200, 1025);
    map.tile(199, 200, 1); // The same tree's footprint.
    map.tile(202, 200, 1025);
    map.tile(201, 200, 17409); // Paving.
    expect(summarizeResources(map.bytes, dictionary).resources).toEqual([
      {
        resourceId: 12,
        name: "Tree",
        count: 2,
        distance: 0,
        north: 67,
        east: 66,
      },
    ]);
  });
  it("rejects incomplete windows, mismatched dictionaries and unknown nodes", () => {
    const map = window();
    map.tile(200, 200, 1026);
    expect(() => summarizeResources(map.bytes, dictionary)).toThrow(/Unknown/);
    expect(() =>
      summarizeResources(map.bytes, { ...dictionary, dict_version: 3 }),
    ).toThrow(/resynchronization/);
    expect(() =>
      summarizeResources(map.bytes.slice(0, 10), dictionary),
    ).toThrow(/Unsupported/);
  });
});
