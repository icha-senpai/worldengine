<template>
  <AuthenticatedLayout>
    <template #header>
      <div class="page-hero">
        <div class="page-hero__copy">
          <div class="page-hero__eyebrow">
            <span>Bitcraft tools</span>
          </div>
          <h1 class="page-hero__title page-hero__title--lg">
            Tool Rate Calculator
          </h1>
          <p class="page-hero__subtitle">
            Extraction estimates from static BitCraft data.
          </p>
        </div>
      </div>
    </template>

    <section class="index-panel">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2
            class="font-ui text-sm font-semibold uppercase tracking-[0.18em] text-muted-2"
          >
            Inputs
          </h2>
        </div>
        <span class="tag" :class="snapshot?.available ? 'tag--success' : ''">{{
          snapshotLabel
        }}</span>
      </div>

      <div class="tool-rate-controls mt-4">
        <label class="tool-rate-control tool-rate-control--search">
          <span class="field-label">Search</span>
          <TextInput
            v-model.trim="settings.search"
            type="search"
            placeholder="Azure, mine, tree, bait..."
          />
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Skill</span>
          <SelectInput v-model="settings.skill">
            <option value="">All skills</option>
            <option v-for="skill in skillOptions" :key="skill" :value="skill">
              {{ skill }}
            </option>
          </SelectInput>
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Tool</span>
          <SelectInput v-model="settings.tool">
            <option value="">All tools</option>
            <option v-for="tool in toolOptions" :key="tool" :value="tool">
              {{ tool }}
            </option>
          </SelectInput>
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Mode</span>
          <SelectInput v-model="settings.mode">
            <option value="sustained">Sustained</option>
            <option value="single">Single target</option>
          </SelectInput>
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Power</span>
          <TextInput
            v-model.number="settings.power"
            type="number"
            min="1"
            max="9999"
            inputmode="numeric"
          />
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Gather speed %</span>
          <TextInput
            v-model.number="settings.gatheringSpeed"
            type="number"
            min="0"
            max="999"
            step="0.1"
            inputmode="decimal"
          />
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Skill speed %</span>
          <TextInput
            v-model.number="settings.skillSpeed"
            type="number"
            min="0"
            max="999"
            step="0.1"
            inputmode="decimal"
          />
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Minutes</span>
          <TextInput
            v-model.number="settings.minutes"
            type="number"
            min="1"
            max="1440"
            step="1"
            inputmode="numeric"
          />
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Crit chance %</span>
          <TextInput
            v-model.number="settings.critChance"
            type="number"
            min="0"
            max="100"
            step="0.1"
            inputmode="decimal"
          />
        </label>

        <label class="tool-rate-control">
          <span class="field-label">Crit multiplier</span>
          <TextInput
            v-model.number="settings.critMultiplier"
            type="number"
            min="1"
            max="20"
            step="0.05"
            inputmode="decimal"
          />
        </label>
      </div>
    </section>

    <section class="surface-section mt-5">
      <div class="surface-section__header">
        <div class="surface-section__copy">
          <h2 class="surface-section__title">Estimates</h2>
          <p class="surface-section__subtitle">
            {{ visibleRows.length }} of {{ entries.length }} tool actions
          </p>
        </div>
        <SelectInput v-model="settings.sort" class="w-full sm:w-56">
          <option value="default">Skill order</option>
          <option value="output">Output in window</option>
          <option value="xp">XP in window</option>
          <option value="actions">Actions in window</option>
        </SelectInput>
      </div>

      <div class="surface-section__body">
        <div v-if="visibleRows.length" class="grid gap-3">
          <article
            v-for="row in visibleRows"
            :key="row.entry.id"
            class="tool-rate-card"
          >
            <div class="tool-rate-card__head">
              <span
                class="tool-rate-card__icon"
                :style="itemFrameStyle(row.entry.resource)"
                aria-hidden="true"
              >
                <img
                  v-if="iconUrl(row.entry.resource)"
                  :src="iconUrl(row.entry.resource)"
                  alt=""
                  loading="lazy"
                  @error="hideBrokenIcon(row.entry.resource?.iconAssetName)"
                />
                <span v-else>{{ itemInitials(row.entry.resource?.name) }}</span>
              </span>

              <div class="min-w-0">
                <h3 class="tool-rate-card__title">{{ row.entry.name }}</h3>
                <div class="mt-2 flex flex-wrap gap-2">
                  <span v-if="row.entry.skill?.name" class="tag">{{
                    row.entry.skill.name
                  }}</span>
                  <span v-if="row.entry.tool?.name" class="tag">{{
                    row.entry.tool.name
                  }}</span>
                  <span v-if="row.entry.levelRequirement" class="tag"
                    >Level {{ row.entry.levelRequirement }}</span
                  >
                  <BitcraftTierBadge
                    v-if="hasTier(row.entry.resource?.tier)"
                    :tier="row.entry.resource.tier"
                  />
                  <span
                    v-if="row.entry.resource?.rarity"
                    class="tag bitcraft-rarity-badge"
                    :style="rarityStyle(row.entry.resource.rarity)"
                  >
                    {{ row.entry.resource.rarity }}
                  </span>
                </div>
              </div>
            </div>

            <div class="tool-rate-grid">
              <div class="tool-rate-stat">
                <span>Action</span>
                <strong>{{ formatSeconds(row.actionSeconds) }}</strong>
              </div>
              <div class="tool-rate-stat">
                <span>Actions</span>
                <strong>{{ formatNumber(row.actionsUsed) }}</strong>
              </div>
              <div class="tool-rate-stat">
                <span>Avg damage</span>
                <strong>{{ formatNumber(row.averageDamagePerAction) }}</strong>
              </div>
              <div class="tool-rate-stat">
                <span>XP</span>
                <strong>{{ formatNumber(row.experience) }}</strong>
              </div>
              <div class="tool-rate-stat">
                <span>Output units</span>
                <strong>{{ formatNumber(row.outputProgress) }}</strong>
              </div>
              <div class="tool-rate-stat">
                <span>Target</span>
                <strong>{{ row.targetLabel }}</strong>
              </div>
            </div>

            <div class="tool-rate-card__lists">
              <div>
                <span class="tool-rate-card__label">Outputs</span>
                <div
                  v-if="row.outputs.length"
                  class="mt-2 flex flex-wrap gap-2"
                >
                  <span
                    v-for="output in row.outputs"
                    :key="`${row.entry.id}:out:${output.id}`"
                    class="tool-rate-pill"
                  >
                    {{ formatNumber(output.expected) }} {{ output.name }}
                    <span class="text-muted-3"
                      >({{ formatPercent(output.probability) }})</span
                    >
                  </span>
                </div>
                <p v-else class="mt-2 text-sm text-muted-3">
                  No direct item output.
                </p>
              </div>

              <div>
                <span class="tool-rate-card__label">Inputs</span>
                <div v-if="row.inputs.length" class="mt-2 flex flex-wrap gap-2">
                  <span
                    v-for="input in row.inputs"
                    :key="`${row.entry.id}:in:${input.id}`"
                    class="tool-rate-pill tool-rate-pill--input"
                  >
                    {{ formatNumber(input.expected) }} {{ input.name }}
                  </span>
                </div>
                <p v-else class="mt-2 text-sm text-muted-3">
                  No consumed items.
                </p>
              </div>
            </div>

            <div v-if="row.entry.spawnedResource" class="tool-rate-spawn">
              Spawns {{ row.entry.spawnedResource.name }}
            </div>
          </article>
        </div>

        <div v-else class="empty-state-panel">
          <p class="text-muted-3 text-sm font-ui">
            No tool actions match the current filters.
          </p>
        </div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import AuthenticatedLayout from "/src/bitcraft-ui/Layout.vue";
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import TextInput from "/src/bitcraft-ui/shared/TextInput.vue";
import BitcraftTierBadge from "/src/bitcraft-ui/Components/BitcraftTierBadge.vue";
import {
  bitcraftItemFrameStyle,
  bitcraftRarityStyle,
  bitjitaAssetUrl,
  hasBitcraftTier,
} from "/src/bitcraft-ui/bitjitaAssets.js";
import { estimateGathering } from "./gatheringEstimates";

