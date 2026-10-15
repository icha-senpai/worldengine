<template>
  <section class="surface-section xl:col-span-2 ui-panel ui-panel--flush">
    <div class="surface-section__header ui-panel-heading">
      <span class="surface-section__title ui-section-title">Gear</span>
      <p class="surface-section__subtitle">
        {{ equipment.length }} equipped · {{ storedTools.length }} stored
      </p>
    </div>
    <div class="surface-section__body ui-panel-body">
      <BoardLayout
        :filters="categoryFilters"
        v-model:filter="selectedCategory"
        :search-term="searchTerm"
        label="Tool category"
        @clear="clearFilters"
      >
        <template #filters
          ><p>
            {{ toolTierUpgrades.ready_count ?? 0 }} tier upgrades ready ·
            {{ toolRarityUpgrades.ready_count ?? 0 }} rarity upgrades ready
          </p>
          <p class="mt-3 text-muted-2">
            Loadout bonuses: +{{ totalExperience }} XP · +{{ totalYield }} yield
          </p></template
        >
        <div class="grid gap-3">
          <article
            v-for="entry in displayedEquipment"
            :key="entry.tool.tool_id"
            class="game-tool-row ui-panel ui-panel--compact"
          >
            <div class="game-tool-row__summary">
              <div class="min-w-0">
                <p class="font-ui text-primary">{{ entry.tool.item_name }}</p>
                <p class="text-sm text-muted-2">
                  {{ entry.tool.slot_label }} ·
                  {{
                    entry.tool.tier_level
                      ? "Tier " +
                        entry.tool.item_tier +
                        " · Lv " +
                        entry.tool.tier_level
                      : "Starter"
                  }}
                  <span class="tag" :data-rarity="entry.tool.rarity">{{
                    entry.tool.rarity
                  }}</span>
                </p>
              </div>
              <div
                class="game-durability"
                :class="{ 'game-durability--broken': entry.tool.is_broken }"
                :aria-label="'Durability ' + durabilityBarLabel(entry.tool)"
              >
                <span>{{
                  entry.tool.is_broken ? "Broken" : durabilityLabel(entry.tool)
                }}</span>
                <div>
                  <span
                    :style="{ width: durabilityPercent(entry.tool) + '%' }"
                  />
                </div>
              </div>
              <span class="text-sm text-muted-2"
                >+{{ entry.tool.experience_bonus }} XP · +{{
                  entry.tool.yield_bonus
                }}
                yield</span
              >
            </div>
            <div class="game-tool-row__actions">
              <button
                v-if="repairPayload(entry.tool)?.is_repairable"
                class="app-btn app-btn--primary app-btn--sm"
                :disabled="inventoryForm.processing"
                @click="requestRepair(entry.tool)"
              >
                {{ repairActionLabel(entry.tool) }}
              </button>
              <button
                v-if="entry.tier && !entry.tier.is_max_tier"
                class="app-btn app-btn--ghost app-btn--sm"
                :disabled="tierForm.processing"
                @click="requestTierUpgrade(entry)"
              >
                Tier Up
              </button>
              <button
                v-if="
                  entry.upgrade &&
                  !entry.upgrade.is_max_rarity &&
                  !entry.upgrade.is_tier_capped
                "
                class="app-btn app-btn--ghost app-btn--sm"
                :disabled="rarityForm.processing"
                @click="requestRarityUpgrade(entry)"
              >
                Rarity Upgrade
              </button>
            </div>
            <details class="game-tool-details">
              <summary>Tool details</summary>
              <div class="game-tool-details__body">
                <p v-if="entry.tool.origin_label" class="text-sm text-muted-2">
                  {{ entry.tool.origin_label
                  }}<span v-if="entry.tool.maker_name">
                    · made by {{ entry.tool.maker_name }}</span
                  >
                </p>
                <div v-for="perk in entry.tool.perks" :key="perk.key">
                  <strong>{{ perk.label }}</strong>
                  <p class="text-sm text-muted-2">{{ perk.description }}</p>
                </div>
                <p v-if="entry.tier">
                  Next tier:
                  {{
                    entry.tier.is_max_tier
                      ? "Highest tier reached"
                      : entry.tier.next_item_name +
                        " · " +
                        entry.tier.gold_cost +
                        "g"
                  }}
                </p>
                <p v-if="entry.upgrade">
                  Rarity: {{ entry.upgrade.status }} ·
                  {{ entry.upgrade.rarity_progress }}% progress<span
                    v-if="!entry.upgrade.is_max_rarity"
                  >
                    · {{ entry.upgrade.success_chance }}% success chance ·
                    {{ entry.upgrade.gold_cost }}g per attempt</span
                  >
                </p>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-if="entry.tool.origin !== 'starter'"
                    class="app-btn app-btn--ghost app-btn--sm"
                    :disabled="inventoryForm.processing"
                    @click="unequipTool(entry.tool.slot)"
                  >
                    Unequip
                  </button>
                  <button
                    v-if="entry.tool.tool_lifecycle?.salvage?.can_salvage"
                    class="app-btn app-btn--ghost app-btn--sm"
                    :disabled="inventoryForm.processing"
                    @click="
                      salvageTool(entry.tool.tool_id, entry.tool.item_name)
                    "
                  >
                    Salvage
                  </button>
                  <button
                    v-if="entry.tool.tool_lifecycle?.can_retire"
                    class="app-btn app-btn--ghost app-btn--sm text-danger"
                    :disabled="inventoryForm.processing"
                    @click="
                      retireTool(entry.tool.tool_id, entry.tool.item_name)
                    "
                  >
                    Retire
                  </button>
                </div>
              </div>
            </details>
          </article>
          <button
            v-if="
              displayedEquipment.length < visibleEquipmentWithUpgrades.length
            "
            class="app-btn app-btn--ghost app-btn--sm justify-self-center"
            @click="equipmentLimit += boardPageSize"
          >
            Show More
          </button>
          <p v-if="!visibleEquipmentWithUpgrades.length" class="game-empty">
            No tools match. Clear filters or choose another category.
          </p>
        </div>
      </BoardLayout>
      <p
        v-if="
          rarityForm.errors.slot ||
          tierForm.errors.slot ||
          inventoryForm.errors._error
        "
        class="mt-3 text-danger"
        role="alert"
      >
        {{
          rarityForm.errors.slot ||
          tierForm.errors.slot ||
          inventoryForm.errors._error
        }}
      </p>
      <details v-if="storedTools.length" class="game-tool-details mt-5" open>
        <summary>Stored tools · {{ storedTools.length }}</summary>
        <div class="game-tool-details__body grid gap-3 md:grid-cols-2">
          <article
            v-for="tool in displayedInventoryTools"
            :key="tool.tool_id"
            class="game-tool-row ui-panel ui-panel--compact"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <strong>{{ tool.item_name }}</strong
              ><span class="tag" :data-rarity="tool.rarity">{{
                tool.rarity
              }}</span>
            </div>
            <p class="text-sm text-muted-2">
              {{ tool.slot_label }} · +{{ tool.experience_bonus }} XP · +{{
                tool.yield_bonus
              }}
              yield · {{ durabilityLabel(tool) }} durability
            </p>
            <button
              class="app-btn app-btn--primary app-btn--sm"
              :disabled="inventoryForm.processing || tool.can_equip === false"
              @click="equipTool(tool.tool_id)"
            >
              {{
                tool.can_equip === false
                  ? "Needs Lv " + tool.equip_required_level
                  : "Equip"
              }}
            </button>
            <details class="game-tool-details">
              <summary>Manage stored tool</summary>
              <div class="game-tool-details__body flex flex-wrap gap-2">
                <button
                  v-if="repairPayload(tool)?.is_repairable"
                  class="app-btn app-btn--ghost app-btn--sm"
                  @click="requestRepair(tool)"
                >
                  Repair</button
                ><button
                  v-if="tool.tool_lifecycle?.salvage?.can_salvage"
                  class="app-btn app-btn--ghost app-btn--sm"
                  @click="salvageTool(tool.tool_id, tool.item_name)"
                >
                  Salvage</button
                ><button
                  v-if="tool.tool_lifecycle?.can_retire"
                  class="app-btn app-btn--ghost app-btn--sm text-danger"
                  @click="retireTool(tool.tool_id, tool.item_name)"
                >
                  Retire
                </button>
              </div>
            </details>
          </article>
          <button
            v-if="displayedInventoryTools.length < inventoryTools.length"
            class="app-btn app-btn--ghost app-btn--sm"
            @click="inventoryLimit += boardPageSize"
          >
            Show More Stored Tools
          </button>
          <p v-if="!inventoryTools.length">No stored tools match.</p>
        </div>
      </details>
    </div>
    <MaterialsDialog
      v-if="suppliesTarget"
      :target="suppliesTarget"
      :sources="materialSources"
      :player="player"
      :error="
        suppliesRequest.action === 'tier'
          ? tierForm.errors.slot
          : suppliesRequest.action === 'rarity'
            ? rarityForm.errors.slot
            : inventoryForm.errors.tool_id
      "
      @close="suppliesRequest = null"
      @complete="completeSuppliesRequest"
    />
  </section>
