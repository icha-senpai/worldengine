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
                    <button
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm mt-4 w-full"
                        :class="{ 'border-focus/70 bg-focus/10 text-primary': autoRepeatEnabled }"
                        :disabled="!repeatJob"
                        @click="toggleAutoRepeatJob"
                    >
                        {{ autoRepeatEnabled ? 'Repeating' : 'Repeat Last' }}
                    </button>
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
                                <span class="tag capitalize">{{ job.rotation }} {{ job.completed_in_rotation }} / {{ job.completion_cap }}</span>
                            </div>
                            <div class="mt-3 grid gap-2">
                                <div v-if="job.requires_acceptance" class="grid gap-1 text-xs">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="min-w-0 truncate text-muted-2">{{ objectiveLabel(job) }}</span>
                                        <span :class="job.progress_quantity >= job.progress_required ? 'text-success' : 'text-muted-3'">
                                            {{ job.progress_quantity }} / {{ job.progress_required }}
                                        </span>
                                    </div>
                                    <span class="h-1.5 overflow-hidden rounded-full bg-surface-1">
                                        <span class="block h-full rounded-full bg-focus" :style="{ width: `${job.progress_percent ?? 0}%` }" />
                                    </span>
                                </div>
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
                                <p v-else-if="!job.is_demand_available" class="mt-1 text-xs text-muted-3">Demand filled</p>
                                <p v-else class="mt-1 text-xs text-muted-3">{{ job.remaining_completions }} left</p>
                            </div>
                            <button
                                v-if="job.requires_acceptance && !job.is_accepted"
                                type="button"
                                class="app-btn app-btn--sm"
                                :disabled="isJobDisabled(job, 'accept')"
                                @click="requestJob('accept', job.key)"
                            >
                                {{ runningJob === job.key ? 'Accepting...' : 'Accept' }}
                            </button>
                            <button
                                v-else
                                type="button"
                                class="app-btn app-btn--sm"
                                :disabled="isJobDisabled(job, 'complete')"
                                @click="requestJob('complete', job.key)"
                            >
                                {{ runningJob === job.key ? 'Completing...' : completeLabel(job) }}
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
    job: null,
})
const selectedFilter = ref('All')
const selectedBoard = ref('ready')
const boardPageSize = 12
const visibleLimit = ref(boardPageSize)
const runningJob = ref('')
const repeatJob = ref(null)
const autoRepeatEnabled = ref(false)
const queuedJobs = ref([])
const localJobs = ref([...props.jobs])

const readyCount = computed(() => localJobs.value.filter((job) => job.can_complete).length)
const filters = computed(() => [
    {
        key: 'All',
        label: 'All',
        count: localJobs.value.length,
        metaLabel: localJobs.value.length,
        progress: localJobs.value.length ? Math.round((readyCount.value / localJobs.value.length) * 100) : 0,
    },
    ...[...new Set(localJobs.value.map((job) => job.skill_label))].map((skillLabel) => {
        const jobs = localJobs.value.filter((job) => job.skill_label === skillLabel)
        const progress = jobs[0]?.skill_progress
        const level = progress?.level ?? jobs[0]?.skill_level ?? 1

        return {
            key: skillLabel,
            label: skillLabel,
            count: jobs.length,
            metaLabel: `Lv ${level}`,
            progress: skillProgressPercent(progress),
        }
    }),
])
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const filteredJobs = computed(() => localJobs.value
    .filter((job) => selectedFilter.value === 'All' || job.skill_label === selectedFilter.value)
    .filter((job) => searchMatches(job, props.searchTerm)))
const readyJobs = computed(() => filteredJobs.value.filter((job) => job.can_complete))
const prepareJobs = computed(() => filteredJobs.value.filter((job) => job.is_unlocked && !job.can_complete && !job.is_accepted))
const jobBoards = computed(() => [
    {
        key: 'ready',
        label: 'Ready',
        count: readyJobs.value.length,
        unit: 'jobs',
        entries: readyJobs.value,
        description: `${activeFilter.value.label} contracts you can finish now.`,
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
        return 'No ready jobs match. Check Active or Prepare for contract work.'
    }

    return 'No jobs match.'
})

watch([selectedBoard, selectedFilter, () => props.searchTerm], () => {
    visibleLimit.value = boardPageSize
})

watch([readyJobs, prepareJobs], () => {
    if (!readyJobs.value.length && prepareJobs.value.length && selectedBoard.value === 'ready') {
        selectedBoard.value = 'prepare'
    }

    if (!prepareJobs.value.length && readyJobs.value.length && selectedBoard.value === 'prepare') {
        selectedBoard.value = 'ready'
    }
}, { immediate: true })

watch(() => props.jobs, (jobs) => {
    localJobs.value = [...jobs]
}, { deep: true })

