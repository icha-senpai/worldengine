<template>
  <fieldset class="bitcraft-widget-theme">
    <legend>Visuals</legend>

    <label>
      <span>Theme</span>
      <SelectInput :value="model.theme" class="input" @change="setTheme">
        <option
          v-for="theme in bitcraftWidgetThemes"
          :key="theme.key"
          :value="theme.key"
        >
          {{ theme.label }}
        </option>
      </SelectInput>
    </label>

    <label v-for="field in colorFields" :key="field.key">
      <span>{{ field.label }}</span>
      <ColorInput
        :model-value="model[field.key]"
        :label="field.label"
        @update:model-value="set(field.key, $event)"
      />
    </label>

    <label>
      <span>Width</span>
      <input
        :value="model.width"
        type="number"
        min="280"
        max="900"
        step="10"
        @input="set('width', $event.target.value)"
      />
    </label>

    <label>
      <span>Scale</span>
      <input
        :value="model.fontScale"
        type="number"
        min="80"
        max="140"
        step="5"
        @input="set('fontScale', $event.target.value)"
      />
    </label>

    <label>
      <span>Radius</span>
      <input
        :value="model.radius"
        type="number"
        min="0"
        max="32"
        step="1"
        @input="set('radius', $event.target.value)"
      />
    </label>

    <label>
      <span>Opacity</span>
      <input
        :value="model.panelOpacity"
        type="number"
        min="20"
        max="100"
        step="1"
        @input="set('panelOpacity', $event.target.value)"
      />
    </label>
  </fieldset>
</template>

<script setup>
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import ColorInput from "../shared/ColorInput.vue";
import { applyWidgetThemePreset, bitcraftWidgetThemes } from "../widgetTheme";

const props = defineProps({
  model: { type: Object, required: true },
});

const emit = defineEmits(["update"]);

const colorFields = [
  { key: "accentColor", label: "Accent" },
  { key: "highlightColor", label: "Highlight" },
  { key: "panelColor", label: "Panel" },
  { key: "textColor", label: "Text" },
  { key: "mutedColor", label: "Muted" },
  { key: "borderColor", label: "Border" },
];

const set = (key, value) => {
  emit("update", { [key]: value });
};

const setTheme = (event) => {
  const nextTheme = { ...props.model };

  applyWidgetThemePreset(nextTheme, event.target.value);
  emit("update", nextTheme);
};
</script>

<style scoped>
.bitcraft-widget-theme {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-top: 12px;
  border: 1px solid rgb(var(--border-color-2-rgb) / 0.28);
  border-radius: 8px;
  padding: 12px;
}

.bitcraft-widget-theme legend {
  padding: 0 6px;
  color: var(--text-muted-3);
  font-size: 10px;
  font-weight: 900;
  text-transform: uppercase;
}

.bitcraft-widget-theme label {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.bitcraft-widget-theme span {
  color: var(--text-muted-3);
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
}

.bitcraft-widget-theme input,
.bitcraft-widget-theme select {
  width: 100%;
  min-height: 36px;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  background-color: var(--bg-canvas);
  color: var(--text-primary);
  font-size: 13px;
}

@media (max-width: 720px) {
  .bitcraft-widget-theme {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 420px) {
  .bitcraft-widget-theme {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
