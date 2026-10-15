<template>
  <section
    v-if="recipeTree.length"
    class="crafting-tree ui-panel ui-panel--flush"
    aria-label="Crafting tree"
  >
    <header class="crafting-tree__header ui-panel-heading">
      <h3 class="ui-section-title">Crafting plan</h3>

      <div class="crafting-tree__actions">
        <template v-if="activeTab === 'tree'">
          <button
            type="button"
            class="crafting-tree__load-all"
            @click="setAllExpanded(true)"
          >
            Expand all
          </button>
          <button
            type="button"
            class="crafting-tree__load-all"
            @click="setAllExpanded(false)"
          >
            Collapse all
          </button>
        </template>
        <button
          type="button"
          class="crafting-tree__load-all"
          :disabled="loadingAllBranches || deferredBranchCount === 0"
          @click="loadAllBranches"
        >
          {{ loadingAllBranches ? "Loading..." : loadAllLabel }}
        </button>

        <div
          class="crafting-tree__tabs ui-tabs"
          role="tablist"
          aria-label="Recipe views"
        >
          <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            role="tab"
            :id="`${viewId}-${tab.key}-tab`"
            :aria-controls="`${viewId}-${tab.key}-panel`"
            class="crafting-tree__tab"
            :class="{ 'crafting-tree__tab--active': activeTab === tab.key }"
            :aria-selected="activeTab === tab.key"
            :tabindex="activeTab === tab.key ? 0 : -1"
            @click="activeTab = tab.key"
            @keydown.left.prevent="switchTab(-1)"
            @keydown.right.prevent="switchTab(1)"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>
    </header>

    <p v-if="loadAllError" class="crafting-tree__load-error">
      {{ loadAllError }}
    </p>
    <p
      v-if="inventory"
      :class="
        inventory.error ? 'crafting-tree__load-error' : 'crafting-tree__note'
      "
    >
      {{
        inventory.error ||
        `Stock for ${inventory.username}. Held quantities do not reserve materials.`
      }}
      <template v-if="inventory.refresh?.delayed"> Refresh delayed.</template>
    </p>

    <div
      v-show="activeTab === 'steps'"
      class="crafting-tree__panel crafting-tree__panel--steps"
      role="tabpanel"
      :id="`${viewId}-steps-panel`"
      :aria-labelledby="`${viewId}-steps-tab`"
      :aria-busy="loadingAllBranches"
    >
      <p
        v-if="loadingAllBranches || deferredBranchCount"
        class="crafting-material-warning"
        role="status"
      >
        {{
          loadingAllBranches
            ? "Loading the remaining recipe branches…"
            : `${deferredBranchCount} branches still unloaded. Load them to see the remaining prerequisites.`
        }}
      </p>
      <CraftingPlanSteps
        :steps="planSteps"
        :completed="completedSteps"
        :loading="loadingAllBranches"
        v-model:group-by-station="groupByStation"
        v-model:hide-completed="hideCompleted"
        @complete="setStepComplete"
        @reset="resetChecklist"
        @route-selected="handleRouteSelected"
      />
    </div>

    <div
      v-show="activeTab === 'tree'"
      :id="`${viewId}-tree-panel`"
      :aria-labelledby="`${viewId}-tree-tab`"
      class="crafting-tree__panel crafting-tree__panel--tree"
      role="tabpanel"
    >
      <CraftingRecipeNode
        v-for="recipe in recipeTree"
        :key="recipe.id ?? recipe.name"
        :recipe="recipe"
        :path="String(recipe.id ?? recipe.name)"
        :desired-quantity="desiredQuantity"
        root
        @route-selected="handleRouteSelected"
      />
    </div>

    <div
      v-show="activeTab === 'totals'"
      :id="`${viewId}-totals-panel`"
      :aria-labelledby="`${viewId}-totals-tab`"
      class="crafting-tree__panel crafting-tree__panel--totals"
      role="tabpanel"
    >
      <div class="crafting-material-controls">
        <label class="ui-field-label"
          >Show
          <SelectInput
            v-model="materialMode"
            class="input"
            aria-label="Material list scope"
          >
            <option value="immediate">Immediate ingredients</option>
            <option value="base">Base materials</option>
          </SelectInput>
        </label>
        <label class="ui-field-label" v-if="inventory && !inventory.error"
          ><input v-model="missingOnly" type="checkbox" /> Missing only</label
        >
      </div>
      <p
        v-if="materialMode === 'base' && deferredBranchCount"
        class="crafting-material-warning"
        role="status"
      >
        {{ deferredBranchCount }} branch{{
          deferredBranchCount === 1 ? "" : "es"
        }}
        still unloaded. This list includes intermediate ingredients until their
        recipes are loaded.
      </p>
      <p class="crafting-material-note">
        {{
          materialMode === "base"
            ? "Deepest ingredients in the selected routes, including existing tools and containers."
            : "Ingredients for the target recipe, without expanding their crafting steps."
        }}
      </p>
      <div v-if="totalGroups.length" class="crafting-totals">
        <section
          v-for="group in totalGroups"
          :key="group.key"
          class="crafting-total-group"
        >
          <header class="crafting-total-group__header">
            <h4>{{ group.label }}</h4>
            <span
              >{{ group.items.length }} item{{
                group.items.length === 1 ? "" : "s"
              }}</span
            >
          </header>

          <div class="crafting-total-group__items">
            <div
              v-for="item in group.items"
              :key="item.key"
              class="crafting-total"
            >
              <span
                class="crafting-total__icon"
                :style="itemFrameStyle(item)"
                aria-hidden="true"
              >
                <img
                  v-if="itemIconUrl(item)"
                  :src="itemIconUrl(item)"
                  alt=""
                  loading="lazy"
                  @error="hideBrokenIcon(item.iconAssetName)"
                />
                <span v-else>{{ itemInitials(item.name) }}</span>
              </span>
              <span class="crafting-total__copy">
                <span class="crafting-total__name">{{ item.name }}</span>
                <span v-if="itemMeta(item)" class="crafting-total__meta">{{
                  itemMeta(item)
                }}</span>
              </span>
              <span class="crafting-total__numbers">
                <strong>Need {{ formatQuantity(item.quantity) }}</strong>
                <template v-if="inventory && !inventory.error">
                  <span>Held {{ formatQuantity(heldQuantity(item)) }}</span>
                  <span
                    :class="{
                      'crafting-stock-missing': missingQuantity(item) > 0,
                    }"
                    >Missing {{ formatQuantity(missingQuantity(item)) }}</span
                  >
                </template>
              </span>
            </div>
          </div>
        </section>
      </div>

      <div v-else class="empty-state-panel">
        <p class="text-muted-3 text-sm font-ui">
          {{
            missingOnly
              ? "Nothing missing from this material list."
              : "No ingredients found."
          }}
        </p>
      </div>
    </div>
  </section>

  <div v-else class="empty-state-panel">
    <p class="text-muted-3 text-sm font-ui">No crafting steps found.</p>
  </div>
