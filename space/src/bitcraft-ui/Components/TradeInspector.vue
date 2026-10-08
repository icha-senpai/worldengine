<script setup>
import { computed, ref, watch } from "vue";
import AppButton from "../shared/ui/AppButton.vue";
import BitcraftTierBadge from "./BitcraftTierBadge.vue";
import SelectInput from "../shared/SelectInput.vue";
import { Link } from "../navigation";
import {
  estimateMarketFill,
  estimateBarterFill,
  sortTradingRows,
} from "../market";
import { hasBitcraftTier } from "../bitjitaAssets";

const props = defineProps({
  item: { type: Object, default: null },
  book: { type: Object, default: null },
  exchange: { type: Object, default: null },
  intent: { type: String, default: "buy" },
  quantity: { type: [Number, String], default: 1 },
  loading: { type: Boolean, default: false },
  refreshing: { type: Boolean, default: false },
  warning: { type: String, default: "" },
  claimLinkHref: { type: Function, required: true },
  tab: { type: String, default: "offers" },
});
defineEmits(["update:tab", "full-book", "retry", "back-results"]);
const count = (value) =>
  value === null || value === undefined || value === ""
    ? "—"
    : Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
const coins = (value) =>
  value === null || value === undefined ? "—" : `${count(value)} hex`;
const ordinary = computed(
  () => props.book?.[props.intent === "buy" ? "sellOrders" : "buyOrders"] ?? [],
);
const packages = computed(
  () =>
    !ordinary.value.length &&
    !!props.book?.[
      props.intent === "buy" ? "packageSellOrders" : "packageBuyOrders"
    ]?.length,
);
const orders = computed(() =>
  packages.value
    ? props.book[
        props.intent === "buy" ? "packageSellOrders" : "packageBuyOrders"
      ]
    : ordinary.value,
);
const selectedClaim = ref("");
const orderPage = ref(1);
const orderSort = ref("price");
const claimKey = (order) =>
  String(order.claimEntityId || order.claimName || "unknown");
const claimOptions = computed(() =>
  [
    ...new Map(
      orders.value.map((order) => [
        claimKey(order),
        {
          value: claimKey(order),
          label: order.claimName || order.locationName || "Unknown claim",
        },
      ]),
    ).values(),
  ].sort((a, b) => a.label.localeCompare(b.label)),
);
const filteredOrders = computed(() =>
  orders.value.filter(
    (order) => !selectedClaim.value || claimKey(order) === selectedClaim.value,
  ),
);
const sortedOrders = computed(() =>
  sortTradingRows(filteredOrders.value, {
    sort: orderSort.value,
    intent: props.intent,
    orders: true,
  }),
);
const fill = computed(() =>
  estimateMarketFill(filteredOrders.value, props.quantity, props.intent),
);
const orderPages = computed(() =>
  Math.max(1, Math.ceil(sortedOrders.value.length / 20)),
);
const visibleOrders = computed(() =>
  sortedOrders.value.slice((orderPage.value - 1) * 20, orderPage.value * 20),
);
watch(
  () => `${props.item?.kind ?? ""}:${props.item?.id ?? ""}:${props.intent}`,
  () => {
    selectedClaim.value = "";
    orderPage.value = 1;
  },
);
watch([selectedClaim, orderSort], () => {
  orderPage.value = 1;
});
watch(claimOptions, (options) => {
  if (
    selectedClaim.value &&
    !options.some((option) => option.value === selectedClaim.value)
  )
    selectedClaim.value = "";
});
watch(orderPages, (pages) => {
  orderPage.value = Math.min(orderPage.value, pages);
});
const bundle = computed(() =>
  estimateBarterFill(props.exchange, props.quantity),
);
const unit = computed(() => (packages.value ? "packages" : "units"));
const location = (order) =>
  order.claimName || order.locationName || "Unknown claim";
function coordinates(order) {
  const x = order.stall?.locationX ?? order.stallLocationX ?? order.locationX;
  const z = order.stall?.locationZ ?? order.stallLocationZ ?? order.locationZ;
  if (
    x === null ||
    x === undefined ||
    z === null ||
    z === undefined ||
    !Number.isFinite(Number(x)) ||
    !Number.isFinite(Number(z))
  )
    return "";
  return `N ${Math.round(Number(z) / 3).toLocaleString()}, E ${Math.round(Number(x) / 3).toLocaleString()}`;
}
const history = computed(() => props.book?.history);
const subtitle = computed(() =>
  props.exchange
    ? {
        coins: "Coin trade",
        items: "Item exchange",
        mixed: "Mixed bundle",
        "one-sided": "One-sided listing",
      }[props.exchange.exchangeType]
    : props.item?.category,
);
</script>

