<script setup>
import { nextTick, ref, watch } from "vue";
const props = defineProps({
  modelValue: { type: String, default: "" },
  label: { type: String, required: true },
});
const emit = defineEmits(["update:modelValue"]);
const open = ref(false);
const input = ref(null);
watch(
  () => props.label,
  () => {
    open.value = false;
  },
);
async function toggle() {
  open.value = !open.value;
  if (open.value) {
    await nextTick();
    input.value?.focus();
  }
}
</script>
<template>
  <div class="game-search" :class="{ 'game-search--open': open || modelValue }">
    <button
      class="game-search__toggle app-btn app-btn--ghost app-btn--sm"
      :aria-expanded="open || Boolean(modelValue)"
      @click="toggle"
    >
      Search
    </button>
    <div class="game-search__field">
      <label class="game-search__input"
        ><span class="sr-only">Search {{ label }}</span
        ><input
          ref="input"
          :value="modelValue"
          @input="emit('update:modelValue', $event.target.value)"
          type="search"
          :placeholder="'Search ' + label + '…'" /></label
      ><button
        v-if="modelValue"
        class="app-btn app-btn--ghost app-btn--sm"
        @click="emit('update:modelValue', '')"
      >
        Clear
      </button>
    </div>
  </div>
</template>
