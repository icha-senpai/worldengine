<script setup>
import { useMarketBooks } from "./useMarketBooks";
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from "vue";
import { route, router } from "./navigation";
import AuthenticatedLayout from "./Layout.vue";
import BitcraftPageHeader from "./Components/BitcraftPageHeader.vue";
import AppButton from "./shared/ui/AppButton.vue";
import TextInput from "./shared/TextInput.vue";
import SelectInput from "./shared/SelectInput.vue";
import BitcraftTierBadge from "./Components/BitcraftTierBadge.vue";
import MarketOrderBookPopup from "./Components/MarketOrderBookPopup.vue";
import TradeInspector from "./Components/TradeInspector.vue";
import {
  bitcraftItemFrameStyle,
  bitjitaAssetUrl,
  hasBitcraftTier,
} from "./bitjitaAssets";
import { sortTradingRows } from "./market";

const props = defineProps({
  filters: { type: Object, default: () => ({}) },
  regions: { type: Array, default: () => [] },
  market: {
    type: Object,
    default: () => ({
      items: [],
      exchanges: [],
      claims: [],
      categories: [],
      listings: [],
      orderBooks: {},
    }),
  },
  tool: {
    type: Object,
    default: () => ({
      key: "market",
      routeName: "bitcraft.market",
      title: "Market",
    }),
  },
  cache: { type: Object, default: () => ({}) },
  error: { type: String, default: null },
});
const isBarter = computed(() => props.tool.key === "barter-stalls");
const form = reactive({
  q: "",
  region: "",
  category: "",
  claimQ: "",
  claimEntityId: "",
  empire: "",
  empireEntityId: "",
  side: "",
});
const intent = ref("buy"),
  quantity = ref(100),
  advancedOpen = ref(false);
const exchangeType = ref(""),
  availableOnly = ref(true),
  sort = ref("price");
const selectedKey = ref("");
const searching = ref(false),
  popupOpen = ref(false),
  detailsTab = ref("offers");
const brokenIcons = ref(new Set());
let searchTimer,
  syncing = false;
const storageKey = computed(() => `space.bitcraft.trading.${props.tool.key}`);
const resultScope = computed(() =>
  JSON.stringify([
    props.filters.q,
    props.filters.region,
    props.filters.regionId,
    props.filters.category,
    props.filters.claimQ,
    props.filters.claimEntityId,
    props.filters.empireEntityId,
  ]),
);
const itemKey = (item) => `${item.kind ?? item.type ?? "item"}:${item.id}`;
const {
  selectedBook,
  bookLoading,
  lookupWarning,
  knownBook,
  selectBook,
  loadHistory,
  invalidate,
  clearBooks,
} = useMarketBooks({
  props,
  isBarter,
  resultScope: () => resultScope.value,
  payload,
  selected: () => selected.value,
  selectedKey,
  popupOpen,
  detailsTab,
  rowKey: (row) => rowKey(row),
  remember,
});
const count = (value) =>
  value === null || value === undefined || value === ""
    ? "—"
    : Number(value).toLocaleString();
const coins = (value) =>
  value === null || value === undefined || value === ""
    ? "—"
    : `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })} hex`;
const categories = computed(() =>
  [...new Set([form.category, ...(props.market.categories ?? [])])]
    .filter(Boolean)
    .sort(),
);
const regionSelection = computed({
  get() {
    const region = props.regions.find(
      (row) => String(row.id ?? row.regionId) === String(form.region),
    );
    return region?.name ?? region?.regionName ?? form.region;
  },
  set(value) {
    form.region = value;
  },
});
const updated = computed(() => {
  if (!props.cache.updatedAt) return "";
  const date = new Date(props.cache.updatedAt);
  return Number.isNaN(date.getTime())
    ? ""
    : date.toLocaleTimeString(undefined, {
        hour: "numeric",
        minute: "2-digit",
      });
});
const scopeLabel = computed(
  () =>
    props.market.claim?.name ||
    props.filters.empireName ||
    props.filters.empire ||
    props.filters.regionName ||
    props.filters.region ||
    "All regions",
);
const advancedCount = computed(
  () =>
    [
      form.category,
      form.claimQ,
      form.claimEntityId,
      form.empire,
      form.side,
    ].filter(Boolean).length,
);
const selectedSide = computed(() => (intent.value === "buy" ? "sell" : "buy"));
const marketPrice = (item) =>
  intent.value === "buy" ? item.lowestSellPrice : item.highestBuyPrice;
const marketQuantity = (item) =>
  intent.value === "buy" ? item.sellOrderQuantity : item.buyOrderQuantity;
