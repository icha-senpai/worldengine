<template>
  <WidgetPageShell
    :setup="setupPageVisible"
    title="Passive Craft Tracker"
    description="Configure the passive craft overlay and keep the OBS widget URL clean."
  >
    <main
      class="passive-craft-source"
      :class="{
        'passive-craft-source--setup': setupVisible,
        'passive-craft-source--in-app': setupPageVisible,
      }"
      :style="widgetThemeStyle"
    >
      <WidgetSetupDrawer
        :show="setupVisible"
        title="Edit Passive Craft Widget"
        @widget-mode="openWidgetMode"
        @save="submitSetup(true)"
      >
        <form class="passive-craft-setup" @submit.prevent="submitSetup(true)">
          <div class="passive-craft-setup__grid">
            <label>
              <span>Character</span>
              <input v-model.trim="form.character" type="text" maxlength="80" />
              <SitePlayerDefaultButton @select="form.character = $event" />
            </label>

            <label>
              <span>Title</span>
              <input v-model.trim="form.title" type="text" maxlength="80" />
            </label>

            <EmojiInput v-model="form.icons" />
          </div>

          <WidgetThemeControls :model="form" @update="updateTheme" />

          <div class="passive-craft-setup__actions">
            <button type="submit">Search / Update</button>
          </div>
        </form>
      </WidgetSetupDrawer>

      <section
        class="passive-craft-widget"
        aria-label="Bitcraft passive craft tracker"
      >
        <header class="passive-craft-widget__header">
          <div>
            <h1>
              <span>{{ titleLabel }}</span>
              <EmojiDisplay v-if="iconsLabel" :value="iconsLabel" />
            </h1>
            <p>{{ summaryLabel }}</p>
          </div>
        </header>

        <TrackerRefreshStatus
          :refresh="refreshStatus"
          :sampled-at="sampledAt"
        />

        <div v-if="error" class="passive-craft-widget__error">
          {{ error }}
        </div>

        <div v-if="!tracker && !error" class="passive-craft-widget__empty">
          Loading passive crafts
        </div>

        <div
          v-else-if="tracker && !groups.length"
          class="passive-craft-widget__empty"
        >
          No passive crafts running
        </div>

        <template v-else-if="tracker">
          <article
            v-for="group in groups"
            :key="group.key"
            class="passive-craft-widget__craft"
            :style="itemVisualStyle(group.output)"
          >
            <div class="passive-craft-widget__row">
              <div class="passive-craft-widget__item">
                <p>
                  <img
                    v-if="itemIconUrl(group.output)"
                    class="passive-craft-widget__icon"
                    :src="itemIconUrl(group.output)"
                    alt=""
                    width="32"
                    height="32"
                    loading="lazy"
                    decoding="async"
                    @error="hideBrokenIcon(group.output?.iconAssetName)"
                  />
                  <BitcraftTierBadge
                    v-if="hasTier(group.output?.tier)"
                    :tier="group.output.tier"
                  />
                  <span>{{ group.name }}</span>
                </p>
              </div>
              <div class="passive-craft-widget__count">
                <strong>x{{ formatNumber(group.totalOutputQuantity) }}</strong>
              </div>
            </div>

            <div class="passive-craft-widget__bar" aria-hidden="true">
              <span :style="{ width: `${craftProgressPercent(group)}%` }" />
            </div>

            <div class="passive-craft-widget__footer">
              <div class="passive-craft-widget__timer">
                <span>{{ remainingTimeLabel(group) }}</span>
                <small v-if="estimatedFinishLabel(group)">
                  Est.
                  {{ group.waitingCount ? "running crafts finish" : "finish" }}
                  {{ estimatedFinishLabel(group) }}
                </small>
                <small v-if="group.waitingCount"
                  >{{ formatNumber(group.waitingCount) }} waiting to
                  start</small
                >
              </div>
              <span>{{ progressLabel(group) }}</span>
            </div>

            <div class="passive-craft-widget__outputs">
              <span>{{ stationLabel(group) }}</span>
              <span>{{ claimName(group) }}</span>
              <span>{{ regionLabel(group) }}</span>
            </div>
          </article>
        </template>
      </section>
    </main>
  </WidgetPageShell>
</template>

