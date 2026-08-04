<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">World Events</span>
                <p class="surface-section__subtitle">{{ worldEvents.active.length }} active · {{ worldEvents.upcoming.length }} upcoming · {{ worldEvents.categories.length }} event circuits.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Event Circuit</p>
                        <span class="tag">{{ activeGroup.events.length }}</span>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <button
                            v-for="group in eventGroups"
                            :key="group.key"
                            type="button"
                            class="grid gap-1 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': selectedGroup === group.key }"
                            @click="selectedGroup = group.key"
                        >
                            <span class="text-xs font-ui text-primary">{{ group.label }}</span>
                            <span class="text-[11px] text-muted-3">{{ group.events.length }} events</span>
                        </button>
                    </div>

                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Events</span>
                            <span class="text-primary">{{ activeGroup.events.length }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">XP Bonus</span>
                            <span class="text-primary">+{{ activeGroup.experience }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Yield Bonus</span>
                            <span class="text-primary">+{{ activeGroup.yield }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Gold Bonus</span>
                            <span class="text-primary">+{{ activeGroup.gold }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid content-start gap-3">
                    <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-ui text-primary">{{ activeGroup.label }}</p>
                                <p class="mt-1 text-xs text-muted-3">{{ activeGroup.events.length }} events · +{{ activeGroup.experience }} XP pool</p>
                            </div>
                            <span class="tag">+{{ activeGroup.gold }} gold</span>
                        </div>
                    </div>

                    <article
                        v-for="(event, index) in activeGroup.events"
                        :key="event.key"
                        class="grid min-h-32 items-start gap-3 rounded-md border px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_10rem]"
                        :class="selectedGroup === 'active' ? 'border-success/40 bg-success/10' : 'border-border bg-surface-2'"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="min-w-0 truncate text-sm font-ui text-primary">{{ event.label }}</p>
                                <span class="tag">{{ event.category }}</span>
                                <span class="tag">{{ event.region }}</span>
                            </div>
                            <p class="mt-2 text-xs text-muted-2">{{ event.description }}</p>
                            <p class="mt-2 text-xs text-muted-3">{{ event.skill_label }}</p>
                            <p class="mt-2 text-xs text-muted-3">{{ event.reward }}</p>
                        </div>

                        <div class="flex flex-wrap content-start gap-2 md:justify-end">
                            <span class="tag">+{{ event.experience_bonus }} XP</span>
                            <span class="tag">+{{ event.yield_bonus }} yield</span>
                            <span class="tag">+{{ event.gold_bonus }} gold</span>
                        </div>
                    </article>

                    <p v-if="!activeGroup.events.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No events on this board.
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
    worldEvents: {
        type: Object,
        required: true,
    },
})

const selectedGroup = ref('active')
const eventGroups = computed(() => [
    groupFor('active', 'Active', props.worldEvents.active),
    groupFor('upcoming', 'Upcoming', props.worldEvents.upcoming),
])
const activeGroup = computed(() => eventGroups.value.find((group) => group.key === selectedGroup.value) ?? eventGroups.value[0])

function groupFor(key, label, events) {
    return {
        key,
        label,
        events,
        experience: events.reduce((total, event) => total + (event.experience_bonus ?? 0), 0),
        yield: events.reduce((total, event) => total + (event.yield_bonus ?? 0), 0),
        gold: events.reduce((total, event) => total + (event.gold_bonus ?? 0), 0),
    }
}
</script>
