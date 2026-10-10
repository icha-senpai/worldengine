<script setup>
import { computed } from "vue";
import EmojiGlyph from "./EmojiGlyph.vue";
import { selectedEmojis } from "../emoji";
const props = defineProps({ value: { type: String, default: "" } });
const selected = computed(() => selectedEmojis(props.value));
</script>

<template>
  <span :aria-label="value" class="emoji-display"
    ><EmojiGlyph v-for="emoji in selected" :key="emoji" :emoji="emoji"
  /></span>
</template>

<style scoped>
.emoji-display {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.3em;
}
</style>
