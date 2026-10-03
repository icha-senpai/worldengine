<script setup>
import {
  computed,
  defineAsyncComponent,
  onBeforeUnmount,
  ref,
  watch,
} from "vue";
import { readWidget } from "../bitcraft-ui/widgets";
import { useRoute } from "vue-router";
import { pageProps } from "../bitcraft-ui/api";
import {
  pageState,
  setPageLoader,
  trackPageLoad,
} from "../bitcraft-ui/navigation";
const route = useRoute();
const pages = {
  market: defineAsyncComponent(() => import("../bitcraft-ui/Market.vue")),
  "barter-stalls": defineAsyncComponent(
    () => import("../bitcraft-ui/Market.vue"),
  ),
  crafting: defineAsyncComponent(() => import("../bitcraft-ui/Crafting.vue")),
  "tool-rates": defineAsyncComponent(
    () => import("../bitcraft-ui/ToolRates.vue"),
  ),
  "hunting-calculator": defineAsyncComponent(
    () => import("../bitcraft-ui/HuntingCalculator.vue"),
  ),
  "open-crafts": defineAsyncComponent(
    () => import("../bitcraft-ui/OpenCrafts.vue"),
  ),
  activity: defineAsyncComponent(() => import("../bitcraft-ui/Activity.vue")),
  inventory: defineAsyncComponent(
    () => import("../bitcraft-ui/InventoryTracker.vue"),
  ),
  "passive-crafts": defineAsyncComponent(
    () => import("../bitcraft-ui/PassiveCraftTracker.vue"),
  ),
  tasks: defineAsyncComponent(() => import("../bitcraft-ui/TaskTracker.vue")),
  guides: defineAsyncComponent(() => import("../bitcraft-ui/Guides/Index.vue")),
  guide: defineAsyncComponent(() => import("../bitcraft-ui/Guides/Show.vue")),
  guideEditor: defineAsyncComponent(
    () => import("../bitcraft-ui/Guides/Form.vue"),
  ),
};
const tool = computed(() =>
  route.meta.guideEditor
    ? "guideEditor"
    : route.params.guide
      ? "guide"
      : String(route.params.tool),
);
const setup = computed(
  () => route.params.mode !== "widget" && route.query.setup !== "0",
);
const component = computed(() => pages[tool.value]);
const currentProps = ref(null),
  error = ref(""),
  loading = ref(false);
let generation = 0;
const widgetRevision = ref(0);
const componentKey = computed(
  () =>
    `${tool.value}:${route.params.guide ?? ""}:${setup.value}:${["activity", "inventory", "passive-crafts"].includes(tool.value) ? `${route.fullPath}:${widgetRevision.value}` : ""}`,
);
let renderedKey = "";
let widgetSettings = "";
async function load() {
  const request = ++generation;
  loading.value = true;
  error.value = "";
  if (renderedKey !== componentKey.value) currentProps.value = null;
  try {
    const props = await pageProps(
      ["guide", "guideEditor"].includes(tool.value) ? "guides" : tool.value,
      route.query,
      setup.value,
      route.params.guide ?? "",
      Boolean(route.meta.guideEditor),
    );
    if (request !== generation) return;
    currentProps.value = props;
    renderedKey = componentKey.value;
    if (route.query.profile)
      widgetSettings = JSON.stringify(
        await readWidget(tool.value, String(route.query.profile)),
      );
    pageState.props = { ...pageState.props, ...props };
    pageState.url = route.fullPath;
  } catch (failure) {
    if (request === generation)
      error.value = failure.message ?? "This tool could not load.";
  } finally {
    if (request === generation) loading.value = false;
  }
}
setPageLoader(() => trackPageLoad(load()));
watch(
  () => route.fullPath,
  () => trackPageLoad(load()),
  { immediate: true },
);
const profileTimer = setInterval(async () => {
  if (setup.value || !route.query.profile || loading.value) return;
  try {
    const settings = await readWidget(tool.value, String(route.query.profile));
    const normalized = JSON.stringify(settings);
    if (normalized !== widgetSettings) {
      if (tool.value === "tasks")
        currentProps.value = {
          filters: { ...settings, profile: route.query.profile, setup: false },
        };
      else {
        widgetRevision.value++;
        await load();
      }
      widgetSettings = normalized;
    }
  } catch {
    /* Keep the last rendered widget when its connection is unavailable. */
  }
}, 5000);
onBeforeUnmount(() => {
  generation++;
  clearInterval(profileTimer);
  setPageLoader(async () => {});
});
</script>
<template>
  <div
    class="bitcraft-ui"
    :class="{
      'bitcraft-widget':
        !setup &&
        ['activity', 'inventory', 'passive-crafts', 'tasks'].includes(tool),
    }"
    :aria-busy="loading"
  >
    <p v-if="pageState.error" class="surface-section p-5" role="alert">
      {{ pageState.error }}
    </p>
    <p v-if="loading && !currentProps" class="bitcraft-loading" role="status">
      Loading BitCraft…
    </p>
    <div v-if="error" class="surface-section p-5" role="alert">
      <p>{{ error }}</p>
      <button class="app-btn app-btn--ghost" @click="load">Try again</button>
    </div>
    <component
      v-if="component && currentProps"
      :is="component"
      :key="componentKey"
      v-bind="currentProps"
    />
  </div>
</template>