<script setup>
import EmojiInput from "./shared/EmojiInput.vue";
import EmojiDisplay from "./shared/EmojiDisplay.vue";
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import { bitcraftFetch as fetch } from "/src/bitcraft-ui/api";
import { route } from "/src/bitcraft-ui/navigation";
import { openWidget, persistWidgetDraft } from "./widgets";
import {
  computed,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from "vue";
import { router, usePage } from "/src/bitcraft-ui/navigation";
import SitePlayerDefaultButton from "./Components/SitePlayerDefaultButton.vue";
import BitcraftTierBadge from "/src/bitcraft-ui/Components/BitcraftTierBadge.vue";
import {
  bitcraftAssetUrl,
  bitcraftItemFrameStyle,
  hasBitcraftTier,
} from "/src/bitcraft-ui/bitjitaAssets.js";
import WidgetSetupDrawer from "./Components/WidgetSetupDrawer.vue";
import WidgetThemeControls from "./Components/WidgetThemeControls.vue";
import WidgetPageShell from "./Components/WidgetPageShell.vue";
import TrackerRefreshStatus from "./Components/TrackerRefreshStatus.vue";
import { createTrackerPoller, retryAfterSeconds } from "./trackerPolling";
import {
  passiveDeadlines,
  passiveSecondsLeft,
  passiveCountdownLabel,
} from "./passiveCountdown";
import {
  normalizeWidgetTheme,
  widgetThemePayload,
  widgetThemeStyle as resolveWidgetThemeStyle,
} from "./widgetTheme";

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  snapshot: {
    type: Object,
    default: () => ({ tracker: null, error: null, sampledAt: null }),
  },
  snapshotUrl: { type: String, required: true },
});

const page = usePage();

const POLL_INTERVAL_MS = 15000;
const STORAGE_KEY = "bitcraft.passiveCraftTracker.lastSetup";

const tracker = ref(props.snapshot.tracker);
const error = ref(props.snapshot.error);
const sampledAt = ref(props.snapshot.sampledAt);
const refreshStatus = ref(props.snapshot.refresh ?? {});
const now = ref(Date.now());
const deadlines = ref(
  passiveDeadlines(tracker.value?.groups, new Map(), now.value),
);
const brokenIcons = ref(new Set());
let countdownTimer;
let restoredSetup = false;

const form = reactive({
  source: props.filters.source ?? "default",
  character: props.filters.character ?? "icha",
  title: props.filters.title ?? "Passive Crafts",
  icons: props.filters.icons ?? "",
  ...normalizeWidgetTheme(props.filters),
});

const setupPageVisible = computed(() => Boolean(props.filters.setup));
const setupVisible = computed(() => setupPageVisible.value);
const titleLabel = computed(() => form.title || "Passive Crafts");
const iconsLabel = computed(() => form.icons || "");
const widgetThemeStyle = computed(() => resolveWidgetThemeStyle(form));
const crafts = computed(() =>
  Array.isArray(tracker.value?.crafts) ? tracker.value.crafts : [],
);
const groups = computed(() =>
  Array.isArray(tracker.value?.groups) ? tracker.value.groups : [],
);
const summaryLabel = computed(() => {
  if (!tracker.value) {
    return form.character || "Unknown";
  }

  const name = tracker.value.player?.username || form.character || "Unknown";

  if (!groups.value.length) {
    return `${name} · 0 active`;
  }

  const latest = groups.value.reduce((longest, group) => {
    const finish = deadlines.value.get(group.key)?.finishesAt;
    return Number.isFinite(finish) && (!longest || finish > longest.finishesAt)
      ? { finishesAt: finish, timerSource: group.timerSource }
      : longest;
  }, null);
  const timerSource =
    latest?.timerSource ??
    (groups.value.every((group) => group.timerSource === "queued")
      ? "queued"
      : null);
  return `${name} · ${formatNumber(tracker.value.totalQueued ?? crafts.value.length)} crafts · ${passiveCountdownLabel(passiveSecondsLeft(latest?.finishesAt, now.value), timerSource)}`;
});

const formatNumber = (value) =>
  new Intl.NumberFormat().format(Math.max(0, Math.round(Number(value) || 0)));
const formatShortTime = (value) => {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return "";
  }

  return new Intl.DateTimeFormat([], {
    hour: "numeric",
    minute: "2-digit",
  }).format(date);
};
const hasTier = (tier) => hasBitcraftTier(tier);
const itemVisualStyle = (item) =>
  bitcraftItemFrameStyle(item?.tier, item?.rarity);
const itemIconUrl = (item) =>
  brokenIcons.value.has(item?.iconAssetName)
    ? null
    : bitcraftAssetUrl(item?.iconAssetName);
