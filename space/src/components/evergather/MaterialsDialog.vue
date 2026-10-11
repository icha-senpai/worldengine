<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import { evergather, evergatherReady } from "../../evergather";
import {
  actionProcessing,
  useActionForm,
} from "../../evergather/useActionForm";
import {
  materialOutputLabel,
  sourcesForMaterial,
  trainingSourceFor,
} from "../../evergather/materialSources";

import { repairTarget, resultFeedback } from "../../evergather/gameplayUi";

const props = defineProps({
  target: { type: Object, required: true },
  sources: { type: Object, required: true },
  player: { type: Object, required: true },
  error: { type: String, default: "" },
});
const emit = defineEmits(["close", "complete"]);
const dialog = ref(null);
const loaded = ref(false);
const loadError = ref("");
const trail = ref([]);
const notice = ref("");
const runningSource = ref("");
const repairingId = ref(null);
const repairReturnTrail = ref([]);
const earnGoldOpen = ref(false);
const activeTarget = computed(() =>
  repairingId.value
    ? (repairTarget(
        props.sources.tools?.find((tool) => tool.tool_id === repairingId.value),
      ) ?? {
        label: "Repair",
        toolName: "Tool unavailable",
        materials: [],
        goldCost: 0,
        ready: false,
        status: "This tool is no longer available.",
      })
    : props.target,
);
const goldJobs = computed(() =>
  [...(props.sources.jobs ?? [])]
    .filter(
      (job) =>
        job.is_unlocked && job.is_demand_available && !job.requires_acceptance,
    )
    .sort(
      (a, b) =>
        Number(b.can_complete) - Number(a.can_complete) ||
        a.required_level - b.required_level ||
        a.label.localeCompare(b.label),
    )
    .slice(0, 6),
);
const goldItems = computed(() =>
  (props.sources.inventory ?? [])
    .filter(
      (item) =>
        item.tradeable && item.owned_quantity > 0 && item.npc_buy_price > 0,
    )
    .slice(0, 6),
);
function initialMaterial() {
  trail.value = [];
  const first =
    activeTarget.value.materials.find((item) => !item.has_enough) ??
    activeTarget.value.materials[0];
  if (first) selectMaterial(first);
}
function openRepair(id) {
  if (!id || repairingId.value) return;
  repairReturnTrail.value = [...trail.value];
  repairingId.value = id;
  earnGoldOpen.value = false;
  notice.value = "";
  initialMaterial();
}
function backToTask() {
  repairingId.value = null;
  trail.value = [...repairReturnTrail.value];
  notice.value = "";
}
function completeTarget() {
  if (!activeTarget.value.ready) return;
  if (!repairingId.value) {
    emit("complete");
    return;
  }
  form.tool_id = repairingId.value;
  form.submit("repair", {
    onSuccess: () => {
      backToTask();
      notice.value = "Tool repaired. Continue your task.";
    },
  });
}
function sellOne(item) {
  form.item_key = item.item_key;
  form.quantity = 1;
  form.submit("vendor", {
    onSuccess: () => {
      notice.value = resultFeedback(props.sources.result) || "Sold one item.";
    },
  });
}
function finishJob(job) {
  form.job = job.key;
  form.submit("job", {
    onSuccess: () => {
      notice.value =
        resultFeedback(props.sources.result) || "Commission completed.";
    },
  });
}

const form = useActionForm({
  action: "",
  activity: "",
  recipe: "",
  expedition: "",
  offer: "",
  job: "",
  tool_id: "",
  item_key: "",
  quantity: 1,
});
let subscription;
let subscriptionGeneration = 0;
const queries = [
  "gathering_definitions",
  "activity_definitions",
  "recipe_definitions",
  "expedition_definitions",
  "shop_definitions",
  "job_definitions",
  "equipment_definitions",
  "inventory_guide_definitions",
].map((table) => `SELECT * FROM ${table}`);

watch(
  [evergather, evergatherReady],
  ([connection, ready]) => {
    const generation = ++subscriptionGeneration;
    subscription?.unsubscribe();
    subscription = undefined;
    loaded.value = false;
    loadError.value = "";
    if (!connection || !ready) return;
    subscription = connection
      .subscriptionBuilder()
      .onApplied(() => {
        if (generation === subscriptionGeneration) loaded.value = true;
      })
      .onError((_context, error) => {
        if (generation === subscriptionGeneration)
          loadError.value = error.message;
      })
      .subscribe(queries);
  },
  { immediate: true },
);

