// Rebuild the small overview from public terrain data. Runtime depth is always relay data.
// Node built-ins keep this asset task independent of image editors and native packages.
import { writeFile, mkdir } from "node:fs/promises";
import { gunzipSync, deflateSync } from "node:zlib";

const host = "https://mapdata.bitjita.com/v1";
async function fetchData(path) {
  const response = await fetch(`${host}/${path}`, {
    signal: AbortSignal.timeout(20000),
  });
  if (!response.ok) throw Error(`${path}: HTTP ${response.status}`);
  return response;
}
const manifest = await (await fetchData("manifest.json")).json();
const level = 3,
  step = 1 << level,
  size = Math.ceil(manifest.worldTiles / step);
if (
  manifest.format !== 3 ||
  manifest.supertile !== 256 ||
  manifest.pad !== 1 ||
  manifest.worldTiles !== 12800
)
  throw Error(
    "Map format or world scale changed; verify coordinates before regenerating.",
  );
const colors = [
  [99, 122, 78],
  [133, 141, 84],
  [82, 122, 93],
  [151, 138, 106],
  [97, 109, 73],
  [119, 126, 108],
  [169, 155, 120],
  [88, 126, 116],
];
const pixels = Buffer.alloc(size * size * 3);
for (let i = 0; i < pixels.length; i += 3) pixels.set([18, 36, 48], i);
const tiles = manifest.levels.find((l) => l.level === level).present;
for (let i = 0; i < tiles.length; i += 4) {
  await Promise.all(
    tiles.slice(i, i + 4).map(async ([x, z, hash]) => {
      let bytes = Buffer.from(
        await (
          await fetchData(`l${level}/${x}_${z}.${hash}.bin.gz`)
        ).arrayBuffer(),
      );
      if (bytes[0] === 31 && bytes[1] === 139) bytes = gunzipSync(bytes);
      if (bytes.length < 258 * 258 * 6) throw Error("Short terrain tile");
      for (let row = 0; row < 256; row++)
        for (let col = 0; col < 256; col++) {
          const px = x * 256 + col,
            pz = z * 256 + row;
          if (px >= size || pz >= size) continue;
          const index = ((row + 1) * 258 + col + 1) * 4,
            biome = bytes[index],
            depth = bytes[index + 3];
          const land = colors[biome % colors.length],
            mix = Math.min(depth / 25, 1);
          const color =
            biome === 255
              ? [18, 36, 48]
              : depth
                ? [57, 132, 149].map((v, channel) =>
                    Math.floor(v * (1 - mix) + [24, 59, 86][channel] * mix),
                  )
                : land;
          pixels.set(color, ((size - 1 - pz) * size + px) * 3);
        }
    }),
  );
}
function crc32(bytes) {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++)
      crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function pngChunk(name, data) {
  const type = Buffer.from(name),
    size = Buffer.alloc(4),
    crc = Buffer.alloc(4);
  size.writeUInt32BE(data.length);
  crc.writeUInt32BE(crc32(Buffer.concat([type, data])));
  return Buffer.concat([size, type, data, crc]);
}
const header = Buffer.alloc(13);
header.writeUInt32BE(size);
header.writeUInt32BE(size, 4);
header[8] = 8;
header[9] = 2;
const scanlines = Buffer.alloc(size * (size * 3 + 1));
for (let row = 0; row < size; row++)
  pixels.copy(
    scanlines,
    row * (size * 3 + 1) + 1,
    row * size * 3,
    (row + 1) * size * 3,
  );
const png = Buffer.concat([
  Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
  pngChunk("IHDR", header),
  pngChunk("IDAT", deflateSync(scanlines)),
  pngChunk("IEND", Buffer.alloc(0)),
]);
const directory = new URL("../public/bitcraft-map/", import.meta.url);
await mkdir(directory, { recursive: true });
await writeFile(new URL("world.png", directory), png);
await writeFile(
  new URL("overview.json", directory),
  JSON.stringify(
    {
      source: `${host}/manifest.json`,
      generatedAt: manifest.generatedAt,
      worldTiles: manifest.worldTiles,
      level,
      note: "Overview uses downsampled terrain and an original palette. Exact depths come from live relay terrain.",
    },
    null,
    2,
  ) + "\n",
);
console.log(
  `Fishing overview: ${size} × ${size}, ${tiles.length} tiles, terrain generated ${manifest.generatedAt}`,
);