const hideBrokenIcon = (assetName) => {
  brokenIcons.value = new Set([...brokenIcons.value, assetName]);
};
const craftProgressPercent = (craft) =>
  Math.max(0, Math.min(100, Number(craft?.progressPercent) || 0));
const progressLabel = (craft) => {
  if (
    ["relay-start", "api", "bitjita", "bitjuice"].includes(craft?.timerSource)
  ) {
    const startedAt = formatShortTime(craft.startedAt);

    return startedAt ? `Started ${startedAt}` : "Live timer";
  }

  if (craft?.timerSource === "recipe") {
    return "Recipe duration estimate";
  }
  if (craft?.timerSource === "queued") return "Queued";

  if (!craft?.totalActionsRequired) {
    return "Waiting";
  }

  return `${formatNumber(craft.progress)} / ${formatNumber(craft.totalActionsRequired)} actions`;
};
const remainingTimeLabel = (entry) => {
  const finish = deadlines.value.get(entry.key)?.finishesAt;
  return passiveCountdownLabel(
    passiveSecondsLeft(finish, now.value),
    entry.timerSource,
  );
};
const estimatedFinishLabel = (entry) => {
  const finish = deadlines.value.get(entry.key)?.finishesAt;
  if (!Number.isFinite(finish)) return "";
  return new Intl.DateTimeFormat([], {
    ...(new Date(finish).toDateString() !== new Date(now.value).toDateString()
      ? { weekday: "short" }
      : {}),
    hour: "numeric",
    minute: "2-digit",
  }).format(new Date(finish));
};
const updateTracker = (value) => {
  now.value = Date.now();
  deadlines.value = passiveDeadlines(value?.groups, deadlines.value, now.value);
  tracker.value = value;
};
const claimName = (group) =>
  group?.claim?.name ||
  (group?.claimEntityId ? `Claim ${group.claimEntityId}` : "Unknown claim");
const regionLabel = (group) => {
  const regionName = group?.claim?.regionName;
  const regionNumber = group?.claim?.region;

  if (regionName && regionNumber) {
    return `${regionName} (R${regionNumber})`;
  }

  return (
    regionName || (regionNumber ? `Region ${regionNumber}` : "Unknown region")
  );
};
const stationLabel = (group) => {
  if (!Array.isArray(group?.buildingNames) || !group.buildingNames.length) {
    return "Unknown station";
  }

  if (group.buildingNames.length === 1) {
    return group.buildingNames[0];
  }

  return `${group.buildingNames[0]} + ${formatNumber(group.buildingNames.length - 1)} more`;
};

const updateTheme = (updates) => {
  Object.assign(form, updates);
  saveSetup();
};

const payload = (setup) => ({
  source: form.source,
  user: props.filters.user ?? undefined,
  character: form.character,
  title: form.title,
  icons: form.icons,
  ...widgetThemePayload(form),
  setup: setup ? 1 : 0,
});

const browserStorage = () => {
  if (typeof window === "undefined") {
    return null;
  }

  return window.localStorage;
};

const normalizeSetup = (setup) => ({
  source:
    typeof setup.source === "string" && setup.source.trim()
      ? setup.source.trim()
      : "default",
  character:
    typeof setup.character === "string" && setup.character.trim()
      ? setup.character.trim()
      : "icha",
  title:
    typeof setup.title === "string" && setup.title.trim()
      ? setup.title.trim()
      : "Passive Crafts",
  icons: typeof setup.icons === "string" ? setup.icons.trim() : "",
  ...normalizeWidgetTheme(setup),
});

const loadSetup = () => {
  const storage = browserStorage();

  if (!storage) {
    return null;
  }

  try {
    const saved = storage.getItem(STORAGE_KEY);
    return saved ? normalizeSetup(JSON.parse(saved)) : null;
  } catch {
    return null;
  }
};

const saveSetup = (persistProfile = true) => {
  const storage = browserStorage();

  if (!storage || !restoredSetup) {
    return;
  }

  if (props.filters.widgetEditable === false) return;
  if (props.filters.setup) {
    storage.setItem(STORAGE_KEY, JSON.stringify(normalizeSetup(form)));
    if (persistProfile)
      persistWidgetDraft(
        "passive-crafts",
        payload(true),
        props.filters.profile,
      );
  }
};

const submitSetup = (setup) => {
  saveSetup();

  router.get(
    setup
      ? route("bitcraft.passive-crafts.setup")
      : route("bitcraft.passive-crafts"),
    payload(setup),
    {
      preserveScroll: true,
      preserveState: false,
      replace: true,
    },
  );
};

