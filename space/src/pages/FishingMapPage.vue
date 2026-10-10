<script setup>
import {
  computed,
  onMounted,
  onBeforeUnmount,
  ref,
  shallowRef,
  watch,
} from "vue";
import { useRoute } from "vue-router";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import Layout from "../bitcraft-ui/Layout.vue";
import SelectInput from "../bitcraft-ui/shared/SelectInput.vue";
import AppButton from "../bitcraft-ui/shared/ui/AppButton.vue";
import {
  REGIONS,
  SPECIES,
  SCHOOL_BY_ID,
  WORLD_SIZE,
  HEX_SCALE,
  waterDepth,
  spawnRing,
} from "../bitcraft-ui/fishing-map";
import { subscribeMap } from "../bitcraft-ui/fishing-map-relay";
import { readMapView, saveMapView } from "../bitcraft-ui/fishing-map-cache";
import { SchoolCanvasLayer } from "../bitcraft-ui/fishing-map-layers";
import { FISH_COLOR } from "../bitcraft-ui/fishing-map-render";
import { createStaticTerrain } from "../bitcraft-ui/fishing-map-static";
import { AtlasCanvasLayer } from "../bitcraft-ui/fishing-map-atlas-layer";

const route = useRoute();
const region = ref(
  REGIONS.some(([id]) => id === Number(route.query.region))
    ? Number(route.query.region)
    : 9,
);
const tier = ref(1),
  schoolType = ref("all"),
  showDepth = ref(true),
  showFish = ref(true);
const mapElement = ref(null),
  schools = shallowRef([]),
  selected = ref(null),
  inspected = ref(null);
const status = ref("connecting"),
  depthStatus = ref("loading"),
  error = ref(""),
  updated = ref("");
const terrainRevision = ref(0),
  viewRevision = ref(0),
  copied = ref(false);
const overviewDate = ref("snapshot");
const mapError = ref("");
const chunks = createStaticTerrain(),
  resources = new Map(),
  locations = new Map();
const schoolIndex = new Map(),
  dirtySchools = new Set();
let terrainRequest = 0;
let overviewUrl;
let map, markers, depthLayer, observer, stopFish, loadTimer, schoolTimer;
let mounted = false,
  generation = 0;
