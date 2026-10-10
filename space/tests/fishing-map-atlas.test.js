import { describe, it, expect, vi, afterEach } from "vitest";
import { gzipSync, inflateSync, gunzipSync } from "node:zlib";
import { readFile } from "node:fs/promises";
import {
  SOURCE_CELLS,
  fineCellIndex,
  regionInfo,
  regionForTile,
  png,
  sourceTile,
} from "../scripts/fishing-map-bake-core.mjs";
import {
  createStaticTerrain,
  visibleAtlasTiles,
} from "../src/bitcraft-ui/fishing-map-static";
import {
  HEX_SCALE,
  smallCell,
  spawnRing,
} from "../src/bitcraft-ui/fishing-map";
vi.mock("leaflet", () => ({ default: { Layer: class {} } }));
import { AtlasCanvasLayer } from "../src/bitcraft-ui/fishing-map-atlas-layer";
afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("Permanent regional map assets", () => {
  it("preserves shared depth cells across every adjoining region boundary", async () => {
    const m = JSON.parse(
      await readFile(
        new URL("../public/bitcraft-map/detail/manifest.json", import.meta.url),
      ),
    );
    const entries = m.levels[0].present,
      hashes = new Map(entries.map(([x, z, h]) => [`${x}:${z}`, h]));
    const loaded = new Map();
    async function values(x, z) {
      const key = `${x}:${z}`;
      if (!loaded.has(key)) {
        const file = new URL(
          `../public/bitcraft-map/detail/${m.version}/l0/${x}_${z}.${hashes.get(key)}.depth.bin.gz`,
          import.meta.url,
        );
        loaded.set(key, gunzipSync(await readFile(file)));
      }
      return loaded.get(key);
    }
    let compared = 0;
    for (const [x, z] of entries)
      for (const [dx, dz] of [
        [1, 0],
        [0, 1],
      ]) {
        if (
          !hashes.has(`${x + dx}:${z + dz}`) ||
          regionForTile(x, z) === regionForTile(x + dx, z + dz)
        )
          continue;
        const a = await values(x, z),
          b = await values(x + dx, z + dz);
        for (let i = 1; i <= 256; i++)
          for (const side of [0, 1]) {
            const ai = dx ? i * 258 + 256 + side : (256 + side) * 258 + i;
            const bi = dx ? i * 258 + side : side * 258 + i;
            if (a.readUInt16LE(ai * 2) !== b.readUInt16LE(bi * 2))
              throw Error(
                `Depth seam at ${x},${z} to ${x + dx},${z + dz}, cell ${i}`,
              );
            compared++;
          }
      }
    expect(compared).toBeGreaterThan(60000);
  });
  it("covers all nine playable regions and four adjoining ocean sections without duplicate tiles", async () => {
    const m = JSON.parse(
      await readFile(
        new URL("../public/bitcraft-map/detail/manifest.json", import.meta.url),
      ),
    );
    expect(m.levels.map((l) => l.present.length)).toEqual([
      1300, 325, 94, 28, 11, 4,
    ]);
    expect(m.regions).toHaveLength(13);
    expect(m.regions.every((r) => r.tileCount === 100)).toBe(true);
    expect(m.maxDepth).toBeLessThan(255);
    const tiles = new Set();
    for (const r of m.regions) {
      const path = new URL(
        `../public/bitcraft-map/detail/${m.version}/${r.file}`,
        import.meta.url,
      );
      const region = JSON.parse(await readFile(path));
      expect(region.bounds).toEqual(regionInfo(r.id).bounds);
      for (const [x, z] of region.tiles) {
        expect(regionForTile(x, z)).toBe(r.id);
        expect(tiles.has(`${x}:${z}`)).toBe(false);
        tiles.add(`${x}:${z}`);
      }
    }
    expect(tiles.size).toBe(1300);
    expect(regionInfo(8).bounds[2]).toBe(regionInfo(9).bounds[0]);
    expect(regionInfo(9).bounds[3]).toBe(regionInfo(14).bounds[1]);
  });
  it("keeps fine hex lookup identical across independently baked tile origins and joins", () => {
    for (const [tx, tz] of [
      [0, 0],
      [20, 0],
      [10, 39],
      [49, 20],
      [33, 14],
    ])
      for (const [x, y] of [
        [0, 0],
        [1535, 0],
        [0, 1330],
        [1535, 1330],
        [767, 665],
        [1519, 17],
      ])
        expect(fineCellIndex(tx, tz, x, y, 1536, 1331)).toBe(
          fineCellIndex(1, 1, x, y, 1536, 1331),
        );
  });
  it("writes a standards-readable PNG with intact pixel values", () => {
    const encoded = png(2, 1, Buffer.from([82, 203, 187, 76, 156, 223]));
    expect(encoded.readUInt32BE(16)).toBe(2);
    expect(encoded.readUInt32BE(20)).toBe(1);
    const idat = encoded.indexOf(Buffer.from("IDAT"));
    const length = encoded.readUInt32BE(idat - 4);
    expect([
      ...inflateSync(encoded.subarray(idat + 4, idat + 4 + length)),
    ]).toEqual([0, 82, 203, 187, 76, 156, 223]);
    expect(encoded.subarray(-8, -4).toString()).toBe("IEND");
  });
  it("distinguishes missing source cells from deep water and rejects truncated data", () => {
    const bytes = Buffer.alloc(SOURCE_CELLS * 8);
    bytes[3] = 230;
    expect(sourceTile(bytes).depthAt(0)).toBe(230);
    bytes[0] = 255;
    expect(sourceTile(bytes).depthAt(0)).toBeNull();
    expect(() => sourceTile(bytes.subarray(0, 100))).toThrow();
  });
});

