<script setup>
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from "vue";
import { localPlay, user, authConfigured, authError, signIn } from "../auth";
import {
  evergather,
  evergatherReady,
  evergatherReconnecting,
  evergatherPanelReady,
  setEvergatherPanel,
  marketPage,
  evergatherError,
  revision,
  definitionRevision,
} from "../evergather";
import {
  useActionForm,
  actionError,
  actionProcessing,
} from "../evergather/useActionForm";
import {
  readCatalog,
  presentEvergather,
  defaultAppearance,
} from "../evergather/presentation";
import { marketListingsFor, marketPageInfo } from "../evergather/market";
import { definitionRowsFor } from "../evergather/catalog";
import CraftingPanel from "../components/evergather/CraftingPanel.vue";
import EquipmentPanel from "../components/evergather/EquipmentPanel.vue";
import ExpeditionsPanel from "../components/evergather/ExpeditionsPanel.vue";
import GatheringPanel from "../components/evergather/GatheringPanel.vue";
import JobsPanel from "../components/evergather/JobsPanel.vue";
import LatestResultPanel from "../components/evergather/LatestResultPanel.vue";
import LeaderboardPanel from "../components/evergather/LeaderboardPanel.vue";
import MarketplacePanel from "../components/evergather/MarketplacePanel.vue";
import ProgressionPanel from "../components/evergather/ProgressionPanel.vue";
import ShopPanel from "../components/evergather/ShopPanel.vue";
import SkillActivitiesPanel from "../components/evergather/SkillActivitiesPanel.vue";
import SkillsPanel from "../components/evergather/SkillsPanel.vue";
import WorldEventsPanel from "../components/evergather/WorldEventsPanel.vue";
const catalogCache = new Map();
const catalog = computed(() => {
  definitionRevision.value;
  return readCatalog(
    [
      ...definitionRowsFor(evergather.value),
      ...marketListingsFor(evergather.value).map((row) => ({
        key: `item_metadata:${row.itemKey}:${row.rarity}`,
        kind: "item_metadata",
        payload: row.metadata,
      })),
    ],
    catalogCache,
  );
});
const view = computed(() => {
  revision.value;
  const conn = evergather.value;
  const rows = (key) => [...(conn?.db[key].iter() ?? [])];
  return presentEvergather(
    {
      player: rows("myPlayer")[0],
      skills: rows("mySkills"),
      inventory: rows("myInventory"),
      tools: rows("myTools"),
      results: rows("myResults"),
      listings: marketListingsFor(conn),
      claims: rows("myAchievements"),
      contracts: rows("myContracts"),
      leaders: rows("topLeaderboard"),
      trades: rows("trade"),
    },
    catalog.value,
  );
});
const now = ref(Date.now());
const props = reactive({
  player: computed(() =>
    view.value.player
      ? {
          ...view.value.player,
          can_act_now:
            !view.value.player.next_action_at ||
            new Date(view.value.player.next_action_at).getTime() <= now.value,
        }
      : null,
  ),
  character_options: computed(() => view.value.character_options),
  actions: computed(() => view.value.actions),
  skill_activities: computed(() => view.value.skill_activities),
  skills: computed(() => view.value.skills),
  skill_catalog: computed(() => view.value.skill_catalog),
  item_guide: computed(() => view.value.item_guide),
  equipment: computed(() => view.value.equipment),
  tool_inventory: computed(() => view.value.tool_inventory),
  tool_rarity_upgrades: computed(() => view.value.tool_rarity_upgrades),
  tool_tier_upgrades: computed(() => view.value.tool_tier_upgrades),
  crafting_recipes: computed(() => view.value.crafting_recipes),
  jobs: computed(() => view.value.jobs),
  expeditions: computed(() => view.value.expeditions),
  marketplace: computed(() => {
    revision.value;
    const pagination = marketPageInfo(evergather.value, marketPage.value);
    return {
      ...view.value.marketplace,
      pagination: pagination ?? { page: 1, pages: 1, total: 0 },
    };
  }),
  shop: computed(() => view.value.shop),
  progression: computed(() => view.value.progression),
  world_events: computed(() => view.value.world_events),
  recent_actions: computed(() => view.value.recent_actions),
  summary: computed(() => view.value.summary),
  leaderboards: computed(() => view.value.leaderboards),
  last_result: computed(() => view.value.last_result),
});
const player = computed(() => props.player);
const character_options = computed(() => props.character_options);
const actions = computed(() => props.actions);
const skill_activities = computed(() => props.skill_activities);
const skills = computed(() => props.skills);
const skill_catalog = computed(() => props.skill_catalog);
const item_guide = computed(() => props.item_guide);
const equipment = computed(() => props.equipment);
const tool_inventory = computed(() => props.tool_inventory);
const tool_rarity_upgrades = computed(() => props.tool_rarity_upgrades);
const tool_tier_upgrades = computed(() => props.tool_tier_upgrades);
const crafting_recipes = computed(() => props.crafting_recipes);
const jobs = computed(() => props.jobs);
const expeditions = computed(() => props.expeditions);
const marketplace = computed(() => props.marketplace);
const shop = computed(() => props.shop);
const progression = computed(() => props.progression);
const world_events = computed(() => props.world_events);
const recent_actions = computed(() => props.recent_actions);
const summary = computed(() => props.summary);
const leaderboards = computed(() => props.leaderboards);
const last_result = computed(() => props.last_result);
const appearanceFields = [
  { key: "body_style", label: "Body" },
  { key: "palette", label: "Palette" },
  { key: "hair_style", label: "Hair" },
  { key: "outfit", label: "Outfit" },
];

const navigationStorageKey = "evergather.navigation-state";
const defaultActivePanel = "gather";
const defaultInventoryCategory = "owned";
const defaultActiveSubPanels = {
  overview: "character",
  gather: "actions",
  craft: "recipes",
  trade: "marketplace",
  progress: "skills",
};

