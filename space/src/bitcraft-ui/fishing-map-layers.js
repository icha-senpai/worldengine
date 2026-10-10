import L from "leaflet";
import { HEX_SCALE } from "./fishing-map";
import { FISH_COLOR, schoolPoints } from "./fishing-map-render";

const BUFFER = 96;
export class ViewportCanvas extends L.Layer {
  constructor(paneName, zIndex) {
    super();
    this.paneName = paneName;
    this.zIndex = zIndex;
  }
  onAdd(map) {
    this.map = map;
    const pane = map.getPane(this.paneName) ?? map.createPane(this.paneName);
    pane.style.zIndex = this.zIndex;
    pane.style.pointerEvents = "none";
    this.canvas = L.DomUtil.create(
      "canvas",
      `leaflet-layer fishing-${this.paneName}-canvas`,
      pane,
    );
    this.redraw();
  }
  getEvents() {
    return { move: this.redraw, zoomend: this.redraw, resize: this.redraw };
  }
  redraw() {
    if (!this.map || this.frame) return;
    this.frame = requestAnimationFrame(() => {
      this.frame = null;
      if (this.map) this.paint();
    });
  }
  prepare() {
    const size = this.map.getSize(),
      dpr = Math.min(devicePixelRatio || 1, 2);
    const width = size.x + BUFFER * 2,
      height = size.y + BUFFER * 2;
    if (
      this.canvas.width !== Math.round(width * dpr) ||
      this.canvas.height !== Math.round(height * dpr)
    ) {
      this.canvas.width = Math.round(width * dpr);
      this.canvas.height = Math.round(height * dpr);
      this.canvas.style.width = `${width}px`;
      this.canvas.style.height = `${height}px`;
    }
    L.DomUtil.setPosition(
      this.canvas,
      this.map.containerPointToLayerPoint([-BUFFER, -BUFFER]),
    );
    const ctx = this.canvas.getContext("2d");
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);
    const corner = this.map.containerPointToLatLng([-BUFFER, -BUFFER]);
    return {
      ctx,
      width,
      height,
      left: corner.lng,
      top: corner.lat,
      scale: 2 ** this.map.getZoom(),
    };
  }
  onRemove() {
    cancelAnimationFrame(this.frame);
    this.frame = null;
    this.canvas?.remove();
    this.map = null;
  }
}

export class SchoolCanvasLayer extends ViewportCanvas {
  constructor() {
    super("schools", "450");
    this.rows = [];
    this.points = [];
    this.visible = true;
    this.hoverLabel = document.createElement("span");
    this.tooltip = L.tooltip({ direction: "top", offset: [0, -10] }).setContent(
      this.hoverLabel,
    );
  }
  getEvents() {
    return {
      ...super.getEvents(),
      move: this.pan,
      moveend: this.redraw,
      mousemove: this.hover,
      mouseout: this.hideTooltip,
      movestart: this.hideTooltip,
    };
  }
  pan() {
    if (!this.canvas) return;
    const current = L.DomUtil.getPosition(this.canvas),
      desired = this.map.containerPointToLayerPoint([-BUFFER, -BUFFER]);
    if (
      Math.abs(current.x - desired.x) > BUFFER * 0.75 ||
      Math.abs(current.y - desired.y) > BUFFER * 0.75
    )
      this.redraw();
  }
  setSchools(rows, selectedId, visible) {
    this.rows = rows;
    this.selectedId = selectedId;
    this.visible = visible;
    this.redraw();
  }
  paint() {
    const { ctx, width, height, left, top, scale } = this.prepare();
    this.points = this.visible
      ? schoolPoints(
          this.rows,
          left,
          top,
          scale,
          width,
          height,
          this.map.getZoom() < 0 ? 40 : 0,
        )
      : [];
    ctx.font = "600 10px Inter, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    for (const p of this.points) {
      const school = p.school,
        cluster = p.count > 1;
      const radius = cluster
        ? 11
        : school.entityId === this.selectedId
          ? 9
          : school.chummed
            ? 6
            : 4;
      p.radius = radius;
      ctx.globalAlpha = 1;
      ctx.fillStyle = FISH_COLOR;
      ctx.beginPath();
      ctx.arc(p.x, p.y, radius, 0, Math.PI * 2);
      ctx.fill();
      if (cluster || school.chummed || school.entityId === this.selectedId) {
        ctx.strokeStyle = cluster ? "#ffb5ef" : "#fff3d6";
        ctx.lineWidth = 1.5;
        ctx.stroke();
      }
      if (cluster) {
        ctx.fillStyle = "#24051d";
        ctx.fillText(String(p.count), p.x, p.y);
      }
    }
  }
  pick(containerPoint) {
    if (!this.visible || !this.canvas) return null;
    const p = this.map.containerPointToLayerPoint(containerPoint),
      origin = L.DomUtil.getPosition(this.canvas);
    const x = p.x - origin.x,
      y = p.y - origin.y;
    let closest = null,
      best = Infinity;
    for (const item of this.points) {
      const d = (item.x - x) ** 2 + (item.y - y) ** 2;
      if (d <= (item.radius + 4) ** 2 && d < best) {
        closest = item;
        best = d;
      }
    }
    return closest;
  }
  hover(event) {
    if (this.map.dragging?.moving()) return;
    const p = this.pick(event.containerPoint);
    this.map.getContainer().style.cursor = p ? "pointer" : "";
    if (!p) {
      this.hideTooltip();
      return;
    }
    const label =
      p.count > 1
        ? `${p.count} schools · click to zoom in`
        : `T${p.school.tier} ${p.school.name} · ${p.school.chummed ? "Chummed" : "Ordinary"}`;
    if (
      this.hoverLabel.textContent === label &&
      this.map.hasLayer(this.tooltip)
    )
      return;
    this.hoverLabel.textContent = label;
    this.tooltip.setLatLng([p.wy, p.wx]).addTo(this.map);
  }
  hideTooltip() {
    if (this.map?.hasLayer(this.tooltip)) this.map.removeLayer(this.tooltip);
  }
  onRemove() {
    this.hideTooltip();
    super.onRemove();
  }
}