const props = defineProps({
  entries: { type: Array, default: () => [] },
  snapshot: { type: Object, default: () => ({}) },
});

const storageKey = "bitcraft.tool-rates.settings";
const savedSettings = readSettings();
const settings = reactive({
  search: "",
  skill: "",
  tool: "",
  mode: "sustained",
  power: 33,
  gatheringSpeed: 16,
  skillSpeed: 0,
  minutes: 30,
  critChance: 0,
  critMultiplier: 1,
  sort: "default",
  ...savedSettings,
});
const brokenIconAssets = ref(new Set());

watch(
  settings,
  () => {
    window.localStorage?.setItem(storageKey, JSON.stringify({ ...settings }));
  },
  { deep: true },
);

const skillOptions = computed(() =>
  uniqueOptions(props.entries.map((entry) => entry.skill?.name)),
);
const toolOptions = computed(() =>
  uniqueOptions(props.entries.map((entry) => entry.tool?.name)),
);

const snapshotLabel = computed(() => {
  if (!props.snapshot?.available) {
    return "Static data unavailable";
  }

  if (!props.snapshot.generatedAt) {
    return "Static snapshot";
  }

  const date = new Date(props.snapshot.generatedAt);

  if (Number.isNaN(date.getTime())) {
    return "Static snapshot";
  }

  return `Snapshot ${date.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  })}`;
});