const savedNavigationState = readSavedNavigationState();
const activePanel = ref(savedNavigationState.activePanel);
const activeSubPanels = ref(savedNavigationState.activeSubPanels);
const searchQuery = ref(savedNavigationState.searchQuery);
const selectedInventoryCategory = ref(
  savedNavigationState.selectedInventoryCategory,
);
const selectedInventoryKey = ref(savedNavigationState.selectedInventoryKey);
const repeatDialog = ref({ open: false, title: "", message: "", details: [] });
const repeatDialogClose = ref(null);
watch(
  () => repeatDialog.value.open,
  async (open) => {
    if (open) {
      await nextTick();
      repeatDialogClose.value?.focus();
    }
  },
);
const repeatForm = useActionForm({});
const repeatProcessing = computed(() => repeatForm.processing);
const characterForm = useActionForm({
  display_name: "",
  title: "",
  species: "human",
  pronouns: "",
  home_region: "moonwake_coast",
  appearance: { ...defaultAppearance },
});
const newName = ref("");
let characterInitialized = false;
watch(
  player,
  (value) => {
    if (!value || characterInitialized) return;
    Object.assign(characterForm, {
      display_name: value.display_name,
      title: value.title,
      species: value.species,
      pronouns: value.pronouns,
      home_region: value.home_region,
      appearance: { ...value.appearance },
    });
    characterInitialized = true;
  },
  { immediate: true },
);
const catalogCount = (kind) =>
  catalog.value.presentation?.settings?.catalog_counts?.[kind] ?? 0;
const workspaceTabs = computed(() => [
  {
    key: "overview",
    label: "Overview",
    count: props.player?.can_act_now ? "Ready" : "Wait",
  },
  {
    key: "gather",
    label: "Gather",
    count:
      (catalog.value.presentation?.settings?.catalog_counts
        ?.gathering_actions ?? 0) +
      (catalog.value.presentation?.settings?.catalog_counts?.skill_activities ??
        0),
  },
  {
    key: "craft",
    label: "Craft",
    count: workspacePanelCount(
      "craft",
      catalogCount("crafting_recipes") +
        catalogCount("job_contracts") +
        catalogCount("expeditions"),
    ),
  },
  {
    key: "trade",
    label: "Trade",
    count: workspacePanelCount(
      "trade",
      catalogCount("shop_offers") + props.marketplace.pagination.total,
    ),
  },
  {
    key: "progress",
    label: "Progress",
    count: workspacePanelCount("progress", props.skills.length),
  },
]);

const workspaceSubTabs = computed(() => ({
  overview: [
    { key: "character", label: "Character", count: props.player?.gold ?? 0 },
    {
      key: "progression",
      label: "Progression",
      count: props.summary.account_level,
    },
    ...resultSubTab(),
  ],
  gather: [
    {
      key: "actions",
      label: "Actions",
      count: catalogCount("gathering_actions"),
    },
    {
      key: "activities",
      label: "Activities",
      count: catalogCount("skill_activities"),
    },
    ...resultSubTab(),
  ],
  craft: [
    {
      key: "equipment",
      label: "Equipment",
      count: subPanelCount("equipment", props.equipment.length),
    },
    {
      key: "recipes",
      label: "Recipes",
      count: subPanelCount("recipes", catalogCount("crafting_recipes")),
    },
    {
      key: "jobs",
      label: "Jobs",
      count: subPanelCount("jobs", catalogCount("job_contracts")),
    },
    {
      key: "expeditions",
      label: "Expeditions",
      count: subPanelCount("expeditions", catalogCount("expeditions")),
    },
    ...resultSubTab(),
  ],
  trade: [
    {
      key: "marketplace",
      label: "Marketplace",
      count: subPanelCount(
        "marketplace",
        props.marketplace.active_listings.length,
      ),
    },
    {
      key: "shop",
      label: "Shop",
      count: subPanelCount("shop", catalogCount("shop_offers")),
    },
    {
      key: "inventory",
      label: "Inventory",
      count: subPanelCount("inventory", props.summary.inventory_quantity),
    },
    ...resultSubTab(),
  ],
  progress: [
    {
      key: "skills",
      label: "Skills",
      count: subPanelCount("skills", props.skills.length),
    },
    {
      key: "events",
      label: "World Events",
      count: subPanelCount(
        "events",
        props.world_events.active.length + props.world_events.upcoming.length,
      ),
    },
    {
      key: "leaderboards",
      label: "Ranks",
      count: subPanelCount(
        "leaderboards",
        props.leaderboards.groups.reduce(
          (total, group) => total + group.count,
          0,
        ),
      ),
    },
    {
      key: "recent",
      label: "Recent",
      count: subPanelCount("recent", props.summary.action_count),
    },
  ],
}));

