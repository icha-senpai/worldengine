<template>
  <section class="surface-section xl:col-span-2 ui-panel ui-panel--flush">
    <div class="surface-section__header ui-panel-heading">
      <div class="surface-section__copy">
        <span class="surface-section__title ui-section-title"
          >Skills & Professions</span
        >
        <p class="surface-section__subtitle">
          {{ skills.length }} tracks · Level {{ catalog.pacing.max_level }} cap
          · {{ catalog.pacing.estimated_hours_to_level_100 }}h baseline curve.
        </p>
      </div>
    </div>

    <div class="surface-section__body ui-panel-body">
      <div class="flex flex-wrap gap-2">
        <button
          v-for="group in groupedSkills"
          :key="group.category"
          type="button"
          class="rounded-md border border-border bg-surface px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
          :class="{
            'border-focus/70 bg-focus/10 text-primary':
              selectedCategory === group.category,
          }"
          @click="selectCategory(group.category)"
        >
          {{ group.category }} · {{ group.entries.length }}
        </button>
      </div>

      <label class="game-select block mt-3 ui-field-label"
        ><span class="sr-only ui-field-label">Skill</span
        ><SelectInput v-model="selectedSkillKey">
          <option
            v-for="skill in activeGroup.entries"
            :key="skill.skill"
            :value="skill.skill"
          >
            {{ skill.label }} · Lv {{ skill.level }}
          </option>
        </SelectInput></label
      >
      <div class="mt-4 grid gap-4">
        <div class="grid gap-4">
          <article
            v-if="activeSkill"
            class="rounded-md border border-border bg-surface px-3 py-3 ui-panel ui-panel--compact"
          >
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <p class="min-w-0 text-base font-ui text-primary">
                    {{ activeSkill.label }}
                  </p>
                  <span class="tag">Lv {{ activeSkill.level }}</span>
                  <span class="tag">{{ activeSkill.type }}</span>
                  <span class="tag">{{ activeSkill.category }}</span>
                </div>
                <p class="mt-2 max-w-3xl text-sm text-muted-2">
                  {{ activeSkill.description }}
                </p>
                <p class="mt-1 text-xs text-muted-3">{{ activeSkill.role }}</p>
              </div>

              <div class="min-w-32 text-left sm:text-right">
                <p class="text-sm font-ui text-primary">
                  {{ skillProgress(activeSkill) }}%
                </p>
                <p class="mt-1 text-xs text-muted-3">to next level</p>
              </div>
            </div>

            <div class="mt-4 h-2 overflow-hidden rounded-full bg-canvas">
              <div
                class="h-full rounded-full bg-focus"
                :style="{ width: `${skillProgress(activeSkill)}%` }"
              />
            </div>

            <div
              class="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-2"
            >
              <span>{{ activeSkill.experience.toLocaleString() }} XP</span>
              <span v-if="activeSkill.next_level_experience"
                >/
                {{ activeSkill.next_level_experience.toLocaleString() }}
                next</span
              >
              <span>{{ activeSkill.activities.length }} activities</span>
              <span>{{ activeSkill.unlocks.length }} unlocks</span>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
              <div
                class="rounded-md border border-border bg-surface px-3 py-3 ui-panel ui-panel--compact"
              >
                <div class="flex items-center justify-between gap-3">
                  <p class="text-sm font-ui text-primary">Activity Tree</p>
                  <span class="tag"
                    >{{ activeSkillBoard.count }}
                    {{ activeSkillBoard.unit }}</span
                  >
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2">
                  <button
                    v-for="board in skillActivityBoards"
                    :key="board.key"
                    type="button"
                    class="rounded-md border border-border bg-surface px-3 py-2 text-left transition hover:border-focus/60"
                    :class="{
                      'border-focus/70 bg-focus/10':
                        selectedSkillBoard === board.key,
                    }"
                    @click="selectedSkillBoard = board.key"
                  >
                    <span class="block text-xs font-ui text-primary">{{
                      board.label
                    }}</span>
                    <span class="mt-1 block text-[11px] text-muted-3"
                      >{{ board.count }} {{ board.unit }}</span
                    >
                  </button>
                </div>

                <div class="mt-3 grid gap-2">
                  <div
                    v-for="activity in visibleSkillActivities"
                    :key="`${activity.type}-${activity.label}`"
                    class="grid gap-2 rounded-md border border-border bg-surface px-3 py-2 sm:grid-cols-[minmax(0,1fr)_auto] ui-panel ui-panel--compact"
                  >
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <span
                          class="tag"
                          :class="{ 'tag--success': activity.unlocked }"
                          >{{ activity.type }}</span
                        >
                        <span class="tag"
                          >Lv {{ activity.required_level }}</span
                        >
                      </div>
                      <p class="mt-1 truncate text-sm font-ui text-primary">
                        {{ activity.label }}
                      </p>
                    </div>
                    <span
                      class="self-center text-xs"
                      :class="
                        activity.unlocked ? 'text-success' : 'text-muted-3'
                      "
                    >
                      {{ activity.unlocked ? "Ready" : "Locked" }}
                    </span>
                  </div>

                  <button
                    v-if="canShowMoreSkillActivities"
                    type="button"
                    class="app-btn app-btn--ghost app-btn--sm justify-self-center"
                    @click="visibleSkillLimit += skillBoardPageSize"
                  >
                    Show More
                  </button>

                  <p
                    v-if="!activeSkillBoard.entries.length"
                    class="rounded-md border border-border bg-surface px-3 py-3 text-sm text-muted-2 ui-panel ui-panel--compact"
                  >
                    No activities listed.
                  </p>
                </div>
              </div>

              <div
                class="rounded-md border border-border bg-surface px-3 py-3 ui-panel ui-panel--compact"
              >
                <div class="flex items-center justify-between gap-3">
                  <p class="text-sm font-ui text-primary">Unlock Tree</p>
                  <span class="tag"
                    >{{ unlockedMilestoneCount(activeSkill) }} reached</span
                  >
                </div>

                <div class="mt-3 grid gap-2">
                  <div
                    v-for="unlock in visibleSkillUnlocks"
                    :key="unlock.level"
                    class="grid gap-2 rounded-md border border-border bg-surface px-3 py-2 sm:grid-cols-[4rem_minmax(0,1fr)_auto] ui-panel ui-panel--compact"
                  >
                    <span class="text-xs font-ui text-primary"
                      >Lv {{ unlock.level }}</span
                    >
                    <span class="min-w-0 text-sm text-muted-2">{{
                      unlock.label
                    }}</span>
                    <span
                      class="text-xs"
                      :class="
                        activeSkill.level >= unlock.level
                          ? 'text-success'
                          : 'text-muted-3'
                      "
                    >
                      {{
                        activeSkill.level >= unlock.level ? "Open" : "Locked"
                      }}
                    </span>
                  </div>

                  <p
                    v-if="!visibleSkillUnlocks.length"
                    class="rounded-md border border-border bg-surface px-3 py-3 text-sm text-muted-2 ui-panel ui-panel--compact"
                  >
                    No unlocks on this board.
                  </p>
                </div>
              </div>
            </div>
          </article>

          <p
            v-else
            class="rounded-md border border-border bg-surface px-3 py-3 text-sm text-muted-2 ui-panel ui-panel--compact"
          >
            No skills match.
          </p>
        </div>
      </div>
      <details class="game-tool-details">
        <summary>Progression targets</summary>
        <div class="mt-3 grid gap-2 text-xs">
          <div class="flex items-center justify-between gap-3">
            <span class="text-muted-2">Level 100 XP</span>
            <span class="text-primary">{{
              catalog.pacing.level_100_experience.toLocaleString()
            }}</span>
          </div>
          <div class="flex items-center justify-between gap-3">
            <span class="text-muted-2">Baseline XP/hour</span>
            <span class="text-primary">{{
              catalog.pacing.calibrated_experience_per_hour.toLocaleString()
            }}</span>
          </div>
          <div class="flex items-center justify-between gap-3">
            <span class="text-muted-2">Category Target</span>
            <span class="text-primary">{{
              formatHoursRange(activeGroupTargetRange)
            }}</span>
          </div>
          <div class="flex items-center justify-between gap-3">
            <span class="text-muted-2">Major Goal</span>
            <span class="text-primary"
              >{{ formatNumberRange(majorActionGoalRange) }} acts</span
            >
          </div>
          <div class="flex items-center justify-between gap-3">
            <span class="text-muted-2">Unlocked Acts</span>
            <span class="text-primary">{{ activeUnlockedActivities }}</span>
          </div>
        </div>
      </details>
    </div>
  </section>
