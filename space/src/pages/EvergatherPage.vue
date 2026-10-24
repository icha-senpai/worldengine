<script setup>
import DiscordAccount from "../components/evergather/DiscordAccount.vue";
import PanelSearch from "../components/evergather/PanelSearch.vue";
import InventoryPanel from "../components/evergather/InventoryPanel.vue";
import RecoveryDialog from "../components/evergather/RecoveryDialog.vue";
import { resultFeedback } from "../evergather/gameplayUi";
import {
  quickAction,
  autoRepeat,
  autoRepeatNotice,
  favorites,
  recentCommands,
  commandId,
  toggleFavorite,
  applyEconomyVersion,
} from "../evergather/playSession";
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import TextInput from "../bitcraft-ui/shared/TextInput.vue";
import {
  computed,
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
  evergatherConnected,
  evergatherReconnecting,
  evergatherPanelReady,
  setEvergatherPanel,
  marketPage,
  evergatherError,
  revision,
  definitionRevision,
  itemDefinitionRows,
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
      ...itemDefinitionRows.value,
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
      buffs: rows("myBuffs"),
    },
    catalog.value,
  );
});
watch(
  () => catalog.value.presentation?.settings?.economy_version,
  applyEconomyVersion,
  { immediate: true },
);
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
  gear: "equipment",
  bag: "inventory",
  trade: "marketplace",
  progress: "skills",
};