const filtered = computed(() =>
  schools.value.filter(
    (s) =>
      (!tier.value || s.tier === Number(tier.value)) &&
      (schoolType.value === "all" ||
        s.chummed === (schoolType.value === "chummed")),
  ),
);
const inView = computed(() => {
  viewRevision.value;
  if (!map) return [];
  const bounds = map.getBounds(),
    center = map.getCenter();
  const west = bounds.getWest(),
    east = bounds.getEast(),
    south = bounds.getSouth(),
    north = bounds.getNorth();
  return filtered.value
    .flatMap((s) => {
      const x = s.x + (s.z % 2 ? 0.5 : 0),
        y = s.z * HEX_SCALE;
      return x >= west && x <= east && y >= south && y <= north
        ? [
            {
              school: s,
              distance: (x - center.lng) ** 2 + (y - center.lat) ** 2,
            },
          ]
        : [];
    })
    .sort((a, b) => a.distance - b.distance)
    .slice(0, 50)
    .map((row) => row.school);
});
const selectedDepth = computed(() => {
  terrainRevision.value;
  return selected.value
    ? waterDepth(chunks, selected.value.x, selected.value.z)
    : null;
});
const ring = computed(() => {
  terrainRevision.value;
  return selected.value
    ? spawnRing(chunks, selected.value.x, selected.value.z)
    : null;
});
const inspectedDepth = computed(() => {
  terrainRevision.value;
  return inspected.value
    ? waterDepth(chunks, inspected.value.x, inspected.value.z)
    : null;
});
const statusLabel = computed(() =>
  status.value === "live"
    ? "Live schools"
    : status.value === "connecting"
      ? "Connecting…"
      : "Reconnecting…",
);
const depthLabel = computed(() =>
  depthStatus.value === "ready"
    ? showDepth.value
      ? "Detailed depth map"
      : "Detailed terrain map"
    : depthStatus.value === "error"
      ? "Map unavailable"
      : "Loading map…",
);
function point(x, z) {
  return L.latLng(z * HEX_SCALE, x + (z % 2 ? 0.5 : 0));
}
function coordinates(s) {
  return `N ${Math.floor(s.z / 3)} · E ${Math.floor(s.x / 3)}`;
}
function depthText(d) {
  return d === null ? "Not loaded" : d === 0 ? "Land / dry" : `${d} game units`;
}
function selectSchool(s) {
  selected.value = s;
  inspected.value = null;
  copied.value = false;
  map.setView(point(s.x, s.z), Math.max(2, map.getZoom()));
  drawMarkers();
  queueDraw();
}
function rebuildSchools(initial = false) {
  if (initial) schoolIndex.clear();
  for (const id of initial ? resources.keys() : dirtySchools) {
    const row = resources.get(id),
      loc = locations.get(id),
      meta = SCHOOL_BY_ID.get(Number(row?.resource_id));
    if (meta && loc && Number(loc.dimension) === 1)
      schoolIndex.set(id, {
        ...meta,
        entityId: id,
        x: Number(loc.x),
        z: Number(loc.z),
      });
    else schoolIndex.delete(id);
  }
  dirtySchools.clear();
  schools.value = [...schoolIndex.values()];
  if (selected.value)
    selected.value = schoolIndex.get(selected.value.entityId) ?? null;
  drawMarkers();
}
function startRegion() {
  const current = ++generation;
  terrainRequest++;
  stopFish?.();
  clearTimeout(loadTimer);
  clearTimeout(schoolTimer);
  schoolTimer = null;
  resources.clear();
  locations.clear();
  schoolIndex.clear();
  dirtySchools.clear();
  schools.value = [];
  selected.value = null;
  inspected.value = null;
  updated.value = "";
  error.value = "";
  terrainRevision.value++;
  drawMarkers();
  queueDraw();
  const centered = restoreView();
  const queries = SPECIES.flatMap((s) => [
    `SELECT resource_state.* FROM resource_state WHERE resource_id = ${s.id}`,
    `SELECT location_state.* FROM location_state JOIN resource_state ON location_state.entity_id = resource_state.entity_id WHERE resource_state.resource_id = ${s.id}`,
  ]);
  stopFish = subscribeMap(region.value, queries, {
    rows(changes, initial) {
      if (current !== generation) return;
      if (initial) {
        resources.clear();
        locations.clear();
      }
      for (const { table, action, row } of changes) {
        const collection =
          table === "resource_state"
            ? resources
            : table === "location_state"
              ? locations
              : null;
        if (!collection) continue;
        const id = String(row.entity_id);
        dirtySchools.add(id);
        if (action === "deletes") collection.delete(id);
        else collection.set(id, row);
      }
      if (initial) rebuildSchools(true);
      else if (!schoolTimer)
        schoolTimer = setTimeout(() => {
          schoolTimer = null;
          rebuildSchools();
        }, 300);
      updated.value = new Date().toLocaleTimeString();
    },
    status(value, message) {
      if (current === generation) {
        status.value = value;
        error.value = message ?? "";
      }
    },
  });
  if (map) {
    if (!centered) fitRegion();
    scheduleTerrain();
  }
}
function restoreView() {
  const x = Number(route.query.x),
    z = Number(route.query.z);
  if (
    Number(route.query.region) === Number(region.value) &&
    Number.isFinite(x) &&
    Number.isFinite(z) &&
    x >= 0 &&
    x < WORLD_SIZE &&
    z >= 0 &&
    z < WORLD_SIZE
  ) {
    map.setView(point(x, z), 2);
    return true;
  }
  const view = readMapView(region.value);
  if (!view) return false;
  map.setView([view.y, view.x], view.zoom);
  return true;
}
function fitRegion() {
  const x = ((region.value - 1) % 5) * 7680,
    z = Math.floor((region.value - 1) / 5) * 7680;
  map.fitBounds(
    [
      [z * HEX_SCALE, x],
      [(z + 7680) * HEX_SCALE, x + 7680],
    ],
    { padding: [25, 25], maxZoom: -2 },
  );
}
function drawMarkers() {
  if (!mounted || !markers) return;
  markers.setSchools(filtered.value, selected.value?.entityId, showFish.value);
}
function scheduleTerrain() {
  if (!mounted) return;
  terrainRequest++;
  clearTimeout(loadTimer);
  queueDraw();
  viewRevision.value++;
  drawMarkers();
  loadTimer = setTimeout(loadTerrain, 300);
}
async function loadTerrain() {
  if (!mounted || !map) return;
  const request = ++terrainRequest,
    current = generation;
  const center = map.getCenter();
  saveMapView(region.value, {
    x: center.lng,
    y: center.lat,
    zoom: map.getZoom(),
  });
  if (map.getZoom() < 0) return;
  const bounds = map.getBounds();
  try {
    await chunks.loadBounds(
      bounds.getWest(),
      bounds.getSouth() / HEX_SCALE,
      bounds.getEast(),
      bounds.getNorth() / HEX_SCALE,
    );
    if (mounted && current === generation && request === terrainRequest)
      terrainRevision.value++;
  } catch (e) {
    if (mounted && current === generation) mapError.value = e.message;
  }
}
async function inspectTerrain(x, z) {
  try {
    await chunks.loadBounds(x - 9, z - 9, x + 9, z + 9);
    if (mounted) terrainRevision.value++;
  } catch (e) {
    if (mounted) mapError.value = e.message;
  }
}
function retryMap() {
  mapError.value = "";
  void depthLayer.retry();
  void loadTerrain();
}
function queueDraw() {
  if (mounted) depthLayer.setView(selected.value, showDepth.value);
}
async function copyLocation() {
  if (!selected.value) return;
  const url = new URL("/bitcraft/fishing-map", window.location.origin);
  url.searchParams.set("region", region.value);
  url.searchParams.set("x", selected.value.x);
  url.searchParams.set("z", selected.value.z);
  try {
    await navigator.clipboard.writeText(url.href);
    copied.value = true;
  } catch {
    copied.value = false;
  }
}
onMounted(() => {
  fetch("/bitcraft-map/overview.json")
    .then((response) => (response.ok ? response.json() : null))
    .then((metadata) => {
      if (metadata?.generatedAt && mounted)
        overviewDate.value = new Date(metadata.generatedAt).toLocaleDateString(
          undefined,
          { month: "short", day: "numeric", year: "numeric" },
        );
    })
    .catch(() => {});
  map = L.map(mapElement.value, {
    crs: L.CRS.Simple,
    preferCanvas: true,
    minZoom: -6,
    maxZoom: 5,
    zoomControl: false,
    attributionControl: false,
    zoomAnimation: false,
    markerZoomAnimation: false,
    fadeAnimation: false,
  });
  map.fitBounds([
    [0, 0],
    [WORLD_SIZE * HEX_SCALE, WORLD_SIZE],
  ]);
  const overviewPane = map.createPane("overview");
  overviewPane.style.zIndex = "200";
  overviewPane.style.pointerEvents = "none";
  void chunks
    .overviewUrl()
    .then((url) => {
      if (!mounted) {
        if (url.startsWith("blob:")) URL.revokeObjectURL(url);
        return;
      }
      overviewUrl = url;
      L.imageOverlay(
        url,
        [
          [0, 0],
          [WORLD_SIZE * HEX_SCALE, WORLD_SIZE],
        ],
        { pane: "overview", opacity: 0.9, interactive: false },
      ).addTo(map);
    })
    .catch(() => {});
  depthLayer = new AtlasCanvasLayer(chunks, {
    status(value, message) {
      if (mounted) {
        depthStatus.value = value;
        if (message) mapError.value = message;
      }
    },
    snapshot(metadata) {
      overviewDate.value = new Date(metadata.generatedAt).toLocaleDateString(
        undefined,
        { month: "short", day: "numeric", year: "numeric" },
      );
    },
  }).addTo(map);
  markers = new SchoolCanvasLayer().addTo(map);
  map.on("moveend zoomend", scheduleTerrain);
  map.on("click", (e) => {
    const hit = markers.pick(e.containerPoint);
    if (hit?.count > 1) {
      map.setView([hit.wy, hit.wx], Math.min(map.getZoom() + 2, 5));
      return;
    }
    if (hit) {
      selectSchool(hit.school);
      return;
    }
    const z = Math.round(e.latlng.lat / HEX_SCALE),
      x = Math.round(e.latlng.lng - (z % 2 ? 0.5 : 0));
    inspected.value = { x, z };
    void inspectTerrain(x, z);
  });
  observer = new ResizeObserver(() => map.invalidateSize());
  observer.observe(mapElement.value);
  mounted = true;
  startRegion();
});
watch(region, () => {
  if (mounted) startRegion();
});
watch([tier, schoolType, showFish], drawMarkers);
watch(showDepth, scheduleTerrain);
onBeforeUnmount(() => {
  mounted = false;
  if (overviewUrl?.startsWith("blob:")) URL.revokeObjectURL(overviewUrl);
  generation++;
  stopFish?.();
  clearTimeout(loadTimer);
  clearTimeout(schoolTimer);
  observer?.disconnect();
  map?.off();
  map?.remove();
});
</script>