</template>

<script setup>
import SelectInput from "../shared/SelectInput.vue";
import { bitcraftFetch as fetch } from "/src/bitcraft-ui/api";
import { route } from "/src/bitcraft-ui/navigation";
import { computed, nextTick, provide, ref, useId, watch } from "vue";
import CraftingRecipeNode from "./CraftingRecipeNode.vue";
import CraftingPlanSteps from "./CraftingPlanSteps.vue";
import {
  craftingTreeContext,
  defaultRecipeIndex,
  selectedRecipe,
  materialTotals,
  craftingSteps,
} from "../craftingTree.js";
import {
  bitcraftItemFrameStyle,
  bitjitaAssetUrl,
} from "/src/bitcraft-ui/bitjitaAssets.js";

const props = defineProps({
  recipes: { type: Array, default: () => [] },
  desiredQuantity: { type: Number, default: 1 },
  storageKey: { type: String, default: null },
  inventory: { type: Object, default: null },
  target: { type: Object, default: () => ({}) },
});

const viewId = useId();
const tabs = [
  { key: "steps", label: "Crafting steps" },
  { key: "totals", label: "Materials" },
  { key: "tree", label: "Tree" },
];
const activeTab = ref("steps");
const treeRevision = ref(0);
const completedSteps = ref({});
const groupByStation = ref(false);
const hideCompleted = ref(false);
const planSteps = computed(() =>
  craftingSteps(recipeTree.value, props.desiredQuantity, props.target),
);
const setStepComplete = ({ step, checked }) => {
  const next = { ...completedSteps.value };
  if (checked) next[step.key] = step.completionSignature;
  else delete next[step.key];
  completedSteps.value = next;
  persistRecipeState();
};
const resetChecklist = () => {
  completedSteps.value = {};
  persistRecipeState();
};
const switchTab = async (offset) => {
  const index = tabs.findIndex((tab) => tab.key === activeTab.value);
  activeTab.value = tabs[(index + offset + tabs.length) % tabs.length].key;
  await nextTick();
  document.getElementById(`${viewId}-${activeTab.value}-tab`)?.focus();
};
const recipeTree = ref([]);
const brokenIconAssets = ref(new Set());
const loadingAllBranches = ref(false);
const loadAllError = ref(null);
const selectedRoutes = ref({});
const expansionState = ref({});
const expandByDefault = ref(false);
const materialMode = ref("base");
const missingOnly = ref(false);

