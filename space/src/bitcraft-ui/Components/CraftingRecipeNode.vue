<template>
  <div
    class="crafting-node"
    :class="{ 'crafting-node--root': root, 'crafting-node--deep': depth >= 2 }"
  >
    <div class="crafting-node__recipe">
      <div class="crafting-node__recipe-head">
        <h4>{{ activeRecipe.name.replace(/^craft\s+/i, "") }}</h4>

        <SelectInput
          v-if="hasAlternatives"
          v-model.number="selectedAlternativeIndex"
          class="crafting-node__route-select"
          aria-label="Recipe route"
          :title="routeLabel(activeRecipe)"
        >
          <option
            v-for="(alternative, index) in alternatives"
            :key="alternative.id ?? alternative.name"
            :value="index"
          >
            {{ routeLabel(alternative) }}
          </option>
        </SelectInput>
      </div>

      <div
        v-if="recipeMeta || activeRecipe.outputQuantity || batches > 1"
        class="crafting-node__meta"
      >
        <span v-if="recipeMeta">{{ recipeMeta }}</span>
        <span v-if="activeRecipe.gathering"
          >Gather {{ formatQuantity(desiredQuantity) }}</span
        >
        <span v-else-if="activeRecipe.outputQuantity"
          >Makes {{ formatQuantity(activeRecipe.outputQuantity) }}</span
        >
        <span v-if="!activeRecipe.gathering && batches > 1"
          >{{ formatQuantity(batches) }} batches</span
        >
      </div>
    </div>

    <div
      v-if="activeRecipe.ingredients?.length"
      class="crafting-node__ingredients"
    >
      <template
        v-for="ingredient in activeRecipe.ingredients"
        :key="`${ingredient.kind}-${ingredient.id}-${ingredient.name}`"
      >
        <details
          v-if="ingredient.recipes?.length"
          class="crafting-ingredient"
          :open="tree?.isExpanded(ingredientPath(ingredient)) ?? false"
          @toggle="
            tree?.setExpanded(ingredientPath(ingredient), $event.target.open)
          "
        >
          <summary class="crafting-ingredient__row">
            <span
              class="crafting-ingredient__icon"
              :style="ingredientFrameStyle(ingredient)"
              aria-hidden="true"
            >
              <img
                v-if="ingredientIconUrl(ingredient)"
                :src="ingredientIconUrl(ingredient)"
                alt=""
                loading="lazy"
                @error="hideBrokenIcon(ingredient.iconAssetName)"
              />
              <span v-else>{{ itemInitials(ingredient.name) }}</span>
            </span>
            <span class="crafting-ingredient__copy">
              <span class="crafting-ingredient__name">
                <strong
                  >{{
                    formatQuantity(scaledQuantity(ingredient.quantity))
                  }}x</strong
                >
                {{ ingredient.name }}
              </span>
              <span
                v-if="ingredientMeta(ingredient)"
                class="crafting-ingredient__meta"
                >{{ ingredientMeta(ingredient) }}</span
              >
              <span v-if="stockAvailable" class="crafting-ingredient__stock">
                Need {{ formatQuantity(scaledQuantity(ingredient.quantity)) }} ·
                Held {{ formatQuantity(heldQuantity(ingredient)) }} ·
                <span
                  :class="{
                    'crafting-ingredient__missing':
                      missingQuantity(ingredient) > 0,
                  }"
                  >Missing
                  {{ formatQuantity(missingQuantity(ingredient)) }}</span
                >
              </span>
            </span>
            <BitcraftTierBadge
              v-if="hasTier(ingredient.tier) && Number(ingredient.tier) > 0"
              class="crafting-ingredient__tier"
              :tier="ingredient.tier"
            />
            <span
              class="crafting-ingredient__chevron"
              aria-hidden="true"
            ></span>
          </summary>

          <div class="crafting-ingredient__children">
            <CraftingRecipeNode
              v-for="childRecipe in ingredient.recipes"
              :key="childRecipe.id ?? childRecipe.name"
              :recipe="childRecipe"
              :depth="depth + 1"
              :path="`${ingredientPath(ingredient)}/${childRecipe.id ?? childRecipe.name}`"
              :desired-quantity="scaledQuantity(ingredient.quantity)"
              @route-selected="emit('route-selected', $event)"
            />
          </div>
        </details>

        <div v-else class="crafting-ingredient">
          <div class="crafting-ingredient__row">
            <span
              class="crafting-ingredient__icon"
              :style="ingredientFrameStyle(ingredient)"
              aria-hidden="true"
            >
              <img
                v-if="ingredientIconUrl(ingredient)"
                :src="ingredientIconUrl(ingredient)"
                alt=""
                loading="lazy"
                @error="hideBrokenIcon(ingredient.iconAssetName)"
              />
              <span v-else>{{ itemInitials(ingredient.name) }}</span>
            </span>
            <span class="crafting-ingredient__copy">
              <span class="crafting-ingredient__name">
                <strong
                  >{{
                    formatQuantity(scaledQuantity(ingredient.quantity))
                  }}x</strong
                >
                {{ ingredient.name }}
              </span>
              <span
                v-if="ingredientMeta(ingredient)"
                class="crafting-ingredient__meta"
                >{{ ingredientMeta(ingredient) }}</span
              >
              <span v-if="stockAvailable" class="crafting-ingredient__stock">
                Need {{ formatQuantity(scaledQuantity(ingredient.quantity)) }} ·
                Held {{ formatQuantity(heldQuantity(ingredient)) }} ·
                <span
                  :class="{
                    'crafting-ingredient__missing':
                      missingQuantity(ingredient) > 0,
                  }"
                  >Missing
                  {{ formatQuantity(missingQuantity(ingredient)) }}</span
                >
              </span>
            </span>
            <BitcraftTierBadge
              v-if="hasTier(ingredient.tier) && Number(ingredient.tier) > 0"
              class="crafting-ingredient__tier"
              :tier="ingredient.tier"
            />
            <button
              v-if="ingredient.recipesDeferred"
              type="button"
              class="crafting-ingredient__load"
              :disabled="isBranchLoading(ingredient)"
              @click.stop="loadBranch(ingredient)"
            >
              {{ isBranchLoading(ingredient) ? "Loading..." : "Load recipe" }}
            </button>
          </div>
          <p v-if="branchError(ingredient)" class="crafting-ingredient__error">
            {{ branchError(ingredient) }}
          </p>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import { bitcraftFetch as fetch } from "/src/bitcraft-ui/api";