watch(() => props.lastResult, (result) => {
    applyJobResult(result)
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
        job.objective_type,
        job.tier_mark,
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

    return filter.progress ?? 0
}

function requestJob(action, job) {
    repeatJob.value = { action, job }

    if (form.processing) {
        queuedJobs.value.push({ action, job })

        return
    }

    submitJob(action, job)
}

function accept(job) {
    submitJob('accept', job)
}

function complete(job) {
    submitJob('complete', job)
}

function submitJob(action, job) {
    repeatJob.value = { action, job }
    form.job = job
    form.post(route(action === 'accept' ? 'evergather.jobs.acceptances.store' : 'evergather.jobs.store'), {
        preserveScroll: true,
        only: jobReloadProps,
        onStart: () => {
            runningJob.value = job
        },
        onFinish: () => {
            runningJob.value = ''
            queueNextJob()
        },
    })
}

function maybeRepeatJob() {
    if (!autoRepeatEnabled.value || !repeatJob.value || form.processing) {
        return
    }

    const job = localJobs.value.find((entry) => entry.key === repeatJob.value.job)

    if (!job || isJobDisabled(job, repeatJob.value.action)) {
        autoRepeatEnabled.value = false

        return
    }

    submitJob(repeatJob.value.action, repeatJob.value.job)
}

function isJobDisabled(job, action) {
    if (action === 'accept') {
        return !job.can_accept
    }

    return !job.can_complete
}

function toggleAutoRepeatJob() {
    autoRepeatEnabled.value = !autoRepeatEnabled.value

    if (autoRepeatEnabled.value) {
        queueNextJob()
    }
}

function queueNextJob(delay = 0) {
    window.setTimeout(() => {
        if (form.processing) {
            queueNextJob(16)

            return
        }

        const queuedJob = queuedJobs.value.shift()

        if (queuedJob) {
            const job = localJobs.value.find((entry) => entry.key === queuedJob.job)

            if (job && !isJobDisabled(job, queuedJob.action)) {
                submitJob(queuedJob.action, queuedJob.job)
            }

            return
        }

        maybeRepeatJob()
    }, delay)
}

function applyJobResult(result) {
    if (!['job', 'job_acceptance'].includes(result?.type)) {
        return
    }

    const deltas = result.type === 'job' ? itemDeltas([], result.items_delivered ?? []) : {}
    const progress = result.skill_progress

    localJobs.value = localJobs.value.map((job) => {
        let nextJob = patchJobInventory(job, deltas)

        if (progress && nextJob.skill === progress.skill) {
            nextJob = {
                ...nextJob,
                skill_label: progress.skill_label ?? nextJob.skill_label,
                skill_level: progress.level ?? nextJob.skill_level,
                skill_progress: progress,
                is_unlocked: (progress.level ?? nextJob.skill_level) >= nextJob.required_level,
            }
        }

        if (nextJob.key === result.job_key) {
            if (result.type === 'job_acceptance') {
                nextJob = {
                    ...nextJob,
                    is_accepted: true,
                    can_accept: false,
                    progress_quantity: result.progress_quantity ?? nextJob.progress_quantity,
                    progress_required: result.progress_required ?? nextJob.progress_required,
                    progress_percent: 0,
                }
            } else {
                nextJob = {
                    ...nextJob,
                    is_accepted: false,
                    remaining_completions: result.remaining_completions ?? nextJob.remaining_completions,
                    completed_in_rotation: Number(nextJob.completed_in_rotation ?? 0) + 1,
                }
            }
        }

        return recomputeJobReady(nextJob)
    })
}

function patchJobInventory(job, deltas) {
    return {
        ...job,
        requirements: job.requirements.map((requirement) => patchOwnedItem(requirement, deltas)),
    }
}

function recomputeJobReady(job) {
    const hasRequirements = job.requirements.every((requirement) => requirement.has_enough)
    const isDemandAvailable = Number(job.remaining_completions ?? 0) > 0
    const hasProgress = Number(job.progress_quantity ?? 0) >= Number(job.progress_required ?? 1)

    return {
        ...job,
        is_demand_available: isDemandAvailable,
        can_accept: job.requires_acceptance && !job.is_accepted && job.is_unlocked && isDemandAvailable,
        can_complete: (job.requires_acceptance ? (job.is_accepted && hasProgress) : true)
            && hasRequirements
            && job.is_unlocked
            && isDemandAvailable,
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

function completeLabel(job) {
    return job.requires_acceptance ? 'Complete' : 'Turn In'
}

function objectiveLabel(job) {
    const type = (job.objective_type ?? '').replaceAll('_', ' ')
    const itemName = job.objective?.item_name

    if (itemName) {
        return `${type}: ${itemName}`
    }

    return type || 'progress'
}
</script>
