<script setup>
import { computed, nextTick, ref } from "vue";
import BoardLayout from "./BoardLayout.vue";
import { useActionForm } from "../../evergather/useActionForm";
const props = defineProps({
  guide: { type: Object, required: true },
  buffs: { type: Array, default: () => [] },
  searchTerm: { type: String, default: "" },
});
const emit = defineEmits(["clear-search", "recover", "navigate", "trade"]);
const category = ref("All");
const selectedKey = ref("");
const dialog = ref(null);
const form = useActionForm({ item_key: "" });
function useItem() {
  form.item_key = selected.value.item_key;
  form.submit("use", { onSuccess: () => dialog.value.close() });
}
const selected = computed(() =>
  props.guide.owned.find((item) => item.item_key === selectedKey.value),
);
const filters = computed(() => [
  { key: "All", label: "All items" },
  ...props.guide.owned_categories.map((value) => ({
    key: value.key,
    label: value.label,
  })),
]);
const entries = computed(() =>
  props.guide.owned
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
  <section class="surface-section xl:col-span-2">
    <div class="surface-section__header">
      <span class="surface-section__title">Bag</span>
      <p class="surface-section__subtitle">
        {{ guide.summary.owned_items }} item types
      </p>
    </div>
    <div class="surface-section__body">
      <p v-for="buff in buffs" :key="buff.family" class="game-notice">
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
            v-for="item in entries"
            :key="item.item_key"
            class="game-inventory__item"
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
        @click="$event.target === dialog && dialog.close()"
      >
        <template v-if="selected">
          <header class="game-dialog__header">
            <strong id="bag-item-title">{{ selected.item_name }}</strong
            ><button
              class="app-btn app-btn--ghost app-btn--sm"
              @click="dialog.close()"
            >
              Close
            </button>
          </header>
          <div class="game-dialog__body grid gap-4">
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
                v-if="selected.consumable"
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
                v-if="selected.tradeable"
                class="app-btn app-btn--ghost app-btn--sm"
                @click="trade('vendor')"
              >
                Sell to vendor</button
              ><button
                v-if="selected.tradeable"
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
            <div v-if="selected.sinks.length">
              <strong>Use this item</strong>
              <div class="grid gap-2 mt-2">
                <button
                  v-for="path in selected.sinks"
                  :key="path.action + path.key"
                  class="game-path"
                  @click="navigate(path)"
                >
                  {{ path.label }}
                  <span>{{ path.type }} · Lv {{ path.required_level }}</span>
                </button>
              </div>
            </div>
            <p v-else-if="!selected.consumable" class="text-sm text-muted-2">
              No crafting or commission uses are known yet.
            </p>
            <details class="game-tool-details">
              <summary>Where to get it</summary>
              <div class="game-tool-details__body">
                <button
                  v-for="path in selected.sources"
                  :key="path.action + path.key"
                  class="game-path"
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