</template>
<script setup>
import BoardLayout from "./BoardLayout.vue";
const emit = defineEmits(["clear-search"]);
function clearFilters() {
  selectedCategory.value = "All";
  emit("clear-search");
}
import { computed, ref, watch } from "vue";
import { useActionForm } from "../../evergather/useActionForm";
import { usePersistedPanelState } from "./usePanelState";
import MaterialsDialog from "./MaterialsDialog.vue";

const props = defineProps({
  player: { type: Object, required: true },
  materialSources: { type: Object, required: true },
  searchTerm: {
    type: String,
    default: "",
  },
  equipment: {
    type: Array,
    required: true,
  },
  toolInventory: {
    type: Array,
    required: true,
  },
  toolRarityUpgrades: {
    type: Object,
    required: true,
  },
  toolTierUpgrades: {
    type: Object,
    required: true,
  },
});

const { selectedCategory } = usePersistedPanelState(
  "evergather.equipment-board-state",
  {
    selectedCategory: "All",
  },
);
const runningEquipmentAction = ref("");
const suppliesRequest = ref(null);
const suppliesTarget = computed(() => {
  const request = suppliesRequest.value;
  if (!request) return null;
  const tool = [...props.equipment, ...props.toolInventory].find(
    (tool) => tool.tool_id === request.toolId,
  );
  if (!tool) return null;
  if (request.action === "repair") {
    const repair = repairPayload(tool);
    if (!repair) return null;
    return {
      label: "Repair",
      toolName: tool.item_name,
      materials: repair.materials,
      goldCost: repair.gold_cost,
      ready: repair.can_repair,
      status:
        repair.missing_durability <= 0
          ? "Fully repaired"
          : !repair.is_repairable
            ? "Repair unavailable"
            : repair.missing_materials
              ? "Need Materials"
              : !repair.has_gold
                ? `Need ${repair.gold_cost}g`
                : "Ready",
    };
  }
  if (tool.status !== "equipped") return null;
  if (request.action === "rarity") {
    const upgrade = upgradesBySlot.value[tool.slot];
    if (!upgrade) return null;
    const sameRarity = tool.rarity === request.rarity;
    return {
      label: "Rarity Upgrade",
      actionLabel: "Attempt Rarity Upgrade",
      toolName: tool.item_name,
      materials: upgrade.materials,
      goldCost: upgrade.gold_cost,
      details: `${upgrade.current_rarity} → ${upgrade.target_rarity ?? "max rarity"} · ${upgrade.success_chance}% success chance · unsuccessful attempts add ${upgrade.progress_gain_min}–${upgrade.progress_gain_max}% progress. Materials and gold are consumed per attempt.`,
      ready: sameRarity && upgrade.can_upgrade,
      status: sameRarity
        ? upgrade.status
        : "Tool rarity already changed. Close this window to select its next upgrade.",
    };
  }
  const tier = tierUpgradesBySlot.value[tool.slot];
  if (!tier) return null;
  const sameTier = tool.tier_level === request.tierLevel;
  return {
    label: "Tier Up",
    toolName: tool.item_name,
    materials: tier.ingredients,
    goldCost: tier.gold_cost,
    details: `${tier.next_item_name} · ${tier.craft_skill_label} Lv ${tier.required_level} · +${tier.experience_awarded} XP`,
    ready: sameTier && tier.can_upgrade,
    status: sameTier
      ? tier.status
      : "Tool already upgraded. Close this window to select its next tier.",
  };
});
const boardPageSize = 12;
const equipmentLimit = ref(boardPageSize);
const inventoryLimit = ref(boardPageSize);
const totalExperience = computed(() =>
  props.equipment.reduce((total, tool) => total + tool.experience_bonus, 0),
);
const totalYield = computed(() =>
  props.equipment.reduce((total, tool) => total + tool.yield_bonus, 0),
);
const rarityForm = useActionForm({
  slot: null,
});
const tierForm = useActionForm({
  slot: null,
});
const inventoryForm = useActionForm({
  tool_id: null,
  slot: null,
});
const upgradesBySlot = computed(() =>
  Object.fromEntries(
    (props.toolRarityUpgrades.options ?? []).map((upgrade) => [
      upgrade.slot,
      upgrade,
    ]),
  ),
);
const tierUpgradesBySlot = computed(() =>
  Object.fromEntries(
    (props.toolTierUpgrades.options ?? []).map((upgrade) => [
      upgrade.slot,
      upgrade,
    ]),
  ),
);
const equipmentWithUpgrades = computed(() =>
  props.equipment.map((tool) => ({
    tool,
    upgrade: upgradesBySlot.value[tool.slot] ?? null,
    tier: tierUpgradesBySlot.value[tool.slot] ?? null,
  })),
);
const categoryFilters = computed(() =>
  [
    "All",
    ...new Set(props.equipment.map((tool) => tool.category).filter(Boolean)),
  ].map((filter) => ({
    key: filter,
    label: filter,
    count: props.equipment.filter(
      (tool) => filter === "All" || tool.category === filter,
    ).length,
  })),
);
const visibleEquipmentWithUpgrades = computed(() =>
  equipmentWithUpgrades.value
    .filter(
      (entry) =>
        (selectedCategory.value === "All" ||
          entry.tool.category === selectedCategory.value) &&
        matchesTool(entry.tool),
    )
    .sort(
      (a, b) =>
        Number(b.tool.is_broken) - Number(a.tool.is_broken) ||
        Number(Boolean(b.tier?.can_upgrade || b.upgrade?.can_upgrade)) -
          Number(Boolean(a.tier?.can_upgrade || a.upgrade?.can_upgrade)) ||
        a.tool.slot.localeCompare(b.tool.slot),
    ),
);
const storedTools = computed(() =>
  props.toolInventory.filter((tool) => tool.status === "inventory"),
);
const inventoryTools = computed(() =>
  storedTools.value.filter(
    (tool) =>
      (selectedCategory.value === "All" ||
        tool.category === selectedCategory.value) &&
      matchesTool(tool),
  ),
);
const displayedEquipment = computed(() =>
  visibleEquipmentWithUpgrades.value.slice(0, equipmentLimit.value),
);
const displayedInventoryTools = computed(() =>
  inventoryTools.value.slice(0, inventoryLimit.value),
);

