import { describe, it, expect } from "vitest";
import { schoolPoints } from "../src/bitcraft-ui/fishing-map-render";
import { HEX_SCALE } from "../src/bitcraft-ui/fishing-map";

describe("Fishing map rendering bounds", () => {
  it("keeps every school counted while bounding crowded overview marker work", () => {
    const schools = Array.from({ length: 10000 }, (_, i) => ({
      entityId: String(i),
      x: 100 + (i % 50),
      z: 100 + (Math.floor(i / 50) % 50),
      tier: 1,
    }));
    const points = schoolPoints(schools, 0, 200, 1, 400, 400, 28);
    expect(points.reduce((count, point) => count + point.count, 0)).toBe(10000);
    expect(points.length).toBeLessThan(20);
  });
  it("preserves individual school identity and the odd-row hex offset at close zoom", () => {
    const school = { entityId: "1297036692719788627", x: 10, z: 11 };
    const [point] = schoolPoints([school], 0, 20, 4, 100, 100);
    expect(point.school).toBe(school);
    expect(point.x).toBe(42);
    expect(point.y).toBeCloseTo((20 - 11 * HEX_SCALE) * 4);
    expect(point.count).toBe(1);
    expect(schoolPoints([{ ...school, x: 1000 }], 0, 20, 4, 100, 100)).toEqual(
      [],
    );
  });
});
