<script setup>
import CalculatorDataContext from "./Components/CalculatorDataContext.vue";
import { useCalculatorSettings } from "./calculatorSettings";
import BitcraftPageHeader from "./Components/BitcraftPageHeader.vue";
import { computed } from "vue";
import { router } from "/src/bitcraft-ui/navigation";
import AuthenticatedLayout from "/src/bitcraft-ui/Layout.vue";
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import TextInput from "/src/bitcraft-ui/shared/TextInput.vue";
import {
  calculateHuntingXp,
  huntingDefaults,
  nubiGoatPreset,
} from "./huntingXp";

const props = defineProps({
  levels: { type: Array, default: () => [] },
  refresh: { type: Object, default: () => ({}) },
  error: { type: String, default: null },
  playerSkill: { type: Object, default: null },
});
const settings = useCalculatorSettings(
  "bitcraft.hunting.settings",
  huntingDefaults,
  { mode: ["both", "processing", "hunting"], xpMode: ["base", "observed"] },
);
const result = computed(() => calculateHuntingXp(props.levels, settings));
const selected = computed(() =>
  result.value.scenarios?.find((row) => row.mode === settings.mode),
);
const error = computed(() => props.error || result.value.error);
const maxLevel = computed(() =>
  Math.max(1, ...props.levels.map((row) => Number(row.level) || 1)),
);
const number = (value) =>
  new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value);

function resetGoatXp() {
  Object.assign(settings, nubiGoatPreset);
}
</script>