watch([selectedCategory, () => props.searchTerm], () => {
  equipmentLimit.value = boardPageSize;
  inventoryLimit.value = boardPageSize;
});

function matchesTool(tool) {
  const query = props.searchTerm.trim().toLowerCase();
  return (
    !query ||
    [
      tool.item_name,
      tool.skill,
      tool.slot_label,
      tool.category,
      tool.rarity,
      tool.origin_label,
      tool.signature_trait,
      tool.discipline,
      ...(tool.perks ?? []).flatMap((perk) => [perk.label, perk.description]),
    ]
      .filter(Boolean)
      .join(" ")
      .toLowerCase()
      .includes(query)
  );
}

watch(
  categoryFilters,
  () => {
    if (
      !categoryFilters.value.some(
        (filter) => filter.key === selectedCategory.value,
      )
    ) {
      selectedCategory.value = "All";
    }
  },
  { immediate: true },
);

function filterProgress(filter) {
  if (!filter.count) {
    return 0;
  }

  const upgradeReadyCount = equipmentWithUpgrades.value.filter(
    (entry) =>
      (filter.key === "All" || entry.tool.category === filter.key) &&
      (entry.upgrade?.can_upgrade || entry.tier?.can_upgrade),
  ).length;

  return Math.round((upgradeReadyCount / filter.count) * 100);
}