<template>
  <Layout>
    <template #header>
      <div class="fishing-heading">
        <div>
          <p class="fishing-eyebrow">BITCRAFT · OCEAN FISHING</p>
          <h1>Fishing Map</h1>
          <p>Find a school. Read the water. Plan the chase.</p>
        </div>
        <span class="fishing-live" :class="{ connected: status === 'live' }">{{
          statusLabel
        }}</span>
      </div>
    </template>
    <div class="fishing-toolbar">
      <label
        >Region<SelectInput v-model="region" aria-label="Fishing region"
          ><option v-for="[id, name] in REGIONS" :key="id" :value="id">
            {{ name }} (R{{ id }})
          </option></SelectInput
        ></label
      >
      <label
        >Fish tier<SelectInput v-model="tier" aria-label="Fish tier"
          ><option :value="0">All tiers</option>
          <option v-for="n in 10" :key="n" :value="n">
            T{{ n }} · {{ SPECIES[(n - 1) * 2].name }}
          </option></SelectInput
        ></label
      >
      <label
        >Schools<SelectInput v-model="schoolType" aria-label="School type"
          ><option value="all">All schools</option>
          <option value="ordinary">Ordinary · needs chum</option>
          <option value="chummed">Chummed · fleeing chance</option></SelectInput
        ></label
      >
      <div class="fishing-toggles">
        <label><input v-model="showDepth" type="checkbox" /> Water depth</label
        ><label
          ><input v-model="showFish" type="checkbox" /> Fish schools</label
        >
      </div>
    </div>
    <div v-if="error" class="fishing-notice" role="status">
      {{ error }} Displayed schools may be stale until the connection recovers.
    </div>
    <div v-if="mapError" class="fishing-notice" role="status">
      {{ mapError }}
      <AppButton size="sm" variant="ghost" @click="retryMap"
        >Retry map</AppButton
      >
    </div>
    <div class="fishing-workspace">
      <section class="fishing-map-shell" aria-label="Ocean fishing map">
        <div
          ref="mapElement"
          class="fishing-map"
          role="region"
          aria-label="Interactive fishing map. Arrow keys pan, plus and minus zoom. Select a school from the nearby list for details."
          tabindex="0"
        ></div>
        <div class="fishing-map-actions">
          <AppButton
            size="sm"
            variant="ghost"
            aria-label="Zoom in"
            @click="map.zoomIn()"
            >+</AppButton
          ><AppButton
            size="sm"
            variant="ghost"
            aria-label="Zoom out"
            @click="map.zoomOut()"
            >−</AppButton
          ><AppButton size="sm" variant="ghost" @click="fitRegion"
            >Region overview</AppButton
          >
        </div>
        <div class="fishing-map-status" role="status">
          {{ depthLabel }} · {{ filtered.length.toLocaleString() }} schools
        </div>
      </section>
      <aside class="fishing-sidebar">
        <section class="fishing-card fishing-legend">
          <h2>Water depth</h2>
          <p>All regions join into one permanent, detailed map.</p>
          <div
            v-for="[color, label] in [
              ['#e0b268', '1–12 · shallow'],
              ['#52cbbb', '13–24 · fleeing depth'],
              ['#4c9cdf', '25–49 · deep'],
              ['#8771d6', '50+ · very deep'],
            ]"
            :key="color"
          >
            <span :style="{ background: color }"></span>{{ label }}
          </div>
          <small
            >Depth = water level − terrain elevation, in game units. Terrain is
            pre-rendered from a fixed snapshot; fish schools update live.</small
          >
        </section>
        <section v-if="inspected" class="fishing-card">
          <h2>Water inspection</h2>
          <p>{{ coordinates(inspected) }}</p>
          <strong>{{ depthText(inspectedDepth) }}</strong>
          <p v-if="inspectedDepth === null">Zoom in here to load terrain.</p>
        </section>
        <section v-if="selected" class="fishing-card fishing-selection">
          <div class="fishing-card-top">
            <span class="fishing-tier" :style="{ color: FISH_COLOR }"
              >T{{ selected.tier }}</span
            ><AppButton
              size="sm"
              variant="ghost"
              aria-label="Close school details"
              @click="
                selected = null;
                queueDraw();
                drawMarkers();
              "
              >×</AppButton
            >
          </div>
          <h2>{{ selected.name }}</h2>
          <p>
            {{
              selected.chummed
                ? "Chummed / frenzied school"
                : "Ordinary school · needs chum"
            }}
          </p>
          <p>
            {{ coordinates(selected) }}<br />Water depth:
            {{ depthText(selectedDepth) }}
          </p>
          <div class="fishing-ring">
            <strong
              >{{ ring.eligible }} / {{ ring.total }} deep-water
              positions</strong
            >
            <p>
              In the first fleeing spawn ring, 7–9 small tiles from this
              school.<span v-if="ring.unknown">
                {{ ring.unknown }} positions still need terrain.</span
              >
            </p>
          </div>
          <p v-if="!selected.chummed">
            Chum this school before fishing for a fleeing encounter.
          </p>
          <p class="fishing-detail-note">
            Fleeing spawns require depth 13–1000. Terrain suitability does not
            guarantee a spawn: the roll, footprint and obstructions also matter.
          </p>
          <AppButton size="sm" variant="ghost" @click="copyLocation">{{
            copied ? "Link copied" : "Copy location link"
          }}</AppButton>
        </section>
        <section class="fishing-card fishing-nearby">
          <h2>
            Schools in view
            <span>{{
              inView.length === 50 ? "50 nearest" : inView.length
            }}</span>
          </h2>
          <p v-if="!inView.length">
            {{
              status === "live"
                ? "No matching schools in this view. Try the region overview or another filter."
                : "Loading ocean schools…"
            }}
          </p>
          <div v-else class="fishing-school-list">
            <button
              v-for="school in inView"
              :key="school.entityId"
              :class="{ active: selected?.entityId === school.entityId }"
              @click="selectSchool(school)"
            >
              <span
                class="fishing-school-dot"
                :class="{ chummed: school.chummed }"
                :style="{ background: FISH_COLOR }"
              ></span
              ><span
                ><strong>T{{ school.tier }} {{ school.name }}</strong
                ><small
                  >{{ school.chummed ? "Chummed" : "Ordinary" }} ·
                  {{ coordinates(school) }}</small
                ></span
              >
            </button>
          </div>
        </section>
      </aside>
    </div>
    <footer class="fishing-footer">
      <span
        >Numbered circles group nearby schools; click to zoom in.
        <span :style="{ color: FISH_COLOR }">●</span> Ordinary school ·
        <span :style="{ color: FISH_COLOR }">◉</span> bright outline = chummed.
        Fleeing fish are temporary personal encounters; these markers locate
        their source schools.</span
      ><span
        >Schools updated {{ updated || "—" }} · Terrain snapshot:
        {{ overviewDate }} ·
        <a
          href="https://bitjita.com/map"
          target="_blank"
          rel="noopener noreferrer"
          >Map data: BitJita</a
        ></span
      >
    </footer>
  </Layout>
