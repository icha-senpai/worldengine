<template>
    <section class="surface-section">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Jobs</span>
                <p class="surface-section__subtitle">{{ readyCount }} ready · {{ jobs.length }} commissions.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[17rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Commission Board</p>
                        <span class="tag">{{ activeBoard.count }} {{ activeBoard.unit }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-3">{{ activeBoard.description }}</p>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            v-for="board in jobBoards"
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
                            <span class="text-primary">{{ visibleJobs.filter((job) => job.can_complete).length }}</span>
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
                                <p class="mt-1 text-xs text-muted-3">{{ activeFilter.label }} · {{ visibleJobs.length }} visible</p>
                            </div>
                            <span class="tag">{{ visibleGold }}g</span>
                        </div>
                    </div>

                    <article
                        v-for="(job, index) in visibleJobs"
                        :key="job.key"
                        class="grid min-h-32 items-start gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_7rem]"
                        :class="{ 'opacity-70': !job.is_unlocked }"
                    >
                        <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </div>

                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="min-w-0 truncate text-sm font-ui text-primary">{{ job.label }}</p>
                                <span class="tag">{{ job.category }}</span>
                                <span class="tag">{{ job.skill_label }}</span>
                                <span class="tag">Lv {{ job.required_level }}</span>
                            </div>
                            <div class="mt-3 grid gap-2">
                                <div
                                    v-for="requirement in job.requirements"
                                    :key="requirement.item_key"
                                    class="flex items-center justify-between gap-3 text-xs"
                                >
                                    <span class="min-w-0 truncate text-muted-2">{{ requirement.item_name }}</span>
                                    <span :class="requirement.has_enough ? 'text-success' : 'text-muted-3'">
                                        {{ requirement.owned_quantity }} / {{ requirement.quantity }}
                                    </span>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="requirement in job.requirements"
                                    :key="`${requirement.item_key}-meta`"
                                    class="tag capitalize"
                                >
                                    {{ requirement.quality }} · {{ requirement.total_weight }} wt
                                </span>
                            </div>
                        </div>

                        <div class="grid content-between gap-3 text-left md:text-right">
                            <div>
                                <p class="text-sm font-ui text-primary">+{{ job.gold }}g</p>
                                <p class="mt-1 text-xs text-muted-2">+{{ job.experience }} XP</p>
                                <p v-if="!job.is_unlocked" class="mt-1 text-xs text-muted-3">Level {{ job.skill_level }} / {{ job.required_level }}</p>
                            </div>
                            <button
                                type="button"
                                class="app-btn app-btn--sm"
                                :disabled="form.processing || !job.can_complete"
                                @click="complete(job.key)"
                            >
                                Turn In
                            </button>
                        </div>
                    </article>

                    <button
                        v-if="canShowMoreJobs"
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm justify-self-center"
                        @click="visibleLimit += boardPageSize"
                    >
                        Show More
                    </button>

                    <p v-if="!visibleJobs.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        {{ emptyBoardMessage }}
                    </p>
                </div>
            </div>

            <p v-if="form.errors.job" class="mt-4 text-sm text-(--accent-pink)">
                {{ form.errors.job }}
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { jobReloadProps } from './reloadProps'

const props = defineProps({
    jobs: {
        type: Array,
        required: true,
    },
    searchTerm: {
        type: String,
        default: '',
    },
})

const form = useForm({
    job: null,
})
const selectedFilter = ref('All')
const selectedBoard = ref('ready')
const boardPageSize = 12
const visibleLimit = ref(boardPageSize)

const readyCount = computed(() => props.jobs.filter((job) => job.can_complete).length)
const filters = computed(() => ['All', ...new Set(props.jobs.map((job) => job.category))].map((filter) => ({
    key: filter,
    label: filter,
    count: props.jobs.filter((job) => filter === 'All' || job.category === filter).length,
})))
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const filteredJobs = computed(() => props.jobs
    .filter((job) => selectedFilter.value === 'All' || job.category === selectedFilter.value)
    .filter((job) => searchMatches(job, props.searchTerm)))
const readyJobs = computed(() => filteredJobs.value.filter((job) => job.can_complete))
const prepareJobs = computed(() => filteredJobs.value.filter((job) => job.is_unlocked && !job.can_complete))
const jobBoards = computed(() => [
    {
        key: 'ready',
        label: 'Ready',
        count: readyJobs.value.length,
        unit: 'jobs',
        entries: readyJobs.value,
        description: `${activeFilter.value.label} turn-ins you can complete now.`,
    },
    {
        key: 'prepare',
        label: 'Prepare',
        count: prepareJobs.value.length,
        unit: 'short',
        entries: prepareJobs.value,
        description: 'Unlocked commissions missing materials.',
    },
])
const activeBoard = computed(() => jobBoards.value.find((board) => board.key === selectedBoard.value) ?? jobBoards.value[0])
const visibleJobs = computed(() => activeBoard.value.entries.slice(0, visibleLimit.value))
const canShowMoreJobs = computed(() => activeBoard.value.entries.length > visibleJobs.value.length)
const visibleGold = computed(() => visibleJobs.value.reduce((total, job) => total + job.gold, 0))
const visibleExperience = computed(() => visibleJobs.value.reduce((total, job) => total + job.experience, 0))
const emptyBoardMessage = computed(() => {
    if (selectedBoard.value === 'ready') {
        return 'No ready jobs match. Check Prepare for missing turn-in supplies.'
    }

    return 'No jobs match.'
})

watch([selectedBoard, selectedFilter, () => props.searchTerm], () => {
    visibleLimit.value = boardPageSize
})

watch(readyJobs, (jobs) => {
    if (!jobs.length && selectedBoard.value === 'ready') {
        selectedBoard.value = 'prepare'
    }

    if (jobs.length && selectedBoard.value === 'prepare') {
        selectedBoard.value = 'ready'
    }
}, { immediate: true })

function searchMatches(job, query) {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery) {
        return true
    }

    return [
        job.label,
        job.category,
        job.skill_label,
        ...(job.requirements ?? []).flatMap((item) => [
            item.item_name,
            item.rarity,
            item.quality,
            item.item_class,
            item.material_family,
            ...(item.tags ?? []),
        ]),
    ].filter(Boolean).join(' ').toLowerCase().includes(normalizedQuery)
}

function filterProgress(filter) {
    if (!filter.count) {
        return 0
    }

    const readyCount = props.jobs.filter((job) => (filter.key === 'All' || job.category === filter.key) && job.can_complete).length

    return Math.round((readyCount / filter.count) * 100)
}

function complete(job) {
    form.job = job
    form.post(route('evergather.jobs.store'), {
        preserveScroll: true,
        only: jobReloadProps,
    })
}
</script>