const marketRows = computed(() => {
  const rows = (props.market.items ?? []).filter(
    (item) =>
      !availableOnly.value ||
      Number(item[`${selectedSide.value}OrderCount`]) > 0,
  );
  return sortTradingRows(rows, { sort: sort.value, intent: intent.value });
});
const exchanges = computed(() =>
  (props.market.exchanges ?? [])
    .filter(
      (row) =>
        (!exchangeType.value || row.exchangeType === exchangeType.value) &&
        (!availableOnly.value ||
          row.remainingStock === null ||
          row.remainingStock === undefined ||
          Number(row.remainingStock) > 0),
    )
    .slice()
    .sort(
      (a, b) =>
        Number(a.exchangeType === "one-sided") -
        Number(b.exchangeType === "one-sided"),
    ),
);
const marketPage = ref(Math.max(1, Number(props.filters.page) || 1));
const marketPages = computed(() =>
  Math.max(1, Math.ceil(marketRows.value.length / 20)),
);
const visibleMarketRows = computed(() =>
  marketRows.value.slice((marketPage.value - 1) * 20, marketPage.value * 20),
);
const barterPage = ref(1);
const barterPages = computed(() =>
  Math.max(1, Math.ceil(exchanges.value.length / 20)),
);
const barterRows = computed(() =>
  exchanges.value.slice((barterPage.value - 1) * 20, barterPage.value * 20),
);
const rows = computed(() =>
  isBarter.value ? barterRows.value : visibleMarketRows.value,
);
const rowKey = (row) => (isBarter.value ? row.exchangeId : itemKey(row));
const selected = computed(
  () => rows.value.find((row) => rowKey(row) === selectedKey.value) ?? null,
);
const selectionPending = computed(
  () =>
    !isBarter.value &&
    !!selected.value &&
    bookLoading.value &&
    !selectedBook.value,
);
const exchangeLabel = (type) =>
  ({
    coins: "Coin trade",
    items: "Item exchange",
    mixed: "Mixed bundle",
    "one-sided": "One-sided listing",
  })[type] || "Exchange";
const iconUrl = (item) =>
  brokenIcons.value.has(item.iconAssetName)
    ? null
    : bitjitaAssetUrl(item.iconAssetName);
const hideIcon = (item) => {
  brokenIcons.value = new Set([...brokenIcons.value, item.iconAssetName]);
};
const initial = (name) =>
  String(name ?? "?")
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0])
    .join("");

