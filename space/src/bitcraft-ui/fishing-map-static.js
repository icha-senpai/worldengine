import { HEX_SCALE, terrainCell } from "./fishing-map";
import {
  version as SNAPSHOT_VERSION,
  manifestFile as SNAPSHOT_MANIFEST,
} from "../../public/bitcraft-map/detail/manifest.json";

export function createStaticTerrain({
  fetcher = globalThis.fetch,
  storage = globalThis.caches,
} = {}) {
  let manifestPromise, cachePromise;
  const data = new Map(),
    pending = new Map();
  async function cachedResponse(path, cachedFallbackPath) {
    if (!cachePromise)
      cachePromise = Promise.resolve()
        .then(() => storage?.open("space-fishing-atlas-v1"))
        .catch(() => null);
    const cache = await cachePromise;
    const saved = await cache?.match(path).catch(() => null);
    if (saved) return saved;
    if (cachedFallbackPath) {
      const legacy = await cache?.match(cachedFallbackPath).catch(() => null);
      if (legacy) return legacy;
    }
    const response = await fetcher(path, { cache: "force-cache" });
    if (!response.ok) throw Error(`Map asset unavailable (${response.status})`);
    if (cache) await cache.put(path, response.clone()).catch(() => {});
    return response;
  }
  async function ready() {
    if (!manifestPromise)
      manifestPromise = cachedResponse(
        `/bitcraft-map/detail/${SNAPSHOT_VERSION}/${SNAPSHOT_MANIFEST || "manifest.json"}`,
      )
        .then(async (r) => {
          if (!r.ok) throw Error("Detailed map is unavailable");
          const m = await r.json();
          if (
            m.format !== 1 ||
            m.smallWorldSize !== 38400 ||
            m.tileLargeSize !== 256 ||
            m.maxDepth >= 255 ||
            (m.imageFormat && !["png", "webp"].includes(m.imageFormat))
          )
            throw Error("Unsupported detailed map snapshot");
          m.tiles = new Map(
            m.levels.map((l) => [
              l.level,
              new Map(l.present.map(([x, z, hash]) => [`${x}:${z}`, hash])),
            ]),
          );
          return m;
        })
        .catch((e) => {
          manifestPromise = null;
          throw e;
        });
    return manifestPromise;
  }
  function path(m, level, x, z, suffix) {
    const hash = m.tiles.get(level)?.get(`${x}:${z}`);
    return hash
      ? `/bitcraft-map/detail/${m.version}/l${level}/${x}_${z}.${hash}${suffix}`
      : null;
  }
  async function loadData(x, z) {
    const key = `${x}:${z}`;
    if (data.has(key)) {
      const value = data.get(key);
      data.delete(key);
      data.set(key, value);
      return;
    }
    if (pending.has(key)) return pending.get(key);
    const request = (async () => {
      const m = await ready(),
        url = path(m, 0, x, z, ".depth.bin.gz");
      if (!url) return;
      const response = await cachedResponse(url);
      let bytes = new Uint8Array(await response.arrayBuffer());
      if (bytes[0] === 31 && bytes[1] === 139) {
        const stream = new Blob([bytes])
          .stream()
          .pipeThrough(new DecompressionStream("gzip"));
        bytes = new Uint8Array(await new Response(stream).arrayBuffer());
      }
      if (bytes.length !== 258 * 258 * 2) throw Error("Incomplete depth asset");
      data.set(key, new Uint16Array(bytes.buffer, bytes.byteOffset, 258 * 258));
      while (data.size > 32) data.delete(data.keys().next().value);
    })().finally(() => pending.delete(key));
    pending.set(key, request);
    return request;
  }
  return {
    ready,
    async overviewUrl() {
      const m = await ready().catch(() => null);
      if (!m?.overviewImage) return "/bitcraft-map/world.png";
      const response = await cachedResponse(`/bitcraft-map/${m.overviewImage}`);
      return URL.createObjectURL(await response.blob());
    },
    async bitmap(level, x, z, depth) {
      const m = await ready(),
        extension = m.imageFormat || "png",
        suffix = depth ? ".depth" : "",
        url = path(m, level, x, z, `${suffix}.${extension}`);
      if (!url) return null;
      const legacy =
        extension === "webp" ? path(m, level, x, z, `${suffix}.png`) : null;
      return createImageBitmap(
        await (await cachedResponse(url, legacy)).blob(),
      );
    },
    depthAtTerrain(x, z) {
      if (x < 0 || z < 0 || x >= 12800 || z >= 12800) return null;
      const tx = Math.floor(x / 256),
        tz = Math.floor(z / 256),
        values = data.get(`${tx}:${tz}`);
      const value = values?.[((z % 256) + 1) * 258 + (x % 256) + 1];
      return value === undefined || value === 65535 ? null : value;
    },
    async loadBounds(west, south, east, north) {
      const a = terrainCell(Math.max(0, west - 12), Math.max(0, south - 12));
      const b = terrainCell(
        Math.min(38399, east + 12),
        Math.min(38399, north + 12),
      );
      const minX = Math.max(0, Math.floor(a.x / 256)),
        maxX = Math.min(49, Math.floor(b.x / 256));
      const minZ = Math.max(0, Math.floor(a.z / 256)),
        maxZ = Math.min(49, Math.floor(b.z / 256));
      if ((maxX - minX + 1) * (maxZ - minZ + 1) > 16) return;
      const jobs = [];
      for (let z = minZ; z <= maxZ; z++)
        for (let x = minX; x <= maxX; x++) jobs.push([x, z]);
      for (let start = 0; start < jobs.length; start += 4)
        await Promise.all(
          jobs.slice(start, start + 4).map(([x, z]) => loadData(x, z)),
        );
    },
  };
}

export function visibleAtlasTiles(
  manifest,
  level,
  left,
  top,
  width,
  height,
  scale,
) {
  const step = 768 * 2 ** level,
    h = step * HEX_SCALE,
    grid = Math.ceil(38400 / step);
  const minX = Math.max(0, Math.floor(left / step)),
    maxX = Math.min(grid - 1, Math.floor((left + width / scale) / step));
  const minZ = Math.max(0, Math.floor((top - height / scale) / h)),
    maxZ = Math.min(grid - 1, Math.floor(top / h));
  const tiles = [];
  for (let z = minZ; z <= maxZ; z++)
    for (let x = minX; x <= maxX; x++)
      if (manifest.tiles.get(level)?.has(`${x}:${z}`))
        tiles.push({ level, x, z, step, h });
  return tiles;
}