const openWidgetMode = () => {
  saveSetup();

  if (typeof window === "undefined") {
    return;
  }

  openWidget(route("bitcraft.passive-crafts", payload(false)), {
    settings: payload(false),
    token: props.filters.profile,
  });
};

const refresh = async () => {
  try {
    const response = await fetch(props.snapshotUrl, {
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    });

    if (response.status === 429) {
      refreshStatus.value = { ...refreshStatus.value, delayed: true };
      return retryAfterSeconds(response);
    }

    if (!response.ok) {
      throw new Error(`Snapshot request failed with ${response.status}`);
    }

    const payload = await response.json();
    refreshStatus.value = payload.refresh ?? {};
    if (payload.tracker) {
      updateTracker(payload.tracker);
      sampledAt.value = payload.sampledAt;
    }
    error.value = payload.error;
    return refreshStatus.value.retryAfter ?? 0;
  } catch {
    error.value = "Tracker refresh failed. Waiting for the next Relay check.";
  }
};

const polling = createTrackerPoller(refresh, POLL_INTERVAL_MS);

watch(
  () => props.snapshot,
  (snapshot) => {
    updateTracker(snapshot.tracker);
    error.value = snapshot.error;
    sampledAt.value = snapshot.sampledAt;
    refreshStatus.value = snapshot.refresh ?? {};
  },
);

watch(
  () => props.filters,
  (filters) => {
    form.source = filters.source ?? "default";
    form.character = filters.character ?? "icha";
    form.title = filters.title ?? "Passive Crafts";
    form.icons = filters.icons ?? "";
    Object.assign(form, normalizeWidgetTheme(filters));
  },
);

watch(form, saveSetup, { deep: true });

onMounted(() => {
  countdownTimer = setInterval(() => {
    now.value = Date.now();
  }, 1000);
  const savedSetup = loadSetup();
  const params = new URLSearchParams(window.location.search);

  restoredSetup = true;

  if (
    !params.has("profile") &&
    !params.has("source") &&
    !params.has("character") &&
    savedSetup?.character
  ) {
    Object.assign(form, savedSetup);
    if (page.props.bitcraft?.player) form.character = props.filters.character;
    submitSetup(Boolean(props.filters.setup));

    return;
  }

  saveSetup(false);
  polling.start(refreshStatus.value.retryAfter ?? 0);
});

onBeforeUnmount(() => {
  clearInterval(countdownTimer);
  polling.stop();
});
</script>

<style scoped>
.passive-craft-source {
  min-height: 100vh;
  display: grid;
  align-content: start;
  gap: 12px;
  background: transparent;
  color: var(--text-primary);
  font-family: var(--font-ui);
}

.passive-craft-source--setup {
  padding: 14px;
  background: var(--bg-canvas);
}

.passive-craft-source--in-app {
  min-height: auto;
  padding: 0;
  background: transparent;
}

.passive-craft-setup {
  width: min(720px, 100%);
  border: 1px solid rgb(var(--border-color-2-rgb) / 0.36);
  border-radius: 8px;
  padding: 12px;
  background:
    linear-gradient(
      180deg,
      rgb(var(--bg-surface-3-rgb) / 0.26),
      rgb(var(--bg-surface-rgb) / 0.94)
    ),
    var(--bg-surface);
}

.passive-craft-setup__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.passive-craft-setup label {
  display: grid;
  gap: 6px;
}

.passive-craft-setup span {
  color: var(--text-muted-3);
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
}

.passive-craft-setup input {
  min-height: 36px;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  background: var(--bg-canvas);
  color: var(--text-primary);
  font-size: 13px;
}

.passive-craft-setup__actions button {
  min-height: 32px;
  border: 1px solid rgb(var(--accent-cyan-rgb) / 0.28);
  border-radius: 6px;
  background: rgb(var(--accent-cyan-rgb) / 0.08);
  color: var(--text-primary-2);
  font-size: 12px;
  font-weight: 900;
}

.passive-craft-setup__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.passive-craft-setup__actions button {
  min-height: 34px;
  padding: 0 12px;
  color: var(--accent-cyan);
}

