<template>
  <WidgetPageShell
    :setup="setupPageVisible"
    title="XP Tracker"
    description="Watch your current grind, session gains, and next goal. Keep a companion beside the game or add an overlay to OBS."
  >
    <main
      class="activity-source"
      :class="{
        'activity-source--setup': setupVisible,
        'activity-source--in-app': setupPageVisible,
        'activity-source--popout': presentation === 'popout',
        'activity-source--obs': presentation === 'obs',
      }"
      :style="widgetThemeStyle"
    >
      <WidgetSetupDrawer
        :show="setupVisible"
        title="Edit EXP Widget"
        @widget-mode="openWidgetMode"
        @save="submitSetup(true)"
      >
        <form class="activity-setup" @submit.prevent="submitSetup(true)">
          <div class="activity-setup__grid">
            <label>
              <span>Title</span>
              <input v-model.trim="form.title" type="text" maxlength="80" />
            </label>

            <EmojiInput v-model="form.icons" />

            <label>
              <span>Character</span>
              <input v-model.trim="form.character" type="text" maxlength="80" />
              <SitePlayerDefaultButton @select="form.character = $event" />
            </label>

            <label>
              <span>Skill Scope</span>
              <SelectInput v-model="form.skill" class="input">
                <option value="all">All skills</option>
                <option
                  v-for="skill in skillOptions"
                  :key="skill.id"
                  :value="String(skill.id)"
                >
                  {{ skill.name }}
                </option>
              </SelectInput>
            </label>
          </div>

          <WidgetThemeControls :model="form" @update="updateTheme" />
          <fieldset class="xp-display-options">
            <legend>Show in the tracker</legend>
            <label
              ><input
                v-model="form.showSession"
                class="checkbox"
                type="checkbox"
              />
              Session statistics</label
            ><label
              ><input
                v-model="form.showActivity"
                class="checkbox"
                type="checkbox"
              />
              Activity and stamina</label
            ><label
              ><input
                v-model="form.showGoals"
                class="checkbox"
                type="checkbox"
              />
              Additional skill goals</label
            ><label
              ><input
                v-model="form.showCrafts"
                class="checkbox"
                type="checkbox"
              />
              Passive crafts</label
            >
          </fieldset>

          <div ref="pickerElement" class="activity-picker">
            <label>
              <span>XP / level goals</span>
              <input
                v-model.trim="form.skillSearch"
                type="search"
                autocomplete="off"
                placeholder="Search skills to add a goal"
                @focus="pickerOpen = true"
              />
            </label>

            <div v-if="pickerOpen" class="activity-picker__menu">
              <button
                v-for="option in filteredSkillOptions"
                :key="option.id"
                type="button"
                class="activity-picker__option"
                :class="{
                  selected: form.skillKeys.includes(String(option.id)),
                }"
                :aria-pressed="form.skillKeys.includes(String(option.id))"
                @click="selectSkill(option)"
              >
                <span>{{ option.name }}</span>
                <small
                  >Lv {{ formatNumber(option.level) }} ·
                  {{ formatNumber(option.xp) }} XP</small
                >
              </button>

              <p v-if="filteredSkillOptions.length === 0">No skills loaded.</p>
            </div>
          </div>

          <div v-if="selectedSkillOptions.length" class="activity-selected">
            <span v-for="skill in selectedSkillOptions" :key="skill.id">
              <strong>{{ skill.name }}</strong>
              <label>
                <small>Goal level</small>
                <input
                  v-model.number="form.skillGoalLevels[String(skill.id)]"
                  type="number"
                  min="1"
                  max="999"
                  :placeholder="
                    skill.nextLevel ? String(skill.nextLevel) : 'Level'
                  "
                />
              </label>
              <label>
                <small>Goal XP</small>
                <input
                  v-model.number="form.skillGoalXp[String(skill.id)]"
                  type="number"
                  min="1"
                  max="999999999"
                  :placeholder="
                    skill.nextLevelXp ? String(skill.nextLevelXp) : 'XP'
                  "
                />
              </label>
              <button type="button" @click="removeSkill(String(skill.id))">
                Remove
              </button>
            </span>
          </div>

          <div class="activity-setup__actions">
            <button type="submit">Search / Update</button>
          </div>
        </form>
      </WidgetSetupDrawer>

      <div v-if="presentation !== 'obs'" class="xp-toolbar">
        <div>
          <span class="xp-kicker">LIVE COMPANION</span>
          <p>One grind at a time. Every gain counts.</p>
        </div>
        <div class="xp-toolbar-actions">
          <button
            v-if="presentation !== 'popout'"
            @click="launchTracker('popout')"
          >
            ↗ Pop out
          </button>
          <button @click="copyObsUrl">Copy OBS URL</button>
          <button v-if="presentation === 'popout'" @click="openFullTracker">
            Full tracker
          </button>
        </div>
      </div>
      <div v-if="widgetNotice" class="xp-notice" role="status">
        {{ widgetNotice }}
      </div>
      <input
        v-if="obsUrl && presentation !== 'obs'"
        class="xp-share-url"
        :value="obsUrl"
        readonly
        aria-label="OBS browser source URL"
        @focus="$event.target.select()"
      />
      <nav
        v-if="presentation !== 'obs'"
        class="xp-character-tabs"
        aria-label="Tracked characters"
      >
        <button
          v-for="character in watchedCharacters"
          :key="character"
          :class="{ 'is-selected': character === form.character }"
          @click="switchCharacter(character)"
        >
          {{ characterLabel(character) }}
        </button>
        <form
          v-if="
            watchedCharacters.length < 5 && filters.widgetEditable !== false
          "
          @submit.prevent="addCharacter"
        >
          <input
            v-model.trim="newCharacter"
            aria-label="Add tracked character"
            placeholder="Character name"
            maxlength="80"
          /><button>Add</button>
        </form>
      </nav>

      <section class="activity-widget" aria-label="Bitcraft EXP tracker">
        <header class="activity-widget__header">
          <div class="activity-widget__identity">
            <p class="activity-widget__eyebrow">
              {{ tracker?.player?.username ?? form.character }}
            </p>
            <h1>
              <span>{{ titleLabel }}</span>
              <EmojiDisplay v-if="iconsLabel" :value="iconsLabel" />
            </h1>
          </div>

          <div class="activity-widget__level">
            <p :class="{ 'xp-working': activityState.active }">
              {{ activityState.label }}
            </p>
            <time>{{ clockLabel }}</time>
          </div>
        </header>

        <TrackerRefreshStatus
          :refresh="refreshStatus"
          :sampled-at="sampledAt"
        />
        <p
          v-if="tracker?.activity && form.showActivity"
          class="activity-widget__warning"
        >
          {{ tracker.activity.signed_in ? "Signed in" : "Last known activity" }}
          <template v-if="tracker.activity.stamina">
            · Stamina {{ Math.round(tracker.activity.stamina.current) }} /
            {{ Math.round(tracker.activity.stamina.max) }}
          </template>
          <template v-if="tracker.activity.target?.name">
            · {{ tracker.activity.target.name }}</template
          >
          <template v-if="tracker.activity.delayed">
            · Refresh delayed</template
          >
        </p>

        <div v-if="blockingError" class="activity-widget__error">
          {{ error }}
        </div>

        <template v-else>
          <div v-if="error" class="activity-widget__warning">
            {{ error }}
          </div>

          <div class="xp-hero">
            <div class="xp-hero-top">
              <div>
                <span class="xp-kicker">{{
                  goalMode ? "YOUR GOAL" : "CURRENT SKILL"
                }}</span>
                <h2>
                  <span>{{
                    primaryStat?.skill.name || "Waiting for your next grind"
                  }}</span>
                  <template v-if="primaryItem">
                    <span aria-hidden="true">-</span>
                    <span class="xp-source-item">
                      <img
                        v-if="sourceIconUrl"
                        :src="sourceIconUrl"
                        alt=""
                        width="28"
                        height="28"
                        loading="lazy"
                        decoding="async"
                        @error="hideSourceIcon"
                      />
                      <span>{{ primaryItem.name }}</span>
                    </span>
                  </template>
                </h2>
                <p>
                  {{
                    primaryStat
                      ? `Level ${primaryStat.skill.level} → ${skillGoalLabel(primaryStat)}`
                      : "Start earning XP, or choose a skill in Edit Widget."
                  }}
                </p>
              </div>
              <strong v-if="primaryStat"
                >{{ Math.floor(displayProgressPercent(primaryStat) * 10) / 10
                }}<small>%</small></strong
              >
            </div>
            <div
              class="xp-goal-track"
              role="progressbar"
              :aria-label="
                primaryStat
                  ? `${primaryStat.skill.name} goal progress`
                  : 'Waiting for skill'
              "
              :aria-valuenow="
                primaryStat ? displayProgressPercent(primaryStat) : 0
              "
              aria-valuemin="0"
              aria-valuemax="100"
            >
              <span
                :style="{
                  width: `${primaryStat ? displayProgressPercent(primaryStat) : 0}%`,
                }"
              />
            </div>
            <div class="xp-goal-copy">
              <span>{{
                primaryStat
                  ? `${formatNumber(primaryStat.skill.xp)} current XP`
                  : waitingLabel
              }}</span
              ><span>{{
                primaryStat
                  ? `${formatNumber(skillRemainingXp(primaryStat))} XP remaining`
                  : ""
              }}</span>
            </div>
          </div>
          <div v-if="form.showSession" class="xp-stats-strip">
            <div>
              <span>Session XP</span
              ><strong>+{{ formatCompact(session?.gained || 0) }}</strong
              ><small>Observed gains</small>
            </div>
            <div>
              <span>XP / hour</span><strong>{{ measuredRate }}</strong
              ><small>{{
                refreshStatus.delayed ? "Refresh delayed" : "Continuous samples"
              }}</small>
            </div>
            <div>
              <span>Time to goal</span
              ><strong>{{
                primaryStat && !refreshStatus.delayed
                  ? skillTimeRemainingLabel(primaryStat)
                  : "—"
              }}</strong
              ><small>At measured rate</small>
            </div>
            <div>
              <span>Active time</span><strong>{{ sessionClock }}</strong
              ><small>Observed XP activity</small>
            </div>
          </div>

          <div
            v-if="form.showGoals && presentation !== 'obs'"
            class="activity-widget__metrics"
          >
            <article
              v-if="!goalMode && activeSkillStats.length"
              class="activity-widget__metric activity-widget__metric--accent"
            >
              <div class="activity-widget__metric-row">
                <div class="activity-widget__metric-item">
                  <p>Total XP Rate</p>
                  <small>{{ totalRecentXpLabel }}</small>
                </div>

                <div class="activity-widget__metric-count">
                  <small>Current Rate</small>
                  <strong>{{ totalXpRateLabel }}</strong>
                </div>
              </div>
            </article>

            <article
              v-for="stat in displaySkillStats"
              :key="stat.skill.id"
              class="activity-widget__metric"
            >
              <div class="activity-widget__metric-row">
                <div class="activity-widget__metric-item">
                  <p>{{ stat.skill.name }}</p>
                  <small>{{ skillDetailLabel(stat) }}</small>
                </div>

                <div class="activity-widget__metric-count">
                  <small>{{ skillCountLabel(stat) }}</small>
                  <strong>{{ skillPrimaryLabel(stat) }}</strong>
                </div>
              </div>

              <div class="activity-widget__mini-progress" aria-hidden="true">
                <span :style="{ width: `${displayProgressPercent(stat)}%` }" />
              </div>
            </article>

            <article
              v-if="!displaySkillStats.length"
              class="activity-widget__metric activity-widget__metric--accent"
            >
              <div class="activity-widget__metric-row">
                <div class="activity-widget__metric-item">
                  <p>{{ goalMode ? "XP Goals" : "All XP" }}</p>
                  <small>{{ waitingLabel }}</small>
                </div>

                <div class="activity-widget__metric-count">
                  <small>Status</small>
                  <strong>Waiting</strong>
                </div>
              </div>
            </article>
          </div>
        </template>
        <div v-if="form.showCrafts" class="xp-crafts">
          <div class="xp-section-head">
            <strong>Passive crafts</strong
            ><span>{{
              tracker?.craftsDelayed
                ? "Last known"
                : `${tracker?.passiveCrafts?.length || 0} in progress`
            }}</span>
          </div>
          <p v-if="!passiveCraftGroups.length">
            {{
              tracker?.craftsDelayed
                ? "Crafts are temporarily unavailable."
                : "No passive crafts in this watched snapshot."
            }}
          </p>
          <article
            v-for="group in passiveCraftGroups"
            :key="group.key"
            :title="group.claim?.name"
          >
            <span
              >{{ group.name }} x{{
                formatNumber(group.totalOutputQuantity)
              }}</span
            >
            <strong>{{ passiveCraftCountdown(group) }}</strong>
          </article>
        </div>
        <div v-if="presentation !== 'obs'" class="xp-session-controls">
          <button @click="resetSession">Reset session</button
          ><button
            v-if="
              watchedCharacters.length > 1 && filters.widgetEditable !== false
            "
            @click="removeCharacter"
          >
            Remove character
          </button>
        </div>
        <p
          v-if="sessionNotice && presentation !== 'obs'"
          class="xp-notice"
          role="status"
        >
          {{ sessionNotice }}
        </p>
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
import { openWidget, widgetUrl, persistWidgetDraft } from "./widgets";
import { restoreXpSession, trackerActivity } from "./xpSession";
import { xpItemForSkill } from "./xpSource";
import { bitcraftAssetUrl } from "./bitjitaAssets";
import {
  passiveDeadlines,
  passiveSecondsLeft,
  passiveCountdownLabel,
} from "./passiveCountdown";
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
import WidgetSetupDrawer from "./Components/WidgetSetupDrawer.vue";
import WidgetThemeControls from "./Components/WidgetThemeControls.vue";
import WidgetPageShell from "./Components/WidgetPageShell.vue";
import TrackerRefreshStatus from "./Components/TrackerRefreshStatus.vue";
import { createTrackerPoller, retryAfterSeconds } from "./trackerPolling";
import {
  activitySkillRate,
  appendActivitySample,
  restoreActivitySamples,
  normalizeActivitySamples,
} from "./activitySamples";
import {
  normalizeWidgetTheme,
  widgetThemePayload,
  widgetThemeStyle as resolveWidgetThemeStyle,
} from "./widgetTheme";