import { route } from "/src/bitcraft-ui/navigation";
import { computed, inject, ref, watch } from "vue";
import BitcraftTierBadge from "/src/bitcraft-ui/Components/BitcraftTierBadge.vue";
import {
  bitcraftItemFrameStyle,
  bitjitaAssetUrl,
  hasBitcraftTier,
} from "/src/bitcraft-ui/bitjitaAssets.js";

import {
  craftingTreeContext,
  defaultRecipeIndex,
  formatCraftDuration,
  recipeRouteLabel,
} from "../craftingTree.js";

defineOptions({ name: "CraftingRecipeNode" });

const emit = defineEmits(["route-selected"]);

const props = defineProps({
  recipe: { type: Object, required: true },
  desiredQuantity: { type: Number, default: 1 },
  root: { type: Boolean, default: false },
  depth: { type: Number, default: 0 },
  path: { type: String, default: "root" },
});

const tree = inject(craftingTreeContext, null);

const selectedAlternativeIndex = ref(
  Number(
    props.recipe.selectedAlternativeIndex ?? defaultRecipeIndex(props.recipe),
  ),
);
const brokenIconAssets = ref(new Set());
const loadingBranches = ref(new Set());
const branchErrors = ref({});

const alternatives = computed(() => {
  if (
    Array.isArray(props.recipe.alternatives) &&
    props.recipe.alternatives.length > 1
  ) {
    return props.recipe.alternatives;
  }

  return [props.recipe];
});

const hasAlternatives = computed(() => alternatives.value.length > 1);

const activeRecipe = computed(
  () =>
    alternatives.value[selectedAlternativeIndex.value] ??
    alternatives.value[0] ??
    props.recipe,
);

watch(
  () => props.recipe,
  () => {
    selectedAlternativeIndex.value = Number(
      props.recipe.selectedAlternativeIndex ?? defaultRecipeIndex(props.recipe),
    );
  },
);

watch(selectedAlternativeIndex, (index) => {
  emit("route-selected", { recipe: props.recipe, index });
});

const formatQuantity = (quantity) => {
  const number = Number(quantity);

  if (!Number.isFinite(number)) {
    return quantity;
  }

  return new Intl.NumberFormat().format(number);
};

const batches = computed(() => {
  const outputQuantity = Math.max(
    1,
    Number(activeRecipe.value.outputQuantity ?? 1) || 1,
  );

  return Math.max(1, Math.ceil(props.desiredQuantity / outputQuantity));
});