const setExpanded = (key, open) => {
  if ((expansionState.value[key] ?? expandByDefault.value) === open) return;
  expansionState.value = { ...expansionState.value, [key]: open };
  persistRecipeState();
};
const setAllExpanded = (open) => {
  expansionState.value = {};
  expandByDefault.value = open;
  persistRecipeState();
};
provide(craftingTreeContext, {
  isExpanded: (key) => expansionState.value[key] ?? expandByDefault.value,
  setExpanded,
  prepareRecipes: (recipes) => applyPersistedSelections(recipes),
  inventory: computed(() => props.inventory),
});
const heldQuantity = (item) =>
  Number(
    props.inventory?.quantities?.[`${item.kind ?? "item"}:${item.id}`] ?? 0,
  );
const missingQuantity = (item) =>
  Math.max(0, item.quantity - heldQuantity(item));

const deferredIngredients = computed(() =>
  collectDeferredIngredients(recipeTree.value),
);

const deferredBranchCount = computed(
  () =>
    new Set(
      deferredIngredients.value.map((ingredient) => branchKey(ingredient)),
    ).size,
);

const loadAllLabel = computed(() => {
  if (deferredBranchCount.value === 0) {
    return "All loaded";
  }

  return `Load all (${deferredBranchCount.value})`;
});

const itemTotals = computed(() => {
  return materialTotals(
    recipeTree.value,
    props.desiredQuantity,
    materialMode.value,
  )
    .filter((item) => !missingOnly.value || missingQuantity(item) > 0)
    .sort(
      (left, right) =>
        tierNumber(left) - tierNumber(right) ||
        left.name.localeCompare(right.name),
    );
});

const totalGroups = computed(() => {
  const groups = [];

  for (let tier = 1; tier <= 10; tier += 1) {
    const items = itemTotals.value.filter((item) => tierNumber(item) === tier);

    if (items.length) {
      groups.push({
        key: `tier-${tier}`,
        label: `Tier ${tier}`,
        items,
      });
    }
  }

  const otherTierItems = itemTotals.value.filter(
    (item) => tierNumber(item) > 10,
  );

  if (otherTierItems.length) {
    groups.push({
      key: "tier-other",
      label: "Other tier",
      items: otherTierItems,
    });
  }

  const noTierItems = itemTotals.value.filter((item) => {
    const tier = tierNumber(item);

    return tier < 1;
  });

  if (noTierItems.length) {
    groups.push({
      key: "tier-none",
      label: "No tier",
      items: noTierItems,
    });
  }

  return groups;
});

const handleRouteSelected = ({ recipe, index }) => {
  recipe.selectedAlternativeIndex = Number(index) || 0;
  selectedRoutes.value = {
    ...selectedRoutes.value,
    [recipeSelectionKey(recipe)]: recipe.selectedAlternativeIndex,
  };
  applyPersistedSelections(recipeTree.value);
  loadAllError.value = null;
  persistRecipeState();
};

const restorePersistedState = () => {
  const state = readPersistedState();
  selectedRoutes.value = state.routes;
  activeTab.value = state.activeTab;
  expansionState.value = state.expansion;
  expandByDefault.value = state.expandByDefault;
  materialMode.value = state.materialMode;
  completedSteps.value = state.completedSteps;
  groupByStation.value = state.groupByStation;
  hideCompleted.value = state.hideCompleted;
};

