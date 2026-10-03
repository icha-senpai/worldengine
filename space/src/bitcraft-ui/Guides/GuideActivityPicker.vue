<script setup>
import { route } from "../navigation";
import { bitcraftFetch as fetch } from "../api";
import { onBeforeUnmount, ref, watch } from "vue";
import Modal from "/src/bitcraft-ui/shared/Modal.vue";
import TextInput from "/src/bitcraft-ui/shared/TextInput.vue";
import { bitcraftAssetUrl } from "/src/bitcraft-ui/bitjitaAssets";
import { gatheringDefaults } from "/src/bitcraft-ui/gatheringEstimates";

const props = defineProps({ editor: { type: Object, default: null } });
const show = ref(false);
const activity = ref("crafting");
const query = ref("");
const items = ref([]);
const recipes = ref([]);
const chosen = ref(null);
const loading = ref(false);
const error = ref("");
let timer;
let request;
let selection;

function open(type) {
  const current = props.editor?.state.selection;
  selection = current ? { from: current.from, to: current.to } : null;
  activity.value = type;
  query.value = "";
  chosen.value = null;
  recipes.value = [];
  items.value = [];
  show.value = true;
  clearTimeout(timer);
  search();
}
async function get(url) {
  request?.abort();
  const controller = new AbortController();
  request = controller;
  loading.value = true;
  error.value = "";
  try {
    const response = await fetch(url, {
      headers: { Accept: "application/json" },
      signal: controller.signal,
    });
    if (!response.ok) throw new Error("Unable to load recipes. Try again.");
    const data = await response.json();
    return controller.signal.aborted ? null : data;
  } catch (failure) {
    if (!controller.signal.aborted) error.value = failure.message;
    return null;
  } finally {
    if (request === controller) loading.value = false;
  }
}
async function search() {
  const data = await get(
    route("bitcraft.guides.card-options", {
      activity: activity.value,
      q: query.value,
    }),
  );
  if (data) {
    items.value = data.items;
    if (!data.available) error.value = "The game snapshot is unavailable.";
  }
}
async function choose(item) {
  if (activity.value === "gathering") {
    insert(item.id, item.name);
    return;
  }
  chosen.value = item;
  recipes.value = [];
  const data = await get(
    route("bitcraft.guides.card-data", {
      activity: "crafting",
      itemId: item.id,
      kind: item.kind,
    }),
  );
  if (data) recipes.value = data.recipes;
}
function insert(recipeId, name) {
  props.editor
    ?.chain()
    .focus()
    .insertContentAt(selection, [
      {
        type: "bitcraftActivity",
        attrs: {
          activity: activity.value,
          recipeId,
          name,
          itemId: chosen.value?.id || null,
          kind: chosen.value?.kind || "item",
          settings: {
            quantity: 1,
            ...gatheringDefaults,
            market: false,
            region: "",
          },
        },
      },
      { type: "paragraph" },
    ])
    .run();
  show.value = false;
}
watch(query, () => {
  clearTimeout(timer);
  request?.abort();
  if (!show.value || chosen.value) return;
  loading.value = true;
  timer = setTimeout(search, 250);
});
watch(show, (value) => {
  if (!value) {
    clearTimeout(timer);
    request?.abort();
  }
});
onBeforeUnmount(() => {
  clearTimeout(timer);
  request?.abort();
});
</script>

<template>
  <div class="flex flex-wrap gap-2">
    <button
      type="button"
      class="app-btn app-btn--ghost app-btn--sm"
      :disabled="!editor"
      @mousedown.prevent
      @click="open('crafting')"
    >
      Insert crafting card
    </button>
    <button
      type="button"
      class="app-btn app-btn--ghost app-btn--sm"
      :disabled="!editor"
      @mousedown.prevent
      @click="open('gathering')"
    >
      Insert gathering card
    </button>
  </div>
  <Modal :show="show" @close="show = false">
    <section class="p-5" aria-labelledby="guide-activity-picker-title">
      <div class="mb-4 flex items-center justify-between gap-3">
        <h2
          id="guide-activity-picker-title"
          class="font-ui text-lg font-semibold"
        >
          {{ activity === "crafting" ? "Crafting card" : "Gathering card" }}
        </h2>
        <button
          type="button"
          class="app-btn app-btn--ghost app-btn--sm"
          @click="show = false"
        >
          Cancel
        </button>
      </div>
      <template v-if="!chosen">
        <label for="guide-activity-search" class="field-label">{{
          activity === "crafting"
            ? "Search recipe outputs"
            : "Search gathering actions"
        }}</label>
        <TextInput
          id="guide-activity-search"
          v-model="query"
          class="w-full"
          maxlength="255"
          autofocus
        />
      </template>
      <button
        v-else
        type="button"
        class="app-btn app-btn--ghost app-btn--sm mb-3"
        @click="
          chosen = null;
          search();
        "
      >
        Back to items
      </button>
      <div
        class="max-h-[55vh] overflow-y-auto"
        :aria-busy="loading"
        aria-live="polite"
      >
        <p v-if="loading" class="py-4 text-sm text-muted-2">
          Loading recipes...
        </p>
        <div v-else-if="error" class="py-4">
          <p class="text-sm text-muted-2">{{ error }}</p>
          <button
            type="button"
            class="app-btn app-btn--ghost app-btn--sm mt-3"
            @click="chosen ? choose(chosen) : search()"
          >
            Retry
          </button>
        </div>
        <div v-else-if="chosen" class="divide-y divide-border">
          <button
            v-for="recipe in recipes"
            :key="recipe.id"
            type="button"
            class="block w-full px-2 py-3 text-left hover:bg-surface-2"
            :aria-label="`Insert recipe ${recipe.recipeName}`"
            @click="insert(recipe.id, recipe.recipeName)"
          >
            <span class="block break-words text-sm font-semibold">{{
              recipe.recipeName
            }}</span>
            <span class="block text-xs text-muted-2"
              >{{ recipe.craftingStation || "No station required" }} &middot;
              Makes {{ recipe.outputQuantity }}</span
            >
          </button>
        </div>
        <div v-else-if="items.length" class="mt-3 divide-y divide-border">
          <button
            v-for="item in items"
            :key="`${item.kind || activity}:${item.id}`"
            type="button"
            class="flex w-full items-center gap-3 px-2 py-3 text-left hover:bg-surface-2"
            :aria-label="`Choose ${item.name}`"
            @click="choose(item)"
          >
            <img
              v-if="bitcraftAssetUrl(item.iconAssetName)"
              :src="bitcraftAssetUrl(item.iconAssetName)"
              alt=""
              loading="lazy"
              class="size-10 shrink-0 object-contain"
            />
            <span class="min-w-0 flex-1">
              <span class="block break-words text-sm font-semibold">{{
                item.name
              }}</span>
              <span class="block text-xs text-muted-2">{{
                item.category || item.kind
              }}</span>
            </span>
          </button>
        </div>
        <p v-else class="py-4 text-sm text-muted-2">
          No matching recipes found.
        </p>
      </div>
    </section>
  </Modal>
</template>
