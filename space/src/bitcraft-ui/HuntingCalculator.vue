<script setup>
import CalculatorDataContext from "./Components/CalculatorDataContext.vue";
import { useCalculatorSettings } from "./calculatorSettings";
import BitcraftPageHeader from "./Components/BitcraftPageHeader.vue";
import { computed, reactive, ref } from "vue";
import { router } from "/src/bitcraft-ui/navigation";
import AuthenticatedLayout from "/src/bitcraft-ui/Layout.vue";
import SelectInput from "/src/bitcraft-ui/shared/SelectInput.vue";
import TextInput from "/src/bitcraft-ui/shared/TextInput.vue";
import {
  calculateHuntingXp,
  calibrateLureSession,
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
  {
    mode: ["both", "processing", "hunting"],
    xpMode: ["base", "observed"],
    method: ["wild", "lure"],
  },
);
const lureMode = computed(() => settings.method === "lure");
const calibration = reactive({ lures: "", xp: "", carcasses: "" });
const calibrationMessage = ref("");
const calibrationError = ref(false);
function applyCalibration() {
  const sample = calibrateLureSession(
    calibration.lures,
    calibration.xp,
    calibration.carcasses,
  );
  calibrationError.value = Boolean(sample.error);
  if (sample.error) {
    calibrationMessage.value = sample.error;
    return;
  }
  settings.lureAnimals = sample.lureAnimals;
  settings.lureXp = sample.lureXp;
  calibrationMessage.value =
    "Session averages applied. Hunting XP already includes your bonuses.";
}
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
        :source="
          lureMode
            ? 'Measured lure averages and level thresholds'
            : 'Editable XP preset and level thresholds'
        "
        :refresh="refresh"
        :note="
          lureMode
            ? 'Lure results use your personal hunting XP and cargo yield. Group damage and gear change your results; measure several lures for a steadier average.'
            : 'XP per animal is an editable planning input. Check the Nubi Goat preset against your own observed XP; counts are estimates.'
        "
      />
      <section class="index-panel ui-panel">
        <label class="mb-4 grid gap-2 ui-field-label">
          <span>Hunting method</span>
          <SelectInput v-model="settings.method">
            <option value="wild">Wild hunting</option>
            <option value="lure">Lure hunting</option>
          </SelectInput>
        </label>
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

      <section v-if="lureMode" class="index-panel ui-panel">
        <h2 class="text-sm font-semibold ui-section-title">
          Lure hunting · 2 minutes per lure
        </h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <label class="grid gap-2 ui-field-label">
            <span>Animal / lure name</span>
            <TextInput
              v-model="settings.lureAnimal"
              placeholder="For example, Nubi Goat"
            />
          </label>
          <label class="grid gap-2 ui-field-label">
            <span>Lure tier</span>
            <SelectInput v-model.number="settings.lureTier">
              <option v-for="tier in 10" :key="tier" :value="tier">
                Tier {{ tier }}
              </option>
            </SelectInput>
          </label>
          <label class="grid gap-2 ui-field-label">
            <span>Average carcasses collected per lure</span>
            <TextInput
              v-model.number="settings.lureAnimals"
              type="number"
              min="0"
              step="any"
            />
          </label>
          <label class="grid gap-2 ui-field-label">
            <span>Your hunting XP per lure (observed)</span>
            <TextInput
              v-model.number="settings.lureXp"
              type="number"
              min="0"
              step="any"
            />
          </label>
          <label class="grid gap-2 ui-field-label">
            <span>Seconds between lures</span>
            <TextInput
              v-model.number="settings.lureGapSeconds"
              type="number"
              min="0"
              step="1"
            />
          </label>
        </div>
        <p class="mt-3 text-sm text-muted-3">
          Animal and tier label your measurements; they do not supply an assumed
          spawn rate or XP value. Count the cargo you personally collected, and
          hunting XP before processing it.
        </p>
        <details class="mt-5">
          <summary class="cursor-pointer text-sm font-semibold">
            Calibrate from a lure session
          </summary>
          <form
            class="mt-4 grid gap-4 sm:grid-cols-3"
            @submit.prevent="applyCalibration"
          >
            <label class="grid gap-2 ui-field-label"
              ><span>Lures used</span
              ><TextInput
                v-model.number="calibration.lures"
                type="number"
                min="1"
                step="1"
                required
            /></label>
            <label class="grid gap-2 ui-field-label"
              ><span>Hunting XP gained (before processing)</span
              ><TextInput
                v-model.number="calibration.xp"
                type="number"
                min="0"
                step="any"
                required
            /></label>
            <label class="grid gap-2 ui-field-label"
              ><span>Total carcasses collected</span
              ><TextInput
                v-model.number="calibration.carcasses"
                type="number"
                min="1"
                step="1"
                required
            /></label>
            <button type="submit" class="btn btn--secondary">
              Use session averages
            </button>
          </form>
          <p
            v-if="calibrationMessage"
            :role="calibrationError ? 'alert' : 'status'"
            class="mt-3 text-sm text-muted-3"
          >
            {{ calibrationMessage }}
          </p>
        </details>
      </section>

      <section class="index-panel ui-panel">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2
            class="font-ui text-sm font-semibold uppercase tracking-[0.18em] text-muted-2 ui-section-title"
          >
            {{ lureMode ? "Work and processing XP" : "XP per animal" }}
          </h2>
          <button
            v-if="!lureMode"
            class="btn btn--secondary"
            type="button"
            @click="resetGoatXp"
          >
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
            <span class="field-label ui-field-label">{{
              lureMode ? "Processing XP values" : "XP values"
            }}</span>
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
          <label v-if="!lureMode" class="grid gap-2 ui-field-label">
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
        <p v-if="!lureMode" class="mt-3 text-sm text-muted-3">
          Nubi defaults are about 386.1 kill XP and 198 processing XP. Shot
          damage, rounding, and processing crits change actual gains. For a
          closer estimate, measure several animals, enter the average XP for
          each stage, and choose observed XP. You can use the same fields for
          other animals.
        </p>
        <p v-else class="mt-3 text-sm text-muted-3">
          The XP bonus applies only to base processing XP. Lure hunting XP is
          always observed and already includes bonuses. Replace the processing
          preset with your animal's measured average; group cargo does not imply
          equal hunting XP.
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
          <div
            v-if="lureMode"
            class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
            data-test="lure-results"
          >
            <div>
              <p class="field-label ui-field-label">
                {{ settings.lureAnimal || "Lure hunting" }} · Tier
                {{ settings.lureTier }}
              </p>
              <p class="mt-2 text-3xl font-semibold">
                {{ selected.lures === null ? "—" : number(selected.lures) }}
                lures
              </p>
            </div>
            <div>
              <p class="field-label ui-field-label">
                {{
                  settings.mode === "processing"
                    ? "Processing XP from each lure’s cargo"
                    : "XP per lure"
                }}
              </p>
              <p class="mt-2 text-xl">{{ number(selected.xpPerLure) }}</p>
              <p class="mt-1 text-sm text-muted-3">
                {{ number(result.scenarios[2].xpPerLure) }} hunting +
                {{ number(result.scenarios[1].xpPerLure) }} processing XP
                available per lure
              </p>
            </div>
            <div>
              <p class="field-label ui-field-label">
                Expected carcasses collected
              </p>
              <p class="mt-2 text-xl">
                {{
                  selected.carcasses === null ? "—" : number(selected.carcasses)
                }}
              </p>
            </div>
            <div>
              <p class="field-label ui-field-label">
                {{
                  settings.mode === "processing"
                    ? "Hunting time to supply cargo"
                    : "Estimated hunting time"
                }}
              </p>
              <p class="mt-2 text-xl">
                {{
                  selected.huntingSeconds === null
                    ? "—"
                    : number(selected.huntingSeconds / 60) + " min"
                }}
              </p>
            </div>
            <p class="text-sm text-muted-3 sm:col-span-2 lg:col-span-4">
              Rounded up to full two-minute lures, plus gaps between them.
              Processing time is not included. Expected yield is an average, not
              a guaranteed spawn count.
            </p>
          </div>
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
              <p v-if="lureMode" class="mt-1 text-sm text-muted-3">
                {{
                  scenario.lures === null ? "—" : number(scenario.lures)
                }}
                lures
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