const estimatedRows = computed(() =>
  props.entries.map((entry, index) => ({
    entry,
    index,
    ...estimate(entry),
  })),
);

const visibleRows = computed(() => {
  const needle = settings.search.trim().toLowerCase();

  const rows = estimatedRows.value.filter((row) => {
    if (settings.skill && row.entry.skill?.name !== settings.skill) {
      return false;
    }

    if (settings.tool && row.entry.tool?.name !== settings.tool) {
      return false;
    }

    if (!needle) {
      return true;
    }

    return searchableText(row.entry).includes(needle);
  });

  if (settings.sort === "output") {
    return rows.sort(
      (a, b) => b.primaryOutput - a.primaryOutput || a.index - b.index,
    );
  }

  if (settings.sort === "xp") {
    return rows.sort(
      (a, b) => b.experience - a.experience || a.index - b.index,
    );
  }

  if (settings.sort === "actions") {
    return rows.sort(
      (a, b) => b.actionsUsed - a.actionsUsed || a.index - b.index,
    );
  }

  return rows.sort((a, b) => a.index - b.index);
});

function estimate(entry) {
  const result = estimateGathering(entry, settings);
  return {
    ...result,
    targetLabel: targetLabel(
      entry,
      result.actionsUsed,
      result.actionSeconds,
      result.depletes,
    ),
  };
}

function targetLabel(entry, actionsUsed, actionSeconds, depletes) {
  if (entry.resource?.ignoreDamage) {
    return "Persistent";
  }

  const maxHealth = Number(entry.resource?.maxHealth ?? 0);

  if (!maxHealth) {
    return "Unknown";
  }

  if (settings.mode === "single") {
    return depletes
      ? `${formatSeconds(actionsUsed * actionSeconds)} to deplete`
      : "Not depleted";
  }

  return `${formatNumber(maxHealth)} health`;
}

function searchableText(entry) {
  return [
    entry.name,
    entry.verb,
    entry.skill?.name,
    entry.tool?.name,
    entry.resource?.name,
    entry.resource?.category,
    ...(entry.outputs ?? []).map((output) => output.name),
    ...(entry.consumedItems ?? []).map((input) => input.name),
  ]
    .filter(Boolean)
    .join(" ")
    .toLowerCase();
}

function uniqueOptions(values) {
  return [...new Set(values.filter(Boolean))].sort((a, b) =>
    String(a).localeCompare(String(b)),
  );
}

function readSettings() {
  if (typeof window === "undefined") {
    return {};
  }

  try {
    return JSON.parse(window.localStorage?.getItem(storageKey) ?? "{}") ?? {};
  } catch {
    return {};
  }
}

const formatNumber = (value) =>
  new Intl.NumberFormat(undefined, {
    maximumFractionDigits: Math.abs(Number(value)) >= 100 ? 0 : 2,
  }).format(Number(value) || 0);

const formatSeconds = (value) => `${formatNumber(value)}s`;
const formatPercent = (value) => `${formatNumber(Number(value ?? 0) * 100)}%`;
const rarityStyle = (rarity) => bitcraftRarityStyle(rarity);
const itemFrameStyle = (item) =>
  bitcraftItemFrameStyle(item?.tier, item?.rarity);
const hasTier = (tier) => hasBitcraftTier(tier);

const iconUrl = (item) => {
  const assetName = item?.iconAssetName;

  if (!assetName || brokenIconAssets.value.has(assetName)) {
    return null;
  }

  return bitjitaAssetUrl(assetName);
};

const itemInitials = (name) =>
  String(name ?? "?")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("") || "?";

const hideBrokenIcon = (assetName) => {
  brokenIconAssets.value = new Set([...brokenIconAssets.value, assetName]);
};
</script>

<style scoped>
.tool-rate-controls {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 12px;
}