const applyPersistedSelections = (recipes) => {
  for (const recipe of recipes ?? []) {
    const index = Number(selectedRoutes.value[recipeSelectionKey(recipe)]);
    const alternatives =
      Array.isArray(recipe.alternatives) && recipe.alternatives.length
        ? recipe.alternatives
        : [recipe];

    if (Number.isInteger(index) && alternatives[index]) {
      recipe.selectedAlternativeIndex = index;
    } else {
      recipe.selectedAlternativeIndex = defaultRecipeIndex(recipe);
    }

    for (const alternative of alternatives) {
      for (const ingredient of alternative.ingredients ?? []) {
        applyPersistedSelections(ingredient.recipes ?? []);
      }
    }
  }
};

const readPersistedState = () => {
  if (!props.storageKey || typeof window === "undefined") {
    return defaultPersistedState();
  }

  try {
    const state = JSON.parse(
      window.localStorage.getItem(props.storageKey) ?? "{}",
    );
    const activeTab =
      state.planVersion === 1 &&
      ["steps", "tree", "totals"].includes(state.activeTab)
        ? state.activeTab
        : "steps";
    const routes =
      state.routes &&
      typeof state.routes === "object" &&
      !Array.isArray(state.routes)
        ? state.routes
        : {};

    const expansion =
      state.expansion &&
      typeof state.expansion === "object" &&
      !Array.isArray(state.expansion)
        ? Object.fromEntries(
            Object.entries(state.expansion).filter(
              ([, open]) => typeof open === "boolean",
            ),
          )
        : {};
    return {
      activeTab,
      routes,
      expansion,
      expandByDefault: state.expandByDefault === true,
      materialMode: state.materialMode === "immediate" ? "immediate" : "base",
      completedSteps:
        state.completedSteps &&
        typeof state.completedSteps === "object" &&
        !Array.isArray(state.completedSteps)
          ? state.completedSteps
          : {},
      groupByStation: state.groupByStation === true,
      hideCompleted: state.hideCompleted === true,
    };
  } catch (error) {
    return defaultPersistedState();
  }
};

const persistRecipeState = () => {
  if (!props.storageKey || typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.setItem(
      props.storageKey,
      JSON.stringify({
        planVersion: 1,
        activeTab: activeTab.value,
        completedSteps: completedSteps.value,
        groupByStation: groupByStation.value,
        hideCompleted: hideCompleted.value,
        routes: selectedRoutes.value,
        expansion: expansionState.value,
        expandByDefault: expandByDefault.value,
        materialMode: materialMode.value,
      }),
    );
  } catch {
    // The plan remains usable when browser storage is unavailable.
  }
};

const defaultPersistedState = () => ({
  activeTab: "steps",
  completedSteps: {},
  groupByStation: false,
  hideCompleted: false,
  routes: {},
  expansion: {},
  expandByDefault: false,
  materialMode: "base",
});

const recipeSelectionKey = (recipe) =>
  [
    recipe?.id ?? "",
    recipe?.name ?? "",
    recipe?.station ?? "",
    recipe?.outputQuantity ?? "",
  ].join("|");

const collectDeferredIngredients = (recipes) => {
  const ingredients = [];

  for (const recipe of recipes ?? []) {
    collectDeferredIngredientsFromRecipe(recipe, ingredients);
  }

  return ingredients;
};

const collectDeferredIngredientsFromRecipe = (recipe, ingredients) => {
  const activeRecipe = selectedRecipe(recipe);

  for (const ingredient of activeRecipe?.ingredients ?? []) {
    if (ingredient.recipesDeferred && ingredient.id && ingredient.kind) {
      ingredients.push(ingredient);
    }

    for (const childRecipe of ingredient.recipes ?? []) {
      collectDeferredIngredientsFromRecipe(childRecipe, ingredients);
    }
  }
};

