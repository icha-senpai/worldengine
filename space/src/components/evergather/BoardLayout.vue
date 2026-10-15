<script setup>
import SelectInput from "../../bitcraft-ui/shared/SelectInput.vue";
import { ref } from "vue";
defineProps({
  filters: { type: Array, default: () => [] },
  boards: { type: Array, default: () => [] },
  filter: { type: String, default: "All" },
  board: { type: String, default: "ready" },
  searchTerm: { type: String, default: "" },
  label: { type: String, default: "Profession" },
  stateLabel: { type: String, default: "Readiness" },
  defaultFilter: { type: String, default: "All" },
});
const emit = defineEmits(["update:filter", "update:board", "clear"]);
const dialog = ref(null);
</script>

<template>
  <div class="game-board">
    <div class="game-board__toolbar">
      <label v-if="filters.length" class="game-select ui-field-label">
        <span class="sr-only ui-field-label">{{ label }}</span>
        <SelectInput
          :value="filter"
          @change="emit('update:filter', $event.target.value)"
        >
          <option
            v-for="option in filters"
            :key="option.key"
            :value="option.key"
          >
            {{ option.label
            }}{{ option.metaLabel ? ` · ${option.metaLabel}` : "" }}
          </option>
        </SelectInput>
      </label>
      <div
        v-if="boards.length"
        class="game-board__states"
        :aria-label="stateLabel"
      >
        <SelectInput
          class="game-readiness-select"
          :aria-label="stateLabel"
          :value="board"
          @change="emit('update:board', $event.target.value)"
        >
          <option
            v-for="option in boards"
            :key="option.key"
            :value="option.key"
          >
            {{ option.label }}
          </option>
        </SelectInput>
        <button
          v-for="option in boards"
          :key="option.key"
          class="app-btn app-btn--ghost app-btn--sm"
          :aria-pressed="board === option.key"
          @click="emit('update:board', option.key)"
        >
          {{ option.label }} <span>{{ option.count }}</span>
        </button>
      </div>
      <button
        v-if="$slots.filters"
        class="app-btn app-btn--ghost app-btn--sm"
        @click="dialog.showModal()"
      >
        Filters
      </button>
      <button
        v-if="filter !== defaultFilter || searchTerm.trim()"
        class="app-btn app-btn--ghost app-btn--sm"
        @click="
          emit('update:filter', defaultFilter);
          emit('clear');
        "
      >
        Clear filters
      </button>
    </div>
    <p v-if="searchTerm.trim()" class="game-filter-note">
      Showing matches for “{{ searchTerm }}”.
    </p>
    <slot />
    <Teleport to="body">
      <dialog
        ref="dialog"
        class="game-dialog game-filter-dialog"
        aria-label="Filters"
        @click="$event.target === dialog && dialog.close()"
      >
        <header class="game-dialog__header ui-panel-heading">
          <strong class="ui-dialog-title">Filters & details</strong
          ><button
            class="app-btn app-btn--ghost app-btn--sm"
            @click="dialog.close()"
          >
            Done
          </button>
        </header>
        <div class="game-dialog__body ui-panel-body">
          <slot name="filters" />
        </div>
      </dialog>
    </Teleport>
  </div>
</template>