function durabilityPercent(tool) {
  if (tool?.durability_percent !== undefined) {
    return tool.durability_percent;
  }

  const maxDurability = Number(tool?.max_durability ?? 100);

  if (maxDurability <= 0) {
    return 0;
  }

  return Math.max(
    0,
    Math.min(
      100,
      Math.round((Number(tool?.durability ?? 0) / maxDurability) * 100),
    ),
  );
}

function durabilityLabel(tool) {
  const maxDurability = tool?.max_durability ?? 100;

  return `${tool?.durability ?? 0}/${maxDurability}`;
}

function durabilityBarLabel(tool) {
  return `${durabilityLabel(tool)} · ${durabilityPercent(tool)}%`;
}

function repairPayload(tool) {
  return tool?.tool_lifecycle?.repair ?? null;
}

function repairMaterials(tool) {
  return repairPayload(tool)?.materials ?? [];
}

function showRepairCost(tool) {
  const repair = repairPayload(tool);

  return Boolean(
    repair &&
    ((repair.is_repairable ?? repair.can_repair) ||
      repair.missing_durability > 0),
  );
}

function canRepairTool(tool) {
  return Boolean(repairPayload(tool)?.can_repair);
}

function requestRepair(tool) {
  inventoryForm.errors = {};
  if (canRepairTool(tool)) {
    repairTool(tool.tool_id);
    return;
  }
  suppliesRequest.value = { action: "repair", toolId: tool.tool_id };
}