const loadAllBranches = async () => {
  if (loadingAllBranches.value || deferredBranchCount.value === 0) {
    return;
  }

  loadingAllBranches.value = true;
  loadAllError.value = null;
  const requestedKeys = new Set();
  const revision = treeRevision.value;

  try {
    while (revision === treeRevision.value) {
      const batch = uniqueDeferredIngredients()
        .filter((ingredient) => !requestedKeys.has(branchKey(ingredient)))
        .slice(0, 8);

      if (!batch.length) {
        break;
      }

      batch.forEach((ingredient) => requestedKeys.add(branchKey(ingredient)));
      await Promise.all(
        batch.map((ingredient) =>
          loadBranchForIngredient(ingredient, revision),
        ),
      );
    }

    if (revision !== treeRevision.value) return;
    if (deferredBranchCount.value > 0) {
      loadAllError.value =
        "Loaded available branches. Some repeated branches are still deferred.";
    }
  } catch (error) {
    if (revision === treeRevision.value)
      loadAllError.value = "Could not load every branch.";
  } finally {
    if (revision === treeRevision.value) loadingAllBranches.value = false;
  }
};

const uniqueDeferredIngredients = () => {
  const seen = new Set();

  return deferredIngredients.value.filter((ingredient) => {
    const key = branchKey(ingredient);

    if (seen.has(key)) {
      return false;
    }

    seen.add(key);

    return true;
  });
};

const loadBranchForIngredient = async (
  ingredient,
  revision = treeRevision.value,
) => {
  const response = await fetch(
    route("bitcraft.crafting.branch", {
      itemId: ingredient.id,
      itemKind: ingredient.kind,
    }),
    {
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    },
  );

  if (!response.ok) {
    throw new Error("Branch request failed.");
  }

  const payload = await response.json();
  if (revision === treeRevision.value)
    applyBranchPayload(branchKey(ingredient), payload);
};

const applyBranchPayload = (key, payload) => {
  const recipes = Array.isArray(payload.recipes) ? payload.recipes : [];

  deferredIngredients.value
    .filter((ingredient) => branchKey(ingredient) === key)
    .forEach((ingredient) => {
      const branchRecipes = JSON.parse(JSON.stringify(recipes));
      applyPersistedSelections(branchRecipes);
      ingredient.recipes = branchRecipes;
      ingredient.recipesDeferred = false;
    });
};

const branchKey = (ingredient) => `${ingredient.kind}:${ingredient.id}`;

const formatQuantity = (quantity) =>
  new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 2,
  }).format(quantity);

const itemMeta = (item) =>
  [
    item.kind === "cargo" ? "Cargo" : "Item",
    item.rarity,
    item.deferred ? "Recipe not loaded" : null,
    /tool/i.test(item.category ?? "") ? "Existing tool" : null,
  ]
    .filter(Boolean)
    .join(" · ");

const tierNumber = (item) => {
  const tier = Number(item?.tier);

  return Number.isFinite(tier) ? tier : 0;
};

const itemInitials = (name) =>
  String(name ?? "?")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join("") || "?";

const hideBrokenIcon = (assetName) => {
  if (!assetName) {
    return;
  }

  brokenIconAssets.value = new Set([...brokenIconAssets.value, assetName]);
};

const itemIconUrl = (item) => {
  if (!item?.iconAssetName || brokenIconAssets.value.has(item.iconAssetName)) {
    return null;
  }

  return bitjitaAssetUrl(item.iconAssetName);
};

const itemFrameStyle = (item) =>
  bitcraftItemFrameStyle(item?.tier, item?.rarity);
watch(
  () => props.recipes,
  (recipes) => {
    treeRevision.value += 1;
    loadingAllBranches.value = false;
    recipeTree.value = JSON.parse(JSON.stringify(recipes ?? []));
    applyPersistedSelections(recipeTree.value);
    loadAllError.value = null;
  },
  { immediate: true },
);

watch(
  () => props.storageKey,
  () => {
    restorePersistedState();
    applyPersistedSelections(recipeTree.value);
  },
  { immediate: true },
);

watch(
  [activeTab, materialMode, groupByStation, hideCompleted],
  persistRecipeState,
);
watch(
  [activeTab, recipeTree, deferredBranchCount],
  () => {
    if (
      activeTab.value === "steps" &&
      deferredBranchCount.value &&
      !loadingAllBranches.value &&
      !loadAllError.value
    )
      loadAllBranches();
  },
  { flush: "post", immediate: true },
);
watch(
  () => props.inventory,
  () => {
    missingOnly.value = false;
  },
);
</script>