.passive-craft-widget {
  width: min(var(--tracker-width), 100%);
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--tracker-border) 46%, transparent);
  border-radius: var(--tracker-radius);
  background: linear-gradient(
    180deg,
    color-mix(
      in srgb,
      var(--tracker-panel) var(--tracker-panel-opacity),
      transparent
    ),
    color-mix(in srgb, var(--tracker-panel) 96%, black)
  );
  color: var(--tracker-text);
  box-shadow: inset 0 1px 0 rgb(var(--text-primary-rgb) / 0.04);
}

.passive-craft-widget__header {
  padding: 18px 22px 14px;
  border-bottom: 1px solid
    color-mix(in srgb, var(--tracker-border) 24%, transparent);
}

.passive-craft-widget__header h1 {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
  color: var(--tracker-text);
  font-size: calc(24px * var(--tracker-font-scale));
  font-weight: 900;
  line-height: 1.1;
}

.passive-craft-widget__header p {
  margin-top: 5px;
  color: var(--tracker-muted);
  font-size: calc(12px * var(--tracker-font-scale));
  font-weight: 800;
}

.passive-craft-widget__header h1 > span {
  min-width: 0;
  max-width: 100%;
  overflow-wrap: anywhere;
}

.passive-craft-widget__craft + .passive-craft-widget__craft {
  border-top: 1px solid
    color-mix(in srgb, var(--tracker-border) 24%, transparent);
}

.passive-craft-widget__craft {
  border-left: 3px solid var(--bitcraft-item-frame-border, transparent);
}

.passive-craft-widget__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 12px;
  padding: 18px 22px 7px;
}

.passive-craft-widget__item p {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--tracker-text);
  font-size: calc(18px * var(--tracker-font-scale));
  font-weight: 900;
  line-height: 1.25;
}

.passive-craft-widget__item p > span {
  min-width: 0;
  overflow-wrap: anywhere;
}

.passive-craft-widget__icon {
  flex: 0 0 32px;
  width: 32px;
  height: 32px;
  object-fit: contain;
}

.passive-craft-widget__count {
  text-align: right;
}

.passive-craft-widget__count strong {
  display: block;
  color: var(--tracker-text);
  font-size: calc(19px * var(--tracker-font-scale));
  font-weight: 900;
  line-height: 1.25;
  white-space: nowrap;
}

.passive-craft-widget__bar {
  height: 6px;
  margin: 8px 22px 0;
  overflow: hidden;
  border-radius: 999px;
  background: color-mix(in srgb, var(--tracker-panel) 68%, black);
}

.passive-craft-widget__bar span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(
    90deg,
    color-mix(
      in srgb,
      var(--bitcraft-item-frame-accent, var(--tracker-highlight)) 74%,
      var(--tracker-highlight)
    ),
    color-mix(
      in srgb,
      var(--bitcraft-item-frame-accent, var(--tracker-accent)) 72%,
      var(--tracker-accent)
    )
  );
  transition: width 320ms ease;
}

.passive-craft-widget__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 8px 22px 18px;
  color: color-mix(in srgb, var(--tracker-text) 72%, var(--tracker-accent));
  font-size: calc(12px * var(--tracker-font-scale));
  font-weight: 900;
}

.passive-craft-widget__timer {
  display: grid;
  gap: 4px;
  font-variant-numeric: tabular-nums;
}

.passive-craft-widget__timer small {
  color: var(--tracker-muted);
  font-size: calc(10px * var(--tracker-font-scale));
}

.passive-craft-widget__outputs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 0 22px 18px;
}

.passive-craft-widget__outputs span {
  max-width: 100%;
  overflow-wrap: anywhere;
  border: 1px solid color-mix(in srgb, var(--tracker-border) 32%, transparent);
  border-radius: 999px;
  padding: 4px 8px;
  background: color-mix(in srgb, var(--tracker-panel) 72%, black);
  color: var(--tracker-muted);
  font-size: calc(11px * var(--tracker-font-scale));
  font-weight: 800;
}

.passive-craft-widget__empty,
.passive-craft-widget__error {
  margin: 16px;
  padding: 14px;
  border-radius: 8px;
  font-size: calc(13px * var(--tracker-font-scale));
  font-weight: 800;
}

.passive-craft-widget__empty {
  border: 1px dashed color-mix(in srgb, var(--tracker-border) 42%, transparent);
  color: var(--tracker-muted);
}

.passive-craft-widget__error {
  border: 1px solid rgb(var(--danger-rgb) / 0.42);
  background: rgb(var(--danger-rgb) / 0.1);
  color: var(--danger);
}

@media (max-width: 680px) {
  .passive-craft-setup__grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

</style>
