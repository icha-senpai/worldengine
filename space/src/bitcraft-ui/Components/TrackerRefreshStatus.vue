<script setup>
import { computed } from "vue";

const props = defineProps({
  refresh: { type: Object, default: () => ({}) },
  sampledAt: { type: String, default: null },
});

const updatedAt = computed(() => props.refresh.updatedAt ?? props.sampledAt);
const timeLabel = computed(() => {
  const date = new Date(updatedAt.value);
  return updatedAt.value && !Number.isNaN(date.getTime())
    ? date.toLocaleTimeString()
    : "";
});
</script>

<template>
  <p
    v-if="timeLabel || refresh.delayed"
    class="tracker-refresh-status"
    role="status"
  >
    <span v-if="refresh.delayed">Refresh delayed. </span>
    <span v-if="timeLabel"
      >Updated <time :datetime="updatedAt">{{ timeLabel }}</time></span
    >
  </p>
</template>

<style scoped>
.tracker-refresh-status {
  margin: 0;
  padding: 6px 22px 0;
  color: var(--tracker-muted, var(--text-muted-2));
  font-size: 12px;
  line-height: 18px;
  overflow-wrap: anywhere;
}
</style>