function remember() {
  try {
    sessionStorage.setItem(
      storageKey.value,
      JSON.stringify({
        intent: intent.value,
        quantity: quantity.value,
        selected: selectedKey.value,
        advanced: advancedOpen.value,
        sort: sort.value,
        exchangeType: exchangeType.value,
        availableOnly: availableOnly.value,
        barterPage: barterPage.value,
        marketPage: marketPage.value,
        scroll: window.scrollY,
        filters: { ...form },
      }),
    );
  } catch {
    /* Browsing still works without storage. */
  }
}
function payload() {
  return Object.fromEntries(
    Object.entries({
      ...form,
      intent: intent.value,
      hasOrders: availableOnly.value ? 1 : 0,
      ...(!isBarter.value
        ? {
            hasSellOrders:
              intent.value === "buy" && availableOnly.value ? 1 : 0,
            hasBuyOrders:
              intent.value === "sell" && availableOnly.value ? 1 : 0,
          }
        : {}),
    }).filter(
      ([, value]) => value !== "" && value !== null && value !== undefined,
    ),
  );
}
function visit(extra = {}) {
  clearTimeout(searchTimer);
  searchTimer = null;
  remember();
  router.get(
    route(props.tool.routeName ?? "bitcraft.market"),
    { ...payload(), ...extra },
    {
      replace: true,
      preserveState: true,
      preserveScroll: true,
      onStart: () => {
        searching.value = true;
      },
      onFinish: () => {
        searching.value = false;
      },
    },
  );
}
function submit() {
  marketPage.value = 1;
  barterPage.value = 1;
  visit();
}
function normalizeQuantity() {
  const value = Number(quantity.value);
  quantity.value = Number.isFinite(value)
    ? Math.max(1, Math.min(Number.MAX_SAFE_INTEGER, Math.floor(value)))
    : 1;
  remember();
}
function reset() {
  clearTimeout(searchTimer);
  searchTimer = null;
  syncing = true;
  Object.assign(
    form,
    Object.fromEntries(Object.keys(form).map((key) => [key, ""])),
  );
  exchangeType.value = "";
  availableOnly.value = true;
  submit();
  nextTick(() => {
    syncing = false;
  });
}
function setIntent(value) {
  intent.value = value;
  detailsTab.value = "offers";
  remember();
  submit();
}
function chooseClaim(claim) {
  form.claimQ = claim.name;
  form.claimEntityId = /^\d+$/.test(String(claim.entityId))
    ? String(claim.entityId)
    : "";
  submit();
}
function claimHref(order) {
  return route(props.tool.routeName ?? "bitcraft.market", {
    ...payload(),
    claimQ: order.claimName,
    claimEntityId: order.claimEntityId,
  });
}
function bestClaim(item) {
  const storedName =
    intent.value === "buy" ? item.bestSellClaimName : item.bestBuyClaimName;
  if (storedName) return storedName;
  const book = knownBook(item);
  if (!book) return "—";
  const ordinary =
    book[selectedSide.value === "sell" ? "sellOrders" : "buyOrders"] ?? [];
  const orders = ordinary.length
    ? ordinary
    : (book[
        selectedSide.value === "sell" ? "packageSellOrders" : "packageBuyOrders"
      ] ?? []);
  const sorted = orders
    .filter(
      (order) =>
        order.price !== null &&
        order.price !== undefined &&
        Number.isFinite(Number(order.price)),
    )
    .slice()
    .sort(
      (a, b) =>
        (Number(a.price) - Number(b.price)) * (intent.value === "buy" ? 1 : -1),
    );
  return sorted[0]?.claimName || sorted[0]?.locationName || "—";
}
async function select(row, moveToDetails = false) {
  if (moveToDetails && window.innerWidth <= 1000) {
    nextTick(() =>
      document
        .getElementById("trade-detail-heading")
        ?.scrollIntoView({ block: "start" }),
    );
  }
  await selectBook(row);
}
watch(availableOnly, () => {
  marketPage.value = 1;
  remember();
});
watch(resultScope, () => {
  marketPage.value = 1;
  clearBooks();
});
watch(marketPages, (pages) => {
  marketPage.value = Math.min(marketPage.value, pages);
});
function focusDetails() {
  nextTick(() =>
    document
      .getElementById("trade-detail-heading")
      ?.focus({ preventScroll: true }),
  );
}
function returnToResults() {
  const row = document.querySelector(".trade-result.is-selected");
  row?.scrollIntoView({ block: "center" });
  row?.focus({ preventScroll: true });
}
watch(
  // Stored market updates replace the props object without changing the search.
  // Only changed filter values should replace the user's editable draft.
  () =>
    JSON.stringify({
      q: props.filters.q ?? "",
      category: props.filters.category ?? "",
      claimQ: props.filters.claimQ ?? "",
      claimEntityId: props.filters.claimEntityId ?? "",
      empire: props.filters.empire ?? props.filters.empireName ?? "",
      empireEntityId: props.filters.empireEntityId ?? "",
      region:
        props.filters.region ??
        props.filters.regionName ??
        props.filters.regionId ??
        "",
      side: props.filters.side ?? "",
      intent: props.filters.intent,
      hasBuyOrders: props.filters.hasBuyOrders,
      hasSellOrders: props.filters.hasSellOrders,
      hasOrders: props.filters.hasOrders,
    }),
  (serialized) => {
    // An earlier response must not cancel an edit waiting for its debounce.
    if (searchTimer) return;
    const filters = JSON.parse(serialized);
    syncing = true;
    clearTimeout(searchTimer);
    Object.assign(form, {
      q: filters.q ?? "",
      category: filters.category ?? "",
      claimQ: filters.claimQ ?? "",
      claimEntityId: filters.claimEntityId ?? "",
      empire: filters.empire ?? filters.empireName ?? "",
      empireEntityId: filters.empireEntityId ?? "",
      region: filters.region ?? filters.regionName ?? filters.regionId ?? "",
      side: filters.side ?? "",
    });
    if (filters.intent)
      intent.value = filters.intent === "sell" ? "sell" : "buy";
    else if (filters.hasBuyOrders && !filters.hasSellOrders)
      intent.value = "sell";
    if (filters.hasOrders !== null && filters.hasOrders !== undefined)
      availableOnly.value = !!filters.hasOrders;
    nextTick(() => {
      syncing = false;
    });
  },
  { immediate: true },
);
watch(
  () => [form.q, form.region],
  () => {
    if (syncing) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(submit, 450);
  },
);
watch([exchanges, exchangeType], () => {
  barterPage.value = Math.min(barterPage.value, barterPages.value);
});
watch(exchangeType, () => {
  barterPage.value = 1;
  remember();
});
watch(
  [rows, resultScope],
  () => {
    if (!rows.value.length) {
      invalidate();
      selectedBook.value = null;
      bookLoading.value = false;
      popupOpen.value = false;
      return;
    }
    const retained = rows.value.find(
      (row) => rowKey(row) === selectedKey.value,
    );
    const urlItem =
      props.filters.itemId &&
      rows.value.find(
        (row) =>
          String(row.id) === String(props.filters.itemId) &&
          (!props.filters.itemKind || row.kind === props.filters.itemKind),
      );
    select(retained || urlItem || rows.value[0]);
  },
  { flush: "post" },
);
onMounted(() => {
  try {
    const saved = JSON.parse(
      sessionStorage.getItem(storageKey.value) || "null",
    );
    if (saved) {
      if (!props.filters.intent && !props.filters.hasBuyOrders)
        intent.value = saved.intent === "sell" ? "sell" : "buy";
      quantity.value = Math.max(
        1,
        Math.floor(Number(saved.quantity) || (isBarter.value ? 1 : 100)),
      );
      selectedKey.value = saved.selected || "";
      advancedOpen.value = !!saved.advanced;
      if (["price", "quantity", "name"].includes(saved.sort))
        sort.value = saved.sort;
      if (
        ["", "coins", "items", "mixed", "one-sided"].includes(
          saved.exchangeType,
        )
      )
        exchangeType.value = saved.exchangeType;
      const sameSearch = Object.keys(form).every(
        (key) => String(saved.filters?.[key] ?? "") === String(form[key] ?? ""),
      );
      const explicitPage = new URLSearchParams(window.location.search).get(
        "page",
      );
      marketPage.value = Math.min(
        Math.max(
          1,
          Number(explicitPage) || (sameSearch ? saved.marketPage : 1) || 1,
        ),
        marketPages.value,
      );
      barterPage.value = Math.min(
        Math.max(1, saved.barterPage || 1),
        barterPages.value,
      );
      // Returning through the sidebar should reopen the last search. An
      // explicit query URL always wins over remembered browser preferences.
      if (
        !window.location.search &&
        saved.filters &&
        Object.values(saved.filters).some(Boolean)
      ) {
        Object.assign(
          form,
          Object.fromEntries(
            Object.keys(form).map((key) => [key, saved.filters[key] ?? ""]),
          ),
        );
        nextTick(submit);
      }
      nextTick(() => {
        if (saved.scroll > 0) window.scrollTo({ top: saved.scroll });
      });
    } else quantity.value = isBarter.value ? 1 : 100;
  } catch {
    /* Use defaults when saved preferences are unavailable. */
  }
  const row =
    rows.value.find((row) => rowKey(row) === selectedKey.value) ||
    rows.value[0];
  if (row) select(row);
  window.addEventListener("pagehide", remember);
});
onBeforeUnmount(() => {
  invalidate();
  clearTimeout(searchTimer);
  remember();
  window.removeEventListener("pagehide", remember);
});
</script>

