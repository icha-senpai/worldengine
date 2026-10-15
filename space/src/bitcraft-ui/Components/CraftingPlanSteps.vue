<template>
  <div class="crafting-plan">
    <div class="crafting-plan__toolbar">
      <span>{{ completeCount }} / {{ steps.length }} steps complete</span>
      <label class="ui-field-label"
        ><input
          type="checkbox"
          :checked="groupByStation"
          @change="emit('update:groupByStation', $event.target.checked)"
        />
        Group by station</label
      >
      <label class="ui-field-label"
        ><input
          type="checkbox"
          :checked="hideCompleted"
          @change="emit('update:hideCompleted', $event.target.checked)"
        />
        Hide completed</label
      >
      <button type="button" :disabled="!completeCount" @click="emit('reset')">
        Reset checklist
      </button>
    </div>
    <p class="crafting-plan__note">
      Check Materials first, then follow the steps from top to bottom. Shared
      recipes are combined; checklist progress is saved in this browser.
    </p>
    <section
      v-for="group in groups"
      :key="group.key"
      class="crafting-plan__group"
    >
      <h4 v-if="groupByStation" class="crafting-plan__group-title">
        Stage {{ group.stage + 1 }} · {{ group.station }}
      </h4>
      <article
        v-for="step in group.steps"
        :key="step.key"
        class="crafting-plan__step"
        :class="{ 'crafting-plan__step--complete': isComplete(step) }"
      >
        <div class="crafting-plan__step-head">
          <input
            type="checkbox"
            :checked="isComplete(step)"
            :disabled="loading"
            :aria-label="`Mark ${step.active.name} complete`"
            @change="emit('complete', { step, checked: $event.target.checked })"
          />
          <span
            class="crafting-plan__icon"
            :style="bitcraftItemFrameStyle(step.item.tier, step.item.rarity)"
            aria-hidden="true"
          >
            <img
              v-if="bitjitaAssetUrl(step.item.iconAssetName)"
              :src="bitjitaAssetUrl(step.item.iconAssetName)"
              alt=""
              loading="lazy"
            />
            <span v-else>{{ step.number }}</span>
          </span>
          <span class="crafting-plan__copy">
            <strong>{{ step.number }}. {{ step.active.name }}</strong>
            <span
              >{{ step.active.station || "No station required"
              }}<template v-if="step.active.skill">
                · {{ step.active.skill }}</template
              ><template v-if="step.active.duration">
                · {{ formatCraftDuration(step.active.duration) }} per
                {{ step.active.gathering ? "action" : "batch" }}</template
              ></span
            >
          </span>
          <span class="crafting-plan__quantity">
            <strong
              >{{ step.active.gathering ? "Gather" : "Make" }}
              {{
                formatQuantity(
                  step.active.gathering ? step.quantity : step.produced,
                )
              }}</strong
            >
            <span v-if="!step.active.gathering"
              >{{ formatQuantity(step.batches) }}
              {{ step.batches === 1 ? "batch" : "batches" }}</span
            >
            <span v-if="!step.active.gathering && step.produced > step.quantity"
              >Need {{ formatQuantity(step.quantity) }} ·
              {{ formatQuantity(step.produced - step.quantity) }} extra</span
            >
          </span>
        </div>
        <div class="crafting-plan__step-controls">
          <SelectInput
            v-if="step.recipe.alternatives?.length > 1"
            :model-value="
              step.recipe.selectedAlternativeIndex ??
              defaultRecipeIndex(step.recipe)
            "
            :aria-label="`Recipe route for ${step.item.name || step.active.name}`"
            @update:model-value="
              emit('route-selected', {
                recipe: step.recipe,
                index: Number($event),
              })
            "
          >
            <option
              v-for="(alternative, index) in step.recipe.alternatives"
              :key="alternative.id ?? index"
              :value="index"
            >
              {{ recipeRouteLabel(alternative) }}
            </option>
          </SelectInput>
          <button
            v-if="step.ingredients.length"
            type="button"
            :aria-expanded="expandedIngredients.has(step.key)"
            @click="toggleIngredients(step.key)"
          >
            {{ step.ingredients.length }}
            {{ step.ingredients.length === 1 ? "ingredient" : "ingredients" }}
          </button>
          <span v-else class="crafting-plan__note"
            >No ingredients required</span
          >
          <button
            type="button"
            :aria-expanded="inspectedStepKey === step.key"
            @click="
              inspectedStepKey = inspectedStepKey === step.key ? null : step.key
            "
          >
            {{
              inspectedStepKey === step.key ? "Close branch" : "Inspect branch"
            }}
          </button>
        </div>
        <ul
          v-if="expandedIngredients.has(step.key)"
          class="crafting-plan__ingredient-list"
        >
          <li
            v-for="ingredient in step.ingredients"
            :key="`${ingredient.kind}:${ingredient.id}`"
          >
            <span>{{ ingredient.name }}</span
            ><strong>{{ formatQuantity(ingredient.quantity) }}</strong>
          </li>
        </ul>
        <section
          v-if="inspectedStepKey === step.key"
          class="crafting-plan__branch"
          :aria-label="`Branch for ${step.active.name}`"
        >
          <p class="crafting-plan__note">
            Branch for {{ formatQuantity(step.inspectionQuantity) }}
            {{ step.item.name || "items" }}. The step above combines all uses.
          </p>
          <CraftingRecipeNode
            :recipe="step.recipe"
            :desired-quantity="step.inspectionQuantity"
            :path="`${step.path}/inspection`"
            root
            @route-selected="emit('route-selected', $event)"
          />
        </section>
      </article>
    </section>
    <p v-if="!visibleSteps.length" class="crafting-plan__empty">
      {{
        steps.length
          ? "All steps are complete. Turn off Hide completed to review them."
          : "No crafting steps found."
      }}
    </p>
  </div>
