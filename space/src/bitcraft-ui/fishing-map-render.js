import { HEX_SCALE } from "./fishing-map";

export const FISH_COLOR = "#ff2bd6";

// Clusters cap paint/hit-test work at overview zooms, without losing school counts.
export function schoolPoints(
  schools,
  left,
  top,
  scale,
  width,
  height,
  clusterSize = 0,
) {
  const bins = new Map(),
    points = [];
  for (const school of schools) {
    const wx = school.x + (school.z % 2 ? 0.5 : 0),
      wy = school.z * HEX_SCALE;
    const x = (wx - left) * scale,
      y = (top - wy) * scale;
    if (x < -20 || y < -20 || x > width + 20 || y > height + 20) continue;
    if (!clusterSize) {
      points.push({ x, y, wx, wy, count: 1, school });
      continue;
    }
    const key = `${Math.floor(x / clusterSize)},${Math.floor(y / clusterSize)}`;
    const bin = bins.get(key);
    if (bin) {
      bin.x += x;
      bin.y += y;
      bin.wx += wx;
      bin.wy += wy;
      bin.count++;
    } else bins.set(key, { x, y, wx, wy, count: 1, school });
  }
  for (const bin of bins.values()) {
    bin.x /= bin.count;
    bin.y /= bin.count;
    bin.wx /= bin.count;
    bin.wy /= bin.count;
    points.push(bin);
  }
  return points;
}
