<template>
  <section class="surface-section">
    <div class="surface-section__header">
      <div class="surface-section__copy">
        <span class="surface-section__title">Gathering Board</span>
        <p class="surface-section__subtitle">
          {{ unlockedCount }} unlocked · {{ actionStateLabel }}
        </p>
      </div>
    </div>

    <div class="surface-section__body">
      <BoardLayout
        :boards="actionBoards"
        v-model:board="selectedBoard"
        :search-term="searchTerm"
        @clear="clearFilters"
        :filters="filters"
        v-model:filter="selectedFilter"
      >
        <template #filters
          ><div class="rounded-md border border-border bg-surface-2 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
              <p class="text-sm font-ui text-primary">Action Board</p>
              <span class="tag"
                >{{ activeBoard.count }} {{ activeBoard.unit }}</span
              >
            </div>
            <p class="mt-1 text-xs text-muted-3">
              {{ activeBoard.description }}
            </p>

            <div class="mt-3 grid grid-cols-2 gap-2">
              <button
                v-for="board in actionBoards"
                :key="board.key"
                type="button"
                class="rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                :class="{
                  'border-focus/70 bg-focus/10': selectedBoard === board.key,
                }"
                @click="selectedBoard = board.key"
              >
                <span class="block text-xs font-ui text-primary">{{
                  board.label
                }}</span>
                <span class="mt-1 block text-[11px] text-muted-3"
                  >{{ board.count }} {{ board.unit }}</span
                >
              </button>
            </div>

            <div class="mt-4 grid gap-2">
              <button
                v-for="filter in filters"
                :key="filter.key"
                type="button"
                class="grid gap-2 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                :class="{
                  'border-focus/70 bg-focus/10': selectedFilter === filter.key,
                }"
                @click="selectedFilter = filter.key"
              >
                <span class="flex min-w-0 items-center justify-between gap-2">
                  <span class="truncate text-xs font-ui text-primary">{{
                    filter.label
                  }}</span>
                  <span class="text-[11px] text-muted-3">{{
                    filter.metaLabel
                  }}</span>
                </span>
                <span class="h-1.5 overflow-hidden rounded-full bg-surface-1">
                  <span
                    class="block h-full rounded-full bg-focus"
                    :style="{ width: `${filterProgress(filter)}%` }"
                  />
                </span>
              </button>
            </div>

            <div class="mt-3 grid gap-2 text-xs">
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted-2">Unlocked</span>
                <span class="text-primary">{{ activeUnlockedCount }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted-2">Locked</span>
                <span class="text-primary">{{
                  visibleActions.length - activeUnlockedCount
                }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted-2">State</span>
                <span class="text-primary">{{
                  canActNow ? "Ready" : "Cooldown"
                }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted-2">Timer</span>
                <span class="text-primary">{{ cooldownLabel }}</span>
              </div>
            </div>
          </div></template
        >

        <div class="grid content-start gap-3">
          <button
            v-for="(action, index) in preferredEntries(
              visibleActions,
              'gather',
            )"
            :key="action.key"
            type="button"
            class="grid game-action-row items-start gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 text-left transition hover:border-focus/60 disabled:cursor-not-allowed disabled:opacity-55 md:grid-cols-[minmax(0,1fr)_6rem]"
            :disabled="isActionDisabled(action) && !requiresToolRepair(action)"
            @click="requestAction(action.key)"
          >
            <span class="min-w-0">
              <span class="flex flex-wrap items-center gap-2">
                <span class="min-w-0 truncate text-sm font-ui text-primary">{{
                  action.label
                }}</span>
                <span v-if="action.label !== action.skill_label" class="tag">{{
                  action.skill_label
                }}</span>
                <span class="tag">Lv {{ action.required_level }}</span>
                <span
                  v-if="action.active_event"
                  class="tag border-success/40 bg-success/10 text-success"
                  >{{ action.active_event.label }}</span
                >
              </span>
              <span class="mt-1 block text-xs text-muted-2">{{
                action.location
              }}</span>
              <span
                v-if="action.equipped_tool"
                class="mt-2 block text-xs text-muted-2"
              >
                {{ toolSummary(action.equipped_tool) }}
              </span>
              <span class="mt-3 flex flex-wrap gap-2">
                <span
                  v-for="item in action.loot_preview.slice(0, 3)"
                  :key="item.item_key"
                  class="tag capitalize"
                  :data-rarity="item.rarity"
                >
                  {{ item.item_name }}
                </span>
              </span>
            </span>

            <span class="text-left md:text-right">
              <span
                v-if="!action.is_unlocked"
                class="block text-xs text-muted-3"
                >Level {{ action.skill_level }} /
                {{ action.required_level }}</span
              >
              <span
                v-else-if="requiresToolRepair(action)"
                class="block text-sm font-ui text-danger"
                >Repair Tool</span
              >
              <span
                v-else-if="runningAction === action.key"
                class="block text-sm font-ui text-focus"
                >Starting...</span
              >
              <span
                v-else-if="canActNow"
                class="block text-sm font-ui text-success"
                >Start</span
              >
              <span v-else class="block text-sm font-ui text-muted-3">{{
                cooldownLabel
              }}</span>
            </span>
          </button>

          <button
            v-if="canShowMoreActions"
            type="button"
            class="app-btn app-btn--ghost app-btn--sm justify-self-center"
            @click="visibleLimit += boardPageSize"
          >
            Show More
          </button>

          <p
            v-if="!visibleActions.length"
            class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2"
          >
            {{ emptyBoardMessage }}
          </p>
        </div>
      </BoardLayout>

      <p v-if="form.errors.action" class="mt-4 text-sm text-danger">
        {{ form.errors.action }}
      </p>
    </div>
  </section>
</template>

<script setup>
import {
  selectQuickAction,
  preferredEntries,
} from "../../evergather/playSession";
import BoardLayout from "./BoardLayout.vue";
const emit = defineEmits(["clear-search", "recover"]);
function clearFilters() {
  selectedFilter.value = "All";
  emit("clear-search");
}
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useActionForm } from "../../evergather/useActionForm";
import { usePersistedPanelState, usePanelFocus } from "./usePanelState";

const props = defineProps({
  focusRequest: { type: Object, default: null },
  actions: {
    type: Array,
    required: true,
  },
  player: {
    type: Object,
    required: true,
  },
  lastResult: {
    type: Object,
    default: null,
  },
  searchTerm: {
    type: String,
    default: "",
  },
});

const { selectedFilter, selectedBoard } = usePersistedPanelState(
  "evergather.gathering-board-state",
  {
    selectedFilter: "All",
    selectedBoard: "ready",
  },
  {
    selectedBoard: ["ready", "next"],
  },
);
const boardPageSize = 12;
const visibleLimit = ref(boardPageSize);
const now = ref(Date.now());
const repeatActionKey = ref("");
const runningAction = ref("");
const queuedActions = ref([]);
const localActions = ref([...props.actions]);
let cooldownTimer = null;
const form = useActionForm({
  action: null,
});

const filters = computed(() => [
  {
    key: "All",
    label: "All",
    count: localActions.value.length,
    metaLabel: localActions.value.length,
    progress: localActions.value.length
      ? Math.round((unlockedCount.value / localActions.value.length) * 100)
      : 0,
  },
  ...[...new Set(localActions.value.map((action) => action.skill_label))].map(
    (skillLabel) => {
      const skillActions = localActions.value.filter(
        (action) => action.skill_label === skillLabel,
      );
      const progress = skillActions[0]?.skill_progress;
      const level = progress?.level ?? skillActions[0]?.skill_level ?? 1;

      return {
        key: skillLabel,
        label: skillLabel,
        count: skillActions.length,
        level,
        metaLabel: `Lv ${level}`,
        progress: skillProgressPercent(progress),
      };
    },
  ),
]);
const activeFilter = computed(
  () =>
    filters.value.find((filter) => filter.key === selectedFilter.value) ??
    filters.value[0],
);
const unlockedCount = computed(
  () => localActions.value.filter((action) => action.is_unlocked).length,
);
const filteredActions = computed(() =>
  localActions.value.filter((action) => {
    const matchesFilter =
      selectedFilter.value === "All" ||
      action.skill_label === selectedFilter.value;

    if (!matchesFilter) {
      return false;
    }

    return searchMatches(action, props.searchTerm);
  }),
);
const readyActions = computed(() =>
  sortActionsByRequiredLevel(
    filteredActions.value.filter((action) => action.is_unlocked),
  ),
);
const lockedActions = computed(() =>
  sortActionsByRequiredLevel(
    filteredActions.value.filter((action) => !action.is_unlocked),
  ),
);
const actionBoards = computed(() => [
  {
    key: "ready",
    label: "Ready",
    count: readyActions.value.length,
    unit: "actions",
    entries: readyActions.value,
    description: `${activeFilter.value.label} actions you can start now.`,
  },
  {
    key: "next",
    label: "Next",
    count: lockedActions.value.length,
    unit: "locked",
    entries: lockedActions.value,
    description: "Closest locked routes without crowding the ready board.",
  },
]);
const activeBoard = computed(
  () =>
    actionBoards.value.find((board) => board.key === selectedBoard.value) ??
    actionBoards.value[0],
);
const visibleActions = computed(() =>
  preferredEntries(activeBoard.value.entries, "gather").slice(
    0,
    visibleLimit.value,
  ),
);
const canShowMoreActions = computed(
  () => activeBoard.value.entries.length > visibleActions.value.length,
);
const activeUnlockedCount = computed(
  () => visibleActions.value.filter((action) => action.is_unlocked).length,
);
const nextActionAt = computed(() =>
  props.player.next_action_at
    ? new Date(props.player.next_action_at).getTime()
    : null,
);
const cooldownRemainingMs = computed(() => {
  if (!nextActionAt.value) {
    return 0;
  }

  return Math.max(0, nextActionAt.value - now.value);
});
const canActNow = computed(
  () => props.player.can_act_now || cooldownRemainingMs.value <= 0,
);
const hasInstantCooldown = computed(() =>
  localActions.value.some(
    (action) => Number(action.cooldown_seconds ?? 0) === 0,
  ),
);
const cooldownLabel = computed(() => {
  if (canActNow.value) {
    return "Ready";
  }

  const totalSeconds = Math.max(
    1,
    Math.floor(cooldownRemainingMs.value / 1000),
  );
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;

  return `${minutes}:${seconds.toString().padStart(2, "0")}`;
});
const actionStateLabel = computed(() =>
  canActNow.value ? "Ready" : `Ready in ${cooldownLabel.value}`,
);
const emptyBoardMessage = computed(() => {
  if (selectedBoard.value === "ready") {
    return "No ready actions match. Check Next for the closest unlocks.";
  }

  return "No gathering actions match.";
});

onMounted(() => {
  cooldownTimer = window.setInterval(() => {
    now.value = Date.now();
  }, 250);
});

onBeforeUnmount(() => {
  if (cooldownTimer) {
    window.clearInterval(cooldownTimer);
  }
});

watch([selectedBoard, selectedFilter, () => props.searchTerm], () => {
  visibleLimit.value = boardPageSize;
});

watch(
  filters,
  (entries) => {
    if (!entries.some((filter) => filter.key === selectedFilter.value)) {
      selectedFilter.value = "All";
    }
  },
  { immediate: true },
);

watch(
  () => props.actions,
  (actions) => {
    localActions.value = [...actions];
  },
  { deep: true },
);

watch(
  [readyActions, lockedActions],
  () => {
    if (
      !readyActions.value.length &&
      lockedActions.value.length &&
      selectedBoard.value === "ready"
    ) {
      selectedBoard.value = "next";
    }

    if (
      !lockedActions.value.length &&
      readyActions.value.length &&
      selectedBoard.value === "next"
    ) {
      selectedBoard.value = "ready";
    }
  },
  { immediate: true },
);

function requestAction(action) {
  const entry = localActions.value.find((value) => value.key === action);
  if (requiresToolRepair(entry)) {
    emit("recover", { action: "repair", key: entry.equipped_tool.tool_id });
    return;
  }
  selectQuickAction("gather", entry);
  repeatActionKey.value = action;

  if (form.processing) {
    if (hasInstantCooldown.value) {
      queuedActions.value.push(action);
    }

    return;
  }

  submitAction(action);
}

function submitAction(action) {
  repeatActionKey.value = action;
  form.action = action;
  form.submit("gather", {
    preserveScroll: true,
    onStart: () => {
      runningAction.value = action;
    },
    onFinish: () => {
      runningAction.value = "";
      queueNextAction();
    },
  });
}

function isActionDisabled(action) {
  if (!canActNow.value || !action.is_unlocked || requiresToolRepair(action)) {
    return true;
  }

  return form.processing && !hasInstantCooldown.value;
}

function requiresToolRepair(action) {
  return Boolean(
    action.requires_tool_repair || action.equipped_tool?.is_broken,
  );
}

function queueNextAction() {
  window.setTimeout(() => {
    now.value = Date.now();

    const queuedAction = queuedActions.value.shift();

    if (queuedAction) {
      submitAction(queuedAction);

      return;
    }
  }, 0);
}

function filterProgress(filter) {
  return filter.progress ?? 0;
}

function sortActionsByRequiredLevel(actions) {
  return actions
    .map((action, index) => ({ action, index }))
    .sort((left, right) => {
      const levelDifference =
        Number(left.action.required_level ?? 1) -
        Number(right.action.required_level ?? 1);

      return levelDifference === 0 ? left.index - right.index : levelDifference;
    })
    .map((entry) => entry.action);
}

function skillProgressPercent(progress) {
  if (!progress) {
    return 0;
  }

  if (progress.next_level_experience === null) {
    return 100;
  }

  const levelSpan =
    Number(progress.next_level_experience) -
    Number(progress.current_level_experience ?? 0);

  if (levelSpan <= 0) {
    return 0;
  }

  return Math.max(
    0,
    Math.min(
      100,
      Math.round(
        (Number(progress.experience_into_level ?? 0) / levelSpan) * 100,
      ),
    ),
  );
}

function toolSummary(tool) {
  const parts = [tool.item_name, tool.signature_trait].filter(Boolean);

  if (tool.is_broken) {
    return [...parts, "Broken"].join(" · ");
  }

  const experienceBonus = Number(tool.experience_bonus ?? 0);
  const yieldBonus = Number(tool.yield_bonus ?? 0);

  if (experienceBonus > 0) {
    parts.push(`+${experienceBonus} XP`);
  }

  if (yieldBonus > 0) {
    parts.push(`+${yieldBonus} yield`);
  }

  if (parts.length <= 2) {
    parts.push("No active bonuses");
  }

  return parts.join(" · ");
}

function searchMatches(action, query) {
  const normalizedQuery = query.trim().toLowerCase();

  if (!normalizedQuery) {
    return true;
  }

  return [
    action.label,
    action.skill_label,
    action.location,
    action.active_event?.label,
    action.equipped_tool?.item_name,
    action.equipped_tool?.signature_trait,
    ...(action.loot_preview ?? []).flatMap((item) => [
      item.item_name,
      item.rarity,
      item.quality,
      item.item_class,
      item.material_family,
      ...(item.tags ?? []),
    ]),
  ]
    .filter(Boolean)
    .join(" ")
    .toLowerCase()
    .includes(normalizedQuery);
}

watch(
  () => form.errors,
  (errors) => {
    if (Object.keys(errors).length) {
      queuedActions.value = [];
    }
  },
);
onBeforeUnmount(() => {
  queuedActions.value = [];
});
usePanelFocus(
  () => props.focusRequest,
  () => localActions.value,
  (entry) => {
    selectedFilter.value = "All";
    selectedBoard.value = entry.is_unlocked ? "ready" : "next";
  },
);
</script>
