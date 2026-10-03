<script setup>
import { bitcraftFetch as fetch } from "/src/bitcraft-ui/api";
import { route } from "/src/bitcraft-ui/navigation";
import { computed, onBeforeUnmount, ref, watch } from "vue";
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import TextInput from "/src/bitcraft-ui/shared/TextInput.vue";

const props = defineProps({
  items: { type: Array, default: () => [] },
  region: { type: String, default: "" },
});
const emit = defineEmits(["update:region"]);
const options = computed(() => [
  ...new Map(
    props.items
      .filter((item) => item.id || item.itemId)
      .map((item) => [
        `${item.kind || item.itemType || "item"}:${item.id || item.itemId}`,
        item,
      ]),
  ).entries(),
]);
const selected = ref("");
const data = ref(null);
const loading = ref(false);
const error = ref("");
let request;

watch(
  options,
  (value) => {
    if (!value.some(([key]) => key === selected.value))
      selected.value = value[0]?.[0] || "";
  },
  { immediate: true },
);
watch([selected, () => props.region], () => {
  request?.abort();
  data.value = null;
  error.value = "";
  loading.value = false;
});
async function load() {
  if (!selected.value) return;
  request?.abort();
  const controller = new AbortController();
  request = controller;
  const [kind, id] = selected.value.split(":");
  loading.value = true;
  error.value = "";
  data.value = null;
  try {
    const response = await fetch(
      route("bitcraft.market.order-book", {
        itemId: id,
        itemKind: kind,
        region: props.region,
      }),
      {
        headers: { Accept: "application/json" },
        signal: controller.signal,
      },
    );
    const payload = await response.json();
    if (controller.signal.aborted) return;
    if (!response.ok || !payload.orderBook)
      throw new Error(
        payload.error || "Market prices are unavailable. Try again.",
      );
    data.value = payload;
  } catch (failure) {
    if (!controller.signal.aborted)
      error.value = failure.message || "Unable to load prices.";
  } finally {
    if (request === controller) loading.value = false;
  }
}
onBeforeUnmount(() => request?.abort());
const stats = computed(() => data.value?.orderBook?.stats || {});
const updatedAt = computed(
  () => data.value?.refresh?.updatedAt || data.value?.cache?.updatedAt,
);
const number = (value) =>
  value === null || value === undefined
    ? "Unavailable"
    : new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(
        value,
      );
</script>

<template>
  <section
    class="guide-market mt-4 border-t border-border pt-4"
    aria-label="Market prices"
  >
    <div class="guide-market__controls grid gap-3">
      <label class="grid min-w-0 gap-1 text-xs text-muted-2">
        Market item
        <SelectInput v-model="selected" class="w-full">
          <option v-for="[key, item] in options" :key="key" :value="key">
            {{ item.name || item.itemName }}
          </option>
        </SelectInput>
      </label>
      <label class="grid min-w-0 gap-1 text-xs text-muted-2">
        Region
        <TextInput
          :model-value="region"
          class="w-full"
          maxlength="120"
          placeholder="All regions"
          @update:model-value="emit('update:region', $event)"
        />
      </label>
    </div>
    <button
      type="button"
      class="app-btn app-btn--ghost app-btn--sm mt-3"
      :disabled="loading || !selected"
      @click="load"
    >
      {{
        loading ? "Loading prices..." : data ? "Refresh prices" : "Load prices"
      }}
    </button>
    <p v-if="error" class="mt-3 text-sm text-muted-2" role="status">
      {{ error }}
    </p>
    <template v-if="data">
      <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
        <dt>Lowest sell</dt>
        <dd class="text-right">{{ number(stats.lowestSell) }}</dd>
        <dt>Highest buy</dt>
        <dd class="text-right">{{ number(stats.highestBuy) }}</dd>
        <dt>Sell / buy orders</dt>
        <dd class="text-right">
          {{ number(stats.sellOrderCount) }} / {{ number(stats.buyOrderCount) }}
        </dd>
      </dl>
      <p class="mt-3 text-xs text-muted-2">
        {{
          data.refresh?.delayed || data.cache?.delayed
            ? "Cached prices. Refresh delayed."
            : "Cached market prices."
        }}
        <template v-if="updatedAt">
          Updated
          <time :datetime="updatedAt">{{
            new Date(updatedAt).toLocaleString()
          }}</time
          >.</template
        >
        <template v-else> Update time unavailable.</template>
      </p>
    </template>
  </section>
</template>

<style scoped>
@container (min-width: 450px) {
  .guide-market__controls {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
