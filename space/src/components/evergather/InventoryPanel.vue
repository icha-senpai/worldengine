<script setup>
import { computed, nextTick, ref, watch, onBeforeUnmount } from "vue";
import {
  evergather,
  evergatherConnected,
  fetchItemDefinitions,
  setItemDefinitions,
} from "../../evergather";
import BoardLayout from "./BoardLayout.vue";
import { useActionForm } from "../../evergather/useActionForm";
const props = defineProps({
  guide: { type: Object, required: true },
  buffs: { type: Array, default: () => [] },
  searchTerm: { type: String, default: "" },
});
const emit = defineEmits(["clear-search", "recover", "navigate", "trade"]);
const category = ref("All");
const mode = ref("owned");
const visibleCount = ref(100);
const detailsLoading = ref(false);
const detailsError = ref("");
let detailGeneration = 0;
const selectedKey = ref("");
const dialog = ref(null);
const form = useActionForm({ item_key: "" });
function useItem() {
  form.item_key = selected.value.item_key;
  form.submit("use", { onSuccess: () => dialog.value.close() });
}
const selected = computed(
  () =>
    props.guide.owned.find((item) => item.item_key === selectedKey.value) ??
    props.guide.directory?.find((item) => item.item_key === selectedKey.value),
);
const directory = computed(() =>
  mode.value === "owned" ? props.guide.owned : (props.guide.directory ?? []),
);
const filters = computed(() => [
  { key: "All", label: "All items" },
  ...[...new Set(directory.value.map((item) => item.item_class))]
    .filter(Boolean)
    .map((key) => ({ key, label: key })),
]);
const entries = computed(() =>
  directory.value
    .filter(
      (item) =>
        (category.value === "All" || item.item_class === category.value) &&
        [item.item_name, item.material_family, item.rarity]
          .join(" ")
          .toLowerCase()
          .includes(props.searchTerm.trim().toLowerCase()),
    )
    .sort((a, b) => a.item_name.localeCompare(b.item_name)),
);
watch([mode, category, () => props.searchTerm], () => {
  visibleCount.value = 100;
});
async function loadDetails() {
  const generation = ++detailGeneration;
  if (!selectedKey.value || !evergatherConnected.value) return;
  detailsLoading.value = true;
  detailsError.value = "";
  try {
    const rows = await fetchItemDefinitions([selectedKey.value]);
    if (generation === detailGeneration)
      setItemDefinitions("bag-details", rows);
  } catch (error) {
    if (generation === detailGeneration)
      detailsError.value = error.message || "Item details could not be loaded.";
  } finally {
    if (generation === detailGeneration) detailsLoading.value = false;
  }
}
watch([selectedKey, evergather, evergatherConnected], loadDetails);
function closeDetails() {
  detailGeneration++;
  selectedKey.value = "";
  setItemDefinitions("bag-details");
}
onBeforeUnmount(closeDetails);
async function inspect(item) {
  selectedKey.value = item.item_key;
  await nextTick();
  dialog.value.showModal();
}
function navigate(path) {
  dialog.value.close();
  emit("navigate", path);
}
function acquire() {
  const item = selected.value;
  dialog.value.close();
  emit("recover", {
    action: "acquire",
    key: item.item_key,
    quantity: Number(item.owned_quantity) + 1,
  });
}
function trade(mode) {
  const item = selected.value;
  dialog.value.close();
  emit("trade", { mode, itemKey: item.item_key });
}
</script>
<template>
  <section class="surface-section xl:col-span-2 ui-panel ui-panel--flush">
    <div class="surface-section__header ui-panel-heading">
      <span class="surface-section__title ui-section-title">Bag</span>
      <p class="surface-section__subtitle">
        {{ guide.summary.owned_items }} item types
      </p>
    </div>
    <div class="surface-section__body ui-panel-body">
      <div class="flex flex-wrap gap-2 mb-4" aria-label="Inventory browsing">
        <button
          class="app-btn app-btn--ghost app-btn--sm"
          :aria-pressed="mode === 'owned'"
          @click="
            mode = 'owned';
            category = 'All';
          "
        >
          Owned items
        </button>
        <button
          class="app-btn app-btn--ghost app-btn--sm"
          :aria-pressed="mode === 'catalog'"
          @click="
            mode = 'catalog';
            category = 'All';
          "
        >
          All items · {{ guide.directory?.length ?? 0 }}
        </button>
      </div>
      <p v-for="buff in buffs" :key="buff.family" class="game-notice ui-notice">
        {{ buff.name }} · {{ buff.description }}
      </p>
      <BoardLayout
        :filters="filters"
        v-model:filter="category"
        :search-term="searchTerm"
        label="Item category"
        @clear="
          category = 'All';
          emit('clear-search');
        "
      >
        <div class="game-inventory">
          <button
            v-for="item in entries.slice(0, visibleCount)"
            :key="item.item_key"
            class="game-inventory__item ui-panel ui-panel--compact"
            @click="inspect(item)"
          >
            <span
              ><strong>{{ item.item_name }}</strong
              ><span class="tag" :data-rarity="item.rarity">{{
                item.rarity
              }}</span></span
            ><span>×{{ item.owned_quantity }}</span>
          </button>
        </div>
        <button
          v-if="entries.length > visibleCount"
          class="app-btn app-btn--ghost app-btn--sm mt-3"
          @click="visibleCount += 100"
        >
          Show more · {{ visibleCount }} of {{ entries.length }}
        </button>
        <p v-if="!entries.length" class="game-empty">
          No items match. Clear filters to see your bag.
        </p>
      </BoardLayout>
    </div>
    <Teleport to="body">
      <dialog
        ref="dialog"
        class="game-dialog"
        aria-labelledby="bag-item-title"
        @close="closeDetails"
        @click="$event.target === dialog && dialog.close()"
      >
        <template v-if="selected">
          <header class="game-dialog__header ui-panel-heading">
            <strong class="ui-dialog-title" id="bag-item-title">{{
              selected.item_name
            }}</strong
            ><button
              class="app-btn app-btn--ghost app-btn--sm"
              @click="dialog.close()"
            >
              Close
            </button>
          </header>
          <div class="game-dialog__body grid gap-4 ui-panel-body">
            <p v-if="detailsLoading" role="status">Loading sources and uses…</p>
            <p v-if="detailsError" role="alert">
              {{ detailsError }}
              <button
                class="app-btn app-btn--ghost app-btn--sm"
                @click="loadDetails"
              >
                Retry
              </button>
            </p>
            <p>
              Owned {{ selected.owned_quantity }}
              <span class="tag" :data-rarity="selected.rarity">{{
                selected.rarity
              }}</span>
            </p>
            <p class="text-sm text-muted-2">
              {{ selected.weight }} weight each · vendor
              {{ selected.npc_buy_price }}g each · market range
              {{ selected.market_price_band }}
            </p>
            <div class="flex flex-wrap gap-2">
              <button
                v-if="selected.consumable && selected.owned_quantity > 0"
                class="app-btn app-btn--primary app-btn--sm"
                :disabled="form.processing || !selected.can_use"
                @click="useItem"
              >
                {{
                  selected.can_use
                    ? "Use"
                    : "Needs Lv " + selected.consumable.required_level
                }}
              </button>
              <button
                class="app-btn app-btn--primary app-btn--sm"
                @click="acquire"
              >
                Gather / make more</button
              ><button
                v-if="selected.tradeable && selected.owned_quantity > 0"
                class="app-btn app-btn--ghost app-btn--sm"
                @click="trade('vendor')"
              >
                Sell to vendor</button
              ><button
                v-if="selected.tradeable && selected.owned_quantity > 0"
                class="app-btn app-btn--ghost app-btn--sm"
                @click="trade('sell')"
              >
                List on marketplace
              </button>
            </div>
            <p v-if="selected.consumable" class="text-sm">
              +{{ selected.consumable.percent }}%
              {{ selected.consumable.effect }} ·
              {{ selected.consumable.charges }} actions.
              {{ selected.consumable.description }}
              Replaces any active {{ selected.consumable.family }} buff.
            </p>
            <div
              v-if="!detailsLoading && !detailsError && selected.sinks.length"
            >
              <strong>Use this item</strong>
              <div class="grid gap-2 mt-2">
                <button
                  v-for="path in selected.sinks"
                  :key="path.action + path.key"
                  class="game-path ui-panel ui-panel--compact"
                  @click="navigate(path)"
                >
                  {{ path.label }}
                  <span>{{ path.type }} · Lv {{ path.required_level }}</span>
                </button>
              </div>
            </div>
            <p
              v-else-if="
                !detailsLoading && !detailsError && !selected.consumable
              "
              class="text-sm text-muted-2"
            >
              No crafting or commission uses are known yet.
            </p>
            <details
              v-if="!detailsLoading && !detailsError"
              class="game-tool-details"
              open
            >
              <summary>Where to get it</summary>
              <div class="game-tool-details__body">
                <button
                  v-for="path in selected.sources"
                  :key="path.action + path.key"
                  class="game-path ui-panel ui-panel--compact"
                  @click="navigate(path)"
                >
                  {{ path.label }}
                  <span>{{ path.type }} · Lv {{ path.required_level }}</span>
                </button>
                <p v-if="!selected.sources.length">
                  No known source. Check the marketplace.
                </p>
              </div>
            </details>
          </div>
        </template>
      </dialog>
    </Teleport>
  </section>
</template>
