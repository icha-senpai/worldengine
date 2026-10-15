<template>
  <section class="surface-section xl:col-span-2 ui-panel ui-panel--flush">
    <div class="surface-section__header ui-panel-heading">
      <div class="surface-section__copy">
        <span class="surface-section__title ui-section-title"
          >Leaderboards</span
        >
        <p class="surface-section__subtitle">
          {{ totalEntries }} top records (up to 20 per board) across
          {{ boardDefinitions.length }} boards.
        </p>
      </div>
    </div>

    <div class="surface-section__body ui-panel-body">
      <div class="game-board__toolbar">
        <label class="game-select ui-field-label"
          ><span class="sr-only ui-field-label">Ranking scope</span
          ><SelectInput v-model="activeGroup">
            <option
              v-for="group in leaderboards.groups"
              :key="group.key"
              :value="group.key"
            >
              {{ group.label }}
            </option>
          </SelectInput></label
        >
        <label class="game-select ui-field-label"
          ><span class="sr-only ui-field-label">Ranking board</span
          ><SelectInput v-model="activeBoard">
            <option
              v-for="board in activeBoards"
              :key="board.key"
              :value="board.key"
            >
              {{ board.label }}
            </option>
          </SelectInput></label
        >
      </div>
      <div class="grid content-start gap-4">
        <div
          class="rounded-md border border-border bg-surface px-3 py-3 ui-panel ui-panel--compact"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-sm font-ui text-primary">
                {{ activeBoardDefinition.label }}
              </p>
              <p class="mt-1 text-xs text-muted-2">
                {{ activeBoardDefinition.description }}
              </p>
            </div>
            <span class="tag">{{ boardCount(activeBoard) }} ranked</span>
          </div>

          <div v-if="activeEntries.length" class="mt-4 grid gap-2">
            <article
              v-for="(entry, index) in activeEntries"
              :key="`${activeBoard}-${entry.id ?? entry.skill ?? index}`"
              class="grid min-h-24 items-start gap-3 rounded-md border border-border bg-surface px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_7rem] ui-panel ui-panel--compact"
            >
              <div
                class="grid h-9 w-9 place-items-center rounded-md border border-border bg-surface text-sm font-ui text-primary ui-panel ui-panel--compact"
              >
                #{{ index + 1 }}
              </div>

              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <p class="min-w-0 truncate text-sm font-ui text-primary">
                    {{ entry.display_name }}
                  </p>
                  <span v-if="entry.species_label" class="tag">{{
                    entry.species_label
                  }}</span>
                  <span v-if="entry.skill_label" class="tag">{{
                    entry.skill_label
                  }}</span>
                </div>
                <p v-if="entry.detail" class="mt-1 text-xs text-muted-2">
                  {{ entry.detail }}
                </p>
              </div>

              <div class="text-left md:text-right">
                <p class="text-sm font-ui text-primary">
                  {{ entry.score_label }}
                </p>
                <p
                  v-if="entry.score !== undefined"
                  class="mt-1 text-xs text-muted-3"
                >
                  {{ entry.score.toLocaleString() }} score
                </p>
              </div>
            </article>
          </div>

          <p v-else class="mt-4 text-sm text-muted-2">No records yet.</p>
        </div>

        <div class="grid gap-3 md:grid-cols-3">
          <div
            v-for="highlight in highlights"
            :key="highlight.label"
            class="rounded-md border border-border bg-surface px-3 py-3 ui-panel ui-panel--compact"
          >
            <p class="text-xs uppercase tracking-[0.14em] text-muted-3">
              {{ highlight.label }}
            </p>
            <p class="mt-2 text-lg font-ui text-primary">
              {{ highlight.value }}
            </p>
            <p class="mt-1 text-xs text-muted-2">{{ highlight.detail }}</p>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import SelectInput from "../../bitcraft-ui/shared/SelectInput.vue";
import { computed, watch } from "vue";
import { usePersistedPanelState } from "./usePanelState";

const props = defineProps({
  leaderboards: {
    type: Object,
    required: true,
  },
});

const { activeGroup, activeBoard } = usePersistedPanelState(
  "evergather.leaderboards-board-state",
  {
    activeGroup: props.leaderboards.groups[0]?.key ?? "summary",
    activeBoard: props.leaderboards.groups[0]?.boards[0] ?? "wealth",
  },
);
const boardDefinitions = computed(() => props.leaderboards.boards ?? []);
const boardDefinitionMap = computed(() =>
  Object.fromEntries(boardDefinitions.value.map((board) => [board.key, board])),
);
const activeGroupRecord = computed(
  () =>
    props.leaderboards.groups.find(
      (group) => group.key === activeGroup.value,
    ) ?? props.leaderboards.groups[0],
);
const activeGroupLabel = computed(
  () => activeGroupRecord.value?.label ?? "Leaderboards",
);
const activeBoards = computed(() =>
  (activeGroupRecord.value?.boards ?? [])
    .map((key) => boardDefinitionMap.value[key])
    .filter(Boolean),
);
const activeBoardDefinition = computed(
  () =>
    boardDefinitionMap.value[activeBoard.value] ??
    activeBoards.value[0] ??
    boardDefinitions.value[0],
);
const activeEntries = computed(
  () => props.leaderboards[activeBoard.value] ?? [],
);
const totalEntries = computed(() =>
  boardDefinitions.value.reduce(
    (total, board) => total + boardCount(board.key),
    0,
  ),
);
const highlights = computed(() => [
  highlightFor("Top Gold", props.leaderboards.wealth[0], "gold"),
  highlightFor("Top Renown", props.leaderboards.realm_score[0], "score"),
  highlightFor("Top Skill", props.leaderboards.skills[0], "experience"),
]);

watch(
  activeGroupRecord,
  (group) => {
    if (
      !props.leaderboards.groups.some(
        (entry) => entry.key === activeGroup.value,
      )
    ) {
      activeGroup.value = props.leaderboards.groups[0]?.key ?? "summary";
      group = props.leaderboards.groups[0];
    }

    if (!group?.boards.includes(activeBoard.value)) {
      activeBoard.value = group?.boards[0] ?? "wealth";
    }
  },
  { immediate: true },
);

function boardCount(key) {
  return props.leaderboards[key]?.length ?? 0;
}

function highlightFor(label, entry, metric) {
  if (!entry) {
    return { label, value: "None", detail: "No records yet." };
  }

  return {
    label,
    value: entry.display_name,
    detail:
      entry.score_label ?? `${entry[metric]?.toLocaleString() ?? 0} ${metric}`,
  };
}
</script>
