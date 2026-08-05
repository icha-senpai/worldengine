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
                                <span class="text-[11px] text-muted-3">{{ filter.count }}</span>
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
                                :disabled="form.processing || !expedition.can_start"
                                @click="run(expedition.key)"
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

const props = defineProps({
    expeditions: {
        type: Array,
        required: true,
    },
    searchTerm: {
        type: String,
        default: '',
    },
})

const form = useForm({
    expedition: null,
})
const selectedFilter = ref('All')
const selectedBoard = ref('ready')
const boardPageSize = 12
const visibleLimit = ref(boardPageSize)
const runningExpedition = ref(null)

const readyCount = computed(() => props.expeditions.filter((expedition) => expedition.can_start).length)
const filters = computed(() => ['All', ...new Set(props.expeditions.map((expedition) => expedition.skill_label))].map((filter) => ({
    key: filter,
    label: filter,
    count: props.expeditions.filter((expedition) => filter === 'All' || expedition.skill_label === filter).length,
})))
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const filteredExpeditions = computed(() => props.expeditions
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

watch([readyExpeditions, prepareExpeditions], () => {
    if (!readyExpeditions.value.length && prepareExpeditions.value.length && selectedBoard.value === 'ready') {
        selectedBoard.value = 'prepare'
    }

    if (!prepareExpeditions.value.length && readyExpeditions.value.length && selectedBoard.value === 'prepare') {
        selectedBoard.value = 'ready'
    }
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

    const readyCount = props.expeditions.filter((expedition) => (filter.key === 'All' || expedition.skill_label === filter.key) && expedition.can_start).length

    return Math.round((readyCount / filter.count) * 100)
}

function run(expedition) {
    form.expedition = expedition
    form.post(route('evergather.expeditions.store'), {
        preserveScroll: true,
        only: expeditionReloadProps,
        onStart: () => {
            runningExpedition.value = expedition
        },
        onFinish: () => {
            runningExpedition.value = null
        },
    })
}
</script>