const activeWorkspaceSubTabs = computed(
  () => workspaceSubTabs.value[activePanel.value] ?? [],
);
const activeSubPanel = computed(() => {
  const savedPanel = activeSubPanels.value[activePanel.value];

  return activeWorkspaceSubTabs.value.some((tab) => tab.key === savedPanel)
    ? savedPanel
    : activeWorkspaceSubTabs.value[0]?.key;
});
watch(
  [activePanel, activeSubPanel],
  ([workspace, panel]) => setEvergatherPanel(workspace, panel ?? ""),
  { immediate: true },
);
const itemGuideCategories = computed(() => [
  { key: "owned", label: "Owned", count: props.item_guide.summary.owned_items },
  {
    key: "uses",
    label: "Has Uses",
    count: props.item_guide.summary.owned_items_with_sinks,
  },
  ...(props.item_guide.owned_categories ?? []),
]);
const visibleInventory = computed(() =>
  (props.item_guide.owned ?? []).filter((item) => {
    const matchesCategory =
      selectedInventoryCategory.value === "owned" ||
      (selectedInventoryCategory.value === "uses" && item.has_use) ||
      item.item_class === selectedInventoryCategory.value;

    return matchesCategory && searchMatches(item, searchQuery.value);
  }),
);
const selectedInventoryItem = computed(
  () =>
    visibleInventory.value.find(
      (item) => item.item_key === selectedInventoryKey.value,
    ) ??
    visibleInventory.value[0] ??
    null,
);
const visibleInventoryWeight = computed(
  () =>
    Math.round(
      visibleInventory.value.reduce(
        (total, item) => total + item.total_weight,
        0,
      ) * 100,
    ) / 100,
);
const visibleInventoryValue = computed(() =>
  visibleInventory.value.reduce(
    (total, item) => total + item.total_vendor_value,
    0,
  ),
);
const recentActionExperience = computed(() =>
  props.recent_actions.reduce(
    (total, entry) => total + entry.experience_awarded,
    0,
  ),
);
const recentActionGold = computed(() =>
  props.recent_actions.reduce((total, entry) => total + entry.gold_awarded, 0),
);
const repeatCommand = computed(() => repeatCommandFor(props.last_result));
const repeatButtonLabel = computed(() => {
  if (repeatProcessing.value) {
    return "Repeating...";
  }

  return props.last_result ? "Repeat Last" : "No Last";
});

const avatarPaletteClass = computed(
  () =>
    ({
      moonlit: "text-focus bg-focus/10",
      ember: "text-danger bg-[rgb(var(--accent-pink-rgb)/0.1)]",
      verdant: "text-success bg-success/10",
      tideglass: "text-focus bg-focus/10",
    })[props.player.appearance.palette] ?? "text-focus bg-focus/10",
);

