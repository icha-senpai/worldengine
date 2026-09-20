<template>
    <section class="surface-section">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Skill Activities</span>
                <p class="surface-section__subtitle">{{ unlockedCount }} unlocked · {{ activityStateLabel }}</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[17rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Activity Board</p>
                        <span class="tag">{{ activeBoard.count }} {{ activeBoard.unit }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-3">{{ activeBoard.description }}</p>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            v-for="board in activityBoards"
                            :key="board.key"
                            type="button"
                            class="rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': selectedBoard === board.key }"
                            @click="selectedBoard = board.key"
                        >
                            <span class="block text-xs font-ui text-primary">{{ board.label }}</span>
                            <span class="mt-1 block text-[11px] text-muted-3">{{ board.count }} {{ board.unit }}</span>
                        </button>
                    </div>

                    <label class="mt-4 grid gap-1">
                        <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Level Band</span>
                        <select
                            v-model="selectedBand"
                            class="rounded-md border-border bg-canvas text-xs font-ui text-primary focus:border-focus focus:ring-focus"
                        >
                            <option
                                v-for="filter in bandFilters"
                                :key="filter.key"
                                :value="filter.key"
                            >
                                {{ filter.label }} · {{ filter.count }}
                            </option>
                        </select>
                    </label>

                    <div class="mt-4 grid gap-2">
                        <button
                            v-for="filter in skillFilters"
                            :key="filter.key"
                            type="button"
                            class="grid gap-2 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': selectedSkill === filter.key }"
                            @click="selectedSkill = filter.key"
                        >
                            <span class="flex min-w-0 items-center justify-between gap-2">
                                <span class="truncate text-xs font-ui text-primary">{{ filter.label }}</span>
                                <span class="text-[11px] text-muted-3">{{ filter.metaLabel }}</span>
                            </span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-surface-1">
                                <span class="block h-full rounded-full bg-focus" :style="{ width: `${skillFilterProgress(filter)}%` }" />
                            </span>
                        </button>
                    </div>

                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Band</span>
                            <span class="text-primary">{{ selectedBand }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Unlocked</span>
                            <span class="text-primary">{{ activeUnlockedCount }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Locked</span>
                            <span class="text-primary">{{ visibleActivities.length - activeUnlockedCount }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Timer</span>
                            <span class="text-primary">{{ cooldownLabel }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm mt-4 w-full"
                        :class="{ 'border-focus/70 bg-focus/10 text-primary': autoRepeatEnabled }"
                        :disabled="!repeatActivityKey"
                        @click="toggleAutoRepeatActivity"
                    >
                        {{ autoRepeatEnabled ? 'Repeating' : 'Repeat Last' }}
                    </button>
                </div>

                <div class="grid content-start gap-3">
                    <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-ui text-primary">{{ activeBoard.label }}</p>
                                <p class="mt-1 text-xs text-muted-3">{{ activeSkill.label }} · {{ selectedBand }} · {{ visibleActivities.length }} visible</p>
                            </div>
                            <span class="tag">{{ cooldownLabel }}</span>
                        </div>
                    </div>

                    <button
                        v-for="(activity, index) in visibleActivities"
                        :key="activity.key"
                        type="button"
                        class="grid min-h-36 items-start gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 text-left transition hover:border-focus/60 disabled:cursor-not-allowed disabled:opacity-55 md:grid-cols-[3rem_minmax(0,1fr)_6.75rem]"
                        :disabled="isActivityDisabled(activity)"
                        @click="requestActivity(activity.key)"
                    >
                        <span class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </span>

                        <span class="min-w-0">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="min-w-0 truncate text-sm font-ui text-primary">{{ activity.label }}</span>
                                <span class="tag">{{ activity.skill_label }}</span>
                                <span class="tag">{{ activity.band }}</span>
                                <span class="tag">Lv {{ activity.required_level }}</span>
                                <span v-if="activity.active_event" class="tag tag--success">{{ activity.active_event.label }}</span>
                            </span>
                            <span class="mt-1 block text-xs text-muted-2">{{ activity.track }} · {{ activity.location }}</span>
                            <span class="mt-2 block text-xs text-muted-3">{{ activity.description }}</span>
                            <span v-if="activity.equipped_tool" class="mt-2 block text-xs text-muted-2">
                                {{ toolSummary(activity.equipped_tool) }}
                            </span>
                            <span class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="item in activity.loot_preview.slice(0, 3)"
                                    :key="item.item_key"
                                    class="tag capitalize"
                                >
                                    {{ item.item_name }}
                                    <span class="text-muted-3">· {{ item.rarity }} · {{ item.market_price_band }}</span>
                                </span>
                                <span
                                    v-for="perk in (activity.equipped_tool?.perks ?? []).slice(0, 2)"
                                    :key="`${activity.key}-${perk.key}`"
                                    class="tag"
                                >
                                    {{ perk.label }}
                                </span>
                            </span>
                        </span>

                        <span class="text-left md:text-right">
                            <span v-if="!activity.is_unlocked" class="block text-xs text-muted-3">Level {{ activity.skill_level }} / {{ activity.required_level }}</span>
                            <span v-else-if="requiresToolRepair(activity)" class="block text-sm font-ui text-danger">Repair Tool</span>
                            <span v-else-if="runningActivity === activity.key" class="block text-sm font-ui text-focus">Starting...</span>
                            <span v-else-if="canActNow" class="block text-sm font-ui text-success">Start</span>
                            <span v-else class="block text-sm font-ui text-muted-3">{{ cooldownLabel }}</span>
                            <span class="mt-2 block text-xs text-muted-2">
                                {{ activity.experience.min }}-{{ activity.experience.max }} XP
                            </span>
                            <span class="mt-1 block text-xs text-muted-3">
                                {{ activity.gold.min }}-{{ activity.gold.max }}g
                            </span>
                        </span>
                    </button>

                    <button
                        v-if="canShowMoreActivities"
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm justify-self-center"
                        @click="visibleLimit += boardPageSize"
                    >
                        Show More
                    </button>

                    <p v-if="!visibleActivities.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        {{ emptyBoardMessage }}
                    </p>
                </div>
            </div>

            <p v-if="form.errors.activity" class="mt-4 text-sm text-(--accent-pink)">
                {{ form.errors.activity }}
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { activityReloadProps } from './reloadProps'

const props = defineProps({
    activities: {
        type: Array,
        required: true,
    },
    player: {
        type: Object,
        required: true,
    },
    lastResult: {
        type: Object,
        default: null,
    },
    searchTerm: {
        type: String,
        default: '',
    },
})

const selectedSkill = ref('All')
const selectedBand = ref('All')
const selectedBoard = ref('ready')
const boardPageSize = 12
const visibleLimit = ref(boardPageSize)
const now = ref(Date.now())
const autoRepeatEnabled = ref(false)
const repeatActivityKey = ref('')
const runningActivity = ref('')
const queuedActivities = ref([])
const localActivities = ref([...props.activities])
let cooldownTimer = null
const form = useForm({
    activity: null,
})

const skillFilters = computed(() => [
    {
        key: 'All',
        label: 'All',
        count: localActivities.value.length,
        metaLabel: localActivities.value.length,
        progress: localActivities.value.length ? Math.round((unlockedCount.value / localActivities.value.length) * 100) : 0,
    },
    ...[...new Set(localActivities.value.map((activity) => activity.skill_label))].map((skillLabel) => {
        const skillActivities = localActivities.value.filter((activity) => activity.skill_label === skillLabel)
        const progress = skillActivities[0]?.skill_progress
        const level = progress?.level ?? skillActivities[0]?.skill_level ?? 1

        return {
            key: skillLabel,
            label: skillLabel,
            count: skillActivities.length,
            level,
            metaLabel: `Lv ${level}`,
            progress: skillProgressPercent(progress),
        }
    }),
])
const bandFilters = computed(() => ['All', '1-30', '30-50', '50-80', '80-100'].map((filter) => ({
    key: filter,
    label: filter,
    count: localActivities.value.filter((activity) => filter === 'All' || activity.band === filter).length,
})))
const activeSkill = computed(() => skillFilters.value.find((filter) => filter.key === selectedSkill.value) ?? skillFilters.value[0])
const unlockedCount = computed(() => localActivities.value.filter((activity) => activity.is_unlocked).length)
const filteredActivities = computed(() => localActivities.value.filter((activity) => {
    const matchesSkill = selectedSkill.value === 'All' || activity.skill_label === selectedSkill.value
    const matchesBand = selectedBand.value === 'All' || activity.band === selectedBand.value

    if (!matchesSkill || !matchesBand) {
        return false
    }

    return searchMatches(activity, props.searchTerm)
}))
const readyActivities = computed(() => filteredActivities.value.filter((activity) => activity.is_unlocked))
const lockedActivities = computed(() => filteredActivities.value.filter((activity) => !activity.is_unlocked))
const activityBoards = computed(() => [
    {
        key: 'ready',
        label: 'Ready',
        count: readyActivities.value.length,
        unit: 'acts',
        entries: readyActivities.value,
        description: `${activeSkill.value.label} activities you can run now.`,
    },
    {
        key: 'next',
        label: 'Next',
        count: lockedActivities.value.length,
        unit: 'locked',
        entries: lockedActivities.value,
        description: 'Closest locked skill activities without crowding the ready board.',
    },
])
const activeBoard = computed(() => activityBoards.value.find((board) => board.key === selectedBoard.value) ?? activityBoards.value[0])
const visibleActivities = computed(() => activeBoard.value.entries.slice(0, visibleLimit.value))
const canShowMoreActivities = computed(() => activeBoard.value.entries.length > visibleActivities.value.length)
const activeUnlockedCount = computed(() => visibleActivities.value.filter((activity) => activity.is_unlocked).length)
const nextActionAt = computed(() => props.player.next_action_at ? new Date(props.player.next_action_at).getTime() : null)
const cooldownRemainingMs = computed(() => {
    if (!nextActionAt.value) {
        return 0
    }

    return Math.max(0, nextActionAt.value - now.value)
})
const canActNow = computed(() => props.player.can_act_now || cooldownRemainingMs.value <= 0)
const cooldownLabel = computed(() => {
    if (canActNow.value) {
        return 'Ready'
    }

    const totalSeconds = Math.max(1, Math.floor(cooldownRemainingMs.value / 1000))
    const minutes = Math.floor(totalSeconds / 60)
    const seconds = totalSeconds % 60

    return `${minutes}:${seconds.toString().padStart(2, '0')}`
})
const activityStateLabel = computed(() => (canActNow.value ? 'Ready' : `Ready in ${cooldownLabel.value}`))
const emptyBoardMessage = computed(() => {
    if (selectedBoard.value === 'ready') {
        return 'No ready activities match. Check Next for the closest unlocks.'
    }

    return 'No skill activities match.'
})

onMounted(() => {
    cooldownTimer = window.setInterval(() => {
        now.value = Date.now()
        maybeRepeatActivity()
    }, 250)
})

onBeforeUnmount(() => {
    if (cooldownTimer) {
        window.clearInterval(cooldownTimer)
    }
})

watch([selectedBoard, selectedSkill, selectedBand, () => props.searchTerm], () => {
    visibleLimit.value = boardPageSize
})

watch(() => props.activities, (activities) => {
    localActivities.value = [...activities]
}, { deep: true })

watch(() => props.lastResult, (result) => {
    applyActivityResult(result)
}, { immediate: true })

watch([readyActivities, lockedActivities], () => {
    if (!readyActivities.value.length && lockedActivities.value.length && selectedBoard.value === 'ready') {
        selectedBoard.value = 'next'
    }

    if (!lockedActivities.value.length && readyActivities.value.length && selectedBoard.value === 'next') {
        selectedBoard.value = 'ready'
    }
}, { immediate: true })

function requestActivity(activity) {
    repeatActivityKey.value = activity

    if (form.processing) {
        if (activityHasInstantCooldown(activity)) {
            queuedActivities.value.push(activity)
        }

        return
    }

    submitActivity(activity)
}

function submitActivity(activity) {
    repeatActivityKey.value = activity
    form.activity = activity
    form.post(route('evergather.activities.store'), {
        preserveScroll: true,
        only: activityReloadProps,
        onStart: () => {
            runningActivity.value = activityHasInstantCooldown(activity) ? '' : activity
        },
        onFinish: () => {
            runningActivity.value = ''
            queueNextActivity()
        },
    })
}

function maybeRepeatActivity() {
    if (!autoRepeatEnabled.value || !repeatActivityKey.value || form.processing || !canActNow.value) {
        return
    }

    const activity = localActivities.value.find((entry) => entry.key === repeatActivityKey.value)

    if (!activity?.is_unlocked || requiresToolRepair(activity)) {
        autoRepeatEnabled.value = false

        return
    }

    submitActivity(repeatActivityKey.value)
}

function isActivityDisabled(activity) {
    if (!canActNow.value || !activity.is_unlocked || requiresToolRepair(activity)) {
        return true
    }

    return form.processing && !activityHasInstantCooldown(activity.key)
}

function requiresToolRepair(activity) {
    return Boolean(activity.requires_tool_repair || activity.equipped_tool?.is_broken)
}

function toggleAutoRepeatActivity() {
    autoRepeatEnabled.value = !autoRepeatEnabled.value

    if (autoRepeatEnabled.value) {
        queueNextActivity()
    }
}

function queueNextActivity(delay = 0) {
    window.setTimeout(() => {
        now.value = Date.now()

        if (form.processing) {
            queueNextActivity(16)

            return
        }

        const queuedActivity = queuedActivities.value.shift()

        if (queuedActivity) {
            submitActivity(queuedActivity)

            return
        }

        maybeRepeatActivity()
    }, delay)
}

function activityHasInstantCooldown(activity) {
    const activityEntry = typeof activity === 'string'
        ? localActivities.value.find((entry) => entry.key === activity)
        : activity

    return Number(activityEntry?.cooldown_seconds ?? -1) === 0
}

function skillFilterProgress(filter) {
    return filter.progress ?? 0
}

function skillProgressPercent(progress) {
    if (!progress) {
        return 0
    }

    if (progress.next_level_experience === null) {
        return 100
    }

    const levelSpan = Number(progress.next_level_experience) - Number(progress.current_level_experience ?? 0)

    if (levelSpan <= 0) {
        return 0
    }

    return Math.max(0, Math.min(100, Math.round((Number(progress.experience_into_level ?? 0) / levelSpan) * 100)))
}

function applyActivityResult(result) {
    if (result?.type !== 'skill_activity' || !result.skill_progress) {
        return
    }

    const progress = result.skill_progress

    localActivities.value = localActivities.value.map((activity) => {
        if (activity.skill !== progress.skill) {
            return activity
        }

        const level = progress.level ?? activity.skill_level

        return {
            ...activity,
            skill_label: progress.skill_label ?? activity.skill_label,
            skill_level: level,
            skill_progress: progress,
            is_unlocked: level >= activity.required_level,
        }
    })
}

function toolSummary(tool) {
    const parts = [
        tool.item_name,
        tool.signature_trait,
    ].filter(Boolean)

    if (tool.is_broken) {
        return [...parts, 'Broken'].join(' · ')
    }

    const experienceBonus = Number(tool.experience_bonus ?? 0)
    const yieldBonus = Number(tool.yield_bonus ?? 0)

    if (experienceBonus > 0) {
        parts.push(`+${experienceBonus} XP`)
    }

    if (yieldBonus > 0) {
        parts.push(`+${yieldBonus} yield`)
    }

    if (parts.length <= 2) {
        parts.push('No active bonuses')
    }

    return parts.join(' · ')
}

function searchMatches(activity, query) {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery) {
        return true
    }

    return [
        activity.label,
        activity.track,
        activity.category,
        activity.skill_label,
        activity.location,
        activity.description,
        activity.band,
        activity.active_event?.label,
        activity.equipped_tool?.item_name,
        activity.equipped_tool?.signature_trait,
        ...(activity.loot_preview ?? []).flatMap((item) => [
            item.item_name,
            item.rarity,
            item.quality,
            item.item_class,
            item.material_family,
            ...(item.tags ?? []),
        ]),
    ].filter(Boolean).join(' ').toLowerCase().includes(normalizedQuery)
}
</script>
