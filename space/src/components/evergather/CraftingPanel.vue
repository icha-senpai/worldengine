<template>
  <section class="surface-section ui-panel ui-panel--flush">
    <div class="surface-section__header ui-panel-heading">
      <div class="surface-section__copy">
        <span class="surface-section__title ui-section-title">Crafting</span>
        <p class="surface-section__subtitle">
          {{ craftableCount }} ready · {{ recipes.length }} known.
        </p>
      </div>
    </div>

    <div class="surface-section__body ui-panel-body">
      <BoardLayout
        :boards="craftBoards"
        v-model:board="selectedBoard"
        :search-term="searchTerm"
        @clear="clearFilters"
        :filters="filters"
        v-model:filter="selectedFilter"
      >
        <template #filters
          ><div
            class="rounded-md border border-border bg-surface px-3 py-3 ui-panel ui-panel--compact"
          >
            <div class="flex items-center justify-between gap-3">
              <p class="text-sm font-ui text-primary">Recipe Board</p>
              <span class="tag"
                >{{ activeBoard.count }} {{ activeBoard.unit }}</span
              >
            </div>
            <p class="mt-1 text-xs text-muted-3">
              {{ activeBoard.description }}
            </p>

            <div class="mt-3 grid grid-cols-2 gap-2">
              <button
                v-for="board in craftBoards"
                :key="board.key"
                type="button"
                class="rounded-md border border-border bg-surface px-3 py-2 text-left transition hover:border-focus/60"
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
              <template v-for="filter in filters" :key="filter.key">
                <button
                  type="button"
                  class="grid gap-2 rounded-md border border-border bg-surface px-3 py-2 text-left transition hover:border-focus/60"
                  :class="{
                    'border-focus/70 bg-focus/10':
                      selectedFilter === filter.key,
                  }"
                  @click="selectCategory(filter.key)"
                >
                  <span class="flex min-w-0 items-center justify-between gap-2">
                    <span class="truncate text-xs font-ui text-primary">{{
                      filter.label
                    }}</span>
                    <span class="text-[11px] text-muted-3">{{
                      filter.count
                    }}</span>
                  </span>
                  <span class="h-1.5 overflow-hidden rounded-full bg-canvas-2">
                    <span
                      class="block h-full rounded-full bg-focus"
                      :style="{ width: `${filterProgress(filter)}%` }"
                    />
                  </span>
                </button>

                <div
                  v-if="showSkillFiltersFor(filter)"
                  class="ml-3 grid gap-1.5 border-l border-border pl-3"
                >
                  <button
                    v-for="skillFilter in nestedSkillFilters"
                    :key="skillFilter.key"
                    type="button"
                    class="grid min-w-0 gap-1.5 rounded-md border border-transparent px-3 py-1.5 text-left transition hover:border-focus/50 hover:bg-focus/5"
                    :class="{
                      'border-focus/60 bg-focus/10':
                        selectedSkillFilter === skillFilter.key,
                    }"
                    @click="selectedSkillFilter = skillFilter.key"
                  >
                    <span
                      class="flex min-w-0 items-center justify-between gap-2"
                    >
                      <span class="min-w-0 truncate text-xs text-primary">{{
                        skillFilter.label
                      }}</span>
                      <span class="text-[11px] text-muted-3">{{
                        skillFilter.metaLabel
                      }}</span>
                    </span>
                    <span class="h-1 overflow-hidden rounded-full bg-canvas-2">
                      <span
                        class="block h-full rounded-full bg-focus"
                        :style="{ width: `${skillFilter.progress}%` }"
                      />
                    </span>
                  </button>
                </div>
              </template>
            </div>

            <div class="mt-3 grid gap-2 text-xs">
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted-2">Ready</span>
                <span class="text-primary">{{
                  visibleRecipes.filter((recipe) => recipe.can_craft).length
                }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted-2">XP</span>
                <span class="text-primary">{{ visibleExperience }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-muted-2">Gold Cost</span>
                <span class="text-primary">{{ visibleGoldCost }}g</span>
              </div>
            </div>
          </div></template
        >

        <div class="grid content-start gap-3">
          <article
            v-for="(recipe, index) in preferredEntries(visibleRecipes, 'craft')"
            :key="recipe.key"
            class="grid game-action-row items-start gap-3 rounded-md border border-border bg-surface px-3 py-3 md:grid-cols-[minmax(0,1fr)_9rem] ui-panel ui-panel--compact"
            :class="{ 'opacity-70': !recipe.is_unlocked }"
          >
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <p class="min-w-0 truncate text-sm font-ui text-primary">
                  {{ recipe.label }}
                </p>
                <span class="tag">{{ recipe.category }}</span>
                <span class="tag">{{ recipe.skill_label }}</span>
                <span class="tag">Lv {{ recipe.required_level }}</span>
              </div>
              <div class="mt-3 grid gap-2">
                <div
                  v-for="ingredient in recipe.ingredients"
                  :key="ingredient.item_key"
                  class="flex items-center justify-between gap-3 text-xs"
                >
                  <span class="min-w-0 truncate text-muted-2">{{
                    ingredient.item_name
                  }}</span>
                  <span
                    :class="
                      ingredient.has_enough ? 'text-success' : 'text-muted-3'
                    "
                  >
                    {{ ingredient.owned_quantity }} / {{ ingredient.quantity }}
                  </span>
                </div>
              </div>
              <div class="mt-3 flex flex-wrap gap-2">
                <span
                  v-for="output in recipe.outputs"
                  :key="output.item_key"
                  class="tag capitalize"
                >
                  {{ output.quantity }} ×
                  {{
                    output.item_name === recipe.label
                      ? "output"
                      : output.item_name
                  }}
                </span>
                <span
                  v-if="recipe.material_preservation?.can_apply"
                  class="tag"
                >
                  Preserves 1 material
                </span>
              </div>
            </div>

            <div class="grid content-between gap-3 text-left md:text-right">
              <div>
                <p class="text-sm font-ui text-primary">
                  +{{ recipe.experience }} XP
                </p>
                <p
                  v-if="recipe.gold_cost > 0"
                  class="mt-1 text-xs text-muted-2"
                >
                  {{ recipe.gold_cost }} gold
                </p>
                <p v-if="!recipe.is_unlocked" class="mt-1 text-xs text-muted-3">
                  Level {{ recipe.skill_level }} / {{ recipe.required_level }}
                </p>
              </div>
              <button
                type="button"
                class="app-btn app-btn--primary app-btn--sm"
                :disabled="isRecipeDisabled(recipe)"
                @click="requestRecipe(recipe.key)"
              >
                {{ recipeActionLabel(recipe) }}
              </button>
            </div>
          </article>

          <button
            v-if="canShowMoreRecipes"
            type="button"
            class="app-btn app-btn--ghost app-btn--sm justify-self-center"
            @click="visibleLimit += boardPageSize"
          >
            Show More
          </button>

          <p
            v-if="!visibleRecipes.length"
            class="rounded-md border border-border bg-surface px-3 py-3 text-sm text-muted-2 ui-panel ui-panel--compact"
          >
            {{ emptyBoardMessage }}
          </p>
        </div>
      </BoardLayout>

      <p v-if="form.errors.recipe" class="mt-4 text-sm text-danger">
        {{ form.errors.recipe }}
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
  selectedSkillFilter.value = "All";
  emit("clear-search");
}
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useActionForm } from "../../evergather/useActionForm";
import { usePersistedPanelState, usePanelFocus } from "./usePanelState";

