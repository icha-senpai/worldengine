<template>
    <section class="surface-section">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Expeditions</span>
                <p class="surface-section__subtitle">{{ readyCount }} supplied · {{ expeditions.length }} routes.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[17rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Route Board</p>
                        <span class="tag">{{ activeBoard.count }} {{ activeBoard.unit }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-3">{{ activeBoard.description }}</p>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            v-for="board in expeditionBoards"
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

                    <div class="mt-4 grid gap-2">
                        <button
                            v-for="filter in filters"
                            :key="filter.key"
                            type="button"
                            class="grid gap-2 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': selectedFilter === filter.key }"
                            @click="selectedFilter = filter.key"
                        >
                            <span class="flex min-w-0 items-center justify-between gap-2">
                                <span class="truncate text-xs font-ui text-primary">{{ filter.label }}</span>
                                <span class="text-[11px] text-muted-3">{{ filter.metaLabel }}</span>
                            </span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-surface-1">
                                <span class="block h-full rounded-full bg-focus" :style="{ width: `${filterProgress(filter)}%` }" />
                            </span>
                        </button>
                    </div>

                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Ready</span>
                            <span class="text-primary">{{ visibleExpeditions.filter((expedition) => expedition.can_start).length }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Gold</span>
                            <span class="text-primary">{{ visibleGold }}g</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">XP</span>
                            <span class="text-primary">{{ visibleExperience }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm mt-4 w-full"
                        :class="{ 'border-focus/70 bg-focus/10 text-primary': autoRepeatEnabled }"
                        :disabled="!repeatExpeditionKey"
                        @click="toggleAutoRepeatExpedition"
                    >
                        {{ autoRepeatEnabled ? 'Repeating' : 'Repeat Last' }}
                    </button>
                </div>

                <div class="grid content-start gap-3">
                    <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-ui text-primary">{{ activeBoard.label }}</p>
                                <p class="mt-1 text-xs text-muted-3">{{ activeFilter.label }} · {{ visibleExpeditions.length }} visible</p>
                            </div>
                            <span class="tag">{{ visibleGold }}g</span>
                        </div>
                    </div>

                    <article
                        v-for="(expedition, index) in visibleExpeditions"
                        :key="expedition.key"
                        class="grid min-h-32 items-start gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_7rem]"
                        :class="{ 'opacity-70': !expedition.is_unlocked }"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="min-w-0 truncate text-sm font-ui text-primary">{{ expedition.label }}</p>
                                <span class="tag">{{ expedition.skill_label }}</span>
                                <span class="tag">Lv {{ expedition.required_level }}</span>
                                <span class="tag">{{ expedition.region }}</span>
                            </div>
                            <div class="mt-3 grid gap-2">
                                <div
                                    v-for="supply in expedition.supplies"
                                    :key="supply.item_key"
                                    class="flex items-center justify-between gap-3 text-xs"
                                >
                                    <span class="min-w-0 truncate text-muted-2">{{ supply.item_name }}</span>
                                    <span :class="supply.has_enough ? 'text-success' : 'text-muted-3'">
                                        {{ supply.owned_quantity }} / {{ supply.quantity }}
                                    </span>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="reward in expedition.rewards"
                                    :key="reward.item_key"
                                    class="tag"
                                >
                                    {{ reward.item_name }} · {{ reward.rarity }} · {{ reward.total_weight }} wt
                                </span>
                            </div>
                        </div>

                        <div class="grid content-between gap-3 text-left md:text-right">
                            <div>
                                <p class="text-sm font-ui text-primary">+{{ expedition.gold }}g</p>
                                <p class="mt-1 text-xs text-muted-2">+{{ expedition.experience }} XP</p>
                                <p v-if="!expedition.is_unlocked" class="mt-1 text-xs text-muted-3">Level {{ expedition.skill_level }} / {{ expedition.required_level }}</p>
                            </div>
                            <button
                                type="button"
                                class="app-btn app-btn--sm"
                                :disabled="isExpeditionDisabled(expedition)"
                                @click="requestExpedition(expedition.key)"
                            >
                                {{ runningExpedition === expedition.key ? 'Running...' : 'Run' }}
                            </button>
                        </div>
                    </article>

                    <button
                        v-if="canShowMoreExpeditions"
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm justify-self-center"
                        @click="visibleLimit += boardPageSize"
                    >
                        Show More
                    </button>

                    <p v-if="!visibleExpeditions.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        {{ emptyBoardMessage }}
                    </p>
                </div>
            </div>

            <p v-if="form.errors.expedition" class="mt-4 text-sm text-(--accent-pink)">
                {{ form.errors.expedition }}
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { expeditionReloadProps } from './reloadProps'
import { usePersistedPanelState } from './usePanelState'

const props = defineProps({
    expeditions: {
        type: Array,
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

const form = useForm({
    expedition: null,
})
const { selectedFilter, selectedBoard } = usePersistedPanelState('evergather.expeditions-board-state', {
    selectedFilter: 'All',
    selectedBoard: 'ready',
}, {
    selectedBoard: ['ready', 'prepare'],
})
const boardPageSize = 12
const visibleLimit = ref(boardPageSize)
const runningExpedition = ref(null)
const repeatExpeditionKey = ref('')
const autoRepeatEnabled = ref(false)
const queuedExpeditions = ref([])
const localExpeditions = ref([...props.expeditions])

const readyCount = computed(() => localExpeditions.value.filter((expedition) => expedition.can_start).length)
const filters = computed(() => [
    {
        key: 'All',
        label: 'All',
        count: localExpeditions.value.length,
        metaLabel: localExpeditions.value.length,
        progress: localExpeditions.value.length ? Math.round((readyCount.value / localExpeditions.value.length) * 100) : 0,
    },
    ...[...new Set(localExpeditions.value.map((expedition) => expedition.skill_label))].map((skillLabel) => {
        const expeditions = localExpeditions.value.filter((expedition) => expedition.skill_label === skillLabel)
        const progress = expeditions[0]?.skill_progress
        const level = progress?.level ?? expeditions[0]?.skill_level ?? 1

        return {
            key: skillLabel,
            label: skillLabel,
            count: expeditions.length,
            metaLabel: `Lv ${level}`,
            progress: skillProgressPercent(progress),
        }
    }),
])
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const filteredExpeditions = computed(() => localExpeditions.value
    .filter((expedition) => selectedFilter.value === 'All' || expedition.skill_label === selectedFilter.value)
    .filter((expedition) => searchMatches(expedition, props.searchTerm)))
const readyExpeditions = computed(() => filteredExpeditions.value.filter((expedition) => expedition.can_start))
const prepareExpeditions = computed(() => filteredExpeditions.value.filter((expedition) => expedition.is_unlocked && !expedition.can_start))
const expeditionBoards = computed(() => [
    {
        key: 'ready',
        label: 'Ready',
        count: readyExpeditions.value.length,
        unit: 'routes',
        entries: readyExpeditions.value,
        description: `${activeFilter.value.label} routes with supplies packed.`,
    },
    {
        key: 'prepare',
        label: 'Prepare',
        count: prepareExpeditions.value.length,
        unit: 'short',
        entries: prepareExpeditions.value,
        description: 'Unlocked expeditions missing supplies.',
    },
])
const activeBoard = computed(() => expeditionBoards.value.find((board) => board.key === selectedBoard.value) ?? expeditionBoards.value[0])
const visibleExpeditions = computed(() => activeBoard.value.entries.slice(0, visibleLimit.value))
const canShowMoreExpeditions = computed(() => activeBoard.value.entries.length > visibleExpeditions.value.length)
const visibleGold = computed(() => visibleExpeditions.value.reduce((total, expedition) => total + expedition.gold, 0))
const visibleExperience = computed(() => visibleExpeditions.value.reduce((total, expedition) => total + expedition.experience, 0))
const emptyBoardMessage = computed(() => {
    if (selectedBoard.value === 'ready') {
        return 'No supplied expeditions match. Check Prepare for routes missing supplies.'
    }

    return 'No expeditions match.'
})

watch([selectedBoard, selectedFilter, () => props.searchTerm], () => {
    visibleLimit.value = boardPageSize
})

watch(filters, () => {
    if (!filters.value.some((filter) => filter.key === selectedFilter.value)) {
        selectedFilter.value = 'All'
    }
}, { immediate: true })

watch([readyExpeditions, prepareExpeditions], () => {
    if (!readyExpeditions.value.length && prepareExpeditions.value.length && selectedBoard.value === 'ready') {
        selectedBoard.value = 'prepare'
    }

    if (!prepareExpeditions.value.length && readyExpeditions.value.length && selectedBoard.value === 'prepare') {
        selectedBoard.value = 'ready'
    }
}, { immediate: true })

watch(() => props.expeditions, (expeditions) => {
    localExpeditions.value = [...expeditions]
}, { deep: true })

watch(() => props.lastResult, (result) => {
    applyExpeditionResult(result)
}, { immediate: true })

function searchMatches(expedition, query) {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery) {
        return true
    }

    return [
        expedition.label,
        expedition.region,
        expedition.skill_label,
        ...(expedition.supplies ?? []).flatMap(itemSearchFields),
        ...(expedition.rewards ?? []).flatMap(itemSearchFields),
    ].filter(Boolean).join(' ').toLowerCase().includes(normalizedQuery)
}

function itemSearchFields(item) {
    return [
        item.item_name,
        item.rarity,
        item.quality,
        item.item_class,
        item.material_family,
        ...(item.tags ?? []),
    ]
}

function filterProgress(filter) {
    if (!filter.count) {
        return 0
    }

    return filter.progress ?? 0
}

function requestExpedition(expedition) {
    repeatExpeditionKey.value = expedition

    if (form.processing) {
        queuedExpeditions.value.push(expedition)

        return
    }

    run(expedition)
}

function run(expedition) {
    repeatExpeditionKey.value = expedition
    form.expedition = expedition
    form.post(route('evergather.expeditions.store'), {
        preserveScroll: true,
        only: expeditionReloadProps,
        onStart: () => {
            runningExpedition.value = expedition
        },
        onFinish: () => {
            runningExpedition.value = null
            queueNextExpedition()
        },
    })
}

function maybeRepeatExpedition() {
    if (!autoRepeatEnabled.value || !repeatExpeditionKey.value || form.processing) {
        return
    }

    const expedition = localExpeditions.value.find((entry) => entry.key === repeatExpeditionKey.value)

    if (!expedition?.can_start) {
        autoRepeatEnabled.value = false

        return
    }

    run(repeatExpeditionKey.value)
}

function isExpeditionDisabled(expedition) {
    return !expedition.can_start
}

function toggleAutoRepeatExpedition() {
    autoRepeatEnabled.value = !autoRepeatEnabled.value

    if (autoRepeatEnabled.value) {
        queueNextExpedition()
    }
}

function queueNextExpedition(delay = 0) {
    window.setTimeout(() => {
        if (form.processing) {
            queueNextExpedition(16)

            return
        }

        const queuedExpedition = queuedExpeditions.value.shift()

        if (queuedExpedition) {
            const expedition = localExpeditions.value.find((entry) => entry.key === queuedExpedition)

            if (expedition?.can_start) {
                run(queuedExpedition)
            }

            return
        }

        maybeRepeatExpedition()
    }, delay)
}

function applyExpeditionResult(result) {
    if (result?.type !== 'expedition') {
        return
    }

    const deltas = itemDeltas(result.items_awarded ?? [], result.supplies_consumed ?? [])
    const progress = result.skill_progress

    localExpeditions.value = localExpeditions.value.map((expedition) => {
        const nextExpedition = patchExpeditionInventory(expedition, deltas)

        if (progress && nextExpedition.skill === progress.skill) {
            nextExpedition.skill_label = progress.skill_label ?? nextExpedition.skill_label
            nextExpedition.skill_level = progress.level ?? nextExpedition.skill_level
            nextExpedition.skill_progress = progress
            nextExpedition.is_unlocked = nextExpedition.skill_level >= nextExpedition.required_level
        }

        nextExpedition.can_start = nextExpedition.is_unlocked
            && nextExpedition.supplies.every((supply) => supply.has_enough)

        return nextExpedition
    })
}

function patchExpeditionInventory(expedition, deltas) {
    return {
        ...expedition,
        supplies: expedition.supplies.map((supply) => patchOwnedItem(supply, deltas)),
    }
}

function itemDeltas(addedItems, removedItems) {
    const deltas = {}

    addedItems.forEach((item) => {
        deltas[item.item_key] = (deltas[item.item_key] ?? 0) + Number(item.quantity ?? 0)
    })

    removedItems.forEach((item) => {
        deltas[item.item_key] = (deltas[item.item_key] ?? 0) - Number(item.quantity ?? 0)
    })

    return deltas
}

function patchOwnedItem(item, deltas) {
    const ownedQuantity = Math.max(0, Number(item.owned_quantity ?? 0) + Number(deltas[item.item_key] ?? 0))

    return {
        ...item,
        owned_quantity: ownedQuantity,
        has_enough: ownedQuantity >= Number(item.quantity ?? 0),
    }
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
</script>