</template>

<style scoped>
.fishing-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
}
.fishing-heading h1 {
  font-size: 30px;
  font-weight: 750;
  margin: 3px 0 6px;
}
.fishing-heading p {
  color: var(--text-muted);
  margin: 0;
}
.fishing-eyebrow {
  font-size: 11px;
  letter-spacing: 0.14em;
}
.fishing-live {
  font-size: 12px;
  border: 1px solid var(--border-color);
  border-radius: 20px;
  padding: 7px 12px;
  white-space: nowrap;
}
.fishing-live.connected {
  color: #7cd7b4;
  background: #17453655;
}
.fishing-toolbar {
  display: grid;
  grid-template-columns:
    minmax(155px, 1fr) minmax(190px, 1.2fr) minmax(190px, 1.2fr)
    auto;
  gap: 16px;
  align-items: end;
  margin-bottom: 18px;
}
.fishing-toolbar > label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.fishing-toggles {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-bottom: 3px;
}
.fishing-toggles label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  white-space: nowrap;
}
.fishing-workspace {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 290px;
  gap: 18px;
  align-items: start;
}
.fishing-map-shell {
  position: relative;
  overflow: hidden;
  border-radius: 14px;
  border: 1px solid var(--border-color);
  background: #122430;
}
.fishing-map {
  height: 690px;
  min-height: 460px;
  background: #122430;
}
.fishing-map:focus-visible {
  outline: 3px solid #9c88e0;
  outline-offset: -3px;
}
.fishing-map-actions {
  position: absolute;
  top: 14px;
  left: 14px;
  z-index: 450;
  display: flex;
  gap: 6px;
  background: var(--surface-card, #242a35);
  padding: 5px;
  border: 1px solid var(--border-color);
  border-radius: 10px;
}
.fishing-map-status {
  position: absolute;
  bottom: 12px;
  left: 12px;
  right: 12px;
  z-index: 450;
  padding: 8px 12px;
  background: #17232ee8;
  border: 1px solid #ffffff20;
  border-radius: 8px;
  color: #d8e7ee;
  font-size: 12px;
  pointer-events: none;
}
.fishing-sidebar {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.fishing-card {
  padding: 17px;
  border: 1px solid var(--border-color);
  border-radius: 12px;
  background: var(--surface-card, var(--bg-secondary));
}
.fishing-card h2 {
  font-size: 15px;
  font-weight: 700;
  margin: 0 0 8px;
}
.fishing-card p {
  font-size: 12px;
  color: var(--text-muted);
  line-height: 1.6;
  margin: 6px 0 10px;
}
.fishing-card small {
  font-size: 11px;
  color: var(--text-muted);
  line-height: 1.5;
}
.fishing-legend > div {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
  margin: 10px 0;
}
.fishing-legend > div > span {
  height: 10px;
  width: 24px;
  border-radius: 3px;
}
.fishing-legend > small {
  display: block;
  margin-top: 14px;
}
.fishing-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 7px;
}
.fishing-tier {
  font-weight: 750;
  font-size: 12px;
}
.fishing-selection {
  border-color: #ad98d666;
}
.fishing-ring {
  background: #7b68b01a;
  border: 1px solid #ad98d633;
  border-radius: 8px;
  padding: 11px;
  margin: 12px 0;
}
.fishing-ring strong {
  font-size: 12px;
}
.fishing-ring p {
  margin: 5px 0 0;
}
.fishing-detail-note {
  font-size: 11px !important;
}
.fishing-nearby h2 {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.fishing-nearby h2 > span {
  font-size: 10px;
  font-weight: 400;
  color: var(--text-muted);
}
.fishing-school-list {
  max-height: 300px;
  overflow: auto;
  margin: 0 -7px;
}
.fishing-school-list button {
  display: flex;
  width: 100%;
  gap: 10px;
  align-items: center;
  padding: 10px 7px;
  text-align: left;
  border: 1px solid transparent;
  border-radius: 7px;
  background: transparent;
  color: inherit;
  cursor: pointer;
}
.fishing-school-list button:hover,
.fishing-school-list button.active {
  background: #8973bd20;
  border-color: #ad98d655;
}
.fishing-school-list button:focus-visible {
  outline: 2px solid #ad98d6;
}
.fishing-school-list strong {
  display: block;
  font-size: 12px;
  font-weight: 600;
}
.fishing-school-list small {
  display: block;
  margin-top: 3px;
  font-size: 10px;
}
.fishing-school-dot {
  width: 9px;
  height: 9px;
  flex-shrink: 0;
  border-radius: 50%;
}
.fishing-school-dot.chummed {
  outline: 2px solid #fff3d6;
  outline-offset: 2px;
}
.fishing-notice {
  padding: 12px;
  background: #b49a5930;
  border-radius: 8px;
  font-size: 12px;
  margin-bottom: 16px;
}
.fishing-footer {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 11px;
  color: var(--text-muted);
  padding: 16px 0 8px;
  line-height: 1.6;
}
.fishing-footer a {
  text-decoration: underline;
}
.fishing-map :deep(.leaflet-tooltip) {
  background: #202c3a;
  color: #e7edf4;
  border-color: #536275;
  font-family: inherit;
  font-size: 12px;
}
.fishing-map :deep(.leaflet-tooltip-top:before) {
  border-top-color: #536275;
}
@media (max-width: 1100px) {
  .fishing-toolbar {
    grid-template-columns: 1fr 1fr;
  }
  .fishing-toggles {
    flex-direction: row;
    min-height: 38px;
    align-items: center;
  }
  .fishing-workspace {
    grid-template-columns: minmax(0, 1fr) 250px;
  }
  .fishing-map {
    height: 650px;
  }
}
@media (max-width: 760px) {
  .fishing-heading {
    align-items: flex-start;
  }
  .fishing-heading h1 {
    font-size: 25px;
  }
  .fishing-live {
    font-size: 10px;
    padding: 6px 8px;
  }
  .fishing-workspace {
    grid-template-columns: 1fr;
  }
  .fishing-map {
    height: 60vh;
    min-height: 380px;
    max-height: 600px;
  }
  .fishing-toolbar {
    gap: 12px;
  }
  .fishing-sidebar {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
  .fishing-selection,
  .fishing-nearby {
    grid-column: 1/-1;
  }
  .fishing-map-actions {
    top: 9px;
    left: 9px;
  }
  .fishing-toolbar > label {
    min-width: 0;
  }
  .fishing-toggles {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
  .fishing-school-list {
    max-height: 240px;
  }
}
@media (max-width: 430px) {
  .fishing-sidebar {
    grid-template-columns: 1fr;
  }
  .fishing-heading p:not(.fishing-eyebrow) {
    font-size: 12px;
  }
  .fishing-map-status {
    font-size: 10px;
  }
}
</style>