describe("Static depth loading", () => {
  function setup() {
    const m = {
      format: 1,
      version: "snapshot",
      smallWorldSize: 38400,
      tileLargeSize: 256,
      maxDepth: 230,
      levels: [{ level: 0, present: [[33, 14, "hash"]] }],
    };
    const values = new Uint16Array(SOURCE_CELLS).fill(18);
    const store = new Map();
    const storage = {
      open: async () => ({
        match: async (path) => store.get(path)?.clone(),
        put: async (path, r) => store.set(path, r.clone()),
      }),
    };
    const fetcher = vi.fn(async (path) =>
      path.endsWith(".json")
        ? Response.json(m)
        : new Response(gzipSync(Buffer.from(values.buffer))),
    );
    return { m, values, storage, fetcher };
  }
  it("persists the optimized overview and gives each mount its own releasable blob URL", async () => {
    const { m, storage, fetcher } = setup();
    m.overviewImage = "world.hash.webp";
    const createUrl = vi
      .spyOn(URL, "createObjectURL")
      .mockReturnValue("blob:overview");
    const terrain = createStaticTerrain({ storage, fetcher });
    expect(await terrain.overviewUrl()).toBe("blob:overview");
    expect(fetcher.mock.calls.at(-1)[0]).toBe("/bitcraft-map/world.hash.webp");
    const before = fetcher.mock.calls.length;
    expect(await createStaticTerrain({ storage, fetcher }).overviewUrl()).toBe(
      "blob:overview",
    );
    expect(fetcher).toHaveBeenCalledTimes(before);
    expect(createUrl).toHaveBeenCalledTimes(2);
  });
  it("fetches lossless WebP for new visitors and reuses it after reopening", async () => {
    const { m, storage, fetcher } = setup();
    m.imageFormat = "webp";
    vi.stubGlobal(
      "createImageBitmap",
      vi.fn(async () => ({ width: 1536 })),
    );
    const terrain = createStaticTerrain({ storage, fetcher });
    await terrain.bitmap(0, 33, 14, true);
    expect(fetcher.mock.calls.at(-1)[0]).toBe(
      "/bitcraft-map/detail/snapshot/l0/33_14.hash.depth.webp",
    );
    const before = fetcher.mock.calls.length;
    const reopened = createStaticTerrain({ storage, fetcher });
    await reopened.bitmap(0, 33, 14, true);
    expect(fetcher).toHaveBeenCalledTimes(before);
  });
  it("keeps previously cached PNG tiles usable without downloading their WebP replacements", async () => {
    const { m, storage, fetcher } = setup();
    m.imageFormat = "webp";
    const cached = new Uint8Array([137, 80, 78, 71]);
    const cache = await storage.open();
    await cache.put(
      "/bitcraft-map/detail/snapshot/l0/33_14.hash.depth.png",
      new Response(cached),
    );
    const decode = vi.fn(
      async (blob) => new Uint8Array(await blob.arrayBuffer()),
    );
    vi.stubGlobal("createImageBitmap", decode);
    const terrain = createStaticTerrain({ storage, fetcher });
    expect(await terrain.bitmap(0, 33, 14, true)).toEqual(cached);
    expect(fetcher.mock.calls).toHaveLength(1);
    expect(fetcher.mock.calls[0][0]).toMatch(/manifest/);
  });
  it("loads exact depths and fleeing rings from local files, then survives an unavailable server through persistent cache", async () => {
    const { storage, fetcher } = setup();
    const terrain = createStaticTerrain({ storage, fetcher });
    const p = smallCell(8606, 3698);
    await terrain.loadBounds(p.x - 9, p.z - 9, p.x + 9, p.z + 9);
    expect(terrain.depthAtTerrain(8606, 3698)).toBe(18);
    expect(spawnRing(terrain, p.x, p.z)).toEqual({
      eligible: 144,
      unknown: 0,
      total: 144,
    });
    const firstCount = fetcher.mock.calls.length;
    await terrain.loadBounds(p.x, p.z, p.x, p.z);
    expect(fetcher).toHaveBeenCalledTimes(firstCount);
    const offline = vi.fn((path) =>
      path.endsWith(".json") ? fetcher(path) : Promise.reject(Error("offline")),
    );
    const reopened = createStaticTerrain({ storage, fetcher: offline });
    await reopened.loadBounds(p.x, p.z, p.x, p.z);
    expect(reopened.depthAtTerrain(8606, 3698)).toBe(18);
    expect(offline).not.toHaveBeenCalled();
    expect(
      fetcher.mock.calls.every(([url]) =>
        url.startsWith("/bitcraft-map/detail/"),
      ),
    ).toBe(true);
  });
  it("leaves uncovered cells unknown and rejects incomplete assets without treating them as water", async () => {
    const { m, values } = setup();
    values.fill(65535);
    const terrain = createStaticTerrain({
      fetcher: async (path) =>
        path.endsWith(".json") ? Response.json(m) : new Response(values.buffer),
    });
    const p = smallCell(8606, 3698);
    await terrain.loadBounds(p.x, p.z, p.x, p.z);
    expect(terrain.depthAtTerrain(8606, 3698)).toBeNull();
    expect(terrain.depthAtTerrain(-1, 0)).toBeNull();
    const broken = createStaticTerrain({
      fetcher: async (path) =>
        path.endsWith(".json") ? Response.json(m) : new Response("bad"),
    });
    await expect(broken.loadBounds(p.x, p.z, p.x, p.z)).rejects.toThrow(
      "Incomplete",
    );
  });
  it("culls absent and offscreen tiles using the world coordinate extent", () => {
    const manifest = {
      tiles: new Map([
        [
          0,
          new Map([
            ["33:14", "hash"],
            ["0:0", "other"],
          ]),
        ],
      ]),
    };
    const tiles = visibleAtlasTiles(
      manifest,
      0,
      33 * 768,
      15 * 768 * HEX_SCALE,
      300,
      300,
      4,
    );
    expect(tiles).toHaveLength(1);
    expect(tiles[0]).toMatchObject({ x: 33, z: 14, step: 768 });
  });
});

describe("Atlas bitmap lifetime", () => {
  it("bounds decoded memory and closes both evicted images and late arrivals after leaving the page", () => {
    vi.stubGlobal(
      "requestAnimationFrame",
      vi.fn(() => 1),
    );
    vi.stubGlobal("cancelAnimationFrame", vi.fn());
    const layer = new AtlasCanvasLayer({}),
      images = [];
    layer.map = {};
    for (let i = 0; i < 80; i++) {
      const bitmap = { width: 512, height: 512, close: vi.fn() };
      images.push(bitmap);
      layer.receive(String(i), bitmap);
    }
    expect(layer.bytes).toBe(64 * 1048576);
    expect(layer.cache.size).toBe(64);
    expect(
      images.slice(0, 16).every((b) => b.close.mock.calls.length === 1),
    ).toBe(true);
    layer.onRemove();
    expect(images.every((b) => b.close.mock.calls.length === 1)).toBe(true);
    const late = { close: vi.fn() };
    layer.receive("late", late);
    expect(late.close).toHaveBeenCalledOnce();
    expect(layer.bytes).toBe(0);
  });
});