const selectedMaterial = computed(() => {
  const selected = trail.value.at(-1);
  if (!selected) return null;
  const requirements = selected.sourceId?.startsWith("job:")
    ? props.sources.jobs?.find((job) => "job:" + job.key === selected.sourceId)
        ?.requirements
    : selected.sourceId
      ? sourcesForMaterial(
          selected.parentKey,
          props.sources,
          props.player,
        ).find((source) => source.id === selected.sourceId)?.requirements
      : activeTarget.value.materials;
  return (
    requirements?.find((item) => item.item_key === selected.item_key) ??
    selected
  );
});
const availableSources = computed(() =>
  selectedMaterial.value
    ? sourcesForMaterial(
        selectedMaterial.value.item_key,
        props.sources,
        props.player,
      )
    : [],
);
const trainingSources = computed(() => {
  const unique = new Map();
  for (const source of availableSources.value) {
    const training = trainingSourceFor(source, props.sources);
    if (training) unique.set(training.id, training);
  }
  return [...unique.values()];
});

function selectMaterial(item, source) {
  trail.value.push({
    ...item,
    sourceId: source?.id,
    parentKey: selectedMaterial.value?.item_key,
  });
  notice.value = "";
}
function goBack() {
  trail.value.pop();
  notice.value = "";
}
function acquire(source) {
  if (!source.ready || actionProcessing.value || !loaded.value) return;
  const field = {
    gather: "action",
    activity: "activity",
    craft: "recipe",
    job: "job",
    expedition: "expedition",
    shop: "offer",
  }[source.action];
  form[field] = source.entry.key;
  form.submit(source.action, {
    onStart: () => {
      runningSource.value = source.id;
      notice.value = "";
    },
    onSuccess: () => {
      notice.value =
        resultFeedback(props.sources.result) ||
        source.entry.label + " complete. Inventory updated.";
    },
    onFinish: () => {
      runningSource.value = "";
    },
  });
}
onMounted(async () => {
  await nextTick();
  dialog.value.showModal();
  initialMaterial();
});
onBeforeUnmount(() => {
  subscriptionGeneration++;
  subscription?.unsubscribe();
  dialog.value?.close();
});
</script>

