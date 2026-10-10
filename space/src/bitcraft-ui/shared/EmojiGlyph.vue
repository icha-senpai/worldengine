<script setup>
import { computed, ref, watch } from "vue";
const props = defineProps({ emoji: { type: String, required: true } });
const failed = ref(false);
const source = computed(
  () =>
    "/assets/emoji/noto-18.0/" +
    Array.from(props.emoji)
      .map((character) => character.codePointAt(0))
      .filter((point) => point !== 0xfe0f)
      .map((point) => point.toString(16).padStart(4, "0"))
      .join("_") +
    ".svg",
);
watch(
  () => props.emoji,
  () => {
    failed.value = false;
  },
);
</script>

<template>
  <span v-if="failed" aria-hidden="true">{{ emoji }}</span>
  <img
    v-else
    :src="source"
    :alt="emoji"
    class="emoji-glyph"
    loading="lazy"
    decoding="async"
    draggable="false"
    @error="failed = true"
  />
</template>

<style scoped>
.emoji-glyph {
  display: inline-block;
  width: 1em;
  height: 1em;
  object-fit: contain;
  vertical-align: -0.15em;
}
</style>