const recipeMeta = computed(() =>
  [
    activeRecipe.value.station,
    activeRecipe.value.skill,
    formatCraftDuration(activeRecipe.value.duration),
  ]
    .filter(Boolean)
    .join(" · "),
);

const routeLabel = recipeRouteLabel;
const ingredientPath = (ingredient) =>
  `${props.path}/${activeRecipe.value.id ?? activeRecipe.value.name}/${ingredient.kind}:${ingredient.id}`;
const stockAvailable = computed(
  () => tree?.inventory.value && !tree.inventory.value.error,
);
const heldQuantity = (ingredient) =>
  Number(
    tree?.inventory.value?.quantities?.[
      `${ingredient.kind}:${ingredient.id}`
    ] ?? 0,
  );
const missingQuantity = (ingredient) =>
  Math.max(0, scaledQuantity(ingredient.quantity) - heldQuantity(ingredient));

const ingredientMeta = (ingredient) =>
  [ingredient.kind === "cargo" ? "Cargo" : null].filter(Boolean).join(" · ");

const itemInitials = (name) =>
  String(name ?? "?")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("") || "?";

const ingredientIconUrl = (ingredient) => {
  const assetName = ingredient?.iconAssetName;

  if (brokenIconAssets.value.has(assetName)) {
    return null;
  }

  return bitjitaAssetUrl(assetName);
};

const hideBrokenIcon = (assetName) => {
  brokenIconAssets.value = new Set([...brokenIconAssets.value, assetName]);
};

const branchKey = (ingredient) => `${ingredient.kind}:${ingredient.id}`;

const isBranchLoading = (ingredient) =>
  loadingBranches.value.has(branchKey(ingredient));

const branchError = (ingredient) =>
  branchErrors.value[branchKey(ingredient)] ?? null;

const setBranchError = (ingredient, message) => {
  branchErrors.value = {
    ...branchErrors.value,
    [branchKey(ingredient)]: message,
  };
};

const clearBranchError = (ingredient) => {
  const nextErrors = { ...branchErrors.value };
  delete nextErrors[branchKey(ingredient)];
  branchErrors.value = nextErrors;
};

const loadBranch = async (ingredient) => {
  if (!ingredient?.id || !ingredient?.kind || isBranchLoading(ingredient)) {
    return;
  }

  const key = branchKey(ingredient);
  loadingBranches.value = new Set([...loadingBranches.value, key]);
  clearBranchError(ingredient);

  try {
    const url = route("bitcraft.crafting.branch", {
      itemId: ingredient.id,
      itemKind: ingredient.kind,
    });
    const response = await fetch(url, {
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    });

    if (!response.ok) {
      throw new Error("Branch request failed.");
    }

    const payload = await response.json();
    ingredient.recipes = Array.isArray(payload.recipes) ? payload.recipes : [];
    tree?.prepareRecipes(ingredient.recipes);
    ingredient.recipesDeferred = false;
    tree?.setExpanded(ingredientPath(ingredient), true);

    if (!ingredient.recipes.length) {
      setBranchError(
        ingredient,
        payload.error ?? "No crafting steps found for this branch.",
      );
    }
  } catch (error) {
    setBranchError(ingredient, "Could not load this branch.");
  } finally {
    const nextLoadingBranches = new Set(loadingBranches.value);
    nextLoadingBranches.delete(key);
    loadingBranches.value = nextLoadingBranches;
  }
};

const ingredientFrameStyle = (ingredient) =>
  bitcraftItemFrameStyle(ingredient?.tier, ingredient?.rarity);
const hasTier = (tier) => hasBitcraftTier(tier);

const scaledQuantity = (quantity) => {
  const number = Number(quantity);

  if (!Number.isFinite(number)) {
    return quantity;
  }

  return number * batches.value;
};
</script>

<style scoped>
.crafting-node {
  --crafting-connector: rgb(var(--accent-cyan-rgb) / 0.34);
  --crafting-connector-muted: rgb(var(--border-color-2-rgb) / 0.72);
  display: grid;
  gap: 10px;
  max-width: 100%;
  min-width: 0;
  position: relative;
}

.crafting-node--root {
  padding: 14px 16px 16px;
}

.crafting-node__recipe {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.crafting-node__recipe-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-width: 0;
}

.crafting-node__recipe h4 {
  margin: 0;
  color: var(--text-muted);
  font-family: var(--font-ui);
  font-size: 14px;
  font-weight: 700;
  min-width: 0;
  overflow-wrap: break-word;
}