</template>

<script setup>
import { computed, ref } from "vue";
import SelectInput from "../shared/SelectInput.vue";
import CraftingRecipeNode from "./CraftingRecipeNode.vue";
import {
  defaultRecipeIndex,
  formatCraftDuration,
  recipeRouteLabel,
} from "../craftingTree.js";
import { bitcraftItemFrameStyle, bitjitaAssetUrl } from "../bitjitaAssets.js";

const props = defineProps({
  steps: { type: Array, default: () => [] },
  completed: { type: Object, default: () => ({}) },
  groupByStation: Boolean,
  hideCompleted: Boolean,
  loading: Boolean,
});
const emit = defineEmits([
  "complete",
  "reset",
  "route-selected",
  "update:groupByStation",
  "update:hideCompleted",
]);
const inspectedStepKey = ref(null);
const expandedIngredients = ref(new Set());
const toggleIngredients = (key) => {
  const next = new Set(expandedIngredients.value);
  if (next.has(key)) next.delete(key);
  else next.add(key);
  expandedIngredients.value = next;
};
const isComplete = (step) =>
  props.completed[step.key] === step.completionSignature;
const completeCount = computed(() => props.steps.filter(isComplete).length);
const orderedSteps = computed(() =>
  props.groupByStation
    ? [...props.steps]
        .sort(
          (a, b) =>
            a.stage - b.stage ||
            (a.active.station || "No station required").localeCompare(
              b.active.station || "No station required",
            ) ||
            a.number - b.number,
        )
        .map((step, index) => ({ ...step, number: index + 1 }))
    : props.steps,
);
const visibleSteps = computed(() =>
  orderedSteps.value.filter(
    (step) => !props.hideCompleted || !isComplete(step),
  ),
);
const groups = computed(() => {
  if (!props.groupByStation) return [{ key: "all", steps: visibleSteps.value }];
  const grouped = new Map();
  for (const step of visibleSteps.value) {
    const station = step.active.station || "No station required";
    const key = `${step.stage}:${station}`;
    if (!grouped.has(key))
      grouped.set(key, { key, stage: step.stage, station, steps: [] });
    grouped.get(key).steps.push(step);
  }
  return [...grouped.values()];
});
const formatQuantity = (value) =>
  new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value);
