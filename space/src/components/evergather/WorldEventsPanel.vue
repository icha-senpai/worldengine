<template>
  <section class="surface-section xl:col-span-2">
    <div class="surface-section__header">
      <div class="surface-section__copy">
        <span class="surface-section__title">World Events</span>
        <p class="surface-section__subtitle">
          {{ worldEvents.active.length }} active ·
          {{ worldEvents.upcoming.length }} upcoming
        </p>
      </div>
    </div>
    <div class="surface-section__body">
      <BoardLayout
        v-model:filter="selectedGroup"
        :filters="eventGroups"
        default-filter="active"
        label="Event window"
      >
        <div class="grid gap-3">
          <article
            v-for="event in activeGroup.events"
            :key="event.key"
            class="game-action-row grid gap-3 rounded-md border px-3 py-3 md:grid-cols-[minmax(0,1fr)_10rem]"
            :class="
              selectedGroup === 'active'
                ? 'border-success/40 bg-success/10'
                : 'border-border bg-surface-2'
            "
          >
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <p class="text-sm font-ui">{{ event.label }}</p>
                <span class="tag">{{ event.category }}</span>
              </div>
              <p class="mt-2 text-xs text-muted-2">{{ event.description }}</p>
              <p class="mt-2 text-xs text-muted-3">
                {{ event.region }} · {{ event.skill_label }}
              </p>
              <p
                v-if="event.reward && event.reward !== event.description"
                class="mt-2 text-xs text-muted-3"
              >
                {{ event.reward }}
              </p>
            </div>
            <div class="flex flex-wrap content-start gap-2 md:justify-end">
              <span class="tag">+{{ event.experience_bonus }} XP</span
              ><span class="tag">+{{ event.yield_bonus }} yield</span
              ><span class="tag">+{{ event.gold_bonus }} gold</span>
            </div>
          </article>
          <p v-if="!activeGroup.events.length" class="game-notice">
            No events on this board.
          </p>
        </div>
      </BoardLayout>
    </div>
  </section>
</template>

<script setup>
import { computed, watch } from "vue";
import BoardLayout from "./BoardLayout.vue";
import { usePersistedPanelState } from "./usePanelState";

const props = defineProps({
  worldEvents: {
    type: Object,
    required: true,
  },
});

const { selectedGroup } = usePersistedPanelState(
  "evergather.world-events-board-state",
  {
    selectedGroup: "active",
  },
  {
    selectedGroup: ["active", "upcoming"],
  },
);
const eventGroups = computed(() => [
  groupFor("active", "Active", props.worldEvents.active),
  groupFor("upcoming", "Upcoming", props.worldEvents.upcoming),
]);
const activeGroup = computed(
  () =>
    eventGroups.value.find((group) => group.key === selectedGroup.value) ??
    eventGroups.value[0],
);

watch(
  eventGroups,
  () => {
    if (!eventGroups.value.some((group) => group.key === selectedGroup.value)) {
      selectedGroup.value = "active";
    }
  },
  { immediate: true },
);

function groupFor(key, label, events) {
  return {
    key,
    label,
    events,
    experience: events.reduce(
      (total, event) => total + (event.experience_bonus ?? 0),
      0,
    ),
    yield: events.reduce((total, event) => total + (event.yield_bonus ?? 0), 0),
    gold: events.reduce((total, event) => total + (event.gold_bonus ?? 0), 0),
  };
}
</script>