.crafting-node__route-select {
  min-width: 0;
  max-width: 100%;
  width: min(260px, 100%);
}

.crafting-node__route-select :deep(.app-select__trigger) {
  border: 1px solid rgb(var(--border-color-rgb) / 0.82);
  border-radius: 6px;
  background: var(--bg-canvas-2);
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 12px;
  font-weight: 650;
  line-height: 1.2;
  padding: 7px 9px;
}

.crafting-node__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.crafting-node__meta span {
  border: 1px solid rgb(var(--border-color-rgb) / 0.72);
  border-radius: 5px;
  background: var(--bg-surface);
  color: var(--text-muted-2);
  font-family: var(--font-ui);
  font-size: 11px;
  font-weight: 650;
  line-height: 1;
  padding: 5px 7px;
}

.crafting-node__ingredients {
  position: relative;
  display: grid;
  gap: 12px;
  margin-left: 10px;
  min-width: 0;
  padding-left: 12px;
}

.crafting-node__ingredients::before {
  content: "";
  position: absolute;
  top: 0;
  bottom: 29px;
  left: 0;
  width: 1px;
  background: linear-gradient(
    180deg,
    var(--crafting-connector),
    var(--crafting-connector-muted)
  );
}

.crafting-ingredient {
  position: relative;
  display: block;
  min-width: 0;
}

.crafting-ingredient::before {
  content: "";
  position: absolute;
  top: 28px;
  left: -12px;
  width: 12px;
  height: 12px;
  border-bottom: 1px solid var(--crafting-connector);
  border-left: 1px solid var(--crafting-connector);
  border-bottom-left-radius: 8px;
  transform: translateY(-100%);
}

.crafting-ingredient:last-child::after {
  content: "";
  position: absolute;
  top: 29px;
  bottom: -12px;
  left: -12px;
  width: 2px;
  background: var(--bg-surface);
}

.crafting-ingredient__row {
  position: relative;
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr) auto auto;
  align-items: center;
  box-sizing: border-box;
  gap: 10px;
  max-width: 100%;
  min-height: 58px;
  min-width: 0;
  border: 1px solid rgb(var(--border-color-2-rgb) / 0.62);
  border-radius: 8px;
  background: var(--bg-surface);
  color: var(--text-primary);
  cursor: default;
  list-style: none;
  padding: 10px 12px;
}

.crafting-ingredient__row::before {
  content: "";
  position: absolute;
  top: 50%;
  left: -17px;
  width: 9px;
  height: 9px;
  border: 1px solid var(--crafting-connector);
  border-radius: 999px;
  background: var(--bg-surface);
  box-shadow: 0 0 0 3px var(--bg-surface);
  transform: translateY(-50%);
}

details > .crafting-ingredient__row {
  cursor: pointer;
}

.crafting-ingredient__row::-webkit-details-marker {
  display: none;
}

.crafting-ingredient__icon {
  position: relative;
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border: 1px solid
    var(--bitcraft-item-frame-border, rgb(var(--border-color-rgb) / 0.7));
  border-radius: 6px;
  background:
    radial-gradient(
      circle at 35% 25%,
      var(--bitcraft-item-frame-bg, rgb(var(--accent-cyan-rgb) / 0.2)),
      transparent 42%
    ),
    linear-gradient(
      180deg,
      color-mix(
        in srgb,
        var(--bitcraft-item-frame-accent, transparent) 12%,
        transparent
      ),
      transparent
    ),
    rgb(var(--bg-surface-rgb) / 0.92);
  box-shadow:
    inset 0 1px 0 rgb(var(--text-primary-rgb) / 0.08),
    0 0 12px
      color-mix(
        in srgb,
        var(--bitcraft-item-frame-accent, transparent) 20%,
        transparent
      );
  color: var(--bitcraft-item-frame-text, var(--text-muted-2));
  font-family: var(--font-ui);
  font-size: 11px;
  font-weight: 800;
  overflow: hidden;
}

.crafting-ingredient__icon img {
  position: absolute;
  inset: 3px;
  width: calc(100% - 6px);
  height: calc(100% - 6px);
  object-fit: contain;
}

.crafting-ingredient__copy {
  display: grid;
  min-width: 0;
  gap: 3px;
}

.crafting-ingredient__name {
  min-width: 0;
  color: var(--text-primary);
  font-family: var(--font-ui);
  font-size: 14px;
  line-height: 1.25;
  overflow-wrap: break-word;
}

