<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Progression</span>
                <p class="surface-section__subtitle">Level {{ progression.account_level }} account profile · {{ unlockedAchievements }} of {{ progression.achievements.length }} achievements complete.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-3">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="group in statusGroups"
                        :key="group.key"
                        type="button"
                        class="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                        :class="{ 'border-focus/70 bg-focus/10 text-primary': selectedGroup === group.key }"
                        @click="selectedGroup = group.key"
                    >
                        {{ group.label }} · {{ group.entries.length }}
                    </button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="group in categoryGroups"
                        :key="group.key"
                        type="button"
                        class="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                        :class="{ 'border-focus/70 bg-focus/10 text-primary': selectedGroup === group.key }"
                        @click="selectedGroup = group.key"
                    >
                        {{ group.label }} · {{ group.unlocked }}/{{ group.entries.length }}
                    </button>
                </div>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <p class="text-sm font-ui text-primary">Account Level {{ progression.account_level }}</p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-canvas">
                        <div
                            class="h-full rounded-full bg-focus"
                            :style="{ width: `${accountProgress}%` }"
                        />
                    </div>
                    <p class="mt-2 text-xs text-muted-2">
                        {{ summary.total_experience }} / {{ progression.next_account_level_experience }} XP
                    </p>

                    <div class="mt-4 grid gap-2 text-xs">
                        <div
                            v-for="stat in stats"
                            :key="stat.label"
                            class="flex items-center justify-between gap-3"
                        >
                            <span class="text-muted-2">{{ stat.label }}</span>
                            <span class="text-primary">{{ stat.value }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3">
                    <article
                        v-for="(achievement, index) in activeGroup.entries"
                        :key="achievement.key"
                        class="grid gap-3 rounded-md border px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                        :class="achievement.unlocked ? 'border-success/40 bg-success/10' : 'border-border bg-surface-2'"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="min-w-0 truncate text-sm font-ui text-primary">{{ achievement.label }}</p>
                                <span class="tag">{{ achievement.unlocked ? 'Done' : 'Open' }}</span>
                                <span class="tag">{{ achievement.category }}</span>
                            </div>
                            <p class="mt-2 text-xs text-muted-2">{{ achievement.description }}</p>
                            <div v-if="achievement.reward" class="mt-3 flex flex-wrap gap-2">
                                <span class="tag">{{ achievement.reward.title }}</span>
                                <span class="tag">{{ achievement.reward.gold }}g</span>
                                <span class="tag">{{ achievement.reward.unlock }}</span>
                            </div>
                        </div>

                        <div class="text-left md:text-right">
                            <p class="text-sm font-ui text-primary">{{ achievement.unlocked ? 'Claimed' : 'Pending' }}</p>
                            <p class="mt-1 text-xs text-muted-3">{{ achievement.reward?.profile_badge ?? activeGroup.label }}</p>
                        </div>
                    </article>

                    <p v-if="!activeGroup.entries.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No achievements on this board.
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
    progression: {
        type: Object,
        required: true,
    },
    summary: {
        type: Object,
        required: true,
    },
})

const selectedGroup = ref('all')
const unlockedAchievements = computed(() => props.progression.achievements.filter((achievement) => achievement.unlocked).length)
const statusGroups = computed(() => [
    { key: 'all', label: 'All', entries: props.progression.achievements },
    { key: 'done', label: 'Done', entries: props.progression.achievements.filter((achievement) => achievement.unlocked) },
    { key: 'open', label: 'Open', entries: props.progression.achievements.filter((achievement) => !achievement.unlocked) },
])
const categoryGroups = computed(() => {
    const groups = new Map()

    props.progression.achievements.forEach((achievement) => {
        const key = `category:${achievement.category_key ?? achievement.category}`

        if (!groups.has(key)) {
            groups.set(key, {
                key,
                label: achievement.category,
                entries: [],
                unlocked: 0,
            })
        }

        const group = groups.get(key)
        group.entries.push(achievement)

        if (achievement.unlocked) {
            group.unlocked += 1
        }
    })

    return Array.from(groups.values())
})
const achievementGroups = computed(() => [...statusGroups.value, ...categoryGroups.value])
const activeGroup = computed(() => achievementGroups.value.find((group) => group.key === selectedGroup.value) ?? achievementGroups.value[0])
const stats = computed(() => [
    { label: 'Total', value: props.progression.stats.total_activity },
    { label: 'Actions', value: props.progression.stats.actions },
    { label: 'Crafts', value: props.progression.stats.crafts },
    { label: 'Jobs', value: props.progression.stats.jobs },
    { label: 'Expeditions', value: props.progression.stats.expeditions },
    { label: 'Trade', value: props.progression.stats.trade_activity },
])

const accountProgress = computed(() => {
    if (!props.progression.next_account_level_experience) {
        return 0
    }

    return Math.min(100, Math.round((props.summary.total_experience / props.progression.next_account_level_experience) * 100))
})
</script>