<template>
  <Teleport to="body">
    <dialog
      ref="dialog"
      class="materials-dialog rounded-lg border border-border bg-surface-1 text-primary shadow-2xl"
      aria-labelledby="materials-dialog-title"
      @cancel.prevent="emit('close')"
      @click="$event.target === dialog && emit('close')"
    >
      <div class="flex max-h-[85dvh] flex-col">
        <header
          class="flex items-start justify-between gap-4 border-b border-border p-4"
        >
          <div class="min-w-0">
            <h2 id="materials-dialog-title" class="font-ui text-base">
              {{ activeTarget.label }} · {{ activeTarget.toolName }}
            </h2>
            <p class="mt-1 text-xs text-muted-2">
              {{
                activeTarget.browseOnly
                  ? "Gather or make more supplies here."
                  : "Gather and make supplies here, then finish your task."
              }}
            </p>
          </div>
          <button
            type="button"
            class="app-btn app-btn--ghost app-btn--sm"
            aria-label="Close materials window"
            @click="emit('close')"
          >
            Close
          </button>
        </header>

        <div class="min-h-0 overflow-y-auto p-4">
          <button
            v-if="repairingId"
            class="app-btn app-btn--ghost app-btn--sm mb-3"
            @click="backToTask"
          >
            Back to {{ target.toolName }}
          </button>
          <button
            v-if="activeTarget.repairToolId && !repairingId"
            class="app-btn app-btn--primary app-btn--sm mb-3"
            @click="openRepair(activeTarget.repairToolId)"
          >
            Repair tool
          </button>
          <div
            class="flex flex-wrap items-center justify-between gap-2 text-xs"
          >
            <span
              >{{ activeTarget.goldCost }}g needed · {{ player.gold }}g
              owned</span
            >
            <span class="tag">{{
              activeTarget.ready ? "Ready" : activeTarget.status
            }}</span>
          </div>
          <details
            v-if="activeTarget.goldCost > player.gold"
            :open="earnGoldOpen"
            class="game-tool-details"
          >
            <summary @click.prevent="earnGoldOpen = !earnGoldOpen">
              Earn {{ activeTarget.goldCost - player.gold }} more gold
            </summary>
            <div class="game-tool-details__body">
              <p class="text-sm text-muted-2">
                Finish a commission or sell one owned item. Every sale below is
                for one item.
              </p>
              <article
                v-for="job in goldJobs"
                :key="job.key"
                class="rounded-md border border-border p-3"
              >
                <div class="flex items-center justify-between gap-2">
                  <strong>{{ job.label }} · +{{ job.gold }}g</strong
                  ><button
                    class="app-btn app-btn--primary app-btn--sm"
                    :disabled="actionProcessing || !job.can_complete"
                    @click="finishJob(job)"
                  >
                    Turn In
                  </button>
                </div>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button
                    v-for="item in job.requirements"
                    :key="item.item_key"
                    class="app-btn app-btn--ghost app-btn--sm"
                    @click="
                      trail = [];
                      selectMaterial(item, { id: 'job:' + job.key });
                    "
                  >
                    {{ item.owned_quantity }}/{{ item.quantity }}
                    {{ item.item_name }} · Get
                  </button>
                </div>
              </article>
              <div
                v-for="item in goldItems"
                :key="item.item_key"
                class="flex items-center justify-between gap-3"
              >
                <span
                  >{{ item.item_name }} · {{ item.owned_quantity }} owned</span
                ><button
                  class="app-btn app-btn--ghost app-btn--sm"
                  :disabled="actionProcessing"
                  @click="sellOne(item)"
                >
                  Sell 1 · +{{ item.npc_buy_price }}g
                </button>
              </div>
              <p v-if="loaded && !goldJobs.length && !goldItems.length">
                Gather materials to sell or unlock a commission.
              </p>
            </div>
          </details>
          <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <div
              v-for="item in activeTarget.materials"
              :key="item.item_key"
              class="flex items-center justify-between gap-3 rounded-md border border-border bg-canvas p-3"
            >
              <div class="min-w-0">
                <p class="text-sm font-ui">{{ item.item_name }}</p>
                <p
                  class="mt-1 text-xs"
                  :class="item.has_enough ? 'text-success' : 'text-muted-2'"
                >
                  {{ item.owned_quantity }} / {{ item.quantity }} owned ·
                  {{
                    item.has_enough
                      ? "Ready"
                      : `${Math.max(0, item.quantity - item.owned_quantity)} missing`
                  }}
                </p>
              </div>
              <button
                type="button"
                class="app-btn app-btn--ghost app-btn--sm"
                @click="
                  trail = [];
                  selectMaterial(item);
                "
              >
                Get
              </button>
            </div>
          </div>

          <p v-if="activeTarget.details" class="mt-3 text-xs text-muted-2">
            {{ activeTarget.details }}
          </p>

          <p v-if="loadError" role="alert" class="mt-4 text-sm text-danger">
            {{ loadError }}
          </p>
          <p
            v-else-if="!loaded"
            role="status"
            class="mt-4 text-sm text-muted-2"
          >
            Loading material sources…
          </p>
          <template v-else-if="selectedMaterial">
            <div class="mt-5 flex flex-wrap items-center gap-3">
              <button
                type="button"
                class="app-btn app-btn--ghost app-btn--sm"
                @click="goBack"
              >
                Back
              </button>
              <h3 class="text-sm font-ui">
                Get {{ selectedMaterial.item_name }}
              </h3>
              <span class="tag"
                >{{ selectedMaterial.owned_quantity }} /
                {{ selectedMaterial.quantity }} owned</span
              >
            </div>
            <p v-if="trail.length > 1" class="mt-2 text-xs text-muted-3">
              {{ trail.map((item) => item.item_name).join(" → ") }}
            </p>
            <p
              v-if="selectedMaterial.has_enough"
              role="status"
              class="mt-2 text-xs text-success"
            >
              You have enough. Go back to finish the next step.
            </p>
            <div
              v-if="trainingSources.length"
              class="mt-3 flex flex-wrap gap-2"
            >
              <button
                v-for="training in trainingSources"
                :key="training.id"
                class="app-btn app-btn--ghost app-btn--sm"
                :disabled="actionProcessing || !training.ready"
                @click="acquire(training)"
              >
                Train {{ training.entry.skill_label }} · Lv
                {{ training.entry.skill_level }} / 10
              </button>
            </div>
            <div class="mt-3 grid gap-3">
              <article
                v-for="source in availableSources"
                :key="source.id"
                class="rounded-md border border-border bg-surface-2 p-3"
              >
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <h4 class="text-sm font-ui">{{ source.entry.label }}</h4>
                    <p class="mt-1 text-xs text-muted-2">
                      {{ source.label }} ·
                      {{
                        source.entry.skill_label
                          ? `${source.entry.skill_label} Lv ${source.entry.required_level}`
                          : `Lv ${source.entry.required_level}`
                      }}<template v-if="source.cost">
                        · {{ source.cost }}g</template
                      >
                    </p>
                    <p class="mt-1 text-xs text-muted-3">
                      {{ materialOutputLabel(source.item)
                      }}<template v-if="source.entry.location">
                        · {{ source.entry.location }}</template
                      >
                    </p>
                  </div>
                  <button
                    type="button"
                    class="app-btn app-btn--primary app-btn--sm"
                    :disabled="actionProcessing || !source.ready"
                    @click="acquire(source)"
                  >
                    {{
                      runningSource === source.id ? "Working…" : source.label
                    }}
                  </button>
                </div>
                <p
                  v-if="source.blockedReason"
                  class="mt-2 text-xs text-muted-2"
                >
                  {{ source.blockedReason }}
                </p>
                <button
                  v-if="source.entry.requires_tool_repair && !repairingId"
                  class="app-btn app-btn--ghost app-btn--sm mt-2"
                  @click="openRepair(source.entry.equipped_tool?.tool_id)"
                >
                  Repair tool
                </button>
                <div
                  v-if="source.requirements.length"
                  class="mt-3 flex flex-wrap gap-2"
                >
                  <button
                    v-for="ingredient in source.requirements"
                    :key="ingredient.item_key"
                    type="button"
                    class="app-btn app-btn--ghost app-btn--sm"
                    @click="selectMaterial(ingredient, source)"
                  >
                    {{ ingredient.owned_quantity }} / {{ ingredient.quantity }}
                    {{ ingredient.item_name }} · Get
                  </button>
                </div>
              </article>
            </div>
            <p
              v-if="!availableSources.length"
              class="mt-3 text-sm text-muted-2"
            >
              No gathering, activity, crafting, expedition, or shop source
              exists for this material. Check the player marketplace.
            </p>
          </template>
          <p v-else-if="loaded" class="mt-4 text-sm text-muted-2">
            Choose Get beside a material to see how to acquire it.
          </p>
          <p v-if="notice" role="status" class="mt-3 text-sm text-success">
            {{ notice }}
          </p>
          <p
            v-if="form.errors._error || error"
            role="alert"
            class="mt-3 text-sm text-danger"
          >
            {{ form.errors._error || error }}
          </p>
        </div>

        <footer
          class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4"
        >
          <p class="text-xs text-muted-2">
            {{
              activeTarget.ready
                ? "Supplies ready. Finish whenever you like."
                : activeTarget.status
            }}
          </p>
          <button
            type="button"
            class="app-btn app-btn--primary app-btn--sm"
            v-if="!activeTarget.browseOnly"
            :disabled="actionProcessing || !activeTarget.ready"
            @click="completeTarget"
          >
            {{ activeTarget.actionLabel ?? activeTarget.label }}
          </button>
        </footer>
      </div>
    </dialog>
  </Teleport>
</template>

<style scoped>
.materials-dialog {
  width: min(48rem, calc(100vw - 1.5rem));
  max-width: none;
  max-height: 85dvh;
  padding: 0;
  margin: auto;
}
.materials-dialog::backdrop {
  background: rgb(0 0 0 / 70%);
}
</style>