const selectWorkspaceTab = (panel) => {
  if (isWorkspacePanel(panel)) activePanel.value = panel;
};
function selectSubPanel(panel) {
  if (activeWorkspaceSubTabs.value.some((tab) => tab.key === panel))
    activeSubPanels.value = {
      ...activeSubPanels.value,
      [activePanel.value]: panel,
    };
}
function subPanelCount(panel, count) {
  return count;
}
function workspacePanelCount(panel, count) {
  return count;
}
function resultSubTab() {
  return props.last_result
    ? [{ key: "result", label: "Result", count: 1 }]
    : [];
}
function appearanceLabel(field, value) {
  return (
    props.character_options.appearance[field]?.find(
      (option) => option.key === value,
    )?.label ?? value
  );
}
const characterSaved = ref(false);
function submitCharacter() {
  characterSaved.value = false;
  characterForm.submit("character", {
    onSuccess: () => {
      characterSaved.value = true;
    },
  });
}
async function createCharacter() {
  if (actionProcessing.value) return;
  actionProcessing.value = true;
  actionError.value = "";
  try {
    await evergather.value.reducers.createCharacter({ name: newName.value });
  } catch (error) {
    actionError.value =
      error instanceof Error
        ? error.message
        : "Could not create your character.";
  } finally {
    actionProcessing.value = false;
  }
}
function repeatCommandFor(result) {
  if (!result) return null;
  const commands = {
    gathering: ["gather", "action"],
    skill_activity: ["activity", "activity"],
    crafting: ["craft", "recipe_key"],
    job: ["job", "job_key"],
    expedition: ["expedition", "expedition_key"],
    shop: ["shop", "offer_key"],
  };
  const command = commands[result.type];
  if (command && result[command[1]])
    return {
      action: command[0],
      data: {
        [{
          gather: "action",
          activity: "activity",
          craft: "recipe",
          job: "job",
          expedition: "expedition",
          shop: "offer",
        }[command[0]]]: result[command[1]],
      },
    };
  if (
    ["tool_rarity_upgrade", "tool_tier_upgrade"].includes(result.type) &&
    result.slot
  )
    return {
      action: result.type === "tool_rarity_upgrade" ? "rarity" : "tier",
      data: { slot: result.slot },
    };
  if (
    result.type === "market_listing" &&
    result.listing_type === "item" &&
    result.item_key
  )
    return {
      action: "list",
      data: {
        listing_type: "item",
        item_key: result.item_key,
        quantity: result.quantity,
        unit_price: result.unit_price,
      },
    };
  if (result.type === "npc_sale" && result.item_key)
    return {
      action: "vendor",
      data: { item_key: result.item_key, quantity: result.quantity },
    };
  return {
    message: ["market_purchase", "market_cancel"].includes(result.type)
      ? "Choose a current listing from Marketplace. Completed purchases and cancellations cannot be repeated."
      : "This result needs a fresh selection. Choose the tool, title, reward, or action from its board.",
  };
}
function repeatLast() {
  const command = repeatCommand.value;
  if (!command) return;
  if (command.message) {
    repeatDialog.value = {
      open: true,
      title: "Choose again",
      message: command.message,
      details: [],
    };
    return;
  }
  Object.assign(repeatForm, command.data);
  repeatForm.submit(command.action, {
    onError: (errors) => {
      repeatDialog.value = {
        open: true,
        title: "Could not repeat",
        message: errors._error,
        details: [],
      };
    },
  });
}
function closeRepeatDialog() {
  if (repeatDialog.value.title === "Could not repeat") actionError.value = "";
  repeatDialog.value.open = false;
  nextTick(() =>
    document
      .querySelector('[aria-label="Evergather command board"] .app-btn')
      ?.focus(),
  );
}
function escapeDialog(event) {
  if (event.key === "Escape" && repeatDialog.value.open) closeRepeatDialog();
}
let scrollSaveFrame = null;
let clock;
watch(
  [
    activePanel,
    activeSubPanels,
    searchQuery,
    selectedInventoryCategory,
    selectedInventoryKey,
  ],
  persistNavigationState,
  { deep: true },
);
onMounted(() => {
  window.addEventListener("scroll", queueNavigationStatePersist, {
    passive: true,
  });
  window.addEventListener("keydown", escapeDialog);
  restoreScrollPosition();
  clock = setInterval(() => {
    now.value = Date.now();
  }, 250);
});
onBeforeUnmount(() => {
  window.removeEventListener("scroll", queueNavigationStatePersist);
  window.removeEventListener("keydown", escapeDialog);
  clearInterval(clock);
  if (scrollSaveFrame !== null) cancelAnimationFrame(scrollSaveFrame);
  persistNavigationState();
});
function searchMatches(entry, query) {
  const normalizedQuery = query.trim().toLowerCase();

  if (!normalizedQuery) {
    return true;
  }

  return [
    entry.item_name,
    entry.rarity,
    entry.quality,
    entry.item_class,
    entry.material_family,
    ...(entry.tags ?? []),
  ]
    .filter(Boolean)
    .join(" ")
    .toLowerCase()
    .includes(normalizedQuery);
}
function readSavedNavigationState() {
  const fallbackState = {
    activePanel: defaultActivePanel,
    activeSubPanels: { ...defaultActiveSubPanels },
    searchQuery: "",
    selectedInventoryCategory: defaultInventoryCategory,
    selectedInventoryKey: "",
    scrollY: 0,
  };

  if (typeof window === "undefined") {
    return fallbackState;
  }

  try {
    const parsedState = JSON.parse(
      window.localStorage.getItem(navigationStorageKey) ?? "{}",
    );
    const savedState =
      parsedState && typeof parsedState === "object" ? parsedState : {};
    const activePanel = isWorkspacePanel(savedState.activePanel)
      ? savedState.activePanel
      : fallbackState.activePanel;
    const activeSubPanels = { ...defaultActiveSubPanels };

    if (
      savedState.activeSubPanels &&
      typeof savedState.activeSubPanels === "object"
    ) {
      Object.entries(savedState.activeSubPanels).forEach(
        ([panel, subPanel]) => {
          if (isWorkspacePanel(panel) && typeof subPanel === "string") {
            activeSubPanels[panel] = subPanel;
          }
        },
      );
    }

    return {
      activePanel,
      activeSubPanels,
      searchQuery:
        typeof savedState.searchQuery === "string"
          ? savedState.searchQuery
          : fallbackState.searchQuery,
      selectedInventoryCategory:
        typeof savedState.selectedInventoryCategory === "string"
          ? savedState.selectedInventoryCategory
          : fallbackState.selectedInventoryCategory,
      selectedInventoryKey:
        typeof savedState.selectedInventoryKey === "string"
          ? savedState.selectedInventoryKey
          : fallbackState.selectedInventoryKey,
      scrollY: Number.isFinite(Number(savedState.scrollY))
        ? Math.max(0, Number(savedState.scrollY))
        : fallbackState.scrollY,
    };
  } catch {
    return fallbackState;
  }
}
function persistNavigationState() {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.setItem(
      navigationStorageKey,
      JSON.stringify({
        activePanel: activePanel.value,
        activeSubPanels: activeSubPanels.value,
        searchQuery: searchQuery.value,
        selectedInventoryCategory: selectedInventoryCategory.value,
        selectedInventoryKey: selectedInventoryKey.value,
        scrollY: Math.max(0, Math.round(window.scrollY ?? 0)),
      }),
    );
  } catch {
    return;
  }
}
function queueNavigationStatePersist() {
  if (typeof window === "undefined" || scrollSaveFrame !== null) {
    return;
  }

  scrollSaveFrame = window.requestAnimationFrame(() => {
    scrollSaveFrame = null;
    persistNavigationState();
  });
}
function restoreScrollPosition() {
  if (typeof window === "undefined" || savedNavigationState.scrollY <= 0) {
    return;
  }

  window.requestAnimationFrame(() => {
    window.scrollTo({
      top: savedNavigationState.scrollY,
      left: 0,
      behavior: "auto",
    });
  });
}
function isWorkspacePanel(panel) {
  return (
    typeof panel === "string" &&
    Object.prototype.hasOwnProperty.call(defaultActiveSubPanels, panel)
  );
}
</script>
<template>
  <section class="page">
    <div
      v-if="actionError || evergatherError"
      role="alert"
      class="notice flex items-center justify-between gap-3"
    >
      <span>{{ actionError || evergatherError }}</span>
      <button
        v-if="actionError"
        type="button"
        class="app-btn app-btn--ghost app-btn--sm"
        @click="actionError = ''"
      >
        Dismiss
      </button>
    </div>
    <div v-if="!localPlay && !user" class="login-panel">
      <h1>Evergather</h1>
      <button class="button gold" :disabled="!authConfigured" @click="signIn">
        Sign in with Discord
      </button>
      <p v-if="authError" role="alert">{{ authError }}</p>
    </div>
    <template v-else-if="!evergatherReady"
      ><p class="muted" role="status">
        {{
          evergatherReconnecting
            ? "Reconnecting to Evergather…"
            : "Loading your Evergather character…"
        }}
      </p></template
    >
    <form
      v-else-if="!player"
      class="surface create-character"
      @submit.prevent="createCharacter"
    >
      <p class="eyebrow">Datacrypt</p>
      <h1>Evergather</h1>
      <h2>Create your character</h2>
      <label
        >Character name<input
          v-model="newName"
          required
          minlength="2"
          maxlength="40"
          autocomplete="nickname" /></label
      ><button class="button gold" :disabled="actionProcessing">
        Start your adventure
      </button>
    </form>
    <template v-else>
      <div class="evergather-ui">
        <header class="mb-6">
          <div class="page-hero">
            <div class="page-hero__copy">
              <div class="page-hero__eyebrow">Datacrypt</div>
              <h1 class="page-hero__title page-hero__title--md">Evergather</h1>
            </div>
          </div>
        </header>

        <nav
          class="mb-5 border-y border-border/70 py-3"
          aria-label="Evergather command board"
        >
          <div
            class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)] xl:items-start"
          >
            <div class="min-w-0 space-y-3">
              <div
                class="flex min-w-0 gap-2 overflow-x-auto pb-1"
                aria-label="Evergather workspaces"
              >
                <button
                  v-for="tab in workspaceTabs"
                  :key="tab.key"
                  type="button"
                  class="tag shrink-0 transition hover:border-focus/60"
                  :class="{
                    'border-focus/70 bg-focus/10 text-primary':
                      activePanel === tab.key,
                  }"
                  :aria-pressed="activePanel === tab.key"
                  @click="selectWorkspaceTab(tab.key)"
                >
                  {{ tab.label }} · {{ tab.count }}
                </button>
              </div>

              <div
                class="flex min-w-0 gap-2 overflow-x-auto pb-1"
                aria-label="Evergather sections"
              >
                <button
                  v-for="tab in activeWorkspaceSubTabs"
                  :key="tab.key"
                  type="button"
                  class="shrink-0 rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                  :class="{
                    'border-focus/70 bg-focus/10 text-primary':
                      activeSubPanel === tab.key,
                  }"
                  :aria-pressed="activeSubPanel === tab.key"
                  @click="selectSubPanel(tab.key)"
                >
                  {{ tab.label }} · {{ tab.count }}
                </button>
              </div>
            </div>

            <div class="grid min-w-0 gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
              <label class="relative min-w-0">
                <span class="sr-only">Search Evergather</span>
                <input
                  v-model="searchQuery"
                  type="search"
                  class="w-full rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                  placeholder="Search Evergather..."
                />
              </label>

              <button
                type="button"
                class="app-btn app-btn--primary app-btn--sm"
                :disabled="actionProcessing || repeatProcessing || !last_result"
                @click="repeatLast"
              >
                {{ repeatButtonLabel }}
              </button>
            </div>
          </div>
        </nav>

        <div
          v-if="repeatDialog.open"
          class="fixed inset-0 z-50 grid place-items-center bg-overlay-2 px-4 py-6"
          role="dialog"
          aria-modal="true"
          aria-labelledby="repeat-dialog-title"
          @keydown.tab.prevent="repeatDialogClose?.focus()"
        >
          <div
            class="w-full max-w-md rounded-md border border-border bg-surface px-4 py-4 shadow-xl"
          >
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <p
                  id="repeat-dialog-title"
                  class="text-sm font-ui text-primary"
                >
                  {{ repeatDialog.title }}
                </p>
                <p class="mt-2 text-sm text-muted-2">
                  {{ repeatDialog.message }}
                </p>
              </div>
              <button
                ref="repeatDialogClose"
                type="button"
                class="rounded-md border border-border bg-surface-2 px-2 py-1 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                @click="closeRepeatDialog"
              >
                Close
              </button>
            </div>

            <div v-if="repeatDialog.details.length" class="mt-4 grid gap-2">
              <div
                v-for="detail in repeatDialog.details"
                :key="detail"
                class="rounded-md border border-border bg-canvas px-3 py-2 text-xs text-muted-2"
              >
                {{ detail }}
              </div>
            </div>
          </div>
        </div>

        <p
          v-if="!evergatherPanelReady"
          class="surface-section p-5"
          role="status"
        >
          Loading panel…
        </p>
        <div
          v-else
          class="grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(24rem,0.75fr)]"
        >
          <section
            v-if="activePanel === 'overview' && activeSubPanel === 'character'"
            class="surface-section xl:col-span-2"
          >
            <div class="surface-section__header">
              <div class="surface-section__copy">
                <span class="surface-section__title">{{
                  player.display_name
                }}</span>
                <p class="surface-section__subtitle">
                  {{ player.title || player.species_label }} ·
                  {{ player.home_region_label }}
                </p>
              </div>

              <span class="tag" :class="{ 'tag--success': player.can_act_now }">
                {{ player.can_act_now ? "Ready" : "Cooling down" }}
              </span>
            </div>

            <div class="surface-section__body">
              <div class="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 p-4">
                  <div
                    class="relative mx-auto grid aspect-square w-full max-w-44 place-items-center rounded-md border"
                    :class="avatarPaletteClass"
                  >
                    <div class="grid place-items-center gap-2">
                      <div
                        class="h-16 w-16 rounded-full border-2 border-current bg-surface/50"
                      />
                      <div
                        class="h-16 w-24 rounded-t-full border-2 border-current bg-surface/40"
                      />
                    </div>
                  </div>

                  <div
                    v-if="player.reward_loadout?.title_label"
                    class="mt-4 flex flex-wrap justify-center gap-2"
                  >
                    <span class="tag">{{
                      player.reward_loadout.title_label
                    }}</span>
                  </div>

                  <div class="mt-4 grid gap-2 text-xs text-muted-2">
                    <div class="flex items-center justify-between gap-3">
                      <span>Body</span>
                      <span class="text-primary">{{
                        appearanceLabel(
                          "body_style",
                          player.appearance.body_style,
                        )
                      }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                      <span>Hair</span>
                      <span class="text-primary">{{
                        appearanceLabel(
                          "hair_style",
                          player.appearance.hair_style,
                        )
                      }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                      <span>Outfit</span>
                      <span class="text-primary">{{
                        appearanceLabel("outfit", player.appearance.outfit)
                      }}</span>
                    </div>
                  </div>
                </div>

                <form class="grid gap-4" @submit.prevent="submitCharacter">
                  <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                        >Name</span
                      >
                      <input
                        v-model="characterForm.display_name"
                        type="text"
                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      />
                      <span
                        v-if="characterForm.errors.display_name"
                        class="text-xs text-danger"
                      >
                        {{ characterForm.errors.display_name }}
                      </span>
                    </label>

                    <label class="grid gap-1">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                        >Title</span
                      >
                      <input
                        v-model="characterForm.title"
                        type="text"
                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      />
                      <span
                        v-if="characterForm.errors.title"
                        class="text-xs text-danger"
                      >
                        {{ characterForm.errors.title }}
                      </span>
                    </label>

                    <label class="grid gap-1">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                        >Species</span
                      >
                      <SelectInput
                        v-model="characterForm.species"
                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      >
                        <option
                          v-for="option in character_options.species"
                          :key="option.key"
                          :value="option.key"
                        >
                          {{ option.label }}
                        </option>
                      </SelectInput>
                      <span
                        v-if="characterForm.errors.species"
                        class="text-xs text-danger"
                      >
                        {{ characterForm.errors.species }}
                      </span>
                    </label>

                    <label class="grid gap-1">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                        >Pronouns</span
                      >
                      <input
                        v-model="characterForm.pronouns"
                        type="text"
                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      />
                      <span
                        v-if="characterForm.errors.pronouns"
                        class="text-xs text-danger"
                      >
                        {{ characterForm.errors.pronouns }}
                      </span>
                    </label>

                    <label class="grid gap-1 sm:col-span-2">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                        >Home Region</span
                      >
                      <SelectInput
                        v-model="characterForm.home_region"
                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      >
                        <option
                          v-for="option in character_options.home_regions"
                          :key="option.key"
                          :value="option.key"
                        >
                          {{ option.label }}
                        </option>
                      </SelectInput>
                      <span
                        v-if="characterForm.errors.home_region"
                        class="text-xs text-danger"
                      >
                        {{ characterForm.errors.home_region }}
                      </span>
                    </label>
                  </div>

                  <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <label
                      v-for="field in appearanceFields"
                      :key="field.key"
                      class="grid gap-1"
                    >
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                        >{{ field.label }}</span
                      >
                      <SelectInput
                        v-model="characterForm.appearance[field.key]"
                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      >
                        <option
                          v-for="option in character_options.appearance[
                            field.key
                          ]"
                          :key="option.key"
                          :value="option.key"
                        >
                          {{ option.label }}
                        </option>
                      </SelectInput>
                    </label>
                  </div>

                  <div
                    class="flex flex-wrap items-center justify-between gap-3"
                  >
                    <div class="flex flex-wrap gap-2">
                      <span class="tag">{{ player.gold }} gold</span>
                      <span class="tag">Level {{ summary.account_level }}</span>
                      <span class="tag">{{ summary.total_experience }} XP</span>
                      <span class="tag"
                        >{{ summary.inventory_quantity }} items</span
                      >
                      <span class="tag">{{ summary.inventory_weight }} wt</span>
                    </div>

                    <button
                      type="submit"
                      class="app-btn app-btn--primary"
                      :disabled="characterForm.processing"
                    >
                      Save Character
                    </button>
                    <span
                      v-if="characterSaved"
                      role="status"
                      class="text-xs text-success"
                      >Character saved.</span
                    >
                  </div>
                </form>
              </div>
            </div>
          </section>

          <ProgressionPanel
            v-if="
              activePanel === 'overview' && activeSubPanel === 'progression'
            "
            class="xl:col-span-2"
            :progression="progression"
            :summary="summary"
          />

          <LatestResultPanel
            v-if="last_result && activeSubPanel === 'result'"
            class="xl:col-span-2"
            :result="last_result"
          />

          <GatheringPanel
            v-if="activePanel === 'gather' && activeSubPanel === 'actions'"
            class="xl:col-span-2"
            :actions="actions"
            :player="player"
            :last-result="last_result"
            :search-term="searchQuery"
          />

          <SkillActivitiesPanel
            v-if="activePanel === 'gather' && activeSubPanel === 'activities'"
            class="xl:col-span-2"
            :activities="skill_activities"
            :player="player"
            :last-result="last_result"
            :search-term="searchQuery"
          />

          <template
            v-if="activePanel === 'craft' && activeSubPanel === 'equipment'"
          >
            <EquipmentPanel
              class="xl:col-span-2"
              :equipment="equipment"
              :tool-inventory="tool_inventory"
              :tool-rarity-upgrades="tool_rarity_upgrades"
              :tool-tier-upgrades="tool_tier_upgrades"
              :search-term="searchQuery"
            />
          </template>

          <template
            v-if="activePanel === 'craft' && activeSubPanel === 'recipes'"
          >
            <CraftingPanel
              class="xl:col-span-2"
              :recipes="crafting_recipes"
              :player="player"
              :last-result="last_result"
              :search-term="searchQuery"
            />
          </template>

          <template v-if="activePanel === 'craft' && activeSubPanel === 'jobs'">
            <JobsPanel
              class="xl:col-span-2"
              :jobs="jobs"
              :last-result="last_result"
              :search-term="searchQuery"
            />
          </template>

          <template
            v-if="activePanel === 'craft' && activeSubPanel === 'expeditions'"
          >
            <ExpeditionsPanel
              class="xl:col-span-2"
              :expeditions="expeditions"
              :last-result="last_result"
              :search-term="searchQuery"
            />
          </template>

          <template
            v-if="activePanel === 'trade' && activeSubPanel === 'marketplace'"
          >
            <MarketplacePanel
              class="xl:col-span-2"
              :marketplace="marketplace"
              @page="marketPage = $event"
              :search-term="searchQuery"
            />
          </template>

          <template v-if="activePanel === 'trade' && activeSubPanel === 'shop'">
            <ShopPanel
              class="xl:col-span-2"
              :shop="shop"
              :search-term="searchQuery"
            />
          </template>

          <template
            v-if="activePanel === 'progress' && activeSubPanel === 'events'"
          >
            <WorldEventsPanel
              class="xl:col-span-2"
              :world-events="world_events"
            />
          </template>

          <template
            v-if="
              activePanel === 'progress' && activeSubPanel === 'leaderboards'
            "
          >
            <LeaderboardPanel
              class="xl:col-span-2"
              :leaderboards="leaderboards"
            />
          </template>

          <template
            v-if="activePanel === 'progress' && activeSubPanel === 'skills'"
          >
            <SkillsPanel
              class="xl:col-span-2"
              :skills="skills"
              :catalog="skill_catalog"
              :search-term="searchQuery"
            />
          </template>

          <template
            v-if="activePanel === 'trade' && activeSubPanel === 'inventory'"
          >
            <section class="surface-section xl:col-span-2">
              <div class="surface-section__header">
                <div class="surface-section__copy">
                  <span class="surface-section__title">Inventory Guide</span>
                  <p class="surface-section__subtitle">
                    {{ item_guide.summary.owned_items }} owned items ·
                    {{ item_guide.summary.owned_items_with_sinks }} with mapped
                    uses ·
                    {{ item_guide.summary.owned_items_with_fallback_sinks }}
                    with fallback disposal.
                  </p>
                </div>
              </div>

              <div class="surface-section__body">
                <div
                  class="grid gap-4 xl:grid-cols-[14rem_minmax(0,1fr)_minmax(18rem,0.8fr)]"
                >
                  <div
                    class="rounded-md border border-border bg-surface-2 px-3 py-3"
                  >
                    <p class="text-sm font-ui text-primary">Item Index</p>
                    <div class="mt-3 flex flex-wrap gap-2 xl:grid">
                      <button
                        v-for="category in itemGuideCategories"
                        :key="category.key"
                        type="button"
                        class="rounded-md border border-border bg-canvas px-3 py-2 text-left text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                        :class="{
                          'border-focus/70 bg-focus/10 text-primary':
                            selectedInventoryCategory === category.key,
                        }"
                        @click="selectedInventoryCategory = category.key"
                      >
                        {{ category.label }} · {{ category.count }}
                      </button>
                    </div>
                    <div class="mt-4 grid gap-2 text-xs">
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">Visible</span>
                        <span class="text-primary">{{
                          visibleInventory.length
                        }}</span>
                      </div>
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">Weight</span>
                        <span class="text-primary"
                          >{{ visibleInventoryWeight }} wt</span
                        >
                      </div>
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">Value</span>
                        <span class="text-primary"
                          >{{ visibleInventoryValue }}g</span
                        >
                      </div>
                    </div>
                  </div>

                  <div
                    v-if="visibleInventory.length"
                    class="grid max-h-[42rem] gap-2 overflow-y-auto pr-1"
                  >
                    <button
                      v-for="(item, index) in visibleInventory"
                      :key="item.item_key"
                      type="button"
                      class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 text-left transition hover:border-focus/60 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                      :class="{
                        'border-focus/70 bg-focus/10':
                          selectedInventoryItem?.item_key === item.item_key,
                      }"
                      @click="selectedInventoryKey = item.item_key"
                    >
                      <div
                        class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary"
                      >
                        #{{ index + 1 }}
                      </div>
                      <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                          <p
                            class="min-w-0 truncate text-sm font-ui text-primary"
                          >
                            {{ item.item_name }}
                          </p>
                          <span class="tag capitalize">{{ item.rarity }}</span>
                          <span class="tag capitalize">{{ item.quality }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-2">
                          {{ item.item_class }} · {{ item.material_family }} ·
                          {{ item.market_price_band }}
                        </p>
                      </div>
                      <div class="text-left md:text-right">
                        <p class="text-sm font-ui text-primary">
                          x{{ item.owned_quantity }}
                        </p>
                        <p class="mt-1 text-xs text-muted-3">
                          {{ item.weight }} wt ea · {{ item.total_weight }} wt
                        </p>
                      </div>
                    </button>
                  </div>

                  <p
                    v-else
                    class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2"
                  >
                    No inventory matches.
                  </p>

                  <aside
                    v-if="selectedInventoryItem"
                    class="rounded-md border border-border bg-surface-2 px-3 py-3"
                  >
                    <div class="flex flex-wrap items-center gap-2">
                      <p class="text-sm font-ui text-primary">
                        {{ selectedInventoryItem.item_name }}
                      </p>
                      <span class="tag capitalize">{{
                        selectedInventoryItem.rarity
                      }}</span>
                      <span class="tag capitalize">{{
                        selectedInventoryItem.quality
                      }}</span>
                    </div>

                    <div class="mt-3 grid gap-2 text-xs">
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">Owned</span>
                        <span class="text-primary">{{
                          selectedInventoryItem.owned_quantity
                        }}</span>
                      </div>
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">NPC floor</span>
                        <span class="text-primary"
                          >{{ selectedInventoryItem.npc_buy_price }}g</span
                        >
                      </div>
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">Market band</span>
                        <span class="text-primary">{{
                          selectedInventoryItem.market_price_band
                        }}</span>
                      </div>
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">Weight</span>
                        <span class="text-primary"
                          >{{ selectedInventoryItem.weight }} wt</span
                        >
                      </div>
                    </div>

                    <div
                      class="mt-4 rounded-md border border-border bg-canvas px-3 py-3"
                    >
                      <p
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                      >
                        Best Source
                      </p>
                      <p
                        v-if="selectedInventoryItem.best_source"
                        class="mt-2 text-sm text-primary"
                      >
                        {{ selectedInventoryItem.best_source.label }}
                      </p>
                      <p
                        v-if="selectedInventoryItem.best_source"
                        class="mt-1 text-xs text-muted-2"
                      >
                        {{ selectedInventoryItem.best_source.type }} ·
                        {{ selectedInventoryItem.best_source.context }} · Lv
                        {{ selectedInventoryItem.best_source.required_level }}
                      </p>
                      <p v-else class="mt-2 text-xs text-muted-2">
                        No source mapped yet.
                      </p>
                    </div>

                    <div
                      class="mt-3 rounded-md border border-border bg-canvas px-3 py-3"
                    >
                      <p
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                      >
                        Best Use
                      </p>
                      <p
                        v-if="selectedInventoryItem.best_sink"
                        class="mt-2 text-sm text-primary"
                      >
                        {{ selectedInventoryItem.best_sink.label }}
                      </p>
                      <p
                        v-if="selectedInventoryItem.best_sink"
                        class="mt-1 text-xs text-muted-2"
                      >
                        {{ selectedInventoryItem.best_sink.type }} ·
                        {{ selectedInventoryItem.best_sink.context }} · Lv
                        {{ selectedInventoryItem.best_sink.required_level }}
                      </p>
                      <p
                        v-if="selectedInventoryItem.purpose"
                        class="mt-2 text-xs text-muted-2"
                      >
                        {{ selectedInventoryItem.purpose }}
                      </p>
                      <p
                        v-if="
                          !selectedInventoryItem.best_sink &&
                          selectedInventoryItem.best_fallback_sink
                        "
                        class="mt-2 text-xs text-muted-2"
                      >
                        Fallback:
                        {{ selectedInventoryItem.best_fallback_sink.label }}
                      </p>
                      <p
                        v-else-if="!selectedInventoryItem.best_sink"
                        class="mt-2 text-xs text-muted-2"
                      >
                        No primary use mapped yet.
                      </p>
                    </div>

                    <div class="mt-3 grid gap-2">
                      <p
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3"
                      >
                        Known Paths
                      </p>
                      <div class="flex flex-wrap gap-2">
                        <span
                          v-for="source in selectedInventoryItem.sources.slice(
                            0,
                            4,
                          )"
                          :key="`source-${source.type}-${source.label}`"
                          class="tag"
                        >
                          {{ source.type }}
                        </span>
                        <span
                          v-for="sink in selectedInventoryItem.sinks.slice(
                            0,
                            4,
                          )"
                          :key="`sink-${sink.type}-${sink.label}`"
                          class="tag"
                        >
                          {{ sink.type }}
                        </span>
                        <span
                          v-for="sink in selectedInventoryItem.fallback_sinks.slice(
                            0,
                            2,
                          )"
                          :key="`fallback-${sink.type}-${sink.label}`"
                          class="tag"
                        >
                          {{ sink.type }}
                        </span>
                        <span
                          v-for="route in selectedInventoryItem.transfer_routes.slice(
                            0,
                            2,
                          )"
                          :key="`transfer-${route.type}-${route.label}`"
                          class="tag"
                        >
                          {{ route.type }}
                        </span>
                      </div>
                    </div>
                  </aside>
                </div>
              </div>
            </section>
          </template>

          <template
            v-if="activePanel === 'progress' && activeSubPanel === 'recent'"
          >
            <section class="surface-section xl:col-span-2">
              <div class="surface-section__header">
                <div class="surface-section__copy">
                  <span class="surface-section__title">Recent Actions</span>
                  <p class="surface-section__subtitle">
                    {{ summary.action_count }} logged actions.
                  </p>
                </div>
              </div>

              <div class="surface-section__body">
                <div class="grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                  <div
                    class="rounded-md border border-border bg-surface-2 px-3 py-3"
                  >
                    <p class="text-sm font-ui text-primary">Action Ledger</p>
                    <div class="mt-3 grid gap-2 text-xs">
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">Visible</span>
                        <span class="text-primary">{{
                          recent_actions.length
                        }}</span>
                      </div>
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">XP</span>
                        <span class="text-primary">{{
                          recentActionExperience
                        }}</span>
                      </div>
                      <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-2">Gold</span>
                        <span class="text-primary">{{ recentActionGold }}</span>
                      </div>
                    </div>
                  </div>

                  <div v-if="recent_actions.length" class="grid gap-3">
                    <article
                      v-for="(entry, index) in recent_actions"
                      :key="entry.id"
                      class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                    >
                      <div
                        class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary"
                      >
                        #{{ index + 1 }}
                      </div>
                      <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                          <p
                            class="min-w-0 truncate text-sm font-ui text-primary"
                          >
                            {{ entry.label || entry.action }}
                          </p>
                          <span class="tag">{{ entry.platform }}</span>
                          <span
                            v-if="entry.event_label"
                            class="tag tag--success"
                            >{{ entry.event_label }}</span
                          >
                        </div>
                        <p
                          v-if="entry.tool_item_name"
                          class="mt-1 text-xs text-muted-3"
                        >
                          {{ entry.tool_item_name }}
                        </p>
                      </div>
                      <div class="text-left md:text-right">
                        <p class="text-sm font-ui text-primary">
                          +{{ entry.experience_awarded }} XP
                        </p>
                        <p class="mt-1 text-xs text-muted-2">
                          +{{ entry.gold_awarded }} gold
                        </p>
                      </div>
                    </article>
                  </div>

                  <p
                    v-else
                    class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2"
                  >
                    No actions yet.
                  </p>
                </div>
              </div>
            </section>
          </template>
        </div>
      </div>
    </template>
  </section>
</template>