</template>

<script setup>
import SelectInput from "../../bitcraft-ui/shared/SelectInput.vue";
import { computed, ref, watch } from "vue";
import { usePersistedPanelState } from "./usePanelState";

const props = defineProps({
  skills: {
    type: Array,
    required: true,
  },
  catalog: {
    type: Object,
    required: true,
  },
  searchTerm: {
    type: String,
    default: "",
  },
});

const { selectedCategory, selectedSkillKey, selectedSkillBoard } =
  usePersistedPanelState(
    "evergather.skills-board-state",
    {
      selectedCategory: "",
      selectedSkillKey: "",
      selectedSkillBoard: "ready",
    },
    {
      selectedSkillBoard: ["ready", "next"],
    },
  );
const skillBoardPageSize = 8;
const visibleSkillLimit = ref(skillBoardPageSize);
const groupedSkills = computed(() =>
  props.skills
    .filter((skill) => searchMatches(skill, props.searchTerm))
    .reduce((groups, skill) => {
      const group = groups.find((entry) => entry.category === skill.category);

      if (group) {
        group.entries.push(skill);

        return groups;
      }

      groups.push({
        category: skill.category,
        entries: [skill],
      });

      return groups;
    }, []),
);
const activeGroup = computed(
  () =>
    groupedSkills.value.find(
      (group) => group.category === selectedCategory.value,
    ) ??
    groupedSkills.value[0] ?? { category: "Skills", entries: [] },
);
const activeGroupTargetRange = computed(
  () =>
    props.catalog.pacing.category_targets?.[activeGroup.value.category]
      ?.target_hours_range ??
    activeGroup.value.entries[0]?.target_hours_range ??
    props.catalog.pacing.target_hours_range ?? [0, 0],
);
const majorActionGoalRange = computed(
  () => props.catalog.pacing.major_action_goal_range ?? [3000, 5000],
);
const activeUnlockedActivities = computed(() =>
  activeGroup.value.entries.reduce(
    (total, skill) =>
      total + skill.activities.filter((activity) => activity.unlocked).length,
    0,
  ),
);
const activeSkill = computed(
  () =>
    activeGroup.value.entries.find(
      (skill) => skill.skill === selectedSkillKey.value,
    ) ??
    activeGroup.value.entries[0] ??
    null,
);
const readySkillActivities = computed(
  () =>
    activeSkill.value?.activities.filter((activity) => activity.unlocked) ?? [],
);
const lockedSkillActivities = computed(
  () =>
    activeSkill.value?.activities.filter((activity) => !activity.unlocked) ??
    [],
);
const skillActivityBoards = computed(() => [
  {
    key: "ready",
    label: "Ready",
    count: readySkillActivities.value.length,
    unit: "acts",
    entries: readySkillActivities.value,
  },
  {
    key: "next",
    label: "Next",
    count: lockedSkillActivities.value.length,
    unit: "locked",
    entries: lockedSkillActivities.value,
  },
]);
const activeSkillBoard = computed(
  () =>
    skillActivityBoards.value.find(
      (board) => board.key === selectedSkillBoard.value,
    ) ?? skillActivityBoards.value[0],
);
const visibleSkillActivities = computed(() =>
  activeSkillBoard.value.entries.slice(0, visibleSkillLimit.value),
);
const canShowMoreSkillActivities = computed(
  () =>
    activeSkillBoard.value.entries.length > visibleSkillActivities.value.length,
);
const visibleSkillUnlocks = computed(() => {
  if (!activeSkill.value) {
    return [];
  }

  if (selectedSkillBoard.value === "ready") {
    return activeSkill.value.unlocks.filter(
      (unlock) => activeSkill.value.level >= unlock.level,
    );
  }

  if (selectedSkillBoard.value === "next") {
    return activeSkill.value.unlocks
      .filter((unlock) => activeSkill.value.level < unlock.level)
      .slice(0, skillBoardPageSize);
  }

  return [];
});