const savedNavigationState = readSavedNavigationState();
const activePanel = ref(savedNavigationState.activePanel);
const activeSubPanels = ref(savedNavigationState.activeSubPanels);
const searchQueries = ref(savedNavigationState.queries ?? {});
const searchQuery = computed({
  get: () =>
    searchQueries.value[activePanel.value + ":" + activeSubPanel.value] ?? "",
  set: (value) => {
    searchQueries.value[activePanel.value + ":" + activeSubPanel.value] = value;
  },
});
const selectedInventoryCategory = ref(
  savedNavigationState.selectedInventoryCategory,
);
const selectedInventoryKey = ref(savedNavigationState.selectedInventoryKey);
const repeatForm = useActionForm({});
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
  { key: "gather", label: "Gather" },
  { key: "craft", label: "Craft" },
  {
    key: "gear",
    label: "Gear",
    count: props.equipment.filter((tool) => tool.is_broken).length
      ? props.equipment.filter((tool) => tool.is_broken).length + " broken"
      : "",
  },
  {
    key: "bag",
    label: "Bag",
    count: props.item_guide.summary.owned_items
      ? props.item_guide.summary.owned_items
      : "",
  },
  { key: "trade", label: "Trade" },
  { key: "progress", label: "Progress" },
  { key: "overview", label: "Character" },
]);
const workspaceSubTabs = computed(() => ({
  gear: [{ key: "equipment", label: "Gear", count: 0 }],
  bag: [{ key: "inventory", label: "Bag", count: 0 }],
  overview: [{ key: "character", label: "Character", count: 0 }],
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
  ],
  craft: [
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
  ],
  progress: [
    ...resultSubTab(),
    {
      key: "skills",
      label: "Skills",
      count: subPanelCount("skills", props.skills.length),
    },
    {
      key: "progression",
      label: "Rewards",
      count: props.progression.achievements.filter((entry) => entry.can_claim)
        .length,
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
  ([workspace, panel]) =>
    setEvergatherPanel(
      workspace === "gear"
        ? "craft"
        : workspace === "bag"
          ? "trade"
          : workspace === "progress" && panel === "progression"
            ? "overview"
            : workspace,
      panel ?? "",
    ),
  { immediate: true },
);
const recentActionExperience = computed(() =>
  props.recent_actions.reduce(
    (total, entry) => total + Number(entry.experience_awarded ?? 0),
    0,
  ),
);
const recentActionGold = computed(() =>
  props.recent_actions.reduce(
    (total, entry) =>
      total + Number(entry.gold_awarded ?? 0) - Number(entry.gold_spent ?? 0),
    0,
  ),
);
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
  focusedPath.value = null;
  if (isWorkspacePanel(panel)) {
    activePanel.value = panel;
    window.scrollTo({ top: 0, behavior: "auto" });
  }
};
function selectSubPanel(panel) {
  focusedPath.value = null;
  window.scrollTo({ top: 0, behavior: "auto" });
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
  if (actionProcessing.value || !evergatherConnected.value) return;
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
let scrollSaveFrame = null;
let clock;
watch(
  [
    activePanel,
    activeSubPanels,
    searchQueries,
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
  restoreScrollPosition();
  clock = setInterval(() => {
    now.value = Date.now();
    maybeAutoRepeat();
  }, 250);
});
onBeforeUnmount(() => {
  window.removeEventListener("scroll", queueNavigationStatePersist);
  clearInterval(clock);
  if (scrollSaveFrame !== null) cancelAnimationFrame(scrollSaveFrame);
  persistNavigationState();
});
function readSavedNavigationState() {
  const fallbackState = {
    activePanel: defaultActivePanel,
    activeSubPanels: { ...defaultActiveSubPanels },
    searchQuery: "",
    queries: {},
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
    let activePanel = isWorkspacePanel(savedState.activePanel)
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

    if (activePanel === "craft" && activeSubPanels.craft === "equipment") {
      activePanel = "gear";
      activeSubPanels.craft = "recipes";
    }
    if (activePanel === "trade" && activeSubPanels.trade === "inventory") {
      activePanel = "bag";
      activeSubPanels.trade = "marketplace";
    }
    if (
      activePanel === "overview" &&
      activeSubPanels.overview === "progression"
    ) {
      activePanel = "progress";
      activeSubPanels.progress = "progression";
      activeSubPanels.overview = "character";
    }
    return {
      queries: Object.fromEntries(
        Object.entries(savedState.queries ?? {}).filter(
          ([, value]) => typeof value === "string",
        ),
      ),
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
        queries: searchQueries.value,
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
const recoveryRequest = ref(null);
const focusedPath = ref(null);
const tradeIntent = ref(null);
const materialSources = computed(() => ({
  actions: props.actions,
  activities: props.skill_activities,
  recipes: props.crafting_recipes,
  jobs: props.jobs,
  expeditions: props.expeditions,
  offers: props.shop.offers,
  tools: [...props.equipment, ...props.tool_inventory],
  inventory: [
    ...new Map(
      [...(props.item_guide.directory ?? []), ...props.item_guide.owned].map(
        (item) => [item.item_key, item],
      ),
    ).values(),
  ],
  result: props.last_result,
}));
const quickCollections = computed(() => ({
  gather: props.actions,
  activity: props.skill_activities,
  craft: props.crafting_recipes,
  job: props.jobs,
  expedition: props.expeditions,
}));
const quickEntry = computed(() =>
  (quickCollections.value[quickAction.value?.action] ?? []).find(
    (entry) => entry.key === quickAction.value?.key,
  ),
);
const quickSkill = computed(() =>
  props.skills.find(
    (skill) =>
      skill.skill === (quickEntry.value?.skill ?? quickAction.value?.skill),
  ),
);
const quickTool = computed(() =>
  props.equipment.find((tool) => tool.skill === quickSkill.value?.skill),
);
const quickUnavailable = computed(() => {
  if (!evergatherConnected.value || evergatherReconnecting.value)
    return "Connection interrupted — review your state before resuming";
  if (
    quickTool.value?.is_broken &&
    ["gather", "activity", "craft"].includes(quickAction.value?.action)
  )
    return "Repair tool first";
  const entry = quickEntry.value;
  if (!entry)
    return quickAction.value?.key?.startsWith("training_")
      ? "Training complete — choose tiered work"
      : "";
  if (!entry.is_unlocked) return "Required level not reached";
  if (entry.missing_supplies) return "Need supplies";
  if (entry.requires_tool_repair) return "Repair tool first";
  if (quickAction.value.action === "craft" && !entry.can_craft)
    return "Need materials or gold";
  if (quickAction.value.action === "job" && !entry.can_complete)
    return "Commission requirements not met";
  if (quickAction.value.action === "expedition" && !entry.can_start)
    return "Need supplies";
  return "";
});
const feedback = computed(() => resultFeedback(props.last_result));
const isFavorite = computed(
  () =>
    quickAction.value &&
    favorites.value.some(
      (entry) => commandId(entry) === commandId(quickAction.value),
    ),
);
const cooldownText = computed(() => {
  const left = Math.ceil(
    (new Date(props.player?.next_action_at ?? 0).getTime() - now.value) / 1000,
  );
  return left > 0 ? left + "s cooldown" : "Ready";
});
const skillPercent = computed(() => {
  const skill = quickSkill.value;
  return !skill
    ? 0
    : skill.next_level_experience === null
      ? 100
      : Math.min(
          100,
          Math.max(
            0,
            (Number(skill.experience_into_level) /
              (Number(skill.next_level_experience) -
                Number(skill.current_level_experience))) *
              100,
          ),
        );
});
watch(
  () => props.last_result?.id,
  () => {
    if (quickAction.value || !props.last_result) return;
    const action = {
      gathering: "gather",
      skill_activity: "activity",
      crafting: "craft",
      job: "job",
      expedition: "expedition",
    }[props.last_result.type];
    const key = {
      gather: "action",
      activity: "activity",
      craft: "recipe_key",
      job: "job_key",
      expedition: "expedition_key",
    }[action];
    if (action && props.last_result[key])
      quickAction.value = {
        action,
        key: props.last_result[key],
        label: props.last_result.label,
        skill: props.last_result.skill_progress?.skill,
      };
  },
  { immediate: true },
);
watch(quickUnavailable, (reason) => {
  if (reason && autoRepeat.value) {
    autoRepeat.value = false;
    autoRepeatNotice.value = "Stopped: " + reason;
  }
});
let lastRepeatAt = 0;
function recover(request) {
  autoRepeat.value = false;
  recoveryRequest.value = request;
}
function repeatSelected() {
  if (
    !quickAction.value ||
    actionProcessing.value ||
    quickUnavailable.value ||
    !props.player.can_act_now
  )
    return;
  const command = quickAction.value;
  const field = {
    gather: "action",
    activity: "activity",
    craft: "recipe",
    job: "job",
    expedition: "expedition",
  }[command.action];
  repeatForm[field] = command.key;
  lastRepeatAt = Date.now();
  repeatForm.submit(command.action, {
    onError: () => {
      autoRepeat.value = false;
      autoRepeatNotice.value = "Stopped: " + repeatForm.errors._error;
    },
  });
}
function toggleAuto() {
  autoRepeat.value = !autoRepeat.value;
  autoRepeatNotice.value = "";
  if (autoRepeat.value) repeatSelected();
}
function maybeAutoRepeat() {
  if (
    autoRepeat.value &&
    Date.now() - lastRepeatAt >= 650 &&
    !document.querySelector("dialog[open]")
  )
    repeatSelected();
}
function openQuickCommand(command) {
  autoRepeat.value = false;
  quickAction.value = command;
  navigatePath(command);
}
function navigatePath(path) {
  const destination = {
    gather: ["gather", "actions"],
    activity: ["gather", "activities"],
    craft: ["craft", "recipes"],
    job: ["craft", "jobs"],
    expedition: ["craft", "expeditions"],
    shop: ["trade", "shop"],
    tier: ["craft", "equipment"],
    rarity: ["craft", "equipment"],
  }[path.action];
  if (!destination) return;
  focusedPath.value = { ...path };
  activePanel.value = destination[0];
  activeSubPanels.value = {
    ...activeSubPanels.value,
    [destination[0]]: destination[1],
  };
  searchQueries.value[destination.join(":")] = ["tier", "rarity"].includes(
    path.action,
  )
    ? ""
    : path.label;
  window.scrollTo({ top: 0, behavior: "auto" });
}
function openTrade(intent) {
  tradeIntent.value = { ...intent, sequence: Date.now() };
  activePanel.value = "trade";
  activeSubPanels.value = { ...activeSubPanels.value, trade: "marketplace" };
  searchQueries.value["trade:marketplace"] = "";
  window.scrollTo({ top: 0, behavior: "auto" });
}
function showResult() {
  activePanel.value = "progress";
  activeSubPanels.value = { ...activeSubPanels.value, progress: "result" };
  window.scrollTo({ top: 0, behavior: "auto" });
}
onBeforeUnmount(() => {
  autoRepeat.value = false;
});
</script>
<template>
  <section class="page">
    <DiscordAccount />
    <div
      v-if="actionError || evergatherError"
      role="alert"
      class="notice flex items-center justify-between gap-3 ui-notice"
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
      v-else-if="
        !player &&
        ![...(evergather?.db.myDiscordAccount.iter() ?? [])][0]?.needsSignIn
      "
      class="surface create-character ui-panel ui-panel--flush"
      @submit.prevent="createCharacter"
    >
      <p class="eyebrow">Datacrypt</p>
      <h1>Evergather</h1>
      <h2 class="ui-section-title">Create your character</h2>
      <label class="ui-field-label"
        >Character name<TextInput
          v-model="newName"
          type="text"
          class="rounded-md border-border bg-canvas-2 text-sm text-primary focus:border-focus focus:ring-focus"
          required
          minlength="2"
          maxlength="40"
          autocomplete="nickname" /></label
      ><button
        class="button gold"
        :disabled="actionProcessing || !evergatherConnected"
      >
        Start your adventure
      </button>
    </form>
    <template v-else-if="player">
      <fieldset
        class="evergather-ui connection-gate"
        :disabled="!evergatherConnected"
      >
        <header class="game-hud">
          <div class="game-hud__identity">
            <h1>Evergather</h1>
            <span>{{ player.display_name }}</span>
          </div>
          <div class="game-hud__stats">
            <span
              v-for="buff in view.active_buffs"
              :key="buff.family"
              :title="buff.name"
              >{{ buff.description }}</span
            >
            <strong>{{ player.gold }}g</strong
            ><span :class="{ 'text-success': player.can_act_now }">{{
              cooldownText
            }}</span
            ><span v-if="quickSkill"
              >{{ quickSkill.label }} Lv {{ quickSkill.level }}
              <span v-if="quickSkill.experience_to_next_level !== null"
                >· {{ quickSkill.experience_to_next_level }} XP to next</span
              ></span
            ><span
              v-if="quickTool"
              :class="{ 'text-danger': quickTool.is_broken }"
              >{{ quickTool.item_name }} · {{ quickTool.durability }}/{{
                quickTool.max_durability
              }}</span
            >
          </div>
          <div
            v-if="quickSkill"
            class="game-hud__progress"
            :aria-label="quickSkill.label + ' level progress'"
          >
            <span :style="{ width: skillPercent + '%' }" />
          </div>
        </header>
        <nav class="game-navigation" aria-label="Evergather command board">
          <div
            class="game-navigation__workspaces"
            aria-label="Evergather workspaces"
          >
            <button
              v-for="tab in workspaceTabs"
              :key="tab.key"
              class="app-btn app-btn--ghost"
              :aria-pressed="activePanel === tab.key"
              @click="selectWorkspaceTab(tab.key)"
            >
              {{ tab.label }}<span v-if="tab.count">{{ tab.count }}</span>
            </button>
          </div>
          <div class="game-navigation__secondary">
            <div
              v-if="activeWorkspaceSubTabs.length > 1"
              class="game-navigation__sections"
              aria-label="Evergather sections"
            >
              <button
                v-for="tab in activeWorkspaceSubTabs"
                :key="tab.key"
                class="app-btn app-btn--ghost app-btn--sm"
                :aria-pressed="activeSubPanel === tab.key"
                @click="selectSubPanel(tab.key)"
              >
                {{ tab.label }}
              </button>
            </div>
            <PanelSearch
              v-if="
                activePanel !== 'overview' &&
                (activePanel !== 'progress' || activeSubPanel === 'skills')
              "
              v-model="searchQuery"
              :label="
                activePanel === 'gear'
                  ? 'gear'
                  : activePanel === 'bag'
                    ? 'bag'
                    : activeSubPanel
              "
            />
          </div>
          <details
            v-if="favorites.length || recentCommands.length"
            class="game-quick-links"
          >
            <summary>Favorites & recent</summary>
            <div>
              <button
                v-for="command in favorites"
                :key="'fav-' + commandId(command)"
                class="app-btn app-btn--ghost app-btn--sm"
                @click="openQuickCommand(command)"
              >
                ★ {{ command.label }}</button
              ><button
                v-for="command in recentCommands.filter(
                  (value) =>
                    !favorites.some(
                      (fav) => commandId(fav) === commandId(value),
                    ),
                )"
                :key="commandId(command)"
                class="app-btn app-btn--ghost app-btn--sm"
                @click="openQuickCommand(command)"
              >
                {{ command.label }}
              </button>
            </div>
          </details>
        </nav>
        <p
          v-if="!evergatherPanelReady"
          class="surface-section p-5 ui-panel ui-panel--flush"
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
            class="surface-section xl:col-span-2 ui-panel ui-panel--flush"
          >
            <div class="surface-section__header ui-panel-heading">
              <div class="surface-section__copy">
                <span class="surface-section__title ui-section-title">{{
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

            <div class="surface-section__body ui-panel-body">
              <div class="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface p-4">
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
                    <label class="grid gap-1 ui-field-label">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3 ui-field-label"
                        >Name</span
                      >
                      <input
                        v-model="characterForm.display_name"
                        type="text"
                        class="rounded-md border-border bg-canvas-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      />
                      <span
                        v-if="characterForm.errors.display_name"
                        class="text-xs text-danger"
                      >
                        {{ characterForm.errors.display_name }}
                      </span>
                    </label>

                    <label class="grid gap-1 ui-field-label">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3 ui-field-label"
                        >Title</span
                      >
                      <input
                        v-model="characterForm.title"
                        type="text"
                        class="rounded-md border-border bg-canvas-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      />
                      <span
                        v-if="characterForm.errors.title"
                        class="text-xs text-danger"
                      >
                        {{ characterForm.errors.title }}
                      </span>
                    </label>

                    <label class="grid gap-1 ui-field-label">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3 ui-field-label"
                        >Species</span
                      >
                      <SelectInput
                        v-model="characterForm.species"
                        class="rounded-md border-border bg-canvas-2 text-sm text-primary focus:border-focus focus:ring-focus"
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

                    <label class="grid gap-1 ui-field-label">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3 ui-field-label"
                        >Pronouns</span
                      >
                      <input
                        v-model="characterForm.pronouns"
                        type="text"
                        class="rounded-md border-border bg-canvas-2 text-sm text-primary focus:border-focus focus:ring-focus"
                      />
                      <span
                        v-if="characterForm.errors.pronouns"
                        class="text-xs text-danger"
                      >
                        {{ characterForm.errors.pronouns }}
                      </span>
                    </label>

                    <label class="grid gap-1 sm:col-span-2 ui-field-label">
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3 ui-field-label"
                        >Home Region</span
                      >
                      <SelectInput
                        v-model="characterForm.home_region"
                        class="rounded-md border-border bg-canvas-2 text-sm text-primary focus:border-focus focus:ring-focus"
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
                      class="grid gap-1 ui-field-label"
                    >
                      <span
                        class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3 ui-field-label"
                        >{{ field.label }}</span
                      >
                      <SelectInput
                        v-model="characterForm.appearance[field.key]"
                        class="rounded-md border-border bg-canvas-2 text-sm text-primary focus:border-focus focus:ring-focus"
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
              activePanel === 'progress' && activeSubPanel === 'progression'
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
            :focus-request="
              focusedPath?.action === 'gather' ? focusedPath : null
            "
            v-if="activePanel === 'gather' && activeSubPanel === 'actions'"
            class="xl:col-span-2"
            :actions="actions"
            :player="player"
            :last-result="last_result"
            :search-term="searchQuery"
            @clear-search="searchQuery = ''"
            @recover="recover"
          />

          <SkillActivitiesPanel
            :focus-request="
              focusedPath?.action === 'activity' ? focusedPath : null
            "
            v-if="activePanel === 'gather' && activeSubPanel === 'activities'"
            class="xl:col-span-2"
            :activities="skill_activities"
            :player="player"
            :last-result="last_result"
            :search-term="searchQuery"
            @clear-search="searchQuery = ''"
            @recover="recover"
          />

          <template v-if="activePanel === 'gear'">
            <EquipmentPanel
              class="xl:col-span-2"
              :equipment="equipment"
              :tool-inventory="tool_inventory"
              :tool-rarity-upgrades="tool_rarity_upgrades"
              :tool-tier-upgrades="tool_tier_upgrades"
              :player="player"
              :material-sources="materialSources"
              :search-term="searchQuery"
              @clear-search="searchQuery = ''"
            />
          </template>

          <template
            v-if="activePanel === 'craft' && activeSubPanel === 'recipes'"
          >
            <CraftingPanel
              :focus-request="
                focusedPath?.action === 'craft' ? focusedPath : null
              "
              class="xl:col-span-2"
              :recipes="crafting_recipes"
              :player="player"
              :last-result="last_result"
              :search-term="searchQuery"
              @clear-search="searchQuery = ''"
              @recover="recover"
            />
          </template>

          <template v-if="activePanel === 'craft' && activeSubPanel === 'jobs'">
            <JobsPanel
              :focus-request="
                focusedPath?.action === 'job' ? focusedPath : null
              "
              class="xl:col-span-2"
              :jobs="jobs"
              :last-result="last_result"
              :search-term="searchQuery"
              @clear-search="searchQuery = ''"
              @recover="recover"
            />
          </template>

          <template
            v-if="activePanel === 'craft' && activeSubPanel === 'expeditions'"
          >
            <ExpeditionsPanel
              :focus-request="
                focusedPath?.action === 'expedition' ? focusedPath : null
              "
              class="xl:col-span-2"
              :expeditions="expeditions"
              :last-result="last_result"
              :search-term="searchQuery"
              @clear-search="searchQuery = ''"
              @recover="recover"
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
              @clear-search="searchQuery = ''"
              :intent="tradeIntent"
            />
          </template>

          <template v-if="activePanel === 'trade' && activeSubPanel === 'shop'">
            <ShopPanel
              :focus-request="
                focusedPath?.action === 'shop' ? focusedPath : null
              "
              class="xl:col-span-2"
              :shop="shop"
              :search-term="searchQuery"
              @clear-search="searchQuery = ''"
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

          <InventoryPanel
            v-if="activePanel === 'bag'"
            :guide="item_guide"
            :buffs="view.active_buffs"
            :search-term="searchQuery"
            @clear-search="searchQuery = ''"
            @recover="recover"
            @navigate="navigatePath"
            @trade="openTrade"
          />

          <template
            v-if="activePanel === 'progress' && activeSubPanel === 'recent'"
          >
            <section
              class="surface-section xl:col-span-2 ui-panel ui-panel--flush"
            >
              <div class="surface-section__header ui-panel-heading">
                <div class="surface-section__copy">
                  <span class="surface-section__title ui-section-title"
                    >Recent Actions</span
                  >
                  <p class="surface-section__subtitle">
                    {{ summary.action_count }} logged actions.
                  </p>
                </div>
              </div>

              <div class="surface-section__body ui-panel-body">
                <div class="grid gap-3">
                  <p class="text-xs text-muted-2">
                    {{ recent_actions.length }} recent ·
                    {{ recentActionExperience }} XP · {{ recentActionGold }}g
                    net
                  </p>
                  <div v-if="recent_actions.length" class="grid gap-3">
                    <article
                      v-for="entry in recent_actions"
                      :key="entry.id"
                      class="grid gap-3 rounded-md border border-border bg-surface px-3 py-3 md:grid-cols-[minmax(0,1fr)_auto]"
                    >
                      <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                          <p class="min-w-0 text-sm font-ui text-primary">
                            {{ resultFeedback(entry) }}
                          </p>
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
                        <p class="text-xs text-muted-2">
                          <time :datetime="entry.created_at">{{
                            new Date(entry.created_at).toLocaleString()
                          }}</time>
                        </p>
                      </div>
                    </article>
                  </div>

                  <p
                    v-else
                    class="rounded-md border border-border bg-surface px-3 py-3 text-sm text-muted-2"
                  >
                    No actions yet.
                  </p>
                </div>
              </div>
            </section>
          </template>
        </div>
        <RecoveryDialog
          v-if="recoveryRequest"
          :request="recoveryRequest"
          :sources="materialSources"
          :player="player"
          @close="recoveryRequest = null"
        />
        <footer class="game-action-bar" aria-label="Current action">
          <div v-if="quickAction" class="game-action-bar__controls">
            <div class="min-w-0">
              <strong :title="quickAction.label">{{
                quickAction.label
              }}</strong>
              <p>
                {{
                  autoRepeat ? "Auto running" : quickUnavailable || cooldownText
                }}
              </p>
            </div>
            <button
              class="app-btn app-btn--primary app-btn--sm"
              :disabled="
                actionProcessing ||
                Boolean(quickUnavailable) ||
                !player.can_act_now
              "
              @click="repeatSelected"
            >
              Repeat once</button
            ><button
              class="app-btn app-btn--ghost app-btn--sm"
              :aria-pressed="autoRepeat"
              :disabled="!autoRepeat && Boolean(quickUnavailable)"
              @click="toggleAuto"
            >
              {{ autoRepeat ? "Stop" : "Auto" }}</button
            ><button
              class="app-btn app-btn--ghost app-btn--sm"
              :aria-pressed="isFavorite"
              :aria-label="isFavorite ? 'Remove favorite' : 'Save favorite'"
              @click="toggleFavorite(quickAction)"
            >
              <span aria-hidden="true">{{ isFavorite ? "★" : "☆" }}</span
              ><span class="game-favorite-label">{{
                isFavorite ? "Saved" : "Save"
              }}</span></button
            ><button
              v-if="
                quickUnavailable &&
                quickEntry &&
                ['craft', 'job', 'expedition'].includes(quickAction.action)
              "
              class="app-btn app-btn--ghost app-btn--sm"
              @click="
                recover({ action: quickAction.action, key: quickAction.key })
              "
            >
              Get supplies</button
            ><button
              v-else-if="quickUnavailable && quickTool?.is_broken"
              class="app-btn app-btn--ghost app-btn--sm"
              @click="recover({ action: 'repair', key: quickTool.tool_id })"
            >
              Repair tool
            </button>
          </div>
          <div v-if="feedback" class="game-action-bar__result">
            <p role="status" aria-live="polite">{{ feedback }}</p>
            <button
              class="app-btn app-btn--ghost app-btn--sm"
              @click="showResult"
            >
              Details
            </button>
          </div>
          <p
            v-if="autoRepeatNotice"
            role="status"
            class="game-action-bar__notice"
          >
            {{ autoRepeatNotice }}
          </p>
          <p v-if="!quickAction && !feedback" class="game-action-bar__notice">
            Choose an action to start. Its repeat controls will stay here.
          </p>
        </footer>
      </fieldset>
    </template>
  </section>
</template>
<style scoped>
.connection-gate {
  min-width: 0;
  border: 0;
  margin: 0;
  padding: 0 0 180px;
}
</style>
