import { describe, expect, it } from "vitest";
import { fishQueries } from "../bitcraft/src/fishing";
import {
  projectEmpireDirectory,
  projectFishLocations,
  validateWorldDirectory,
} from "../bitcraft/src/world-directories";

describe("collector world directories", () => {
  it("projects only surface fish schools and requires joined locations", () => {
    const rows = [
      { entity_id: "1297036692719788627", resource_id: 1110002 },
      { entity_id: "2", resource_id: 1110003 },
      { entity_id: "3", resource_id: 123 },
    ];
    const tables = {
      resource_state: rows,
      location_state: [
        { entity_id: rows[0].entity_id, x: 123, z: 456, dimension: 1 },
        { entity_id: "2", x: 11, z: 22, dimension: 2 },
      ],
    };
    const data = projectFishLocations(tables);
    expect(data).toEqual([
      { entityId: rows[0].entity_id, resourceId: 1110002, x: 123, z: 456 },
    ]);
    expect(validateWorldDirectory("fish", 12, data)).toEqual(data);
    expect(() => projectFishLocations({ resource_state: rows })).toThrow(
      "incomplete",
    );
    expect(() => validateWorldDirectory("fish", 0, data)).toThrow("scope");
    expect(() =>
      validateWorldDirectory("fish", 12, [...data, ...data]),
    ).toThrow();
    expect(() =>
      validateWorldDirectory("fish", 12, [{ ...data[0], resourceId: 123 }]),
    ).toThrow();
    expect(() =>
      validateWorldDirectory("fish", 12, [
        { ...data[0], entityId: 1297036692719788627 },
      ]),
    ).toThrow();
    expect(fishQueries()).toHaveLength(2);
    expect(fishQueries()[1]).toContain("JOIN resource_state");
  });
  it("preserves large empire and membership IDs with bounded public fields", () => {
    const data = projectEmpireDirectory({
      empire_state: [
        {
          entity_id: "1297036692719788627",
          name: "Builders",
          shard_treasury: 500,
        },
      ],
      empire_settlement_state: [
        {
          building_entity_id: "2",
          empire_entity_id: "1297036692719788627",
          claim_entity_id: "1008806316831722377",
        },
      ],
    });
    expect(validateWorldDirectory("empires", 0, data)).toEqual(data);
    expect(data.settlements[0].claimEntityId).toBe("1008806316831722377");
    expect(data.empires[0]).not.toHaveProperty("shard_treasury");
    expect(() => validateWorldDirectory("empires", 12, data)).toThrow("scope");
    expect(() =>
      validateWorldDirectory("empires", 0, {
        ...data,
        empires: [...data.empires, ...data.empires],
      }),
    ).toThrow();
    expect(() =>
      validateWorldDirectory("empires", 0, {
        ...data,
        settlements: [{ ...data.settlements[0], claimEntityId: "bad" }],
      }),
    ).toThrow();
  });
});