<template>
  <AuthenticatedLayout>
    <template #header>
      <BitcraftPageHeader
        :title="isBarter ? 'Barter exchanges' : 'Market'"
        :description="
          isBarter
            ? 'Browse barter stalls and find item exchanges.'
            : 'Find items, compare prices, and inspect market orders.'
        "
      >
        <template #actions>
          <div class="trade-freshness" role="status">
            <span>{{ scopeLabel }}</span
            ><span v-if="searching">{{
              isBarter ? "Refreshing…" : "Reading stored market…"
            }}</span
            ><span v-else-if="market.storage?.pending && form.q"
              >Refreshing {{ market.storage.pending }} matching items…</span
            ><span v-else-if="cache.delayed">Refresh delayed</span
            ><span v-else-if="updated"
              >{{ market.storage ? "Stored · " : "" }}Updated
              {{ updated }}</span
            ><span v-else>Waiting for data</span>
          </div>
        </template>
      </BitcraftPageHeader>
    </template>
    <div class="trade-workspace">
      <form
        class="trade-search ui-panel"
        @submit.prevent="submit"
        :aria-busy="searching"
      >
        <div class="trade-primary-fields">
          <label class="field-group trade-query ui-field-label"
            ><span class="field-label ui-field-label">{{
              isBarter ? "Find an item in either side" : "Find an item"
            }}</span
            ><TextInput
              v-model.trim="form.q"
              type="search"
              placeholder="Plank, ore, tools…"
          /></label>
          <label class="field-group ui-field-label"
            ><span class="field-label ui-field-label">Region</span
            ><SelectInput v-model="regionSelection" aria-label="Region">
              <option value="">All regions</option>
              <option
                v-for="region in regions"
                :key="region.id ?? region.regionId"
                :value="region.name ?? region.regionName"
              >
                {{ region.name ?? region.regionName }} (R{{
                  region.id ?? region.regionId
                }})
              </option></SelectInput
            ></label
          >
          <label class="field-group ui-field-label"
            ><span class="field-label ui-field-label">{{
              isBarter ? "Bundles" : "Quantity"
            }}</span
            ><TextInput
              v-model="quantity"
              type="number"
              min="1"
              step="1"
              inputmode="numeric"
              @change="normalizeQuantity"
          /></label>
          <AppButton type="submit" variant="primary" :disabled="searching"
            >Search</AppButton
          >
        </div>
        <div class="trade-toolbar">
          <div
            v-if="!isBarter"
            class="trade-intent"
            aria-label="Trading intent"
          >
            <button
              type="button"
              :aria-pressed="intent === 'buy'"
              @click="setIntent('buy')"
            >
              I want to buy</button
            ><button
              type="button"
              :aria-pressed="intent === 'sell'"
              @click="setIntent('sell')"
            >
              I want to sell
            </button>
          </div>
          <label v-else class="trade-type ui-field-label"
            ><span class="field-label ui-field-label">Exchange type</span
            ><SelectInput v-model="exchangeType"
              ><option value="">All exchanges</option>
              <option value="coins">Coin trades</option>
              <option value="items">Item exchanges</option>
              <option value="mixed">Mixed bundles</option>
              <option value="one-sided">One-sided listings</option></SelectInput
            ></label
          >
          <label class="trade-check ui-field-label"
            ><input
              v-model="availableOnly"
              type="checkbox"
              @change="submit"
            /><span>{{
              isBarter ? "Available bundles only" : "Only items with offers"
            }}</span></label
          >
          <AppButton type="button" variant="ghost" @click="reset"
            >Reset</AppButton
          >
        </div>
        <details
          class="trade-advanced"
          :open="advancedOpen"
          @toggle="
            advancedOpen = $event.target.open;
            remember();
          "
        >
          <summary>
            More filters<span v-if="advancedCount">
              · {{ advancedCount }} active</span
            >
          </summary>
          <div class="trade-advanced-fields">
            <label class="field-group ui-field-label"
              ><span class="field-label ui-field-label">Category</span
              ><SelectInput v-model="form.category" @change="submit"
                ><option value="">All categories</option>
                <option v-for="category in categories" :key="category">
                  {{ category }}
                </option></SelectInput
              ></label
            >
            <label class="field-group ui-field-label"
              ><span class="field-label ui-field-label">Claim name</span
              ><TextInput
                v-model.trim="form.claimQ"
                placeholder="Holmgard, Rivendell…"
                @change="
                  form.claimEntityId = '';
                  submit();
                "
            /></label>
            <label class="field-group ui-field-label"
              ><span class="field-label ui-field-label">Empire</span
              ><TextInput
                v-model.trim="form.empire"
                placeholder="Empire name"
                @input="form.empireEntityId = ''"
                @change="submit"
            /></label>
            <label class="field-group ui-field-label"
              ><span class="field-label ui-field-label">Claim ID</span
              ><TextInput
                v-model.trim="form.claimEntityId"
                inputmode="numeric"
                placeholder="Exact claim ID"
                @change="submit"
            /></label>
            <label v-if="isBarter" class="field-group ui-field-label"
              ><span class="field-label ui-field-label">Item position</span
              ><SelectInput v-model="form.side" @change="submit"
                ><option value="">Given or received</option>
                <option value="sell">You receive the item</option>
                <option value="buy">You give the item</option></SelectInput
              ></label
            >
          </div>
          <details v-if="market.claims?.length" class="trade-claims">
            <summary>Matching claims · {{ market.claims.length }}</summary>
            <div>
              <button
                v-for="claim in market.claims"
                :key="claim.entityId"
                type="button"
                @click="chooseClaim(claim)"
              >
                {{ claim.name
                }}<small>{{ claim.regionName || "Region unknown" }}</small>
              </button>
            </div>
          </details>
        </details>
      </form>
      <p v-if="!isBarter" class="trade-footnote" data-test="market-source-mode">
        {{
          form.q.trim()
            ? "Stored matches appear immediately. Matching items refresh in the background."
            : "Browsing stored market data. Type an item in Find item to request fresh orders."
        }}
        <span
          v-if="
            market.storage &&
            market.storage.loadedActiveItems < market.storage.activeItems
          "
        >
          Initial collection: {{ count(market.storage.loadedActiveItems) }} of
          {{ count(market.storage.activeItems) }} items with orders collected.
          Missing prices stay unknown; regional results may expand.
        </span>
        <span v-if="market.storage?.relay"
          >Live regions: {{ market.storage.relay.ready }} /
          {{ market.storage.relay.total }}</span
        >
        <span v-if="market.storage?.error">{{ market.storage.error }}</span>
      </p>
      <p v-if="error" class="trade-warning" role="alert">{{ error }}</p>
      <div class="trade-columns">
        <section
          class="trade-results"
          :aria-busy="searching"
          aria-label="Trading results"
        >
          <div class="trade-results-heading">
            <div>
              <h2 class="ui-section-title">
                {{ isBarter ? "Compare exact exchanges" : "Compare items" }}
              </h2>
              <p>
                {{ rows.length }} shown<span v-if="isBarter">
                  · {{ exchanges.length }} matching exchanges</span
                ><span v-else>
                  &middot; {{ marketRows.length }} matching items &middot;
                  sorted across all
                  {{
                    market.storage &&
                    market.storage.loadedItems < market.storage.totalItems
                      ? "stored"
                      : "matching"
                  }}
                  results</span
                >
                <span v-if="!isBarter && market.orderScope === 'region'">
                  · stored orders cover all collected claims in this
                  region</span
                >
              </p>
            </div>
            <label v-if="!isBarter" class="trade-sort ui-field-label"
              ><span class="field-label ui-field-label"
                >Sort all matching items</span
              ><SelectInput
                v-model="sort"
                @change="
                  marketPage = 1;
                  remember();
                "
                ><option value="price">
                  {{ intent === "buy" ? "Lowest price" : "Highest price" }}
                </option>
                <option value="quantity">Most quantity</option>
                <option value="name">Item name</option></SelectInput
              ></label
            >
          </div>
          <div
            v-if="rows.length"
            class="trade-row-list"
            :class="{ 'trade-row-list--barter': isBarter }"
          >
            <div class="trade-row trade-row--labels" aria-hidden="true">
              <span>{{ isBarter ? "You give" : "Item" }}</span
              ><span>{{
                isBarter
                  ? "You get"
                  : intent === "buy"
                    ? "Pay / unit"
                    : "Receive / unit"
              }}</span
              ><span>{{
                isBarter
                  ? "Bundles left"
                  : intent === "buy"
                    ? "For sale"
                    : "Wanted"
              }}</span
              ><span>{{ isBarter ? "Claim" : "Best claim" }}</span>
            </div>
            <button
              v-for="row in rows"
              :key="rowKey(row)"
              type="button"
              class="trade-row trade-result"
              :class="{ 'is-selected': rowKey(row) === selectedKey }"
              :aria-pressed="rowKey(row) === selectedKey"
              :data-test="isBarter ? 'barter-exchange-row' : 'market-item-row'"
              @click="select(row, true)"
              @keydown.enter.prevent="
                select(row, true);
                focusDetails();
              "
            >
              <template v-if="isBarter">
                <span class="trade-stack-list"
                  ><small v-if="!row.requiredStacks.length"
                    >No cost listed</small
                  ><span
                    v-for="(stack, index) in row.requiredStacks"
                    :key="index"
                    >{{ count(stack.quantity) }} × {{ stack.name
                    }}<small v-if="stack.kind === 'cargo'">Cargo</small></span
                  ><small>{{ exchangeLabel(row.exchangeType) }}</small></span
                >
                <span class="trade-stack-list"
                  ><small v-if="!row.offerStacks.length"
                    >No returned items listed</small
                  ><span v-for="(stack, index) in row.offerStacks" :key="index"
                    >{{ count(stack.quantity) }} × {{ stack.name
                    }}<small v-if="stack.kind === 'cargo'">Cargo</small></span
                  ></span
                >
                <span class="trade-numeric"
                  >{{ count(row.remainingStock)
                  }}<small
                    v-if="
                      row.remainingStock === null ||
                      row.remainingStock === undefined
                    "
                    >Unknown</small
                  ></span
                >
                <span
                  >{{ row.claimName || row.stall?.name || "Unknown claim"
                  }}<small>{{
                    row.regionName ||
                    (row.regionId ? `Region ${row.regionId}` : "Region unknown")
                  }}</small></span
                >
              </template>
              <template v-else>
                <span class="trade-item"
                  ><span
                    class="trade-icon bitcraft-item-icon"
                    :style="bitcraftItemFrameStyle(row.tier, row.rarity)"
                    aria-hidden="true"
                    ><img
                      v-if="iconUrl(row)"
                      :src="iconUrl(row)"
                      alt=""
                      loading="lazy"
                      @error="hideIcon(row)"
                    /><span v-else>{{ initial(row.name) }}</span></span
                  ><span
                    ><strong>{{ row.name }}</strong
                    ><small
                      ><BitcraftTierBadge
                        v-if="hasBitcraftTier(row.tier)"
                        :tier="row.tier"
                      />{{ row.rarity
                      }}<span v-if="row.kind === 'cargo'"> · Cargo</span></small
                    ></span
                  ></span
                >
                <span class="trade-numeric"
                  >{{ coins(marketPrice(row))
                  }}<small
                    v-if="
                      marketPrice(row) === null ||
                      marketPrice(row) === undefined
                    "
                    >No quoted price</small
                  ></span
                >
                <span class="trade-numeric">{{
                  count(marketQuantity(row))
                }}</span>
                <span
                  >{{ bestClaim(row)
                  }}<small
                    >{{ count(row[`${selectedSide}OrderCount`]) }} orders</small
                  ></span
                >
              </template>
            </button>
          </div>
          <p v-else class="trade-empty" role="status">
            {{
              searching
                ? "Searching…"
                : isBarter
                  ? "No exchanges match. Try another item, region or exchange type."
                  : "No offers on this page match your intent. Try another page or search."
            }}
          </p>
          <nav
            v-if="isBarter && barterPages > 1"
            class="trade-pagination"
            aria-label="Barter result pages"
          >
            <AppButton
              :disabled="barterPage <= 1"
              @click="
                barterPage--;
                remember();
              "
              >Previous</AppButton
            ><span>Page {{ barterPage }} of {{ barterPages }}</span
            ><AppButton
              :disabled="barterPage >= barterPages"
              @click="
                barterPage++;
                remember();
              "
              >Next</AppButton
            >
          </nav>
          <nav
            v-else-if="!isBarter && marketPages > 1"
            class="trade-pagination"
            aria-label="Market result pages"
          >
            <AppButton
              :disabled="marketPage <= 1"
              @click="
                marketPage--;
                remember();
              "
              >Previous</AppButton
            >
            <span>Page {{ marketPage }} of {{ marketPages }}</span>
            <AppButton
              :disabled="marketPage >= marketPages"
              @click="
                marketPage++;
                remember();
              "
              >Next</AppButton
            >
          </nav>
        </section>
        <TradeInspector
          :item="selected"
          :book="selectedBook"
          :exchange="isBarter ? selected : null"
          :intent="intent"
          :quantity="quantity"
          :loading="selectionPending"
          :warning="lookupWarning"
          :refreshing="searching"
          :claim-link-href="claimHref"
          v-model:tab="detailsTab"
          @full-book="
            popupOpen = true;
            loadHistory();
          "
          @retry="
            detailsTab === 'history'
              ? loadHistory()
              : selected && select(selected)
          "
          @back-results="returnToResults"
        />
      </div>
      <p class="trade-footnote">
        Collected offers may change in game. Estimates use the visible stock and
        do not reserve or execute a trade.
      </p>
    </div>
    <MarketOrderBookPopup
      :show="popupOpen && !isBarter && !!selectedBook"
      :order-book="selectedBook"
      :claim-link-href="claimHref"
      @close="popupOpen = false"
    />
  </AuthenticatedLayout>