<style scoped>
.crafting-tree {
  min-width: 0;
  overflow: hidden;
  width: 100%;
}

.crafting-tree__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.crafting-tree__header h3 {
  margin: 0;
}

.crafting-tree__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}

.crafting-tree__load-all {
  border: 1px solid rgb(var(--accent-cyan-rgb) / 0.45);
  border-radius: 7px;
  background: rgb(var(--accent-cyan-rgb) / 0.12);
  color: var(--accent-cyan);
  cursor: pointer;
  font-family: var(--font-ui);
  font-size: 12px;
  font-weight: 800;
  line-height: 1;
  padding: 8px 10px;
}

.crafting-tree__load-all:disabled {
  cursor: default;
  opacity: 0.55;
}

.crafting-tree__note,
.crafting-tree__load-error {
  margin: 0;
  border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.58);
  color: var(--danger);
  font-family: var(--font-ui);
  font-size: 12px;
  font-weight: 650;
  padding: 10px 14px;
}

.crafting-tree__note {
  color: var(--text-muted-2);
}

.crafting-tree__tabs {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid rgb(var(--border-color-rgb) / 0.7);
  border-radius: 8px;
  background: var(--bg-surface);
  padding: 3px;
}

.crafting-tree__tab {
  cursor: pointer;
}

.crafting-tree__panel {
  display: grid;
  gap: 10px;
  min-width: 0;
}

.crafting-tree__panel--tree {
  overflow-x: auto;
  overscroll-behavior-x: contain;
}

.crafting-tree__panel--steps,
.crafting-tree__panel--totals {
  padding: 12px;
}

.crafting-totals {
  display: grid;
  gap: 14px;
}

.crafting-total-group {
  display: grid;
  gap: 8px;
}

.crafting-total-group__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.crafting-total-group__header h4 {
  margin: 0;
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
}

.crafting-total-group__header span {
  color: var(--text-muted-3);
  font-family: var(--font-ui);
  font-size: 11px;
}

.crafting-total-group__items {
  display: grid;
  gap: 8px;
}

.crafting-total {
  display: grid;
  grid-template-columns: 36px minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  border: 1px solid rgb(var(--border-color-rgb) / 0.62);
  border-radius: 8px;
  background: var(--bg-surface);
  padding: 8px 10px;
}

.crafting-total__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: 1px solid rgb(var(--border-color-rgb) / 0.74);
  border-radius: 7px;
  background: var(--bg-surface);
  color: var(--text-muted);
  font-family: var(--font-ui);
  font-size: 11px;
  font-weight: 800;
  overflow: hidden;
}

.crafting-total__icon img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.crafting-total__copy {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.crafting-total__name {
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 13px;
  font-weight: 700;
}

.crafting-total__meta {
  color: var(--text-muted-3);
  font-family: var(--font-ui);
  font-size: 11px;
}

.crafting-total__numbers {
  display: grid;
  gap: 3px;
  text-align: right;
  color: var(--text-muted-2);
  font-size: 12px;
  font-variant-numeric: tabular-nums;
}
.crafting-stock-missing {
  color: var(--accent-pink);
}
.crafting-material-controls {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 12px;
}
.crafting-material-controls label {
  display: flex;
  align-items: center;
  gap: 8px;
}
.crafting-material-controls .app-select {
  width: auto;
  max-width: 100%;
}
.crafting-material-warning,
.crafting-material-note {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--text-muted-2);
}
.crafting-material-warning {
  padding: 10px;
  border: 1px solid rgb(var(--accent-cyan-rgb) / 0.4);
  border-radius: 6px;
  color: var(--accent-cyan);
}
@media (max-width: 640px) {
  .crafting-tree__header {
    align-items: flex-start;
    flex-direction: column;
  }

  .crafting-tree__actions {
    justify-content: flex-start;
    width: 100%;
  }

  .crafting-total {
    grid-template-columns: 32px minmax(0, 1fr);
  }

  .crafting-total__icon {
    width: 32px;
    height: 32px;
  }

  .crafting-total__numbers {
    grid-column: 2;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    text-align: left;
  }
}
</style>