const props = defineProps({
  filters: {
    type: Object,
    default: () => ({ character: "icha", skill: "all" }),
  },
  snapshot: {
    type: Object,
    default: () => ({ tracker: null, error: null, sampledAt: null }),
  },
  pollUrl: { type: String, required: true },
});

const page = usePage();

const POLL_INTERVAL_MS = 10 * 1000;
const ACTIVE_STAT_GRACE_MS = 15 * 60 * 1000;
const STORAGE_KEY = "bitcraft.activityTracker.lastSetup";
const SAMPLE_STORAGE_PREFIX = "bitcraft.activityTracker.samples.v2.";

const tracker = ref(props.snapshot.tracker);
const error = ref(props.snapshot.error);
const sampledAt = ref(props.snapshot.sampledAt);
const refreshStatus = ref(props.snapshot.refresh ?? {});
const samples = ref([]);
const now = ref(new Date());
const passiveCraftGroups = computed(
  () => tracker.value?.passiveCraftGroups ?? [],
);
const passiveCraftDeadlines = ref(new Map());
watch(
  [() => tracker.value?.player?.entityId, passiveCraftGroups],
  ([playerId, groups], previous) => {
    passiveCraftDeadlines.value = passiveDeadlines(
      groups,
      playerId === previous?.[0] ? passiveCraftDeadlines.value : new Map(),
      now.value.getTime(),
    );
  },
  { immediate: true },
);
const passiveCraftCountdown = (group) => {
  const seconds = passiveSecondsLeft(
    passiveCraftDeadlines.value.get(group.key)?.finishesAt,
    now.value.getTime(),
  );
  const label = passiveCountdownLabel(seconds, group.timerSource);
  return group.waitingCount && group.timerSource !== "queued"
    ? `${label} · ${group.waitingCount} waiting`
    : label;
};
const pickerOpen = ref(false);
const pickerElement = ref(null);
const lastActiveSkillStats = ref([]);
const lastActiveSkillStatsAt = ref(0);
let clockTimer = null;
let restoredSetup = false;