function requestRarityUpgrade(entry) {
  rarityForm.errors = {};
  if (entry.upgrade.is_max_rarity || entry.upgrade.is_tier_capped) return;
  suppliesRequest.value = {
    action: "rarity",
    toolId: entry.tool.tool_id,
    slot: entry.tool.slot,
    rarity: entry.tool.rarity,
  };
}

function requestTierUpgrade(entry) {
  tierForm.errors = {};
  suppliesRequest.value = {
    action: "tier",
    toolId: entry.tool.tool_id,
    slot: entry.tool.slot,
    tierLevel: entry.tool.tier_level,
  };
}

function completeSuppliesRequest() {
  if (!suppliesTarget.value?.ready) return;
  const request = suppliesRequest.value;
  if (request.action === "repair") repairTool(request.toolId);
  else if (request.action === "rarity") attemptRarityUpgrade(request.slot);
  else attemptTierUpgrade(request.slot);
}

function repairMaterialLabel(material) {
  if (material.owned_quantity === undefined) {
    return `${material.quantity} ${material.item_name}`;
  }

  const neededQuantity = Number(material.quantity);
  const ownedQuantity = Math.min(
    Number(material.owned_quantity),
    neededQuantity,
  );

  return `${ownedQuantity}/${neededQuantity} ${material.item_name}`;
}

function repairActionLabel(tool) {
  if (
    runningEquipmentAction.value === equipmentActionKey("repair", tool.tool_id)
  ) {
    return "Repairing...";
  }

  return "Repair";
}

function attemptRarityUpgrade(slot) {
  rarityForm.slot = slot;
  rarityForm.submit("rarity", {
    preserveScroll: true,
    onSuccess: () => {
      suppliesRequest.value = null;
    },
    onStart: () => {
      runningEquipmentAction.value = equipmentActionKey("rarity", slot);
    },
    onFinish: () => {
      runningEquipmentAction.value = "";
    },
  });
}

function attemptTierUpgrade(slot) {
  tierForm.slot = slot;
  tierForm.submit("tier", {
    preserveScroll: true,
    onSuccess: () => {
      suppliesRequest.value = null;
    },
    onStart: () => {
      runningEquipmentAction.value = equipmentActionKey("tier", slot);
    },
    onFinish: () => {
      runningEquipmentAction.value = "";
    },
  });
}

function equipTool(toolId) {
  inventoryForm.tool_id = toolId;
  inventoryForm.slot = null;
  inventoryForm.submit("equip", {
    preserveScroll: true,
    onStart: () => {
      runningEquipmentAction.value = equipmentActionKey("equip", toolId);
    },
    onFinish: () => {
      runningEquipmentAction.value = "";
    },
  });
}

function unequipTool(slot) {
  inventoryForm.slot = slot;
  inventoryForm.tool_id = null;
  inventoryForm.submit("unequip", {
    preserveScroll: true,
    onStart: () => {
      runningEquipmentAction.value = equipmentActionKey("unequip", slot);
    },
    onFinish: () => {
      runningEquipmentAction.value = "";
    },
  });
}

function repairTool(toolId) {
  inventoryForm.tool_id = toolId;
  inventoryForm.slot = null;
  inventoryForm.submit("repair", {
    preserveScroll: true,
    onSuccess: () => {
      suppliesRequest.value = null;
    },
    onStart: () => {
      runningEquipmentAction.value = equipmentActionKey("repair", toolId);
    },
    onFinish: () => {
      runningEquipmentAction.value = "";
    },
  });
}

function salvageTool(toolId, itemName) {
  if (!window.confirm(`Salvage ${itemName}?`)) {
    return;
  }

  inventoryForm.tool_id = toolId;
  inventoryForm.slot = null;
  inventoryForm.submit("salvage", {
    preserveScroll: true,
    onStart: () => {
      runningEquipmentAction.value = equipmentActionKey("salvage", toolId);
    },
    onFinish: () => {
      runningEquipmentAction.value = "";
    },
  });
}

function retireTool(toolId, itemName) {
  if (!window.confirm(`Retire ${itemName}?`)) {
    return;
  }

  inventoryForm.tool_id = toolId;
  inventoryForm.slot = null;
  inventoryForm.submit("retire", {
    preserveScroll: true,
    onStart: () => {
      runningEquipmentAction.value = equipmentActionKey("retire", toolId);
    },
    onFinish: () => {
      runningEquipmentAction.value = "";
    },
  });
}

function equipmentActionKey(action, value) {
  return `${action}:${value}`;
}
</script>