watch(
  groupedSkills,
  (groups) => {
    if (!groups.some((group) => group.category === selectedCategory.value)) {
      selectedCategory.value = groups[0]?.category ?? "";
    }

    if (
      !activeGroup.value.entries.some(
        (skill) => skill.skill === selectedSkillKey.value,
      )
    ) {
      selectedSkillKey.value = activeGroup.value.entries[0]?.skill ?? "";
    }
  },
  { immediate: true },
);

watch([selectedSkillKey, selectedSkillBoard], () => {
  visibleSkillLimit.value = skillBoardPageSize;
});

watch(
  [readySkillActivities, lockedSkillActivities],
  () => {
    if (
      !readySkillActivities.value.length &&
      lockedSkillActivities.value.length &&
      selectedSkillBoard.value === "ready"
    ) {
      selectedSkillBoard.value = "next";
    }

    if (
      !lockedSkillActivities.value.length &&
      readySkillActivities.value.length &&
      selectedSkillBoard.value === "next"
    ) {
      selectedSkillBoard.value = "ready";
    }
  },
  { immediate: true },
);

function selectCategory(category) {
  selectedCategory.value = category;
  selectedSkillKey.value =
    groupedSkills.value.find((group) => group.category === category)?.entries[0]
      ?.skill ?? "";
}

function unlockedActivityCount(skill) {
  return skill.activities.filter((activity) => activity.unlocked).length;
}

function unlockedMilestoneCount(skill) {
  return skill.unlocks.filter((unlock) => skill.level >= unlock.level).length;
}

function formatHoursRange(range) {
  return `${range[0]}-${range[1]}h`;
}

function formatNumberRange(range) {
  return `${range[0].toLocaleString()}-${range[1].toLocaleString()}`;
}

function searchMatches(skill, query) {
  const normalizedQuery = query.trim().toLowerCase();

  if (!normalizedQuery) {
    return true;
  }

  return [
    skill.skill,
    skill.label,
    skill.category,
    skill.role,
    skill.description,
    ...skill.activities.flatMap((activity) => [activity.type, activity.label]),
    ...skill.unlocks.map((unlock) => unlock.label),
  ]
    .filter(Boolean)
    .join(" ")
    .toLowerCase()
    .includes(normalizedQuery);
}

function skillProgress(skill) {
  if (!skill.next_level_experience) {
    return 100;
  }

  const current = skill.current_level_experience ?? 0;
  const needed = skill.next_level_experience - current;

  if (needed <= 0) {
    return 0;
  }

  return Math.min(
    100,
    Math.round(((skill.experience - current) / needed) * 100),
  );
}
</script>
