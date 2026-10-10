// One-time, resumable acquisition and offline rendering. The site serves only these assets.
import { mkdir, readFile, writeFile, stat, rename } from "node:fs/promises";
import { gzipSync, gunzipSync } from "node:zlib";
import { createHash } from "node:crypto";
import {
  sourceTile,
  SOURCE_CELLS,
  renderTile,
  png,
  regionForTile,
  regionInfo,
} from "./fishing-map-bake-core.mjs";
const host = "https://mapdata.bitjita.com/v1";
const staging = new URL("../output/research/terrain-source/", import.meta.url);
const root = new URL("../public/bitcraft-map/detail/", import.meta.url);
async function atomicWrite(path, bytes) {
  const temporary = new URL(path.href + ".tmp");
  await writeFile(temporary, bytes);
  await rename(temporary, path);
}
await mkdir(staging, { recursive: true });
await mkdir(root, { recursive: true });
const pinned = new URL("manifest.json", staging);
let manifest;
try {
  manifest = JSON.parse(await readFile(pinned, "utf8"));
} catch {
  manifest = await (await fetch(`${host}/manifest.json`)).json();
  await writeFile(pinned, JSON.stringify(manifest));
}
if (
  manifest.format !== 3 ||
  manifest.worldTiles !== 12800 ||
  manifest.supertile !== 256 ||
  manifest.pad !== 1
)
  throw Error("Source format changed; inspect before baking.");
const version = manifest.generatedAt.replace(/[^0-9]/g, "").slice(0, 14);
const directory = new URL(`${version}/`, root);
await mkdir(directory, { recursive: true });
const baked = {
  format: 1,
  manifestFile: "manifest.json",
  version,
  source: `${host}/manifest.json`,
  generatedAt: manifest.generatedAt,
  worldTiles: 12800,
  smallWorldSize: 38400,
  tileLargeSize: 256,
  nativePixelsPerSmallTile: 2,
  maxDepth: 0,
  regions: [],
  levels: [],
  bytes: 0,
};
const regions = new Map();
for (const level of manifest.levels) {
  const folder = new URL(`l${level.level}/`, directory);
  await mkdir(folder, { recursive: true });
  const entries = [];
  for (let start = 0; start < level.present.length; start += 4) {
    await Promise.all(
      level.present.slice(start, start + 4).map(async ([x, z, hash]) => {
        const stem = `${x}_${z}.${hash}`,
          sourcePath = new URL(`l${level.level}-${stem}.bin.gz`, staging);
        let raw;
        try {
          raw = await readFile(sourcePath);
          sourceTile(gunzipSync(raw));
        } catch {
          let last;
          for (let attempt = 0; attempt < 3; attempt++)
            try {
              const response = await fetch(
                `${host}/l${level.level}/${stem}.bin.gz`,
                { signal: AbortSignal.timeout(30000) },
              );
              if (!response.ok) throw Error(`HTTP ${response.status}`);
              raw = Buffer.from(await response.arrayBuffer());
              if (raw[0] !== 31 || raw[1] !== 139) raw = gzipSync(raw);
              sourceTile(gunzipSync(raw));
              await atomicWrite(sourcePath, raw);
              break;
            } catch (e) {
              last = e;
            }
          if (!raw) throw Error(`Missing source tile ${stem}: ${last}`);
        }
        const bytes = gunzipSync(raw),
          decoded = sourceTile(bytes);
        let maximum = 0;
        for (let i = 0; i < SOURCE_CELLS; i++)
          maximum = Math.max(maximum, decoded.depthAt(i) ?? 0);
        if (level.level === 0)
          baked.maxDepth = Math.max(baked.maxDepth, maximum);
        const files = [
          `${stem}.png`,
          `${stem}.depth.png`,
          ...(level.level === 0 ? [`${stem}.depth.bin.gz`] : []),
        ];
        let exists = true;
        for (const file of files)
          try {
            const previous = await readFile(new URL(file, folder));
            if (file.endsWith(".gz")) {
              if (gunzipSync(previous).length !== SOURCE_CELLS * 2)
                exists = false;
            } else if (previous.subarray(-8, -4).toString() !== "IEND")
              exists = false;
          } catch {
            exists = false;
          }
        if (!exists) {
          const image = renderTile(bytes, level.level, x, z);
          await atomicWrite(
            new URL(files[0], folder),
            png(image.width, image.height, image.base),
          );
          await atomicWrite(
            new URL(files[1], folder),
            png(image.width, image.height, image.depth),
          );
          if (level.level === 0) {
            const depths = Buffer.alloc(SOURCE_CELLS * 2);
            for (let i = 0; i < SOURCE_CELLS; i++)
              depths.writeUInt16LE(decoded.depthAt(i) ?? 65535, i * 2);
            await atomicWrite(new URL(files[2], folder), gzipSync(depths));
          }
        }
        for (const file of files) {
          const size = (await stat(new URL(file, folder))).size;
          baked.bytes += size;
        }
        entries.push([x, z, hash]);
        if (level.level === 0) {
          const region = regionForTile(x, z);
          if (!regions.has(region))
            regions.set(region, { ...regionInfo(region), tiles: [] });
          regions.get(region).tiles.push([x, z, hash]);
        }
      }),
    );
    if (start % 40 === 0 || start + 4 >= level.present.length)
      console.log(
        `L${level.level}: ${Math.min(start + 4, level.present.length)}/${level.present.length} tiles baked`,
      );
  }
  entries.sort((a, b) => a[1] - b[1] || a[0] - b[0]);
  baked.levels.push({ level: level.level, present: entries });
}
if (baked.maxDepth === 255)
  throw Error(
    "Source contains saturated depths; verify exact deep water before publishing.",
  );
for (const region of [...regions.values()].sort((a, b) => a.id - b.id)) {
  region.tiles.sort((a, b) => a[1] - b[1] || a[0] - b[0]);
  const file = `region-${region.id}.json`;
  await writeFile(
    new URL(file, directory),
    JSON.stringify({ version, ...region }, null, 2) + "\n",
  );
  baked.regions.push({
    ...regionInfo(region.id),
    tileCount: region.tiles.length,
    file,
  });
}
baked.integrity = createHash("sha256")
  .update(JSON.stringify(baked.levels))
  .digest("hex");
await writeFile(
  new URL("manifest.json", directory),
  JSON.stringify(baked, null, 2) + "\n",
);
await writeFile(
  new URL("manifest.json", root),
  JSON.stringify(baked, null, 2) + "\n",
);
console.log(
  `Complete: ${baked.regions.length} region maps; ${(baked.bytes / 1048576).toFixed(1)} MiB; maximum depth ${baked.maxDepth}; snapshot ${version}`,
);