<template>
  <AuthenticatedLayout>
    <template #header>
      <BitcraftPageHeader
        title="Hunting XP Calculator"
        description="Plan how many animals to hunt or process to reach your next goal."
      />
    </template>

    <div class="grid gap-5">
      <CalculatorDataContext
        source="Editable XP preset and level thresholds"
        :refresh="refresh"
        note="XP per animal is an editable planning input. Check the Nubi Goat preset against your own observed XP; counts are estimates."
      />
      <section class="index-panel ui-panel">
        <button
          v-if="playerSkill"
          type="button"
          class="tag mb-3"
          @click="
            settings.currentLevel = playerSkill.level;
            settings.currentXp = playerSkill.xpIntoLevel;
            settings.targetLevel = Math.max(
              settings.targetLevel,
              playerSkill.level,
            );
          "
        >
          Use {{ playerSkill.username }}'s Hunting level {{ playerSkill.level }}
        </button>
        <h2
          class="font-ui text-sm font-semibold uppercase tracking-[0.18em] text-muted-2 ui-section-title"
        >
          Your goal
        </h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
          <label class="grid gap-2 ui-field-label">
            <span class="field-label ui-field-label"
              >Current Hunting level</span
            >
            <TextInput
              v-model.number="settings.currentLevel"
              type="number"
              min="1"
              :max="maxLevel"
              step="1"
            />
          </label>
          <label class="grid gap-2 ui-field-label">
            <span class="field-label ui-field-label"
              >XP earned toward next level</span
            >
            <TextInput
              v-model.number="settings.currentXp"
              type="number"
              min="0"
              step="1"
            />
          </label>
          <label class="grid gap-2 ui-field-label">
            <span class="field-label ui-field-label">Target Hunting level</span>
            <TextInput
              v-model.number="settings.targetLevel"
              type="number"
              min="1"
              :max="maxLevel"
              step="1"
            />
          </label>
        </div>
        <p class="mt-3 text-sm text-muted-3">
          Enter XP already earned in your current level, not XP remaining or
          lifetime XP.
        </p>
      </section>

      <section class="index-panel ui-panel">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2
            class="font-ui text-sm font-semibold uppercase tracking-[0.18em] text-muted-2 ui-section-title"
          >
            XP per animal
          </h2>
          <button class="btn btn--secondary" type="button" @click="resetGoatXp">
            Use Nubi goat estimates
          </button>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <label class="grid gap-2 ui-field-label">
            <span class="field-label ui-field-label">Your work</span>
            <SelectInput v-model="settings.mode">
              <option value="both">Hunt and process</option>
              <option value="processing">Process only (hire a hunter)</option>
              <option value="hunting">Hunt only</option>
            </SelectInput>
          </label>
          <label class="grid gap-2 ui-field-label">
            <span class="field-label ui-field-label">XP values</span>
            <SelectInput v-model="settings.xpMode">
              <option value="base">Base estimates, before XP bonuses</option>
              <option value="observed">
                Observed in-game XP, including bonuses
              </option>
            </SelectInput>
          </label>
          <label class="grid gap-2 ui-field-label">
            <span class="field-label ui-field-label">XP bonus (%)</span>
            <TextInput
              v-model.number="settings.bonus"
              type="number"
              min="0"
              step="0.1"
              :disabled="settings.xpMode === 'observed'"
            />
          </label>
          <label class="grid gap-2 ui-field-label">
            <span class="field-label ui-field-label">XP per kill</span>
            <TextInput
              v-model.number="settings.killXp"
              type="number"
              min="0"
              step="0.01"
            />
          </label>
          <label class="grid gap-2 ui-field-label">
            <span class="field-label ui-field-label"
              >XP per fully processed carcass</span
            >
            <TextInput
              v-model.number="settings.processingXp"
              type="number"
              min="0"
              step="0.01"
            />
          </label>
        </div>
        <p class="mt-3 text-sm text-muted-3">
          Nubi defaults are about 386.1 kill XP and 198 processing XP. Shot
          damage, rounding, and processing crits change actual gains. For a
          closer estimate, measure several animals, enter the average XP for
          each stage, and choose observed XP. You can use the same fields for
          other animals.
        </p>
        <p
          v-if="settings.xpMode === 'observed'"
          class="mt-2 text-sm text-muted-3"
        >
          Observed XP already includes your bonuses, so the bonus field is
          ignored.
        </p>
      </section>

      <section class="index-panel ui-panel" aria-live="polite">
        <template v-if="error">
          <p role="alert" class="text-sm text-muted-2">{{ error }}</p>
          <button
            v-if="props.error || !props.levels.length"
            class="btn btn--secondary mt-3"
            type="button"
            @click="router.reload({ only: ['levels', 'error'] })"
          >
            Reload level thresholds
          </button>
        </template>
        <template v-else>
          <div class="grid gap-5 sm:grid-cols-2">
            <div>
              <p class="field-label ui-field-label">XP still needed</p>
              <p class="mt-2 text-3xl font-semibold" data-test="remaining-xp">
                {{ number(result.remainingXp) }}
              </p>
            </div>
            <div>
              <p class="field-label ui-field-label">
                {{ selected.label }} · animals needed
              </p>
              <p class="mt-2 text-3xl font-semibold" data-test="animal-count">
                {{ selected.count === null ? "—" : number(selected.count) }}
              </p>
              <p class="mt-2 text-sm text-muted-3">
                {{
                  selected.count === null
                    ? "Enter an XP gain above zero for this work."
                    : "Estimated count, rounded up to whole animals."
                }}
              </p>
            </div>
          </div>

          <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <div
              v-for="scenario in result.scenarios"
              :key="scenario.mode"
              class="rounded-xl border border-border p-4"
            >
              <h2 class="font-semibold ui-section-title">
                {{ scenario.label }}
              </h2>
              <p class="mt-2 text-xl">
                {{ scenario.count === null ? "—" : number(scenario.count) }}
                animals
              </p>
              <p class="mt-1 text-sm text-muted-3">
                {{ number(scenario.xp) }} XP per animal
              </p>
            </div>
          </div>

          <details v-if="result.breakdown.length" class="mt-5">
            <summary class="cursor-pointer text-sm font-semibold">
              XP by level
            </summary>
            <dl class="mt-3 grid gap-2 text-sm">
              <div
                v-for="row in result.breakdown"
                :key="row.level"
                class="flex justify-between gap-3"
              >
                <dt>Level {{ row.level }} → {{ row.target }}</dt>
                <dd>{{ number(row.xp) }} XP</dd>
              </div>
            </dl>
          </details>
          <p v-else class="mt-4 text-sm text-muted-3">
            You have already reached this target level.
          </p>
        </template>
      </section>
    </div>
  </AuthenticatedLayout>
</template>