</template>

<style scoped>
.trade-workspace {
  min-width: 0;
  font-family: var(--font-ui, inherit);
}
.trade-freshness {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px 16px;
  font-size: 12px;
  color: var(--text-muted-2);
}
.trade-search {
  margin-bottom: 22px;
}
.trade-primary-fields {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(130px, 0.36fr) 100px auto;
  gap: 14px;
  align-items: end;
}
.trade-primary-fields :deep(input) {
  width: 100%;
  min-width: 0;
}
.trade-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 14px;
}
.trade-intent {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.trade-intent button {
  padding: 9px 13px;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  font-size: 13px;
  color: var(--text-muted-2);
}
.trade-intent button[aria-pressed="true"] {
  background: rgb(var(--accent-cyan-rgb) / 0.1);
  border-color: rgb(var(--accent-cyan-rgb) / 0.5);
  color: var(--text-primary);
}
.trade-check {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-left: auto;
  min-height: 32px;
}
.trade-check input {
  appearance: auto;
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  margin: 0;
  accent-color: var(--accent-cyan);
}
.trade-type {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.trade-type :deep(select) {
  min-width: 160px;
}
.trade-advanced {
  margin-top: 15px;
  font-size: 12px;
  color: var(--text-muted-2);
}
.trade-advanced summary,
.trade-claims summary {
  padding: 5px 0;
  cursor: pointer;
}
.trade-advanced-fields {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  padding: 15px 0 4px;
}
.trade-advanced-fields :deep(input),
.trade-advanced-fields :deep(select) {
  min-width: 0;
  width: 100%;
}
.trade-claims {
  margin-top: 14px;
}
.trade-claims > div {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 8px;
  max-height: 280px;
  overflow: auto;
  margin-top: 10px;
}
.trade-claims button {
  padding: 10px;
  text-align: left;
  border: 1px solid var(--border-color);
  border-radius: 6px;
  color: var(--text-primary);
  overflow-wrap: anywhere;
}
.trade-claims small {
  display: block;
  color: var(--text-muted-2);
}
.trade-columns {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 22px;
  align-items: start;
}
.trade-results {
  min-width: 0;
}
.trade-results-heading {
  display: flex;
  gap: 12px;
  justify-content: space-between;
  align-items: start;
  margin-bottom: 16px;
}
.trade-results-heading h2 {
  margin: 0 0 6px;
}
.trade-results-heading p {
  font-size: 12px;
  color: var(--text-muted-2);
  margin: 0;
}
.trade-sort {
  max-width: 170px;
}
.trade-sort :deep(select) {
  font-size: 12px;
  min-width: 0;
  width: 100%;
}
.trade-row {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 0.65fr) minmax(
      0,
      1fr
    );
  gap: 12px;
  align-items: center;
  padding: 15px 12px;
  width: 100%;
  text-align: left;
  border-bottom: 1px solid var(--border-color);
  font-size: 13px;
  color: var(--text-primary);
}
.trade-row > span {
  min-width: 0;
  overflow-wrap: anywhere;
}
.trade-row--labels {
  font-size: 11px;
  color: var(--text-muted-2);
  padding-top: 8px;
  padding-bottom: 12px;
}
.trade-result {
  background: transparent;
  transition: background 0.12s;
}
.trade-result:hover {
  background: rgb(var(--accent-cyan-rgb) / 0.05);
}
.trade-result.is-selected {
  background: rgb(var(--accent-cyan-rgb) / 0.1);
  box-shadow: inset 3px 0 rgb(var(--accent-cyan-rgb) / 0.6);
}
.trade-row small {
  display: block;
  font-size: 11px;
  color: var(--text-muted-2);
  margin-top: 5px;
  line-height: 1.5;
}
.trade-item {
  display: flex;
  gap: 10px;
  align-items: center;
}
.trade-item strong {
  font-weight: 500;
}
.trade-icon {
  flex-shrink: 0;
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;

  font-size: 12px;
}
.trade-item small {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
.trade-item small :deep(.bitcraft-tier-badge) {
  font-size: 10px;
}
.trade-numeric {
  font-variant-numeric: tabular-nums;
}
.trade-row-list--barter .trade-row {
  grid-template-columns:
    minmax(0, 1.4fr) minmax(0, 1.4fr) minmax(0, 0.55fr)
    minmax(0, 0.9fr);
}
.trade-stack-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.trade-stack-list > span {
  display: block;
}
.trade-stack-list small {
  margin-top: 1px;
}
.trade-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 18px;
  font-size: 12px;
  color: var(--text-muted-2);
}
.trade-empty {
  padding: 35px 16px;
  border: 1px dashed var(--border-color);
  border-radius: 8px;
  color: var(--text-muted-2);
  font-size: 13px;
}
.trade-warning {
  padding: 12px;
  border: 1px solid rgb(var(--warning-rgb) / 0.4);
  color: var(--warning);
  border-radius: 6px;
  margin: 12px 0;
}
.trade-footnote {
  font-size: 11px;
  color: var(--text-muted-2);
  margin: 22px 0 0;
}
@media (max-width: 1200px) {
  .trade-columns {
    grid-template-columns: minmax(0, 1fr) 290px;
    gap: 16px;
  }
  .trade-row {
    gap: 8px;
    padding-inline: 9px;
  }
}
@media (max-width: 1000px) {
  .trade-columns {
    grid-template-columns: 1fr;
  }
  .trade-primary-fields {
    grid-template-columns: minmax(0, 1fr) 150px 90px;
  }
  .trade-primary-fields > .app-btn {
    grid-column: 1/-1;
    justify-self: start;
  }
  .trade-advanced-fields {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 600px) {
  .trade-freshness {
    justify-content: flex-start;
  }
  .trade-primary-fields {
    grid-template-columns: minmax(0, 1fr) 90px;
    gap: 12px;
  }
  .trade-query {
    grid-column: 1/-1;
  }
  .trade-primary-fields > .app-btn {
    grid-column: auto;
    justify-self: start;
  }
  .trade-primary-fields :deep(input) {
    font-size: 16px;
  }
  .trade-advanced-fields {
    grid-template-columns: 1fr;
  }
  .trade-check {
    margin-left: 0;
    min-height: 44px;
  }
  .trade-intent button {
    min-height: 44px;
  }
  .trade-toolbar > .app-btn {
    margin-left: auto;
  }
  .trade-results-heading {
    flex-wrap: wrap;
  }
  .trade-row {
    font-size: 12px;
    gap: 7px;
    padding: 13px 7px;
    min-height: 64px;
    grid-template-columns:
      minmax(0, 1.6fr) minmax(0, 1fr) minmax(0, 0.55fr)
      minmax(0, 0.9fr);
  }
  .trade-icon {
    display: none;
  }
  .trade-item {
    gap: 0;
  }
  .trade-row--labels {
    min-height: 0;
    font-size: 11px;
  }
  .trade-row-list--barter .trade-row {
    grid-template-columns:
      minmax(0, 1.2fr) minmax(0, 1.2fr) minmax(0, 0.55fr)
      minmax(0, 0.85fr);
  }
  .trade-type {
    width: 100%;
  }
  .trade-sort {
    max-width: none;
  }
  .trade-claims > div {
    grid-template-columns: 1fr;
  }
}
</style>