<template>
  <aside
    class="trade-inspector"
    aria-label="Selected trade details"
    :aria-busy="loading || refreshing"
  >
    <template v-if="item">
      <AppButton
        class="inspector-back"
        size="sm"
        variant="ghost"
        @click="$emit('back-results')"
        >Back to results</AppButton
      >
      <header class="inspector-heading">
        <div>
          <h2 id="trade-detail-heading" tabindex="-1">
            {{ exchange ? "Exchange bundle" : item.name }}
          </h2>
          <p>
            {{ subtitle
            }}<template v-if="!exchange && item.rarity">
              · {{ item.rarity }}</template
            >
          </p>
        </div>
        <BitcraftTierBadge
          v-if="!exchange && hasBitcraftTier(item.tier)"
          :tier="item.tier"
        />
      </header>
      <p v-if="refreshing" class="inspector-status" role="status">
        Refreshing results · current details remain visible
      </p>
      <p v-if="warning" class="inspector-warning" role="alert">
        {{ warning }}
        <button type="button" @click="$emit('retry')">Try again</button>
      </p>
      <div
        v-if="!exchange && book"
        class="inspector-tabs"
        aria-label="Detail view"
      >
        <button
          type="button"
          :aria-pressed="tab === 'offers'"
          @click="$emit('update:tab', 'offers')"
        >
          Offers</button
        ><button
          type="button"
          :aria-pressed="tab === 'history'"
          @click="$emit('update:tab', 'history')"
        >
          History
        </button>
      </div>
      <p v-if="loading" class="inspector-status" role="status">
        Loading selected order details…
      </p>
      <template v-else-if="exchange">
        <div
          class="bundle-summary"
          aria-live="polite"
          data-test="barter-estimate"
        >
          <strong
            >{{ count(bundle.bundles) }}
            {{ bundle.bundles === 1 ? "bundle" : "bundles" }}</strong
          ><span>{{
            bundle.stock === null
              ? "Stock unknown · quantities are illustrative until verified"
              : `${count(bundle.stock)} bundles remaining`
          }}</span
          ><span
            v-if="bundle.stock !== null && bundle.requested > bundle.stock"
            class="inspector-warning"
            >Requested {{ count(bundle.requested) }} · only
            {{ count(bundle.stock) }} available</span
          >
        </div>
        <section class="inspector-stacks">
          <h3>You give</h3>
          <p v-if="!bundle.give.length" class="inspector-status">
            No cost listed
          </p>
          <div v-for="(stack, index) in bundle.give" :key="index">
            <strong>{{ count(stack.quantity) }} × {{ stack.name }}</strong
            ><small v-if="stack.kind === 'cargo'">Cargo</small>
          </div>
        </section>
        <section class="inspector-stacks">
          <h3>You get</h3>
          <p v-if="!bundle.get.length" class="inspector-status">
            No returned items listed
          </p>
          <div v-for="(stack, index) in bundle.get" :key="index">
            <strong>{{ count(stack.quantity) }} × {{ stack.name }}</strong
            ><small v-if="stack.kind === 'cargo'">Cargo</small>
          </div>
        </section>
        <p v-if="exchange.exchangeType === 'mixed'" class="inspector-status">
          Every required stack is part of the cost. This bundle has no implied
          coin price.
        </p>
        <p
          v-if="exchange.exchangeType === 'one-sided'"
          class="inspector-status"
        >
          The source lists only one side. Verify the offer in game before
          trading.
        </p>
        <div class="inspector-location">
          <Link
            v-if="exchange.claimEntityId || exchange.claimName"
            :href="claimLinkHref(exchange)"
            >{{ exchange.claimName || "View claim" }}</Link
          ><strong v-else>{{ location(exchange) }}</strong
          ><span>{{
            exchange.regionName ||
            (exchange.regionId
              ? `Region ${exchange.regionId}`
              : "Region unknown")
          }}</span
          ><span>{{ exchange.stall?.name || "Barter Stall" }}</span
          ><span v-if="exchange.ownerUsername"
            >Owner · {{ exchange.ownerUsername }}</span
          ><span v-if="coordinates(exchange)">{{ coordinates(exchange) }}</span>
        </div>
      </template>
      <template v-else-if="book && tab === 'history'">
        <section class="inspector-history">
          <h3>Completed coin trades</h3>
          <template v-if="history"
            ><div>
              <span>24h average</span
              ><strong>{{ coins(history.avg24h) }}</strong>
            </div>
            <div>
              <span>7d average</span><strong>{{ coins(history.avg7d) }}</strong>
            </div>
            <p v-if="book.historyDelayed" class="inspector-status">
              History refresh delayed
            </p>
            <p class="inspector-status">
              History reflects the available trade feed; it may cover a wider
              scope than these offers.
            </p></template
          >
          <p
            v-else-if="book.historyLoaded === false"
            class="inspector-status"
            role="status"
          >
            Loading completed-trade history...
          </p>
          <p v-else class="inspector-status">
            No completed-trade history is available for this view.
          </p>
        </section>
      </template>
      <template v-else-if="book">
        <label class="inspector-claim-filter"
          ><span
            >Claim · {{ count(orders.length) }} orders across
            {{ count(claimOptions.length) }} claims</span
          ><SelectInput
            v-model="selectedClaim"
            aria-label="Filter orders by claim"
            ><option value="">All available claims</option>
            <option
              v-for="claim in claimOptions"
              :key="claim.value"
              :value="claim.value"
            >
              {{ claim.label }}
            </option></SelectInput
          ></label
        >
        <label class="inspector-claim-filter">
          <span>Sort all matching orders</span>
          <SelectInput
            v-model="orderSort"
            aria-label="Sort all matching orders"
          >
            <option value="price">
              {{ intent === "buy" ? "Lowest price" : "Highest price" }}
            </option>
            <option value="quantity">Most quantity</option>
            <option value="name">Claim name</option>
          </SelectInput>
        </label>
        <div
          class="inspector-estimate"
          aria-live="polite"
          data-test="market-estimate"
        >
          <span
            >{{ intent === "buy" ? "Estimated spend" : "Estimated proceeds" }} ·
            {{ count(fill.filled) }} of {{ count(fill.requested) }}
            {{ unit }}</span
          ><strong>{{
            fill.filled ? coins(fill.total) : "No visible stock"
          }}</strong
          ><span v-if="fill.average !== null"
            >{{ coins(fill.average) }} /
            {{ packages ? "package" : "unit" }}</span
          ><span v-if="fill.remaining" class="inspector-warning"
            >{{ count(fill.remaining) }} {{ unit }} cannot be filled from these
            offers</span
          >
        </div>
        <h3>{{ intent === "buy" ? "Sellers" : "Buyers" }} in this view</h3>
        <div v-if="sortedOrders.length" class="inspector-orders">
          <article
            v-for="order in visibleOrders"
            :key="order.entityId"
            class="inspector-order"
          >
            <div>
              <Link
                v-if="order.claimEntityId || order.claimName"
                :href="claimLinkHref(order)"
                >{{ location(order) }}</Link
              ><strong v-else>{{ location(order) }}</strong
              ><span
                >{{ count(order.quantity) }} {{ unit
                }}<template v-if="order.regionName">
                  · {{ order.regionName }}</template
                ></span
              ><span v-if="order.ownerUsername">{{ order.ownerUsername }}</span
              ><span v-if="coordinates(order)">{{ coordinates(order) }}</span>
            </div>
            <strong>{{ coins(order.price) }}</strong>
          </article>
        </div>
        <p v-else class="inspector-status">
          No {{ intent === "buy" ? "sell" : "buy" }} offers in this view.
        </p>
        <nav
          v-if="orderPages > 1"
          class="inspector-pagination"
          aria-label="Selected item order pages"
        >
          <AppButton
            size="sm"
            variant="ghost"
            :disabled="orderPage <= 1"
            @click="orderPage--"
            >Previous orders</AppButton
          ><span>{{ orderPage }} / {{ orderPages }}</span
          ><AppButton
            size="sm"
            variant="ghost"
            :disabled="orderPage >= orderPages"
            @click="orderPage++"
            >Next orders</AppButton
          >
        </nav>
        <small class="inspector-status"
          >{{ count(visibleOrders.length) }} of
          {{ count(sortedOrders.length) }} orders shown ·
          {{ selectedClaim ? "Selected claim" : "All available claims" }}</small
        >
      </template>
      <p v-else-if="!loading && !warning" class="inspector-status">
        Order details are unavailable.
      </p>
      <AppButton
        v-if="book && !exchange"
        class="inspector-full-book"
        size="sm"
        variant="ghost"
        @click="$emit('full-book')"
        >Full order book</AppButton
      >
    </template>
    <p v-else class="inspector-status">
      Select a result to compare quantities and locations.
    </p>
  </aside>
