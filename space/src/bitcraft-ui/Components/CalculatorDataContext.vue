<script setup>
import { computed } from "vue";
const props = defineProps({
  source: { type: String, required: true },
  snapshot: { type: Object, default: () => ({}) },
  refresh: { type: Object, default: () => ({}) },
  note: { type: String, required: true },
});
const dateLabel = computed(() => {
  const raw = props.snapshot.generatedAt ?? props.refresh.updatedAt;
  if (!raw)
    return props.snapshot.available ? "Static snapshot" : "Manual inputs";
  const date = new Date(raw);
  return Number.isNaN(date.getTime())
    ? "Date unavailable"
    : `${props.snapshot.generatedAt ? "Snapshot" : "Updated"} ${date.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" })}`;
});
</script>
<template>
  <section
    class="ui-panel ui-panel-body"
    aria-label="Calculator data and estimates"
  >
    <div class="flex flex-wrap items-center gap-2">
      <span class="tag">{{ source }}</span
      ><span class="tag">{{ dateLabel }}</span
      ><span class="tag">Estimates</span
      ><span v-if="refresh.delayed" class="tag">Refresh delayed</span>
    </div>
    <p class="mt-3 text-sm text-muted-2">{{ note }}</p>
  </section>
</template>
