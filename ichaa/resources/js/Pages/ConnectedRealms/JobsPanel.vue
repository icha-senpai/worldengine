<template>
    <section class="surface-section">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Jobs</span>
                <p class="surface-section__subtitle">{{ readyCount }} ready · {{ jobs.length }} commissions.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="filter in filters"
                    :key="filter.key"
                    type="button"
                    class="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                    :class="{ 'border-focus/70 bg-focus/10 text-primary': selectedFilter === filter.key }"
                    @click="selectedFilter = filter.key"
                >
                    {{ filter.label }} · {{ filter.count }}
                </button>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <p class="text-sm font-ui text-primary">{{ activeFilter.label }}</p>
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

                <div class="grid gap-3">
                    <article
                        v-for="(job, index) in visibleJobs"
                        :key="job.key"
                        class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
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

                    <p v-if="!visibleJobs.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No jobs match.
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
import { computed, ref } from 'vue'
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

const readyCount = computed(() => props.jobs.filter((job) => job.can_complete).length)
const filters = computed(() => ['All', 'Ready', ...new Set(props.jobs.map((job) => job.category))].map((filter) => ({
    key: filter,
    label: filter,
    count: props.jobs.filter((job) => filter === 'All' || (filter === 'Ready' ? job.can_complete : job.category === filter)).length,
})))
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const visibleJobs = computed(() => props.jobs.filter((job) => {
    if (selectedFilter.value === 'Ready') {
        return job.can_complete
    }

    if (selectedFilter.value === 'All') {
        return true
    }

    return job.category === selectedFilter.value
}).filter((job) => searchMatches(job, props.searchTerm)))
const visibleGold = computed(() => visibleJobs.value.reduce((total, job) => total + job.gold, 0))
const visibleExperience = computed(() => visibleJobs.value.reduce((total, job) => total + job.experience, 0))

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

function complete(job) {
    form.job = job
    form.post(route('evergather.jobs.store'), {
        preserveScroll: true,
        only: jobReloadProps,
    })
}
</script>
