import { computed, onScopeDispose, ref, watch } from "vue";
import { bitcraftFetch } from "./api";
import { route } from "./navigation";
import { selectedRecipe } from "./craftingTree";
import {
  applyRecipeSelections,
  parseCraftingPlanState,
  needsCraftingPlanMigration,
  selectedRouteState,
} from "./craftingPlanState";

export function useCraftingPlan(props) {
  const recipeTree = ref([]);
  const activeTab = ref("steps");
  const completedSteps = ref({});
  const groupByStation = ref(false);
  const hideCompleted = ref(false);
  const selectedRoutes = ref({});
  const expansionState = ref({});
  const expandByDefault = ref(false);
  const materialMode = ref("base");
  const loadingAllBranches = ref(false);
  const loadAllError = ref(null);
  const revision = ref(0);
  let controller = new AbortController();
  let restoringState = false;
  const requests = new Map();
  const branchKey = (item) => `${item.kind}:${item.id}`;
  const prepareRecipes = (recipes) =>
    applyRecipeSelections(recipes, selectedRoutes.value);
  const deferredIngredients = computed(() => {
    const result = [];
    function collect(recipes) {
      for (const recipe of recipes) {
        for (const ingredient of selectedRecipe(recipe)?.ingredients ?? []) {
          if (ingredient.recipesDeferred && ingredient.id && ingredient.kind)
            result.push(ingredient);
          collect(ingredient.recipes ?? []);
        }
      }
    }
    collect(recipeTree.value);
    return result;
  });
  const uniqueDeferredIngredients = computed(() => [
    ...new Map(
      deferredIngredients.value.map((item) => [branchKey(item), item]),
    ).values(),
  ]);
  const deferredBranchCount = computed(
    () => uniqueDeferredIngredients.value.length,
  );
  const loadAllLabel = computed(() =>
    deferredBranchCount.value
      ? `Load all (${deferredBranchCount.value})`
      : "All loaded",
  );
  function persistRecipeState() {
    if (restoringState || !props.storageKey || typeof window === "undefined")
      return;
    try {
      window.localStorage.setItem(
        props.storageKey,
        JSON.stringify({
          planVersion: 2,
          activeTab: activeTab.value,
          routes: selectedRoutes.value,
          expansion: expansionState.value,
          expandByDefault: expandByDefault.value,
          materialMode: materialMode.value,
          completedSteps: completedSteps.value,
          groupByStation: groupByStation.value,
          hideCompleted: hideCompleted.value,
        }),
      );
    } catch {
      /* A plan remains usable without browser storage. */
    }
  }
  function handleRouteSelected({ recipe, index }) {
    const choice = selectedRouteState(recipe, Number(index));
    if (!choice) return;
    selectedRoutes.value = { ...selectedRoutes.value, [choice.key]: choice.id };
    prepareRecipes(recipeTree.value);
    loadAllError.value = null;
    persistRecipeState();
  }
  function setStepComplete({ step, checked }) {
    const next = { ...completedSteps.value };
    if (checked) next[step.key] = step.completionSignature;
    else delete next[step.key];
    completedSteps.value = next;
    persistRecipeState();
  }
  function resetChecklist() {
    completedSteps.value = {};
    persistRecipeState();
  }
  function setExpanded(key, open) {
    if ((expansionState.value[key] ?? expandByDefault.value) === open) return;
    expansionState.value = { ...expansionState.value, [key]: open };
    persistRecipeState();
  }
  function setAllExpanded(open) {
    expansionState.value = {};
    expandByDefault.value = open;
    persistRecipeState();
  }
  async function loadBranchForIngredient(
    ingredient,
    expectedRevision = revision.value,
  ) {
    const key = branchKey(ingredient);
    if (!requests.has(key)) {
      const request = bitcraftFetch(
        route("bitcraft.crafting.branch", {
          itemId: ingredient.id,
          itemKind: ingredient.kind,
        }),
        {
          signal: controller.signal,
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
        },
      ).then(async (response) => {
        if (!response.ok) throw Error("Branch request failed.");
        const payload = await response.json();
        return Array.isArray(payload.recipes) ? payload.recipes : [];
      });
      requests.set(key, request);
      request.catch(() => {
        if (requests.get(key) === request) requests.delete(key);
      });
    }
    const recipes = await requests.get(key);
    if (expectedRevision !== revision.value) return;
    // Every occurrence gets its own nodes, sharing only the fetched definition.
    const occurrences = deferredIngredients.value.filter(
      (item) => branchKey(item) === key,
    );
    if (!occurrences.includes(ingredient)) occurrences.push(ingredient);
    for (const item of occurrences) {
      item.recipes = JSON.parse(JSON.stringify(recipes));
      prepareRecipes(item.recipes);
      item.recipesDeferred = false;
    }
  }
  async function loadAllBranches() {
    if (loadingAllBranches.value || !deferredBranchCount.value) return;
    const expectedRevision = revision.value;
    loadingAllBranches.value = true;
    loadAllError.value = null;
    const requested = new Set();
    try {
      while (expectedRevision === revision.value) {
        const batch = uniqueDeferredIngredients.value
          .filter((item) => !requested.has(branchKey(item)))
          .slice(0, 8);
        if (!batch.length) break;
        batch.forEach((item) => requested.add(branchKey(item)));
        await Promise.all(
          batch.map((item) => loadBranchForIngredient(item, expectedRevision)),
        );
      }
      if (expectedRevision === revision.value && deferredBranchCount.value)
        loadAllError.value =
          "Loaded available branches. Some repeated branches are still deferred.";
    } catch {
      if (expectedRevision === revision.value)
        loadAllError.value = "Could not load every branch.";
    } finally {
      if (expectedRevision === revision.value) loadingAllBranches.value = false;
    }
  }
  watch(
    [() => props.storageKey, () => props.recipes],
    async () => {
      restoringState = true;
      controller.abort();
      controller = new AbortController();
      revision.value++;
      const expectedRevision = revision.value;
      requests.clear();
      recipeTree.value = [];
      loadingAllBranches.value = false;
      loadAllError.value = null;
      let serialized = null;
      try {
        if (props.storageKey && typeof window !== "undefined")
          serialized = window.localStorage.getItem(props.storageKey);
      } catch {
        /* Storage can be disabled. */
      }
      let legacy = {};
      if (needsCraftingPlanMigration(serialized)) {
        try {
          legacy = (await import("./craftingLegacyRoutes.json")).default;
        } catch {
          if (expectedRevision === revision.value)
            loadAllError.value =
              "Could not restore the saved crafting plan. Reload to retry.";
          return;
        }
      }
      if (expectedRevision !== revision.value) return;
      const state = parseCraftingPlanState(serialized, legacy);
      activeTab.value = state.activeTab;
      selectedRoutes.value = state.routes;
      expansionState.value = state.expansion;
      expandByDefault.value = state.expandByDefault;
      materialMode.value = state.materialMode;
      completedSteps.value = state.completedSteps;
      groupByStation.value = state.groupByStation;
      hideCompleted.value = state.hideCompleted;
      recipeTree.value = JSON.parse(JSON.stringify(props.recipes ?? []));
      prepareRecipes(recipeTree.value);
      loadingAllBranches.value = false;
      loadAllError.value = null;
      restoringState = false;
      // Persist a successful legacy migration before catalog ordering changes.
      if (serialized) persistRecipeState();
    },
    { immediate: true },
  );
  watch(
    [activeTab, materialMode, groupByStation, hideCompleted],
    persistRecipeState,
  );
  watch(
    [
      activeTab,
      deferredBranchCount,
      loadingAllBranches,
      loadAllError,
      selectedRoutes,
    ],
    () => {
      if (
        activeTab.value === "steps" &&
        deferredBranchCount.value &&
        !loadingAllBranches.value &&
        !loadAllError.value
      )
        loadAllBranches();
    },
    { immediate: true },
  );
  onScopeDispose(() => {
    revision.value++;
    controller.abort();
    requests.clear();
  });
  return {
    recipeTree,
    activeTab,
    completedSteps,
    groupByStation,
    hideCompleted,
    expansionState,
    expandByDefault,
    materialMode,
    loadingAllBranches,
    loadAllError,
    deferredBranchCount,
    loadAllLabel,
    handleRouteSelected,
    setStepComplete,
    resetChecklist,
    setExpanded,
    setAllExpanded,
    prepareRecipes,
    loadAllBranches,
    loadBranchForIngredient,
  };
}
