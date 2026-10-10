import { ViewportCanvas } from "./fishing-map-layers";
import { HEX_SCALE } from "./fishing-map";
import { visibleAtlasTiles } from "./fishing-map-static";
import L from "leaflet";

export class AtlasCanvasLayer extends ViewportCanvas {
  constructor(terrain, callbacks = {}) {
    super("depth", "350");
    this.terrain = terrain;
    this.callbacks = callbacks;
    this.cache = new Map();
    this.pending = new Set();
    this.failed = new Set();
    this.queue = new Map();
    this.bytes = 0;
    this.depth = true;
  }
  onAdd(map) {
    super.onAdd(map);
    this.retry();
  }
  getEvents() {
    return { ...super.getEvents(), move: this.pan, moveend: this.redraw };
  }
  pan() {
    if (!this.canvas || !this.map) return;
    const current = L.DomUtil.getPosition(this.canvas);
    const desired = this.map.containerPointToLayerPoint([-96, -96]);
    if (
      Math.abs(current.x - desired.x) > 72 ||
      Math.abs(current.y - desired.y) > 72
    )
      this.redraw();
  }
  async retry() {
    this.failed.clear();
    this.callbacks.status?.("loading");
    try {
      const manifest = await this.terrain.ready();
      if (!this.map) return;
      this.manifest = manifest;
      this.callbacks.snapshot?.(manifest);
      this.redraw();
    } catch (e) {
      if (this.map) this.callbacks.status?.("error", e.message);
    }
  }
  setView(selected, depth) {
    this.selected = selected;
    this.depth = depth;
    this.redraw();
  }
  drop(key) {
    const value = this.cache.get(key);
    if (!value) return;
    this.bytes -= value.bytes;
    value.bitmap.close?.();
    this.cache.delete(key);
  }
  receive(key, bitmap) {
    if (!this.map) {
      bitmap?.close?.();
      return;
    }
    this.drop(key);
    if (!bitmap) return;
    this.cache.set(key, { bitmap, bytes: bitmap.width * bitmap.height * 4 });
    this.bytes += bitmap.width * bitmap.height * 4;
    while (this.bytes > 64 * 1048576 || this.cache.size > 96)
      this.drop(this.cache.keys().next().value);
    this.redraw();
  }
  pump() {
    while (this.map && this.pending.size < 4 && this.queue.size) {
      const [key, tile] = this.queue.entries().next().value;
      this.queue.delete(key);
      this.pending.add(key);
      this.terrain
        .bitmap(tile.level, tile.x, tile.z, tile.depth)
        .then((bitmap) => this.receive(key, bitmap))
        .catch((e) => {
          if (this.map) {
            this.failed.add(key);
            this.callbacks.status?.("error", e.message);
          }
        })
        .finally(() => {
          this.pending.delete(key);
          this.pump();
          this.redraw();
        });
    }
  }
  paint() {
    const { ctx, width, height, left, top, scale } = this.prepare();
    if (!this.manifest) return;
    let level = Math.min(5, Math.max(0, Math.ceil(-this.map.getZoom())));
    let tiles = visibleAtlasTiles(
      this.manifest,
      level,
      left,
      top,
      width,
      height,
      scale,
    );
    if (level === 0 && tiles.length > 8) {
      level = 1;
      tiles = visibleAtlasTiles(
        this.manifest,
        level,
        left,
        top,
        width,
        height,
        scale,
      );
    }
    this.queue.clear();
    let missing = false,
      failed = false;
    ctx.imageSmoothingEnabled = false;
    for (const tile of tiles) {
      const key = `${level}:${tile.x}:${tile.z}:${this.depth}`;
      const cached = this.cache.get(key);
      if (cached) {
        this.cache.delete(key);
        this.cache.set(key, cached);
        ctx.drawImage(
          cached.bitmap,
          (tile.x * tile.step - left) * scale,
          (top - (tile.z + 1) * tile.h) * scale,
          tile.step * scale,
          tile.h * scale,
        );
      } else {
        missing = true;
        failed ||= this.failed.has(key);
        if (!this.pending.has(key) && !this.failed.has(key))
          this.queue.set(key, { ...tile, depth: this.depth });
      }
    }
    this.callbacks.status?.(failed ? "error" : missing ? "loading" : "ready");
    this.pump();
    this.drawRing(ctx, left, top, scale);
  }
  drawRing(ctx, left, top, scale) {
    if (!this.selected || this.map.getZoom() < 0) return;
    const s = this.selected,
      q = s.x - Math.trunc(s.z / 2);
    ctx.strokeStyle = "#fff2cb";
    ctx.lineWidth = 2;
    ctx.setLineDash([4, 4]);
    for (const d of [7, 9]) {
      ctx.beginPath();
      for (const [dq, dr] of [
        [d, 0],
        [0, d],
        [-d, d],
        [-d, 0],
        [0, -d],
        [d, -d],
      ]) {
        const z = s.z + dr,
          x = q + dq + Math.trunc(z / 2) + (z % 2 ? 0.5 : 0);
        ctx.lineTo((x - left) * scale, (top - z * HEX_SCALE) * scale);
      }
      ctx.closePath();
      ctx.stroke();
    }
    ctx.setLineDash([]);
  }
  onRemove() {
    this.queue.clear();
    for (const key of this.cache.keys()) this.drop(key);
    super.onRemove();
  }
}