const parseSkillKeys = (value) => {
  const keys = Array.isArray(value) ? value : String(value ?? "").split(",");

  return [
    ...new Set(
      keys.map((key) => String(key).trim()).filter((key) => /^\d+$/.test(key)),
    ),
  ];
};

const parseGoalMap = (value) => {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    return Object.fromEntries(
      Object.entries(value)
        .map(([key, goal]) => [String(key), Number(goal)])
        .filter(
          ([key, goal]) =>
            /^\d+$/.test(key) && Number.isFinite(goal) && goal > 0,
        ),
    );
  }

  return Object.fromEntries(
    String(value ?? "")
      .split(",")
      .map((entry) => {
        const separator = entry.indexOf("=");

        return separator === -1
          ? ["", 0]
          : [
              entry.slice(0, separator).trim(),
              Number(entry.slice(separator + 1).trim()),
            ];
      })
      .filter(
        ([key, goal]) => /^\d+$/.test(key) && Number.isFinite(goal) && goal > 0,
      ),
  );
};

const formatGoalMap = (goals) =>
  Object.entries(goals)
    .filter(
      ([key, goal]) =>
        /^\d+$/.test(key) && Number.isFinite(Number(goal)) && Number(goal) > 0,
    )
    .map(([key, goal]) => `${key}=${Math.round(Number(goal))}`)
    .join(",");

const form = reactive({
  source: props.filters.source ?? "default",
  character: props.filters.character ?? "icha",
  skill: props.filters.skill ?? "all",
  title: props.filters.title ?? "XP Goals",
  icons: props.filters.icons ?? "",
  skillSearch: props.filters.skillSearch ?? "",
  skillKeys: parseSkillKeys(props.filters.skillKeys ?? ""),
  skillGoalLevels: parseGoalMap(props.filters.skillGoalLevels ?? ""),
  skillGoalXp: parseGoalMap(props.filters.skillGoalXp ?? ""),
  characters: String(
    props.filters.characters ?? props.filters.character ?? "icha",
  ),
  showSession: ![false, "false", "0"].includes(props.filters.showSession),
  showActivity: ![false, "false", "0"].includes(props.filters.showActivity),
  showGoals: ![false, "false", "0"].includes(props.filters.showGoals),
  showCrafts: ![false, "false", "0"].includes(props.filters.showCrafts),
  ...normalizeWidgetTheme(props.filters),
});

const presentation = computed(() =>
  setupPageVisible.value
    ? "full"
    : props.filters.presentation === "popout"
      ? "popout"
      : "obs",
);
const newCharacter = ref(""),
  obsUrl = ref(""),
  widgetNotice = ref(""),
  sessionNotice = ref(""),
  backgroundRecords = ref([]);