</template>

<style scoped>
.trade-inspector {
  background: var(--bg-surface-2);
  border: 1px solid var(--border-color);
  border-radius: 10px;
  padding: 20px;
  min-width: 0;
  font-size: 13px;
  color: var(--text-primary);
  font-family: var(--font-ui);
}
.inspector-heading {
  display: flex;
  gap: 12px;
  align-items: start;
  justify-content: space-between;
  margin-bottom: 16px;
}
.inspector-heading > div {
  min-width: 0;
}
.inspector-heading h2 {
  font-size: 17px;
  font-weight: 600;
  margin: 0;
  overflow-wrap: anywhere;
}
.inspector-heading p {
  font-size: 11px;
  color: var(--text-muted-2);
  margin: 7px 0 0;
}
.inspector-heading :deep(.bitcraft-tier-badge) {
  flex-shrink: 0;
}
.inspector-tabs {
  display: flex;
  gap: 8px;
  margin: 15px 0;
}
.inspector-tabs button {
  padding: 8px 12px;
  border: 1px solid var(--border-color);
  border-radius: 5px;
  font-size: 12px;
  color: var(--text-muted-2);
}
.inspector-tabs button[aria-pressed="true"] {
  color: var(--accent-cyan);
  background: rgb(var(--accent-cyan-rgb) / 0.1);
  border-color: var(--accent-cyan);
}
.inspector-estimate,
.bundle-summary {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0 0 20px;
  padding: 0 0 18px;
  border-bottom: 1px solid var(--border-color);
  font-variant-numeric: tabular-nums;
}
.inspector-estimate > strong {
  font-size: 24px;
  font-weight: 500;
  color: var(--accent-cyan);
}
.inspector-estimate > span,
.bundle-summary > span {
  font-size: 11px;
  color: var(--text-muted-2);
  line-height: 1.7;
}
.bundle-summary > strong {
  font-size: 20px;
  font-weight: 500;
}
.trade-inspector h3 {
  font-size: 12px;
  font-weight: 500;
  color: var(--text-muted-2);
  margin: 0 0 10px;
}
.inspector-orders {
  display: flex;
  flex-direction: column;
}
.inspector-order {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 14px;
  border-bottom: 1px solid var(--border-color);
  padding: 13px 0;
  font-size: 12px;
}
.inspector-order > div {
  min-width: 0;
  overflow-wrap: anywhere;
}
.inspector-order > strong {
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  font-weight: 500;
}
.inspector-order span {
  display: block;
  color: var(--text-muted-2);
  font-size: 11px;
  margin-top: 5px;
}
.inspector-order a,
.inspector-location a {
  color: var(--accent-cyan);
  text-decoration: underline;
  text-underline-offset: 3px;
}
.inspector-stacks {
  padding: 0 0 18px;
  margin: 0 0 18px;
  border-bottom: 1px solid var(--border-color);
}
.inspector-stacks > div {
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin: 10px 0;
}
.inspector-stacks strong {
  font-size: 16px;
  font-weight: 500;
  color: var(--accent-cyan);
  overflow-wrap: anywhere;
  font-variant-numeric: tabular-nums;
}
.inspector-stacks small {
  color: var(--text-muted-2);
  font-size: 11px;
}
.inspector-location {
  display: flex;
  flex-direction: column;
  gap: 7px;
  font-size: 12px;
  overflow-wrap: anywhere;
  margin-top: 18px;
}
.inspector-location span {
  color: var(--text-muted-2);
}
.inspector-history > div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid var(--border-color);
  padding: 14px 0;
  font-variant-numeric: tabular-nums;
}
.inspector-status {
  font-size: 12px;
  color: var(--text-muted-2);
  line-height: 1.7;
}
.inspector-warning {
  color: var(--warning) !important;
  font-size: 12px;
  line-height: 1.6;
}
.inspector-warning button {
  text-decoration: underline;
}
.inspector-full-book {
  margin-top: 18px;
}
.inspector-claim-filter {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 20px;
  font-size: 11px;
  color: var(--text-muted-2);
}
.inspector-claim-filter :deep(select) {
  width: 100%;
  min-width: 0;
  font-size: 12px;
}
.inspector-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  margin: 16px 0;
  font-size: 11px;
  color: var(--text-muted-2);
}
.inspector-back {
  display: none;
  margin-bottom: 16px;
}
@media (max-width: 1000px) {
  .inspector-back {
    display: inline-flex;
  }
}
@media (max-width: 600px) {
  .trade-inspector {
    padding: 16px;
  }
  .inspector-tabs button {
    min-height: 44px;
  }
  .inspector-location a {
    min-height: 36px;
    padding: 5px 0;
  }
}
</style>