.tool-rate-control {
  display: grid;
  min-width: 0;
  grid-column: span 3;
  gap: 8px;
}

.tool-rate-control :deep(.input) {
  width: 100%;
  min-width: 0;
}

.tool-rate-control--search {
  grid-column: 1 / -1;
}

@media (max-width: 1024px) {
  .tool-rate-control {
    grid-column: span 4;
  }
}

@media (max-width: 720px) {
  .tool-rate-control {
    grid-column: span 6;
  }
}

@media (max-width: 520px) {
  .tool-rate-control {
    grid-column: 1 / -1;
  }
}

.tool-rate-card {
  display: grid;
  gap: 14px;
  border: 1px solid rgb(var(--border-color-rgb) / 0.72);
  border-radius: 8px;
  background:
    linear-gradient(
      180deg,
      rgb(var(--bg-surface-rgb) / 0.92),
      rgb(var(--bg-soft-rgb) / 0.82)
    ),
    rgb(var(--bg-surface-rgb) / 0.9);
  padding: 14px;
}

.tool-rate-card__head {
  display: grid;
  grid-template-columns: 46px minmax(0, 1fr);
  gap: 12px;
  align-items: center;
}

.tool-rate-card__icon {
  position: relative;
  display: grid;
  place-items: center;
  width: 46px;
  height: 46px;
  border: 1px solid
    var(--bitcraft-item-frame-border, rgb(var(--border-color-rgb) / 0.7));
  border-radius: 6px;
  background:
    radial-gradient(
      circle at 35% 25%,
      var(--bitcraft-item-frame-bg, rgb(var(--accent-cyan-rgb) / 0.2)),
      transparent 42%
    ),
    linear-gradient(
      180deg,
      color-mix(
        in srgb,
        var(--bitcraft-item-frame-accent, transparent) 12%,
        transparent
      ),
      transparent
    ),
    rgb(var(--bg-surface-rgb) / 0.92);
  color: var(--bitcraft-item-frame-text, var(--text-muted-2));
  font-family: var(--font-ui);
  font-size: 11px;
  font-weight: 800;
  overflow: hidden;
}

.tool-rate-card__icon img {
  position: absolute;
  inset: 4px;
  width: calc(100% - 8px);
  height: calc(100% - 8px);
  object-fit: contain;
}

.tool-rate-card__title {
  overflow-wrap: anywhere;
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 16px;
  font-weight: 800;
  line-height: 1.25;
}

.tool-rate-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(116px, 1fr));
  gap: 8px;
}

.tool-rate-stat {
  min-height: 66px;
  border: 1px solid rgb(var(--border-color-rgb) / 0.62);
  border-radius: 6px;
  background: rgb(var(--bg-base-rgb) / 0.32);
  padding: 10px;
}

.tool-rate-stat span,
.tool-rate-card__label {
  display: block;
  color: var(--text-muted-3);
  font-family: var(--font-ui);
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
}

.tool-rate-stat strong {
  display: block;
  margin-top: 6px;
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 16px;
  line-height: 1.2;
}

.tool-rate-card__lists {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
}

.tool-rate-pill {
  display: inline-flex;
  min-height: 28px;
  align-items: center;
  gap: 5px;
  border: 1px solid rgb(var(--accent-cyan-rgb) / 0.28);
  border-radius: 6px;
  background: rgb(var(--accent-cyan-rgb) / 0.08);
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 12px;
  font-weight: 750;
  padding: 5px 8px;
}

.tool-rate-pill--input {
  border-color: rgb(var(--accent-pink-rgb) / 0.28);
  background: rgb(var(--accent-pink-rgb) / 0.08);
}

.tool-rate-spawn {
  border-top: 1px solid rgb(var(--border-color-rgb) / 0.56);
  color: var(--text-muted-2);
  font-family: var(--font-ui);
  font-size: 12px;
  font-weight: 700;
  padding-top: 10px;
}

.bitcraft-rarity-badge {
  border-color: var(--bitcraft-rarity-border, currentColor);
  background:
    linear-gradient(
      180deg,
      var(--bitcraft-rarity-bg, transparent),
      rgb(var(--bg-surface-rgb) / 0.7)
    ),
    rgb(var(--bg-surface-rgb) / 0.7);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 0.08),
    0 0 12px
      color-mix(
        in srgb,
        var(--bitcraft-rarity-accent, transparent) 22%,
        transparent
      );
  color: var(--bitcraft-rarity-text, currentColor);
}
</style>