</script>

<style scoped>
.crafting-plan {
  display: grid;
  gap: 14px;
  min-width: 0;
}
.crafting-plan__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  font-size: 12px;
  color: var(--text-muted-2);
}
.crafting-plan__toolbar label {
  display: flex;
  align-items: center;
  gap: 6px;
}
.crafting-plan button {
  border: 1px solid rgb(var(--border-color-rgb) / 0.75);
  background: rgb(var(--bg-surface-rgb) / 0.7);
  border-radius: 6px;
  padding: 7px 10px;
  color: var(--accent-cyan);
  font-size: 12px;
  cursor: pointer;
}
.crafting-plan button:disabled {
  opacity: 0.5;
  cursor: default;
}
.crafting-plan__note {
  margin: 0;
  color: var(--text-muted-2);
  font-size: 12px;
  line-height: 1.5;
}
.crafting-plan__group {
  display: grid;
  gap: 8px;
  min-width: 0;
}
.crafting-plan__group-title {
  margin: 6px 0 0;
  color: var(--accent-cyan);
  font-size: 12px;
}
.crafting-plan__step {
  border: 1px solid rgb(var(--border-color-rgb) / 0.65);
  background: var(--bg-surface);
  border-radius: 8px;
  padding: 10px;
  min-width: 0;
}
.crafting-plan__step--complete > .crafting-plan__step-head {
  opacity: 0.55;
}
.crafting-plan__step-head {
  display: grid;
  grid-template-columns: 18px 34px minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
}
.crafting-plan__icon {
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border: 1px solid
    var(--bitcraft-item-frame-border, rgb(var(--border-color-rgb) / 0.7));
  border-radius: 6px;
  background: var(--bitcraft-item-frame-bg, transparent);
}
.crafting-plan__icon img {
  width: 30px;
  height: 30px;
  object-fit: contain;
}
.crafting-plan__copy,
.crafting-plan__quantity {
  display: grid;
  gap: 4px;
  min-width: 0;
  font-size: 13px;
}
.crafting-plan__copy > span,
.crafting-plan__quantity > span {
  color: var(--text-muted-2);
  font-size: 11px;
}
.crafting-plan__quantity {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.crafting-plan__step-controls {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}
.crafting-plan__step-controls > :first-child:not(button) {
  max-width: min(270px, 100%);
}
.crafting-plan__ingredient-list {
  display: grid;
  gap: 6px;
  margin: 10px 0 0;
  padding: 10px 0 0;
  list-style: none;
  border-top: 1px solid rgb(var(--border-color-rgb) / 0.6);
  font-size: 12px;
  color: var(--text-muted-2);
}
.crafting-plan__ingredient-list li {
  display: flex;
  justify-content: space-between;
  gap: 10px;
}
.crafting-plan__ingredient-list strong {
  font-variant-numeric: tabular-nums;
}
.crafting-plan__branch {
  margin-top: 12px;
  border-top: 1px solid rgb(var(--border-color-rgb) / 0.65);
  padding-top: 10px;
}
.crafting-plan__empty {
  margin: 0;
  padding: 12px;
  color: var(--text-muted-2);
  font-size: 13px;
}
@media (max-width: 640px) {
  .crafting-plan__step-head {
    grid-template-columns: 18px 30px minmax(0, 1fr);
    gap: 8px;
  }
  .crafting-plan__icon {
    width: 30px;
    height: 30px;
  }
  .crafting-plan__icon img {
    width: 26px;
    height: 26px;
  }
  .crafting-plan__quantity {
    grid-column: 3;
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 8px;
    text-align: left;
  }
  .crafting-plan__step-controls {
    justify-content: flex-start;
  }
  .crafting-plan__step-controls > :first-child:not(button) {
    width: 100%;
    max-width: 100%;
  }
}
</style>
