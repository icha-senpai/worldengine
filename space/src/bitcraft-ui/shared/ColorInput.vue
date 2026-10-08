<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  useId,
  watch,
} from "vue";
import {
  hexToRgbObject,
  hsvToHex,
  normalizeHex,
  rgbToHex,
  rgbToHsv,
} from "../lib/tiptap/colorUtils";

const props = defineProps({
  modelValue: { type: String, default: "#D4A44A" },
  label: { type: String, default: "Color" },
  disabled: Boolean,
});
const emit = defineEmits(["update:modelValue"]);
const id = useId();
const trigger = ref(null);
const panel = ref(null);
const hexInput = ref(null);
const open = ref(false);
const draftHex = ref(props.modelValue);
const error = ref("");
const hsv = ref(rgbToHsv(hexToRgbObject(props.modelValue)));
const channels = ref(hexToRgbObject(props.modelValue));
const panelStyle = ref({});
const color = computed(() => normalizeHex(props.modelValue));
const swatches = [
  "#D4A44A",
  "#6FB08D",
  "#E59A9A",
  "#A78BFA",
  "#38BDF8",
  "#FFF6E6",
  "#110A18",
  "#000000",
];

watch(
  () => props.modelValue,
  (value) => {
    draftHex.value = normalizeHex(value);
    channels.value = hexToRgbObject(value);
    hsv.value = rgbToHsv(channels.value);
    error.value = "";
  },
);
function apply(value) {
  emit("update:modelValue", normalizeHex(value));
}
function applyHsv() {
  apply(hsvToHex(hsv.value));
}
function applyRgb() {
  apply(rgbToHex(channels.value));
}
function commitHex() {
  if (!/^#?(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(draftHex.value.trim())) {
    error.value = "Use a 3 or 6 digit hex color.";
    return;
  }
  error.value = "";
  draftHex.value = normalizeHex(draftHex.value);
  apply(draftHex.value);
}
function positionPanel() {
  if (!open.value || !trigger.value) return;
  const rect = trigger.value.getBoundingClientRect();
  const width = Math.min(320, window.innerWidth - 24);
  const height = panel.value?.offsetHeight ?? 410;
  const top =
    rect.bottom + 8 + height <= window.innerHeight - 12
      ? rect.bottom + 8
      : Math.max(12, rect.top - height - 8);
  panelStyle.value = {
    width: `${width}px`,
    left: `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`,
    top: `${top}px`,
    maxHeight: `${window.innerHeight - 24}px`,
  };
}
async function showPanel() {
  open.value = true;
  await nextTick();
  positionPanel();
  hexInput.value?.focus();
}
function closePanel() {
  open.value = false;
  trigger.value?.focus();
}
function outsidePointer(event) {
  if (
    open.value &&
    !trigger.value?.contains(event.target) &&
    !panel.value?.contains(event.target)
  )
    open.value = false;
}
function updateSurface(event) {
  const rect = event.currentTarget.getBoundingClientRect();
  hsv.value = {
    ...hsv.value,
    s: Math.max(
      0,
      Math.min(100, ((event.clientX - rect.left) / rect.width) * 100),
    ),
    v: Math.max(
      0,
      Math.min(100, 100 - ((event.clientY - rect.top) / rect.height) * 100),
    ),
  };
  applyHsv();
}
function startSurface(event) {
  event.currentTarget.setPointerCapture(event.pointerId);
  updateSurface(event);
}
function moveSurface(event) {
  if (event.currentTarget.hasPointerCapture(event.pointerId))
    updateSurface(event);
}
function surfaceKey(event) {
  const step = event.shiftKey ? 10 : 1;
  const deltas = {
    ArrowLeft: [-step, 0],
    ArrowRight: [step, 0],
    ArrowUp: [0, step],
    ArrowDown: [0, -step],
  };
  if (!deltas[event.key]) return;
  event.preventDefault();
  const [s, v] = deltas[event.key];
  hsv.value = {
    ...hsv.value,
    s: Math.max(0, Math.min(100, hsv.value.s + s)),
    v: Math.max(0, Math.min(100, hsv.value.v + v)),
  };
  applyHsv();
}
function panelKey(event) {
  if (event.key === "Escape") {
    event.preventDefault();
    event.stopPropagation();
    closePanel();
  }
  if (event.key === "Tab") {
    const controls = Array.from(
      panel.value.querySelectorAll('button, input, [tabindex="0"]'),
    ).filter((el) => !el.disabled);
    const first = controls[0],
      last = controls.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }
}
onMounted(() => {
  document.addEventListener("pointerdown", outsidePointer);
  window.addEventListener("resize", positionPanel);
  window.addEventListener("scroll", positionPanel, true);
});
onBeforeUnmount(() => {
  document.removeEventListener("pointerdown", outsidePointer);
  window.removeEventListener("resize", positionPanel);
  window.removeEventListener("scroll", positionPanel, true);
});
</script>