const props = defineProps({
  focusRequest: { type: Object, default: null },
  recipes: {
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

const form = useActionForm({
  recipe: null,
});
const { selectedFilter, selectedSkillFilter, selectedBoard } =
  usePersistedPanelState(
    "evergather.crafting-board-state",
    {
      selectedFilter: "All",
      selectedSkillFilter: "All",
      selectedBoard: "ready",
    },
    {
      selectedBoard: ["ready", "prepare"],
    },
  );
const boardPageSize = 12;
const visibleLimit = ref(boardPageSize);
const runningRecipe = ref("");
const repeatRecipeKey = ref("");
const queuedRecipes = ref([]);
const localRecipes = ref([...props.recipes]);

const craftableCount = computed(
  () => localRecipes.value.filter((recipe) => recipe.can_craft).length,
);
const filters = computed(() =>
  ["All", ...new Set(localRecipes.value.map((recipe) => recipe.category))].map(
    (filter) => ({
      key: filter,
      label: filter,
      count: localRecipes.value.filter(
        (recipe) => filter === "All" || recipe.category === filter,
      ).length,
    }),
  ),
);
const activeFilter = computed(
  () =>
    filters.value.find((filter) => filter.key === selectedFilter.value) ??
    filters.value[0],
);
const categoryRecipes = computed(() =>
  localRecipes.value.filter(
    (recipe) =>
      selectedFilter.value === "All" ||
      recipe.category === selectedFilter.value,
  ),
);
const skillFilters = computed(() => [
  {
    key: "All",
    label: "All Skills",
    count: categoryRecipes.value.length,
    metaLabel: categoryRecipes.value.length,
    progress: categoryRecipes.value.length
      ? Math.round(
          (categoryRecipes.value.filter((recipe) => recipe.can_craft).length /
            categoryRecipes.value.length) *
            100,
        )
      : 0,
  },
  ...[
    ...new Set(categoryRecipes.value.map((recipe) => recipe.skill_label)),
  ].map((skillLabel) => {
    const recipes = categoryRecipes.value.filter(
      (recipe) => recipe.skill_label === skillLabel,
    );
    const progress = recipes[0]?.skill_progress;
    const level = progress?.level ?? recipes[0]?.skill_level ?? 1;

    return {
      key: skillLabel,
      label: skillLabel,
      count: recipes.length,
      metaLabel: `Lv ${level}`,
      progress: skillProgressPercent(progress),
    };
  }),
]);
const nestedSkillFilters = computed(() =>
  skillFilters.value.filter((filter) => filter.key !== "All"),
);
const activeSkillFilter = computed(
  () =>
    skillFilters.value.find(
      (filter) => filter.key === selectedSkillFilter.value,
    ) ?? skillFilters.value[0],
);
const showSkillFilters = computed(
  () => selectedFilter.value !== "All" && nestedSkillFilters.value.length > 0,
);
const activeScopeLabel = computed(() => {
  if (!showSkillFilters.value || selectedSkillFilter.value === "All") {
    return activeFilter.value.label;
  }

  return `${activeFilter.value.label} - ${activeSkillFilter.value.label}`;
});
const filteredRecipes = computed(() =>
  localRecipes.value
    .filter(
      (recipe) =>
        selectedFilter.value === "All" ||
        recipe.category === selectedFilter.value,
    )
    .filter(
      (recipe) =>
        selectedSkillFilter.value === "All" ||
        recipe.skill_label === selectedSkillFilter.value,
    )
    .filter((recipe) => searchMatches(recipe, props.searchTerm)),
);
const readyRecipes = computed(() =>
  filteredRecipes.value.filter((recipe) => recipe.can_craft),
);
const prepareRecipes = computed(() =>
  filteredRecipes.value.filter(
    (recipe) =>
      (recipe.is_unlocked || recipe.key === props.focusRequest?.key) &&
      !recipe.can_craft,
  ),
);
const craftBoards = computed(() => [
  {
    key: "ready",
    label: "Ready",
    count: readyRecipes.value.length,
    unit: "recipes",
    entries: readyRecipes.value,
    description: `${activeScopeLabel.value} recipes you can craft now.`,
  },
  {
    key: "prepare",
    label: "Prepare",
    count: prepareRecipes.value.length,
    unit: "short",
    entries: prepareRecipes.value,
    description: "Unlocked recipes that need materials or gold.",
  },
]);
const activeBoard = computed(
  () =>
    craftBoards.value.find((board) => board.key === selectedBoard.value) ??
    craftBoards.value[0],
);
const visibleRecipes = computed(() =>
  preferredEntries(activeBoard.value.entries, "craft").slice(
    0,
    visibleLimit.value,
  ),
);
const canShowMoreRecipes = computed(
  () => activeBoard.value.entries.length > visibleRecipes.value.length,
);
const visibleExperience = computed(() =>
  visibleRecipes.value.reduce((total, recipe) => total + recipe.experience, 0),
);
const visibleGoldCost = computed(() =>
  visibleRecipes.value.reduce((total, recipe) => total + recipe.gold_cost, 0),
);
const emptyBoardMessage = computed(() => {
  if (selectedBoard.value === "ready") {
    return "No craftable recipes match. Check Prepare for recipes missing supplies.";
  }

  return "No recipes match.";
});

watch(selectedFilter, () => {
  selectedSkillFilter.value = "All";
});

watch(
  filters,
  () => {
    if (!filters.value.some((filter) => filter.key === selectedFilter.value)) {
      selectedFilter.value = "All";
    }
  },
  { immediate: true },
);

watch(skillFilters, () => {
  if (
    !skillFilters.value.some(
      (filter) => filter.key === selectedSkillFilter.value,
    )
  ) {
    selectedSkillFilter.value = "All";
  }
});

watch(
  [selectedBoard, selectedFilter, selectedSkillFilter, () => props.searchTerm],
  () => {
    visibleLimit.value = boardPageSize;
  },
);

watch(
  [readyRecipes, prepareRecipes],
  () => {
    if (
      !readyRecipes.value.length &&
      prepareRecipes.value.length &&
      selectedBoard.value === "ready"
    ) {
      selectedBoard.value = "prepare";
    }

    if (
      !prepareRecipes.value.length &&
      readyRecipes.value.length &&
      selectedBoard.value === "prepare"
    ) {
      selectedBoard.value = "ready";
    }
  },
  { immediate: true },
);

watch(
  () => props.recipes,
  (recipes) => {
    localRecipes.value = [...recipes];
  },
  { deep: true },
);

function searchMatches(recipe, query) {
  const normalizedQuery = query.trim().toLowerCase();

  if (!normalizedQuery) {
    return true;
  }

  return [
    recipe.label,
    recipe.category,
    recipe.skill_label,
    ...(recipe.ingredients ?? []).flatMap(itemSearchFields),
    ...(recipe.outputs ?? []).flatMap(itemSearchFields),
  ]
    .filter(Boolean)
    .join(" ")
    .toLowerCase()
    .includes(normalizedQuery);
}

function itemSearchFields(item) {
  return [
    item.item_name,
    item.rarity,
    item.quality,
    item.item_class,
    item.material_family,
    ...(item.tags ?? []),
  ];
}

function filterProgress(filter) {
  if (!filter.count) {
    return 0;
  }

  const readyCount = localRecipes.value.filter(
    (recipe) =>
      (filter.key === "All" || recipe.category === filter.key) &&
      recipe.can_craft,
  ).length;

  return Math.round((readyCount / filter.count) * 100);
}

function showSkillFiltersFor(filter) {
  return showSkillFilters.value && selectedFilter.value === filter.key;
}

function selectCategory(category) {
  selectedFilter.value = category;
  selectedSkillFilter.value = "All";
}

function requestRecipe(recipe) {
  const entry = localRecipes.value.find((value) => value.key === recipe);
  if (!entry?.can_craft) {
    emit("recover", { action: "craft", key: recipe });
    return;
  }
  selectQuickAction("craft", entry);
  repeatRecipeKey.value = recipe;

  if (form.processing) {
    queuedRecipes.value.push(recipe);

    return;
  }

  craft(recipe);
}

function craft(recipe) {
  repeatRecipeKey.value = recipe;
  form.recipe = recipe;
  form.submit("craft", {
    async: true,
    preserveScroll: true,
    onStart: () => {
      runningRecipe.value = "";
    },
    onSuccess: () => {
      queueNextRecipe();
    },
    onFinish: () => {
      runningRecipe.value = "";
    },
  });
}

function isRecipeDisabled(recipe) {
  return !recipe.is_unlocked;
}

function recipeActionLabel(recipe) {
  if (requiresToolRepair(recipe)) {
    return "Repair Tool";
  }

  return !recipe.can_craft
    ? "Get materials"
    : runningRecipe.value === recipe.key
      ? "Crafting..."
      : "Craft";
}

function requiresToolRepair(recipe) {
  return Boolean(
    recipe.requires_tool_repair || recipe.equipped_tool?.is_broken,
  );
}

function queueNextRecipe(delay = 0) {
  window.setTimeout(() => {
    const queuedRecipe = queuedRecipes.value.shift();

    if (queuedRecipe) {
      const recipe = localRecipes.value.find(
        (entry) => entry.key === queuedRecipe,
      );

      if (recipe?.can_craft) {
        craft(queuedRecipe);
      }

      return;
    }
  }, delay);
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

watch(
  () => form.errors,
  (errors) => {
    if (Object.keys(errors).length) {
      queuedRecipes.value = [];
    }
  },
);
onBeforeUnmount(() => {
  queuedRecipes.value = [];
});
usePanelFocus(
  () => props.focusRequest,
  () => localRecipes.value,
  (entry) => {
    selectedFilter.value = "All";
    selectedSkillFilter.value = "All";
    selectedBoard.value = entry.can_craft ? "ready" : "prepare";
  },
);
</script>