const session = ref(null);
let sessionSeedUsed = false;
const latestSourceKey = ref(props.snapshot.sampleSourceKey);
let backgroundTimer;
const watchedCharacters = computed(() =>
  [
    ...new Set(
      [form.character, ...String(form.characters || "").split(",")]
        .map((value) => value.trim())
        .filter(Boolean),
    ),
  ].slice(0, 5),
);
const sessionKey = (id) => `space.bitcraft.xpSession.v1.${id}`;
function recordSession(sample, selected = false, history = []) {
  let previous;
  try {
    previous = JSON.parse(
      localStorage.getItem(sessionKey(sample.playerId)) || "null",
    );
  } catch {
    /* Storage is optional. */
  }
  if (selected && !previous && session.value?.playerId === sample.playerId)
    previous = session.value;
  if (selected && !previous && !sessionSeedUsed && props.filters.sessionState) {
    sessionSeedUsed = true;
    try {
      previous = JSON.parse(props.filters.sessionState);
    } catch {
      /* Invalid seed is ignored. */
    }
  }
  const next = restoreXpSession(previous, sample, history);
  if (selected) session.value = next;
  if (next)
    try {
      localStorage.setItem(sessionKey(sample.playerId), JSON.stringify(next));
    } catch {
      /* Continue in memory. */
    }
}
const activityState = ref(null);
watch(
  [
    () => tracker.value?.activity,
    () => tracker.value?.player?.entityId,
    latestSourceKey,
    () => refreshStatus.value.delayed,
    () => session.value?.lastGainAt,
    now,
  ],
  () => {
    const activity = tracker.value?.activity;
    activityState.value = trackerActivity(
      activity
        ? {
            ...activity,
            delayed: refreshStatus.value.delayed || activity.delayed,
          }
        : refreshStatus.value.delayed
          ? { delayed: true }
          : null,
      now.value.getTime(),
      session.value?.playerId === String(tracker.value?.player?.entityId)
        ? session.value.lastGainAt
        : 0,
      activityState.value,
      `${tracker.value?.player?.entityId ?? ""}|${latestSourceKey.value ?? ""}`,
    );
  },
  { immediate: true },
);
const primaryStat = computed(
  () =>
    goalSkillStats.value[0] ||
    skillStats.value.find(
      (stat) => String(stat.skill.id) === String(session.value?.activeSkillId),
    ) ||
    (form.skill !== "all" ? skillStats.value[0] : null),
);
const brokenSourceIcons = ref(new Set());
const primaryItem = computed(() =>
  refreshStatus.value.delayed
    ? null
    : xpItemForSkill(
        tracker.value?.xpSource,
        primaryStat.value?.skill,
        tracker.value?.activity,
        now.value.getTime(),
        tracker.value?.player?.entityId,
      ),
);
const sourceIconUrl = computed(() =>
  brokenSourceIcons.value.has(primaryItem.value?.iconAssetName)
    ? null
    : bitcraftAssetUrl(primaryItem.value?.iconAssetName),
);
const hideSourceIcon = () => {
  brokenSourceIcons.value = new Set([
    ...brokenSourceIcons.value,
    primaryItem.value?.iconAssetName,
  ]);
};
const measuredRate = computed(() =>
  refreshStatus.value.delayed
    ? "—"
    : samples.value.length >= 2 &&
        skillStats.value.some((s) => s.minutesSampled >= 1)
      ? formatCompact(primaryStat.value?.hourRate ?? totalStats.value.hourRate)
      : "Sampling",
);
const sessionClock = computed(() => {
  const seconds = Math.floor((session.value?.activeMs || 0) / 1000);
  return `${String(Math.floor(seconds / 3600)).padStart(2, "0")}:${String(Math.floor(seconds / 60) % 60).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;
});
function characterLabel(character) {
  const row = backgroundRecords.value.find(
    (row) => row.character === character,
  );
  return (
    row?.username ||
    (character === form.character
      ? tracker.value?.player?.username
      : character) ||
    character
  );
}
function switchCharacter(character) {
  if (character === form.character) return;
  form.character = character;
  if (props.filters.widgetEditable === false) {
    const url = new URL(location.href);
    url.searchParams.set("character", character);
    router.visit(url.pathname + url.search);
    return;
  }
  submitSetup(setupPageVisible.value);
}
function addCharacter() {
  if (!newCharacter.value || watchedCharacters.value.length >= 5) return;
  form.characters = [...watchedCharacters.value, newCharacter.value].join(",");
  const next = newCharacter.value;
  newCharacter.value = "";
  switchCharacter(next);
}
function removeCharacter() {
  const remaining = watchedCharacters.value.filter((c) => c !== form.character);
  if (!remaining.length) return;
  form.characters = remaining.join(",");
  form.character = remaining[0];
  submitSetup(setupPageVisible.value);
}
async function refreshBackground() {
  if (presentation.value === "obs") return;
  for (const character of watchedCharacters.value.filter(
    (c) => c !== form.character,
  )) {
    try {
      const url = new URL(props.pollUrl, location.origin);
      url.searchParams.delete("profile");
      url.searchParams.set("character", character);
      url.searchParams.set("background", "1");
      url.searchParams.set("skill", "all");
      const response = await fetch(url.href, { cache: "no-store" });
      if (!response.ok) continue;
      const snapshot = await response.json(),
        player = snapshot.tracker?.player;
      if (!player || snapshot.refresh?.delayed) continue;
      recordSession(
        {
          playerId: String(player.entityId),
          sourceKey: snapshot.sampleSourceKey,
          at: Date.parse(snapshot.sampledAt),
          xp: Object.fromEntries(
            trackerSkills(snapshot.tracker)
              .filter((s) => s.xpKnown !== false)
              .map((s) => [String(s.id), s.xp]),
          ),
        },
        false,
        snapshot.xpHistory,
      );
      backgroundRecords.value = [
        ...backgroundRecords.value.filter((row) => row.character !== character),
        { character, username: player.username },
      ];
    } catch {
      /* The selected tracker continues while another character reconnects. */
    }
  }
}
function resetSession() {
  sessionSeedUsed = true;
  session.value = null;
  try {
    localStorage.removeItem(
      sessionKey(String(tracker.value?.player?.entityId)),
    );
  } catch {
    /* optional */
  }
  samples.value = [];
  lastActiveSkillStats.value = [];
  lastActiveSkillStatsAt.value = 0;
  addSample(
    tracker.value,
    sampledAt.value,
    latestSourceKey.value,
    refreshStatus.value.delayed,
  );
  sessionNotice.value = "Session reset. A fresh XP baseline has been taken.";
}
function widgetPayload(mode) {
  return {
    ...payload(false),
    presentation: mode,
    sessionState: session.value ? JSON.stringify(session.value) : "",
  };
}
function launchTracker(mode) {
  const tab = openWidget(route("bitcraft.activity", widgetPayload(mode)), {
    popup: mode === "popout",
    settings: widgetPayload(mode),
    token: props.filters.profile,
  });
  if (!tab)
    widgetNotice.value =
      "The browser blocked the popout. Allow popups for this site, then try again.";
}
function openFullTracker() {
  window.open(
    route("bitcraft.activity.setup", {
      ...payload(true),
      profile: props.filters.profile,
    }),
    "_blank",
    "noopener",
  );
}
async function copyObsUrl() {
  try {
    obsUrl.value = await widgetUrl(
      route("bitcraft.activity", widgetPayload("obs")),
      { settings: widgetPayload("obs"), token: props.filters.profile },
    );
    await navigator.clipboard.writeText(obsUrl.value);
    widgetNotice.value = `OBS URL copied. Add it as a Browser Source at ${form.width}px wide; the page background is transparent.`;
  } catch {
    widgetNotice.value = obsUrl.value
      ? "Select and copy the URL above for your OBS Browser Source."
      : "The widget could not be saved. Try again after the connection recovers.";
  }
}
const trackerSkills = (nextTracker) => {
  if (!nextTracker) {
    return [];
  }

  if (Array.isArray(nextTracker.skills) && nextTracker.skills.length) {
    return nextTracker.skills;
  }

  return nextTracker.skill
    ? [
        {
          ...nextTracker.skill,
          xp: nextTracker.xp,
          level: nextTracker.level,
          nextLevel: nextTracker.nextLevel,
          xpRemaining: nextTracker.xpRemaining,
          progressPercent: nextTracker.progressPercent,
        },
      ]
    : [];
};

const addSample = (
  nextTracker,
  nextSampledAt,
  sourceKey,
  delayed = false,
  history = [],
) => {
  latestSourceKey.value = sourceKey;
  if (!nextTracker || delayed) {
    return;
  }

  const sampledTime = Date.parse(nextSampledAt);
  const sample = {
    at: sampledTime,
    playerId: String(nextTracker.player?.entityId ?? ""),
    sourceKey,
    xpBySkill: Object.fromEntries(
      trackerSkills(nextTracker)
        .filter((skill) => skill.xpKnown !== false && skill.xp != null)
        .map((skill) => [String(skill.id), Number(skill.xp ?? 0)]),
    ),
  };

  recordSession({ ...sample, xp: sample.xpBySkill }, true, history);
  samples.value = history.length
    ? restoreActivitySamples(
        samples.value,
        history.filter((row) => row.at >= session.value?.startedAt),
        sample,
      )
    : appendActivitySample(samples.value, sample);
  if (samples.value.length === 1) {
    lastActiveSkillStats.value = [];
    lastActiveSkillStatsAt.value = 0;
  }
  saveSamples();
};

const refresh = async () => {
  try {
    const url = new URL(props.pollUrl, window.location.origin);
    url.searchParams.set("_", Date.now().toString());

    const response = await fetch(url.toString(), {
      cache: "no-store",
      headers: {
        Accept: "application/json",
        "Cache-Control": "no-cache",
        Pragma: "no-cache",
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
      tracker.value = payload.tracker;
      sampledAt.value = payload.sampledAt;
      addSample(
        payload.tracker,
        payload.sampledAt,
        payload.sampleSourceKey,
        refreshStatus.value.delayed,
        payload.xpHistory,
      );
    }

    error.value = payload.error;
    return refreshStatus.value.retryAfter ?? 0;
  } catch {
    error.value =
      "Tracker refresh failed. Waiting for the next provider check.";
  }
};

const polling = createTrackerPoller(refresh, POLL_INTERVAL_MS, {
  pauseWhenHidden: false,
});

onMounted(() => {
  const savedSetup = loadSetup();
  const params = new URLSearchParams(window.location.search);

  restoredSetup = true;

  if (
    !params.has("profile") &&
    !params.has("source") &&
    !params.has("skillKeys") &&
    savedSetup
  ) {
    Object.assign(form, savedSetup);
    if (page.props.bitcraft?.player && !params.has("character"))
      form.character = props.filters.character;
    submitSetup(Boolean(props.filters.setup));

    return;
  }

  saveSetup(false);
  samples.value = loadSamples();
  addSample(
    tracker.value,
    sampledAt.value,
    props.snapshot.sampleSourceKey,
    refreshStatus.value.delayed,
    props.snapshot.xpHistory,
  );
  polling.start(refreshStatus.value.retryAfter ?? 0);
  clockTimer = window.setInterval(() => {
    now.value = new Date();
  }, 1000);
  void refreshBackground();
  backgroundTimer = window.setInterval(refreshBackground, 10000);
  document.addEventListener("pointerdown", closePickerOnOutsidePointer);
});

onBeforeUnmount(() => {
  polling.stop();
  window.clearInterval(clockTimer);
  window.clearInterval(backgroundTimer);
  document.removeEventListener("pointerdown", closePickerOnOutsidePointer);
});

const formatNumber = (value) =>
  new Intl.NumberFormat().format(Math.max(0, Math.round(Number(value) || 0)));

const formatCompact = (value) =>
  new Intl.NumberFormat(undefined, {
    notation: "compact",
    maximumFractionDigits: Number(value) >= 1000000 ? 1 : 0,
  }).format(Math.max(0, Number(value) || 0));

const formatDuration = (minutes) => {
  if (!Number.isFinite(minutes) || minutes <= 0) {
    return "--";
  }

  const roundedMinutes = Math.ceil(minutes);

  if (roundedMinutes < 60) {
    return `${roundedMinutes}m`;
  }

  const hours = Math.floor(roundedMinutes / 60);
  const remainingMinutes = roundedMinutes % 60;

  return remainingMinutes ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
};

const payload = (setup) => ({
  source: form.source,
  user: props.filters.user ?? undefined,
  character: form.character,
  skill: form.skill,
  title: form.title,
  icons: form.icons,
  skillSearch: form.skillSearch,
  skillKeys: form.skillKeys.join(","),
  skillGoalLevels: formatGoalMap(form.skillGoalLevels),
  skillGoalXp: formatGoalMap(form.skillGoalXp),
  ...widgetThemePayload(form),
  characters: watchedCharacters.value.join(","),
  showSession: form.showSession,
  showActivity: form.showActivity,
  showGoals: form.showGoals,
  showCrafts: form.showCrafts,
  presentation: props.filters.presentation || "full",
  setup: setup ? 1 : 0,
});

const browserStorage = () => {
  if (typeof window === "undefined") {
    return null;
  }

  return window.localStorage;
};

const sampleStorageKey = () => {
  const url = new URL(props.pollUrl, window.location.origin);

  url.searchParams.delete("_");

  return `${SAMPLE_STORAGE_PREFIX}${url.pathname}?${url.searchParams.toString()}`;
};

const normalizeSamples = normalizeActivitySamples;

const loadSamples = () => {
  const storage = browserStorage();

  if (!storage) {
    return [];
  }

  try {
    return normalizeSamples(
      JSON.parse(storage.getItem(sampleStorageKey()) ?? "[]"),
    );
  } catch {
    return [];
  }
};

const saveSamples = () => {
  const storage = browserStorage();

  if (!storage) {
    return;
  }

  storage.setItem(
    sampleStorageKey(),
    JSON.stringify(normalizeSamples(samples.value)),
  );
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
  skill:
    typeof setup.skill === "string" && setup.skill.trim()
      ? setup.skill.trim()
      : "all",
  title:
    typeof setup.title === "string" && setup.title.trim()
      ? setup.title.trim()
      : "XP Goals",
  icons: typeof setup.icons === "string" ? setup.icons.trim() : "",
  skillSearch:
    typeof setup.skillSearch === "string" ? setup.skillSearch.trim() : "",
  skillKeys: parseSkillKeys(setup.skillKeys),
  skillGoalLevels: parseGoalMap(setup.skillGoalLevels),
  skillGoalXp: parseGoalMap(setup.skillGoalXp),
  characters: String(setup.characters || setup.character || "icha"),
  showSession: setup.showSession !== false,
  showActivity: setup.showActivity !== false,
  showGoals: setup.showGoals !== false,
  showCrafts: setup.showCrafts !== false,
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
      persistWidgetDraft("activity", payload(true), props.filters.profile);
  }
};

const updateTheme = (updates) => {
  Object.assign(form, updates);
  saveSetup();
};

const submitSetup = (setup) => {
  saveSetup();

  router.get(
    setup ? route("bitcraft.activity.setup") : route("bitcraft.activity"),
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

  launchTracker("obs");
};

const skills = computed(() => trackerSkills(tracker.value));
const availableSkills = computed(() => {
  if (
    Array.isArray(tracker.value?.skillOptions) &&
    tracker.value.skillOptions.length
  ) {
    return tracker.value.skillOptions;
  }

  return skills.value;
});
const levels = computed(() => tracker.value?.levels ?? []);
const setupPageVisible = computed(() => Boolean(props.filters.setup));
const setupVisible = computed(() => setupPageVisible.value);
const titleLabel = computed(() => form.title || "EXP Tracker");
const iconsLabel = computed(() => form.icons || "");
const widgetThemeStyle = computed(() => ({
  ...resolveWidgetThemeStyle(form),
  "--tracker-width": setupPageVisible.value ? "760px" : `${form.width}px`,
}));
const skillOptions = computed(() =>
  availableSkills.value
    .slice()
    .sort((a, b) => String(a.name).localeCompare(String(b.name))),
);
const filteredSkillOptions = computed(() => {
  const search = form.skillSearch.toLowerCase();

  return skillOptions.value
    .filter((skill) => {
      if (!search) {
        return true;
      }

      return [skill.name, skill.title, skill.level]
        .filter(Boolean)
        .join(" ")
        .toLowerCase()
        .includes(search);
    })
    .slice(0, 40);
});
const selectedSkillOptions = computed(() =>
  form.skillKeys
    .map(
      (key) =>
        skillOptions.value.find((skill) => String(skill.id) === key) ?? {
          id: key,
          name: `Skill ${key}`,
        },
    )
    .filter(Boolean),
);

const selectSkill = (skill) => {
  const skillId = String(skill.id);

  if (!form.skillKeys.includes(skillId)) {
    form.skillKeys.push(skillId);
  }

  if (!form.skillGoalLevels[skillId] && skill.nextLevel) {
    form.skillGoalLevels[skillId] = skill.nextLevel;
  }

  form.skillSearch = "";
  pickerOpen.value = false;
  saveSetup();
};

const removeSkill = (skillId) => {
  form.skillKeys = form.skillKeys.filter((key) => key !== String(skillId));
  delete form.skillGoalLevels[String(skillId)];
  delete form.skillGoalXp[String(skillId)];
  saveSetup();
};

const closePickerOnOutsidePointer = (event) => {
  if (!pickerElement.value || pickerElement.value.contains(event.target)) {
    return;
  }

  pickerOpen.value = false;
};

watch(
  () => props.snapshot,
  (snapshot) => {
    tracker.value = snapshot.tracker;
    error.value = snapshot.error;
    sampledAt.value = snapshot.sampledAt;
    refreshStatus.value = snapshot.refresh ?? {};
    addSample(
      snapshot.tracker,
      snapshot.sampledAt,
      snapshot.sampleSourceKey,
      refreshStatus.value.delayed,
      snapshot.xpHistory,
    );
  },
);

watch(form, saveSetup, { deep: true });

const skillStats = computed(() =>
  skills.value.map((skill) => ({
    skill,
    ...activitySkillRate(samples.value, String(skill.id)),
    progressPercent: Math.max(
      0,
      Math.min(100, Number(skill.progressPercent ?? 0)),
    ),
  })),
);

const levelXp = (level) => {
  const levelRecord = levels.value.find(
    (entry) => Number(entry.level) === Number(level),
  );

  return levelRecord ? Number(levelRecord.xp) : null;
};

const goalForSkill = (skill) => {
  const skillId = String(skill.id);
  const goalLevel = Number(form.skillGoalLevels[skillId] ?? 0);
  const goalXp = Number(form.skillGoalXp[skillId] ?? 0);
  const goalLevelXp = goalLevel > 0 ? levelXp(goalLevel) : null;
  const fallbackGoalXp = Number(skill.nextLevelXp ?? 0);
  const targetXp = Math.max(
    goalXp > 0 ? goalXp : 0,
    goalLevelXp ?? 0,
    fallbackGoalXp,
  );

  return {
    level: goalLevel > 0 ? goalLevel : null,
    xp: targetXp > 0 ? targetXp : null,
  };
};

const goalSkillStats = computed(() =>
  form.skillKeys
    .map((skillId) => {
      const stat = skillStats.value.find(
        (item) => String(item.skill.id) === String(skillId),
      );

      if (!stat) {
        return null;
      }

      const goal = goalForSkill(stat.skill);
      const targetXp = goal.xp;
      const currentXp = Number(stat.skill.xp ?? 0);
      const xpRemaining =
        targetXp === null ? null : Math.max(0, targetXp - currentXp);

      return {
        ...stat,
        goal,
        targetXp,
        xpRemaining,
        goalProgressPercent:
          targetXp === null
            ? stat.progressPercent
            : Math.max(
                0,
                Math.min(100, (currentXp / Math.max(1, targetXp)) * 100),
              ),
      };
    })
    .filter(Boolean),
);

const activeSkillStats = computed(() =>
  skillStats.value
    .filter((stat) => stat.xpDelta > 0 && stat.hourRate > 0)
    .sort((a, b) => b.hourRate - a.hourRate),
);
watch(activeSkillStats, (stats) => {
  if (!stats.length) {
    return;
  }

  lastActiveSkillStats.value = stats;
  lastActiveSkillStatsAt.value = Date.now();
});
const goalMode = computed(() => goalSkillStats.value.length > 0);
const fallbackActiveSkillStats = computed(() => {
  if (
    !lastActiveSkillStats.value.length ||
    now.value.getTime() - lastActiveSkillStatsAt.value > ACTIVE_STAT_GRACE_MS
  ) {
    return [];
  }

  return lastActiveSkillStats.value;
});
const displaySkillStats = computed(() =>
  goalMode.value
    ? goalSkillStats.value
    : activeSkillStats.value.length
      ? activeSkillStats.value
      : fallbackActiveSkillStats.value,
);

const totalStats = computed(() =>
  activeSkillStats.value.reduce(
    (total, stat) => ({
      xpDelta: total.xpDelta + stat.xpDelta,
      hourRate: total.hourRate + stat.hourRate,
    }),
    { xpDelta: 0, hourRate: 0 },
  ),
);

const skillLevelLabel = (skill) =>
  `${skill.name} Lv ${formatNumber(skill.level ?? 0)}`;
const displayProgressPercent = (stat) =>
  Number(stat.goalProgressPercent ?? stat.progressPercent ?? 0);
const summaryProgressPercent = computed(() =>
  displaySkillStats.value[0]
    ? displayProgressPercent(displaySkillStats.value[0])
    : 0,
);
const activitySummaryLabel = computed(() => {
  if (!tracker.value) {
    return "All XP";
  }

  const topGoalSkill = goalSkillStats.value[0]?.skill;

  if (topGoalSkill) {
    return skillLevelLabel(topGoalSkill);
  }

  const topActiveSkill = activeSkillStats.value[0]?.skill;

  if (topActiveSkill) {
    return skillLevelLabel(topActiveSkill);
  }

  if (skills.value.length === 1) {
    return skillLevelLabel(skills.value[0]);
  }

  return "Sampling";
});
const clockLabel = computed(() =>
  new Intl.DateTimeFormat(undefined, {
    hour: "numeric",
    minute: "2-digit",
  }).format(now.value),
);

const totalXpRateLabel = computed(
  () => `${formatCompact(totalStats.value.hourRate)} XP/hr`,
);
const totalRecentXpLabel = computed(
  () =>
    `${formatNumber(totalStats.value.xpDelta)} recent XP across ${activeSkillStats.value.length} skill${activeSkillStats.value.length === 1 ? "" : "s"}`,
);
const blockingError = computed(() => Boolean(error.value && !tracker.value));
const waitingLabel = computed(() =>
  tracker.value
    ? "Sampling at least a minute before calculating XP/hr."
    : "Waiting for player data.",
);

const skillRateLabel = (stat) => `${formatCompact(stat.hourRate)} XP/hr`;
const skillRemainingXp = (stat) =>
  stat.targetXp
    ? Number(stat.xpRemaining ?? 0)
    : Number(stat.skill.xpRemaining ?? 0);
const skillTimeRemainingLabel = (stat) => {
  if (
    !stat.targetXp &&
    (!stat.skill.nextLevel || stat.skill.xpRemaining === null)
  ) {
    return "Done";
  }

  const xpRemaining = skillRemainingXp(stat);

  if (xpRemaining <= 0) {
    return "Done";
  }

  if (stat.hourRate <= 0) {
    return "--";
  }

  const minutes = (xpRemaining / stat.hourRate) * 60;

  return formatDuration(minutes);
};
const skillPrimaryLabel = (stat) =>
  skillTimeRemainingLabel(stat) ?? skillRateLabel(stat);
const skillCountLabel = (stat) =>
  stat.targetXp || stat.skill.nextLevel ? "Time Left" : "Current Rate";
const skillGoalLabel = (stat) => {
  if (stat.goal?.level) {
    return `${stat.skill.name} ${stat.goal.level}`;
  }

  if (stat.targetXp) {
    return `${formatNumber(stat.targetXp)} XP`;
  }

  return stat.skill.nextLevel
    ? `${stat.skill.name} ${stat.skill.nextLevel}`
    : "goal";
};

const skillDetailLabel = (stat) => {
  const xpRemaining = skillRemainingXp(stat);

  if (stat.targetXp && xpRemaining <= 0) {
    return `Goal reached - ${formatNumber(stat.targetXp)} XP target`;
  }

  if (
    !stat.targetXp &&
    (!stat.skill.nextLevel || stat.skill.xpRemaining === null)
  ) {
    return "Max level reached";
  }

  if (stat.hourRate <= 0) {
    return `${formatNumber(xpRemaining)} XP remaining to ${skillGoalLabel(stat)}`;
  }

  return `${formatNumber(xpRemaining)} XP to ${skillGoalLabel(stat)} - ${skillRateLabel(stat)}`;
};
</script>

<style scoped>
.activity-source {
  min-height: 100vh;
  display: grid;
  align-content: start;
  place-items: start;
  gap: 12px;
  background: transparent;
  color: var(--text-primary);
  font-family: var(--font-ui);
}

.activity-source--setup {
  padding: 14px;
  background: var(--bg-canvas);
}

.activity-source--in-app {
  min-height: auto;
  padding: 0;
  background: transparent;
}

.xp-toolbar,
.xp-character-tabs,
.xp-notice,
.xp-share-url {
  width: min(var(--tracker-width), 100%);
}
.xp-toolbar {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: center;
  padding: 8px 0;
}
.xp-toolbar p {
  margin: 5px 0 0;
  color: var(--tracker-muted);
  font-size: 12px;
}
.xp-kicker {
  color: var(--tracker-accent);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}
.xp-toolbar-actions,
.xp-character-tabs,
.xp-session-controls {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.xp-toolbar button,
.xp-character-tabs button,
.xp-session-controls button {
  cursor: pointer;
  border: 1px solid color-mix(in srgb, var(--tracker-border) 45%, transparent);
  border-radius: 8px;
  background: color-mix(in srgb, var(--tracker-panel) 90%, transparent);
  color: var(--tracker-text);
  padding: 9px 12px;
  font: inherit;
  font-size: 12px;
}
.xp-character-tabs {
  padding: 0 0 4px;
}
.xp-character-tabs button.is-selected {
  border-color: var(--tracker-accent);
  color: var(--tracker-accent);
  background: color-mix(
    in srgb,
    var(--tracker-accent) 10%,
    var(--tracker-panel)
  );
}
.xp-character-tabs form {
  display: flex;
  gap: 5px;
  max-width: 100%;
}
.xp-character-tabs input,
.xp-share-url {
  min-width: 0;
  border: 1px solid color-mix(in srgb, var(--tracker-border) 35%, transparent);
  border-radius: 7px;
  background: var(--tracker-panel);
  color: var(--tracker-text);
  padding: 9px 10px;
  font-size: 12px;
}
.xp-character-tabs input {
  width: 135px;
}
.xp-share-url {
  box-sizing: border-box;
}
.xp-notice {
  padding: 10px 14px;
  color: var(--tracker-muted);
  font-size: 12px;
  box-sizing: border-box;
}
.xp-working {
  color: var(--tracker-highlight);
}
.xp-hero {
  padding: 24px 22px 18px;
}
.xp-hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
}
.xp-hero h2 {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: calc(27px * var(--tracker-font-scale));
  line-height: 1.2;
  font-weight: 650;
  margin: 8px 0 6px;
  color: var(--tracker-text);
}
.xp-hero-top > div {
  min-width: 0;
  flex: 1;
}
.xp-source-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  max-width: 100%;
}
.xp-source-item span {
  min-width: 0;
  overflow-wrap: anywhere;
}
.xp-source-item img {
  flex: 0 0 28px;
  width: 28px;
  height: 28px;
  object-fit: contain;
}
.xp-hero p {
  font-size: 12px;
  color: var(--tracker-muted);
  margin: 0;
}
.xp-hero-top > strong {
  flex-shrink: 0;
  font-size: 42px;
  font-weight: 600;
  color: var(--tracker-accent);
}
.xp-hero-top > strong small {
  font-size: 18px;
}
.xp-goal-track {
  height: 9px;
  border-radius: 10px;
  overflow: hidden;
  margin: 20px 0 9px;
  background: color-mix(in srgb, var(--tracker-border) 18%, transparent);
}
.xp-goal-track span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(
    90deg,
    var(--tracker-accent),
    var(--tracker-highlight)
  );
  transition: width 0.5s ease;
}
.xp-goal-copy {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  color: var(--tracker-muted);
  font-size: 11px;
}
.xp-stats-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  padding: 15px 22px;
  gap: 12px;
  border-block: 1px solid
    color-mix(in srgb, var(--tracker-border) 25%, transparent);
  background: color-mix(in srgb, var(--tracker-border) 5%, transparent);
}
.xp-stats-strip > div {
  display: grid;
  gap: 7px;
  min-width: 0;
}
.xp-stats-strip span {
  font-size: 10px;
  color: var(--tracker-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.xp-stats-strip strong {
  font-size: calc(22px * var(--tracker-font-scale));
  color: var(--tracker-text);
  font-variant-numeric: tabular-nums;
  overflow-wrap: anywhere;
}
.xp-stats-strip small {
  font-size: 10px;
  color: var(--tracker-muted);
  line-height: 1.4;
}
.xp-crafts {
  padding: 18px 22px;
  border-top: 1px solid
    color-mix(in srgb, var(--tracker-border) 25%, transparent);
}
.xp-section-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 12px;
  color: var(--tracker-text);
}
.xp-section-head span,
.xp-crafts p {
  color: var(--tracker-muted);
  font-size: 11px;
}
.xp-crafts article {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  font-size: 12px;
  padding: 12px 0 0;
}
.xp-crafts article > span {
  min-width: 0;
  overflow-wrap: anywhere;
}
.xp-crafts article > strong {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.xp-session-controls {
  padding: 14px 22px;
  border-top: 1px solid
    color-mix(in srgb, var(--tracker-border) 25%, transparent);
}
.xp-session-controls label {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  color: var(--tracker-muted);
}
.xp-display-options {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: 16px;
  padding: 10px;
  border: 1px solid var(--border-color);
  border-radius: 8px;
}
.activity-setup .xp-display-options label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  min-height: 40px;
  cursor: pointer;
  color: var(--text-primary);
}
.activity-setup .xp-display-options input {
  width: 18px;
  height: 18px;
  min-height: 18px;
  padding: 0;
  margin: 0;
}
.activity-source--popout {
  padding: 12px;
  box-sizing: border-box;
}
.activity-source--popout .xp-toolbar {
  align-items: start;
  flex-direction: column;
  gap: 8px;
}
.activity-source--obs {
  min-height: 0;
}
:global(body:has(.activity-source--obs)),
:global(html:has(.activity-source--obs)) {
  background: transparent !important;
}
@media (max-width: 600px) {
  .xp-toolbar {
    align-items: start;
    flex-direction: column;
  }
  .xp-stats-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    row-gap: 20px;
  }
  .xp-hero h2 {
    font-size: 24px;
  }
  .xp-hero-top > strong {
    font-size: 34px;
  }
  .xp-goal-copy {
    flex-wrap: wrap;
  }
  .xp-character-tabs form {
    width: 100%;
  }
  .xp-character-tabs input {
    flex: 1;
  }
  .xp-hero,
  .xp-crafts {
    padding-inline: 18px;
  }
  .xp-session-controls {
    padding-inline: 18px;
  }
}

.activity-setup {
  box-sizing: border-box;
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

.activity-setup__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.activity-setup label,
.activity-picker label {
  display: grid;
  gap: 6px;
}

.activity-setup span,
.activity-picker span {
  color: var(--text-muted-3);
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
}

.activity-setup input:not([type="checkbox"]),
.activity-setup select,
.activity-picker input {
  min-height: 36px;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  background-color: var(--bg-canvas);
  color: var(--text-primary);
  font-size: 13px;
}

.activity-picker {
  position: relative;
  margin-top: 10px;
}

.activity-picker__menu {
  position: absolute;
  z-index: 5;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  max-height: 260px;
  overflow-y: auto;
  border: 1px solid rgb(var(--border-color-2-rgb) / 0.42);
  border-radius: 8px;
  background: var(--bg-surface);
  box-shadow: 0 18px 38px rgb(0 0 0 / 0.32);
}

.activity-picker__option {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-bottom: 1px solid rgb(var(--border-color-2-rgb) / 0.16);
  color: var(--text-primary);
  text-align: left;
}

.activity-picker__option:hover,
.activity-picker__option.selected {
  background: rgb(var(--accent-cyan-rgb) / 0.1);
}

.activity-picker__option small,
.activity-picker__menu p {
  color: var(--text-muted-3);
  font-size: 12px;
}

.activity-picker__menu p {
  padding: 12px;
}

.activity-selected {
  display: grid;
  gap: 6px;
  margin-top: 10px;
}

.activity-selected span {
  display: grid;
  grid-template-columns: minmax(120px, 1fr) 86px 110px auto;
  align-items: center;
  gap: 6px;
  border: 1px solid rgb(var(--accent-cyan-rgb) / 0.28);
  border-radius: 8px;
  padding: 6px 8px;
  background: rgb(var(--accent-cyan-rgb) / 0.08);
  color: var(--text-primary-2);
  font-size: 12px;
  font-weight: 800;
}

@media (max-width: 600px) {
  .activity-setup__grid,
  .xp-display-options {
    grid-template-columns: minmax(0, 1fr);
  }

  .activity-selected span {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .activity-selected strong {
    grid-column: 1 / -1;
  }

  .activity-selected button {
    grid-column: 1 / -1;
    justify-self: start;
    min-height: 32px;
  }
}

.activity-selected label {
  display: grid;
  gap: 2px;
}

.activity-selected small {
  color: var(--text-muted-3);
  font-size: 9px;
  font-weight: 900;
  text-transform: uppercase;
}

.activity-selected input {
  min-height: 28px;
  width: 100%;
  border: 1px solid var(--border-color);
  border-radius: 5px;
  background: var(--bg-canvas);
  color: var(--text-primary);
  font-size: 12px;
}

.activity-selected button {
  color: var(--accent-pink);
  font-size: 11px;
  font-weight: 900;
}

.activity-setup__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.activity-setup__actions button {
  min-height: 34px;
  padding: 0 12px;
  border: 1px solid rgb(var(--accent-cyan-rgb) / 0.34);
  border-radius: 6px;
  background: rgb(var(--accent-cyan-rgb) / 0.1);
  color: var(--accent-cyan);
  font-size: 12px;
  font-weight: 800;
}

.activity-widget {
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
    color-mix(
      in srgb,
      var(--tracker-panel) var(--tracker-panel-opacity),
      transparent
    )
  );
  color: var(--tracker-text);
  box-shadow: inset 0 1px 0 rgb(var(--text-primary-rgb) / 0.04);
}

.activity-widget__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 18px 22px 14px;
  border-bottom: 1px solid
    color-mix(in srgb, var(--tracker-border) 24%, transparent);
}

.activity-widget__identity,
.activity-widget__level {
  min-width: 0;
}

.activity-widget__eyebrow,
.activity-widget__metric-count small {
  color: var(--tracker-accent);
  font-size: calc(11px * var(--tracker-font-scale));
  font-weight: 800;
  line-height: 1.1;
  text-transform: uppercase;
}

.activity-widget h1 {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 4px;
  color: var(--tracker-text);
  font-size: calc(24px * var(--tracker-font-scale));
  font-weight: 900;
  line-height: 1.1;
}

.activity-widget h1 > span {
  min-width: 0;
  max-width: 100%;
  overflow-wrap: anywhere;
}

.activity-widget__level {
  text-align: right;
}

.activity-widget__level p {
  color: var(--tracker-accent);
  font-size: calc(18px * var(--tracker-font-scale));
  font-weight: 900;
  line-height: 1.1;
}

.activity-widget__level time {
  display: block;
  margin-top: 4px;
  color: color-mix(in srgb, var(--tracker-accent) 74%, var(--tracker-text));
  font-size: calc(12px * var(--tracker-font-scale));
  font-weight: 800;
}

.activity-widget__progress {
  display: none;
}

.activity-widget__progress span {
  display: block;
  height: 100%;
  background: linear-gradient(
    90deg,
    var(--tracker-highlight),
    var(--tracker-accent)
  );
  transition: width 320ms ease;
}

.activity-widget__metrics {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0;
  padding: 0;
}

.activity-widget__metric {
  min-height: 0;
  padding: 0 0 18px;
  border: 0;
  border-radius: 0;
  background: transparent;
}

.activity-widget__metric + .activity-widget__metric {
  border-top: 1px solid
    color-mix(in srgb, var(--tracker-border) 24%, transparent);
}

.activity-widget__metric-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 20px;
  padding: 22px 24px 6px;
}

.activity-widget__metric-item p {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--tracker-text);
  font-size: calc(18px * var(--tracker-font-scale));
  font-weight: 900;
  line-height: 1.25;
}

.activity-widget__metric-item small,
.activity-widget__metric-count small {
  display: block;
  margin-top: 5px;
  color: var(--tracker-muted);
  font-size: calc(11px * var(--tracker-font-scale));
  font-weight: 800;
  line-height: 1.35;
}

.activity-widget__metric-count {
  text-align: right;
}

.activity-widget__metric-count small {
  color: var(--tracker-highlight);
}

.activity-widget__metric-count strong {
  display: block;
  margin-top: 3px;
  color: var(--tracker-text);
  font-size: calc(19px * var(--tracker-font-scale));
  font-weight: 900;
  line-height: 1;
}

.activity-widget__mini-progress {
  height: 6px;
  margin: 8px 24px 0;
  overflow: hidden;
  border-radius: 999px;
  background: color-mix(in srgb, var(--tracker-panel) 68%, black);
}

.activity-widget__mini-progress span {
  display: block;
  height: 100%;
  margin: 0;
  border-radius: inherit;
  background: linear-gradient(
    90deg,
    var(--tracker-highlight),
    var(--tracker-accent)
  );
  transition: width 320ms ease;
}

.activity-widget__error {
  margin: 16px;
  padding: 14px;
  border: 1px solid rgb(var(--danger-rgb) / 0.42);
  border-radius: 8px;
  background: rgb(var(--danger-rgb) / 0.1);
  color: var(--danger);
  font-size: 13px;
  font-weight: 800;
}

.activity-widget__warning {
  margin: 12px 16px 0;
  padding: 10px 12px;
  border: 1px solid rgb(var(--warning-rgb) / 0.28);
  border-radius: 8px;
  background: rgb(var(--warning-rgb) / 0.08);
  color: color-mix(in srgb, var(--text-primary-2) 72%, var(--warning));
  font-size: 12px;
  font-weight: 800;
}

@media (max-width: 520px) {
  .activity-setup__grid,
  .activity-selected span {
    grid-template-columns: minmax(0, 1fr);
  }

  .activity-widget__metrics {
    grid-template-columns: minmax(0, 1fr);
  }

  .activity-widget__metric-row {
    grid-template-columns: minmax(0, 1fr);
  }

  .activity-widget__metric-count {
    text-align: left;
  }

  .activity-widget h1 {
    font-size: 26px;
  }
}
</style>