<template>
  <button
    ref="trigger"
    type="button"
    class="app-color-trigger"
    :disabled="disabled"
    :aria-label="`${label} color`"
    aria-haspopup="dialog"
    :aria-expanded="open"
    :aria-controls="`${id}-picker`"
    @click="open ? closePanel() : showPanel()"
  >
    <span class="app-color-trigger__swatch" :style="{ background: color }" />
    <span class="app-color-trigger__hex">{{ color }}</span>
  </button>
  <Teleport to="body">
    <section
      v-if="open"
      :id="`${id}-picker`"
      ref="panel"
      class="app-color-panel"
      role="dialog"
      aria-modal="true"
      :aria-label="`${label} color picker`"
      :style="panelStyle"
      @keydown="panelKey"
    >
      <header>
        <strong>{{ label }} color</strong
        ><button
          type="button"
          aria-label="Close color picker"
          @click="closePanel"
        >
          ✕
        </button>
      </header>
      <div
        class="app-color-panel__surface"
        :style="{ backgroundColor: `hsl(${hsv.h} 100% 50%)` }"
        tabindex="0"
        role="slider"
        aria-label="Saturation and brightness"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="Math.round(hsv.s)"
        :aria-valuetext="`${Math.round(hsv.s)}% saturation, ${Math.round(hsv.v)}% brightness`"
        @pointerdown.prevent="startSurface"
        @pointermove="moveSurface"
        @keydown="surfaceKey"
      >
        <span
          class="app-color-panel__handle"
          :style="{
            left: `${hsv.s}%`,
            top: `${100 - hsv.v}%`,
            background: color,
          }"
        />
      </div>
      <label class="app-color-panel__hue-label"
        >Hue
        <input
          v-model.number="hsv.h"
          class="app-color-panel__hue"
          type="range"
          min="0"
          max="359"
          aria-label="Hue"
          @input="applyHsv"
        />
      </label>
      <div class="app-color-panel__swatches" aria-label="Color presets">
        <button
          v-for="swatch in swatches"
          :key="swatch"
          type="button"
          :style="{ backgroundColor: swatch }"
          :aria-label="`Use ${swatch}`"
          :aria-pressed="color === swatch"
          @click="apply(swatch)"
        />
      </div>
      <label class="app-color-panel__hex-label"
        >Hex color
        <input
          ref="hexInput"
          v-model="draftHex"
          type="text"
          maxlength="7"
          spellcheck="false"
          :aria-invalid="Boolean(error)"
          :aria-describedby="error ? `${id}-error` : undefined"
          @change="commitHex"
          @keydown.enter.prevent="commitHex"
        />
      </label>
      <p
        v-if="error"
        :id="`${id}-error`"
        class="app-color-panel__error"
        role="status"
      >
        {{ error }}
      </p>
      <div class="app-color-panel__rgb">
        <label
          v-for="(channel, key) in { r: 'Red', g: 'Green', b: 'Blue' }"
          :key="key"
          >{{ channel
          }}<input
            v-model.number="channels[key]"
            type="number"
            min="0"
            max="255"
            @change="applyRgb"
        /></label>
      </div>
      <button type="button" class="app-color-panel__done" @click="closePanel">
        Done
      </button>
    </section>
  </Teleport>