.crafting-ingredient__name strong {
  display: inline-block;
  min-width: 3ch;
  text-align: center;
  font-variant-numeric: tabular-nums;
  margin-right: 7px;
  border-radius: 4px;
  background: rgb(var(--bg-surface-rgb) / 0.78);
  color: var(--text-muted);
  font-size: 12px;
  font-weight: 800;
  line-height: 1;
  padding: 4px 6px;
  vertical-align: 1px;
}

.crafting-ingredient__meta {
  color: var(--text-muted-3);
  font-family: var(--font-ui);
  font-size: 11px;
  font-weight: 650;
}

.crafting-ingredient__stock {
  color: var(--text-muted-2);
  font-size: 11px;
  line-height: 1.5;
  font-variant-numeric: tabular-nums;
}
.crafting-ingredient__missing {
  color: var(--accent-pink);
}

.crafting-ingredient__tier {
  margin-left: auto;
}

.crafting-ingredient__load {
  border: 1px solid rgb(var(--accent-cyan-rgb) / 0.45);
  border-radius: 6px;
  background: rgb(var(--accent-cyan-rgb) / 0.12);
  color: var(--accent-cyan);
  font-family: var(--font-ui);
  font-size: 12px;
  font-weight: 800;
  line-height: 1;
  padding: 8px 10px;
}

.crafting-ingredient__load:disabled {
  cursor: wait;
  opacity: 0.72;
}

.crafting-ingredient__error {
  margin: 6px 0 0 44px;
  color: var(--danger);
  font-family: var(--font-ui);
  font-size: 12px;
  font-weight: 650;
}

.crafting-ingredient__chevron {
  width: 8px;
  height: 8px;
  border-right: 2px solid var(--accent-cyan);
  border-bottom: 2px solid var(--accent-cyan);
  transform: rotate(45deg);
  transition: transform 150ms ease;
}

details:not([open]) > .crafting-ingredient__row .crafting-ingredient__chevron {
  transform: rotate(-45deg);
}

.crafting-ingredient__children {
  position: relative;
  display: grid;
  gap: 12px;
  margin-left: 10px;
  min-width: 0;
  padding-top: 12px;
  padding-left: 12px;
}

.crafting-ingredient__children::before {
  content: "";
  position: absolute;
  top: 0;
  bottom: 29px;
  left: 0;
  width: 1px;
  background: linear-gradient(
    180deg,
    var(--crafting-connector),
    var(--crafting-connector-muted)
  );
}

.crafting-ingredient__children .crafting-node__ingredients,
.crafting-ingredient__children .crafting-ingredient__children {
  margin-left: 8px;
  padding-left: 10px;
}

@media (max-width: 640px) {
  .crafting-node--root {
    padding: 12px;
  }

  .crafting-node__recipe-head {
    align-items: stretch;
    flex-direction: column;
  }

  .crafting-node__route-select {
    width: 100%;
  }

  .crafting-ingredient__row {
    grid-template-columns: 30px minmax(0, 1fr) auto;
    min-height: 54px;
    gap: 8px;
    padding: 10px;
  }

  .crafting-ingredient__icon {
    width: 30px;
    height: 30px;
  }

  .crafting-node__ingredients {
    margin-left: 6px;
    padding-left: 10px;
  }

  .crafting-ingredient::before {
    left: -10px;
    width: 10px;
  }

  .crafting-ingredient:last-child::after {
    left: -10px;
  }

  .crafting-ingredient__row::before {
    left: -15px;
  }

  .crafting-ingredient__children {
    margin-left: 6px;
    padding-left: 10px;
  }

  .crafting-node--deep > .crafting-node__ingredients,
  .crafting-node--deep
    > .crafting-node__ingredients
    > .crafting-ingredient
    > .crafting-ingredient__children {
    margin-left: 0;
    padding-left: 0;
  }
  .crafting-node--deep > .crafting-node__ingredients::before,
  .crafting-node--deep
    > .crafting-node__ingredients
    > .crafting-ingredient::before,
  .crafting-node--deep
    > .crafting-node__ingredients
    > .crafting-ingredient::after,
  .crafting-node--deep
    > .crafting-node__ingredients
    > .crafting-ingredient
    > .crafting-ingredient__row::before,
  .crafting-node--deep
    > .crafting-node__ingredients
    > .crafting-ingredient
    > .crafting-ingredient__children::before {
    display: none;
  }
  .crafting-ingredient__tier {
    display: none;
  }

  .crafting-ingredient__load {
    grid-column: 2 / -1;
    justify-self: start;
  }

  .crafting-ingredient__error {
    margin-left: 40px;
  }
}
</style>
