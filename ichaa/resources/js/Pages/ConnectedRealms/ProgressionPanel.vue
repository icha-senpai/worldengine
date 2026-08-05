<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Progression</span>
                <p class="surface-section__subtitle">Level {{ progression.account_level }} account profile · {{ unlockedAchievements }} of {{ progression.achievements.length }} achievements complete.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <span v-if="claimableAchievements.length" class="tag tag--success">{{ claimableAchievements.length }} ready to claim</span>
                <span class="tag">{{ progression.claimed_rewards.length }} rewards claimed</span>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[minmax(0,0.95fr)_minmax(24rem,1.05fr)]">
                <div class="rounded-md border border-focus/30 bg-focus/10 px-4 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-ui uppercase tracking-[0.14em] text-muted-2">Account Rank</p>
                            <p class="mt-2 text-3xl font-ui text-primary">Level {{ progression.account_level }}</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <p class="text-sm font-ui text-primary">{{ accountProgress }}%</p>
                            <p class="mt-1 text-xs text-muted-3">to next level</p>
                        </div>
                    </div>

                    <div class="mt-4 h-3 overflow-hidden rounded-full bg-canvas">
                        <div class="h-full rounded-full bg-focus" :style="{ width: `${accountProgress}%` }" />
                    </div>
                    <p class="mt-2 text-xs text-muted-2">
                        {{ summary.total_experience }} / {{ progression.next_account_level_experience }} XP
                    </p>

                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <div v-for="stat in featuredStats" :key="stat.label" class="rounded-md border border-border/70 bg-canvas px-3 py-2">
                            <p class="text-lg font-ui text-primary">{{ stat.value }}</p>
                            <p class="mt-1 text-[11px] uppercase tracking-[0.12em] text-muted-3">{{ stat.label }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-md border border-border bg-surface-2 px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Reward Loadout</p>
                                <p class="mt-2 text-sm font-ui text-primary">{{ activeLoadout.title_label ?? 'No title equipped' }}</p>
                            </div>
                            <span v-if="activeLoadout.has_equipped" class="tag tag--success">Equipped</span>
                        </div>

                        <form class="mt-3 grid gap-3" @submit.prevent="updateRewardLoadout">
                            <label class="grid gap-1">
                                <span class="text-xs text-muted-2">Title slot</span>
                                <select
                                    v-model="loadoutForm.title_claim_key"
                                    class="rounded-md border-border bg-canvas text-xs text-primary focus:border-focus focus:ring-focus"
                                >
                                    <option value="">No earned title</option>
                                    <option
                                        v-for="option in rewardOptions.titles"
                                        :key="`title-${option.key}`"
                                        :value="option.key"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <span v-if="loadoutForm.errors.title_claim_key" class="text-xs text-(--accent-pink)">
                                    {{ loadoutForm.errors.title_claim_key }}
                                </span>
                            </label>

                            <button
                                type="submit"
                                class="app-btn app-btn--primary app-btn--sm"
                                :disabled="loadoutForm.processing || !progression.claimed_rewards.length"
                            >
                                {{ loadoutForm.processing ? 'Equipping...' : 'Equip Title' }}
                            </button>
                        </form>
                    </div>

                    <div class="rounded-md border border-border bg-surface-2 px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Next Focus</p>
                                <p class="mt-2 text-sm font-ui text-primary">{{ focusAchievement?.label ?? 'Claim a reward' }}</p>
                            </div>
                            <span class="tag">{{ focusAchievementState }}</span>
                        </div>
                        <p class="mt-2 text-xs text-muted-2">
                            {{ focusAchievement?.description ?? 'Unlocked achievements with rewards appear here first.' }}
                        </p>
                        <div v-if="focusAchievement?.reward" class="mt-3 flex flex-wrap gap-2">
                            <span class="tag">{{ focusAchievement.reward.title }}</span>
                            <span class="tag">{{ focusAchievement.reward.gold }}g</span>
                        </div>
                        <button
                            v-if="focusAchievement?.can_claim"
                            type="button"
                            class="app-btn app-btn--success app-btn--sm mt-4"
                            :disabled="claimForm.processing"
                            @click="claimAchievement(focusAchievement.key)"
                        >
                            {{ runningAchievement === focusAchievement.key ? 'Claiming...' : 'Claim Reward' }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid gap-4 xl:grid-cols-[17rem_minmax(0,1fr)]">
                <aside class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Achievement Path</p>
                        <span class="tag">{{ activeCategory.unlocked }}/{{ activeCategory.entries.length }}</span>
                    </div>

                    <div class="mt-3 grid gap-2">
                        <button
                            v-for="category in categoryGroups"
                            :key="category.key"
                            type="button"
                            class="grid gap-2 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': selectedCategory === category.key }"
                            @click="selectedCategory = category.key"
                        >
                            <span class="flex min-w-0 items-center justify-between gap-2">
                                <span class="truncate text-xs font-ui text-primary">{{ category.label }}</span>
                                <span class="text-[11px] text-muted-3">{{ category.unlocked }}/{{ category.entries.length }}</span>
                            </span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-surface-1">
                                <span class="block h-full rounded-full bg-focus" :style="{ width: `${category.progress}%` }" />
                            </span>
                        </button>
                    </div>

                    <div class="mt-4 border-t border-border/70 pt-4">
                        <p class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Claimed Titles</p>
                        <div v-if="progression.claimed_rewards.length" class="mt-3 grid gap-2">
                            <div v-for="reward in progression.claimed_rewards.slice(0, 4)" :key="reward.achievement_key" class="rounded-md border border-border bg-canvas px-3 py-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-xs font-ui text-primary">{{ reward.reward.title }}</p>
                                    <span v-if="equippedSlotsFor(reward.achievement_key).length" class="tag tag--success">Equipped</span>
                                </div>
                                <p class="mt-1 text-[11px] text-muted-3">{{ reward.achievement_label }} · {{ reward.reward.gold }}g</p>
                            </div>
                        </div>
                        <p v-else class="mt-3 text-xs text-muted-2">Claim an unlocked achievement to start your wardrobe.</p>
                    </div>
                </aside>

                <div class="grid gap-4">
                    <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-ui text-primary">{{ activeBoard.label }}</p>
                                <p class="mt-1 text-xs text-muted-3">{{ activeBoard.description }}</p>
                            </div>
                            <span class="tag">{{ activeBoardEntries.length }} found</span>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                            <button
                                v-for="board in boardTabs"
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
                    </div>

                    <div v-if="visibleBoardEntries.length" class="grid gap-3">
                        <article
                            v-for="(achievement, index) in visibleBoardEntries"
                            :key="achievement.key"
                            class="grid gap-3 rounded-md border px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                            :class="achievementTone(achievement)"
                        >
                            <div class="grid h-10 w-10 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                                {{ achievementIcon(achievement) }}
                            </div>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="min-w-0 truncate text-sm font-ui text-primary">{{ achievement.label }}</p>
                                    <span class="tag">{{ achievementStatusLabel(achievement) }}</span>
                                    <span class="tag">{{ achievement.category }}</span>
                                </div>
                                <p class="mt-2 text-xs text-muted-2">{{ achievement.description }}</p>
                                <div v-if="achievement.reward" class="mt-3 flex flex-wrap gap-2">
                                    <span class="tag">{{ achievement.reward.title }}</span>
                                    <span class="tag">{{ achievement.reward.gold }}g</span>
                                </div>
                            </div>

                            <div class="text-left md:text-right">
                                <p class="text-sm font-ui text-primary">{{ boardEntryNumber(index) }}</p>
                                <p class="mt-1 text-xs text-muted-3">{{ achievement.reward?.title ?? activeCategory.label }}</p>
                                <button
                                    type="button"
                                    class="app-btn app-btn--sm mt-3"
                                    :class="{ 'app-btn--success': achievement.can_claim }"
                                    :disabled="claimForm.processing || !achievement.can_claim"
                                    @click="claimAchievement(achievement.key)"
                                >
                                    {{ runningAchievement === achievement.key ? 'Claiming...' : achievement.claimed ? 'Claimed' : achievement.can_claim ? 'Claim' : 'Locked' }}
                                </button>
                            </div>
                        </article>

                        <button
                            v-if="canShowMoreBoardEntries"
                            type="button"
                            class="app-btn app-btn--ghost app-btn--sm justify-self-center"
                            @click="visibleLimit += boardPageSize"
                        >
                            Show More
                        </button>
                    </div>

                    <p v-if="claimForm.errors.achievement" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-(--accent-pink)">
                        {{ claimForm.errors.achievement }}
                    </p>

                    <p v-if="!activeBoardEntries.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        {{ emptyBoardMessage }}
                    </p>

                    <p
                        v-if="selectedBoard === 'next' && activeBoardEntries.length > visibleBoardEntries.length"
                        class="rounded-md border border-border bg-surface-2 px-3 py-3 text-xs text-muted-2"
                    >
                        Showing the closest milestones first. Use Show More when you want to keep browsing.
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { achievementReloadProps } from './reloadProps'

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

const selectedCategory = ref('all')
const selectedBoard = ref('ready')
const boardPageSize = 12
const visibleLimit = ref(boardPageSize)
const runningAchievement = ref('')
const claimForm = useForm({
    achievement: '',
})
const loadoutForm = useForm({
    title_claim_key: props.progression.reward_loadout?.title_claim_key ?? '',
})
const unlockedAchievements = computed(() => props.progression.achievements.filter((achievement) => achievement.unlocked).length)
const claimableAchievements = computed(() => props.progression.achievements.filter((achievement) => achievement.can_claim))
const completedAchievements = computed(() => props.progression.achievements.filter((achievement) => achievement.unlocked))
const lockedAchievements = computed(() => props.progression.achievements.filter((achievement) => !achievement.unlocked))
const nextAchievements = computed(() => lockedAchievements.value.slice(0, 24))
const rewardOptions = computed(() => props.progression.reward_options ?? {
    titles: [],
})
const activeLoadout = computed(() => props.progression.reward_loadout ?? {
    title_claim_key: null,
    title_label: null,
    has_equipped: false,
})
const categoryGroups = computed(() => {
    const groups = new Map()

    groups.set('all', {
        key: 'all',
        label: 'All Paths',
        entries: [],
        unlocked: 0,
        progress: 0,
    })

    props.progression.achievements.forEach((achievement) => {
        const key = `category:${achievement.category_key ?? achievement.category}`
        const allGroup = groups.get('all')

        allGroup.entries.push(achievement)

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
            allGroup.unlocked += 1
        }
    })

    return Array.from(groups.values()).map((group) => ({
        ...group,
        progress: group.entries.length ? Math.round((group.unlocked / group.entries.length) * 100) : 0,
    }))
})
const activeCategory = computed(() => categoryGroups.value.find((group) => group.key === selectedCategory.value) ?? categoryGroups.value[0])
const activeCategoryKeys = computed(() => new Set(activeCategory.value.entries.map((achievement) => achievement.key)))
const boardTabs = computed(() => [
    {
        key: 'ready',
        label: 'Claim',
        count: claimableAchievements.value.length,
        unit: 'ready',
        entries: claimableAchievements.value,
        description: 'Unlocked rewards that still need a claim.',
    },
    {
        key: 'next',
        label: 'Next',
        count: lockedAchievements.value.length,
        unit: 'locked',
        entries: lockedAchievements.value,
        description: 'Closest locked milestones, trimmed so the board stays readable.',
    },
    {
        key: 'earned',
        label: 'Earned',
        count: completedAchievements.value.length,
        unit: 'done',
        entries: completedAchievements.value,
        description: 'Completed achievements and already claimed rewards.',
    },
])
const activeBoard = computed(() => boardTabs.value.find((board) => board.key === selectedBoard.value) ?? boardTabs.value[0])
const activeBoardEntries = computed(() => activeBoard.value.entries.filter((achievement) => activeCategory.value.key === 'all' || activeCategoryKeys.value.has(achievement.key)))
const visibleBoardEntries = computed(() => activeBoardEntries.value.slice(0, visibleLimit.value))
const canShowMoreBoardEntries = computed(() => activeBoardEntries.value.length > visibleBoardEntries.value.length)
const featuredStats = computed(() => [
    { label: 'Total', value: props.progression.stats.total_activity },
    { label: 'Actions', value: props.progression.stats.actions },
    { label: 'Trade', value: props.progression.stats.trade_activity },
])
const focusAchievement = computed(() => claimableAchievements.value[0] ?? nextAchievements.value[0] ?? completedAchievements.value[0] ?? null)
const focusAchievementState = computed(() => {
    if (!focusAchievement.value) {
        return 'Empty'
    }

    return achievementStatusLabel(focusAchievement.value)
})
const emptyBoardMessage = computed(() => {
    if (selectedBoard.value === 'ready') {
        return 'No rewards are waiting. Check Next for the closest milestones.'
    }

    if (selectedBoard.value === 'earned') {
        return 'No achievements completed on this path yet.'
    }

    return 'No achievements on this board.'
})

const accountProgress = computed(() => {
    if (!props.progression.next_account_level_experience) {
        return 0
    }

    return Math.min(100, Math.round((props.summary.total_experience / props.progression.next_account_level_experience) * 100))
})

watch(() => props.progression.reward_loadout, (loadout) => {
    if (loadoutForm.processing) {
        return
    }

    loadoutForm.title_claim_key = loadout?.title_claim_key ?? ''
}, { deep: true })

watch([selectedBoard, selectedCategory], () => {
    visibleLimit.value = boardPageSize
})

watch([claimableAchievements, lockedAchievements], () => {
    if (!claimableAchievements.value.length && lockedAchievements.value.length && selectedBoard.value === 'ready') {
        selectedBoard.value = 'next'
    }

    if (!lockedAchievements.value.length && claimableAchievements.value.length && selectedBoard.value === 'next') {
        selectedBoard.value = 'ready'
    }
}, { immediate: true })

function claimAchievement(achievement) {
    claimForm.achievement = achievement
    claimForm.post(route('evergather.achievements.claims.store'), {
        preserveScroll: true,
        only: achievementReloadProps,
        onStart: () => {
            runningAchievement.value = achievement
        },
        onFinish: () => {
            runningAchievement.value = ''
        },
    })
}

function updateRewardLoadout() {
    loadoutForm.put(route('evergather.rewards.loadout.update'), {
        preserveScroll: true,
        only: achievementReloadProps,
    })
}

function equippedSlotsFor(achievementKey) {
    return [
        activeLoadout.value.title_claim_key === achievementKey ? 'title' : null,
    ].filter(Boolean)
}

function achievementStatusLabel(achievement) {
    if (achievement.claimed) {
        return 'Claimed'
    }

    if (achievement.can_claim) {
        return 'Ready'
    }

    return achievement.unlocked ? 'Unlocked' : 'Locked'
}

function achievementIcon(achievement) {
    if (achievement.claimed) {
        return '✓'
    }

    if (achievement.can_claim) {
        return '!'
    }

    return achievement.unlocked ? '✓' : '·'
}

function achievementTone(achievement) {
    if (achievement.can_claim) {
        return 'border-success/40 bg-success/10'
    }

    if (achievement.unlocked) {
        return 'border-focus/30 bg-focus/10'
    }

    return 'border-border bg-surface-2'
}

function boardEntryNumber(index) {
    return `#${index + 1}`
}

</script>