</template>

<style scoped>
.app-color-trigger {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  min-height: 40px;
  padding: 6px 8px;
  border: 1px solid var(--border-color);
  border-radius: 8px;
  background: var(--bg-surface);
  color: var(--text-primary);
  cursor: pointer;
}
.app-color-trigger__swatch {
  flex: 0 0 24px;
  width: 24px;
  height: 24px;
  border: 1px solid rgb(255 255 255 / 0.25);
  border-radius: 5px;
}
.app-color-trigger__hex {
  font-size: 11px;
  font-weight: 500;
  color: var(--text-primary);
}
.app-color-trigger:focus-visible {
  outline: 2px solid var(--accent-cyan);
  outline-offset: 2px;
}
.app-color-panel {
  position: fixed;
  z-index: 2100;
  display: grid;
  gap: 12px;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 16px;
  border: 1px solid var(--border-color-2);
  border-radius: 12px;
  background: var(--bg-surface);
  color: var(--text-primary);
  box-shadow: 0 16px 40px rgb(0 0 0 / 0.5);
  font-family: var(--font-ui);
  font-size: 12px;
}
.app-color-panel header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.app-color-panel header button {
  width: 28px;
  height: 28px;
  border: 0;
  border-radius: 5px;
  background: var(--bg-surface-3);
  color: var(--text-primary);
}
.app-color-panel__surface {
  position: relative;
  height: 150px;
  border-radius: 6px;
  background-image:
    linear-gradient(to top, #000, transparent),
    linear-gradient(to right, #fff, transparent);
  touch-action: none;
  cursor: crosshair;
}
.app-color-panel__handle {
  position: absolute;
  width: 13px;
  height: 13px;
  border: 2px solid white;
  border-radius: 50%;
  transform: translate(-50%, -50%);
  box-shadow: 0 0 2px 1px #000;
  pointer-events: none;
}
.app-color-panel label {
  display: grid;
  gap: 5px;
  color: var(--text-muted-3);
  font-size: 11px;
}
.app-color-panel input:not([type="range"]) {
  width: 100%;
  min-width: 0;
  padding: 8px;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  background: var(--bg-canvas);
  color: var(--text-primary);
  font-size: 12px;
}
.app-color-panel__hue {
  appearance: none;
  width: 100%;
  height: 14px;
  min-height: 14px;
  padding: 0;
  margin: 4px 0;
  border: 0;
  border-radius: 8px;
  background: linear-gradient(
    to right,
    #f00,
    #ff0,
    #0f0,
    #0ff,
    #00f,
    #f0f,
    #f00
  );
  cursor: pointer;
}
.app-color-panel__hue::-webkit-slider-thumb {
  appearance: none;
  width: 18px;
  height: 18px;
  border: 2px solid white;
  border-radius: 50%;
  background: var(--bg-surface);
  box-shadow: 0 0 3px #000;
}
.app-color-panel__hue::-moz-range-thumb {
  width: 14px;
  height: 14px;
  border: 2px solid white;
  border-radius: 50%;
  background: var(--bg-surface);
}
.app-color-panel__swatches {
  display: grid;
  grid-template-columns: repeat(8, minmax(0, 1fr));
  gap: 6px;
}
.app-color-panel__swatches button {
  height: 26px;
  padding: 0;
  border: 1px solid rgb(255 255 255 / 0.3);
  border-radius: 5px;
}
.app-color-panel__swatches button[aria-pressed="true"] {
  outline: 2px solid var(--accent-cyan);
  outline-offset: 2px;
}
.app-color-panel__rgb {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}
.app-color-panel__error {
  color: var(--danger);
  margin: 0;
}
.app-color-panel__done {
  padding: 8px;
  border: 1px solid var(--border-color-2);
  border-radius: 6px;
  background: var(--bg-surface-3);
  color: var(--accent-cyan);
}
</style>
