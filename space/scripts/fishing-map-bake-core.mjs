import { deflateSync } from "node:zlib";
import {
  HEX_SCALE,
  terrainCell,
  REGIONS,
} from "../src/bitcraft-ui/fishing-map.js";

export const SOURCE_SIZE = 258,
  SOURCE_CELLS = SOURCE_SIZE ** 2;
export const LAND = [
  [99, 122, 78],
  [133, 141, 84],
  [82, 122, 93],
  [151, 138, 106],
  [97, 109, 73],
  [119, 126, 108],
  [169, 155, 120],
  [88, 126, 116],
];
const VOID = [18, 36, 48];
export function sourceTile(bytes) {
  if (![SOURCE_CELLS * 6, SOURCE_CELLS * 8].includes(bytes.length))
    throw Error(`Unexpected terrain payload: ${bytes.length} bytes`);
  return {
    bytes,
    depthAt(index) {
      return bytes[index * 4] === 255 ? null : bytes[index * 4 + 3];
    },
  };
}
export function cellColor(bytes, index, showDepth) {
  const b = index * 4;
  if (bytes[b] === 255) return VOID;
  const depth = bytes[b + 3];
  if (depth && showDepth)
    return depth < 13
      ? [224, 178, 104]
      : depth < 25
        ? [82, 203, 187]
        : depth < 50
          ? [76, 156, 223]
          : [135, 113, 214];
  if (depth) {
    const mix = Math.min(depth / 25, 1);
    return [57, 132, 149].map((v, c) =>
      Math.round(v * (1 - mix) + [24, 59, 86][c] * mix),
    );
  }
  const blend = bytes[b + 2] / 255,
    a = LAND[bytes[b] % LAND.length],
    c = LAND[bytes[b + 1] % LAND.length];
  return a.map((v, i) => Math.round(v * (1 - blend) + c[i] * blend));
}
// Locate the small hex containing a world point, then use the game's exact
// small-to-large conversion. Neighbor padding keeps independently baked joins equal.
export function fineCellIndex(tileX, tileZ, px, py, width, height) {
  const wx = tileX * 768 + ((px + 0.5) * 768) / width;
  const wz = (tileZ + 1) * 768 - ((py + 0.5) * 768) / height;
  let closest = Infinity,
    sx,
    sz;
  for (let z = Math.round(wz) - 1; z <= Math.round(wz) + 1; z++) {
    const offset = Math.abs(z % 2) * 0.5,
      x = Math.round(wx - offset);
    const distance = (wx - x - offset) ** 2 + ((wz - z) * HEX_SCALE) ** 2;
    if (distance < closest) {
      closest = distance;
      sx = x;
      sz = z;
    }
  }
  const cell = terrainCell(sx, sz),
    x = cell.x - tileX * 256 + 1,
    z = cell.z - tileZ * 256 + 1;
  if (x < 0 || x >= SOURCE_SIZE || z < 0 || z >= SOURCE_SIZE)
    throw Error("Terrain padding is insufficient");
  return z * SOURCE_SIZE + x;
}
let fineIndexes;
export function renderTile(bytes, level, tileX, tileZ) {
  const width = level === 0 ? 1536 : 256;
  const height = Math.ceil(width * HEX_SCALE);
  const base = Buffer.alloc(width * height * 3),
    depth = Buffer.alloc(base.length);
  if (level === 0 && !fineIndexes) {
    fineIndexes = new Uint32Array(width * height);
    for (let y = 0; y < height; y++)
      for (let x = 0; x < width; x++)
        fineIndexes[y * width + x] = fineCellIndex(1, 1, x, y, width, height);
  }
  const baseColors = new Uint32Array(SOURCE_CELLS),
    depthColors = new Uint32Array(SOURCE_CELLS);
  for (let i = 0; i < SOURCE_CELLS; i++) {
    const a = cellColor(bytes, i, false),
      b = cellColor(bytes, i, true);
    baseColors[i] = (a[0] << 16) | (a[1] << 8) | a[2];
    depthColors[i] = (b[0] << 16) | (b[1] << 8) | b[2];
  }
  for (let y = 0; y < height; y++)
    for (let x = 0; x < width; x++) {
      const index =
        level === 0
          ? fineIndexes[y * width + x]
          : (256 - Math.floor(((y + 0.5) * 256) / height)) * SOURCE_SIZE +
            x +
            1;
      const p = (y * width + x) * 3,
        a = baseColors[index],
        b = depthColors[index];
      base[p] = a >>> 16;
      base[p + 1] = a >>> 8;
      base[p + 2] = a;
      depth[p] = b >>> 16;
      depth[p + 1] = b >>> 8;
      depth[p + 2] = b;
    }
  return { width, height, base, depth };
}
export function regionForTile(x, z) {
  return Math.floor(z / 10) * 5 + Math.floor(x / 10) + 1;
}
export function regionInfo(id) {
  const x = ((id - 1) % 5) * 7680,
    z = Math.floor((id - 1) / 5) * 7680;
  return {
    id,
    name: REGIONS.find((r) => r[0] === id)?.[1] ?? "Outer ocean",
    bounds: [x, z, x + 7680, z + 7680],
  };
}

const crcTable = Uint32Array.from({ length: 256 }, (_, value) => {
  for (let bit = 0; bit < 8; bit++)
    value = (value >>> 1) ^ (value & 1 ? 0xedb88320 : 0);
  return value >>> 0;
});
function chunk(name, data) {
  const type = Buffer.from(name),
    length = Buffer.alloc(4),
    checksum = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  let crc = 0xffffffff;
  for (const bytes of [type, data])
    for (const byte of bytes) crc = crcTable[(crc ^ byte) & 255] ^ (crc >>> 8);
  checksum.writeUInt32BE((crc ^ 0xffffffff) >>> 0);
  return Buffer.concat([length, type, data, checksum]);
}
export function png(width, height, pixels) {
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width);
  header.writeUInt32BE(height, 4);
  header[8] = 8;
  header[9] = 2;
  const rows = Buffer.alloc(height * (width * 3 + 1));
  for (let y = 0; y < height; y++)
    pixels.copy(
      rows,
      y * (width * 3 + 1) + 1,
      y * width * 3,
      (y + 1) * width * 3,
    );
  return Buffer.concat([
    Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
    chunk("IHDR", header),
    chunk("IDAT", deflateSync(rows, { level: 6 })),
    chunk("IEND", Buffer.alloc(0)),
  ]);
}
