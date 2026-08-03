<template>
    <section class="surface-section">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Expeditions</span>
                <p class="surface-section__subtitle">{{ readyCount }} supplied · {{ expeditions.length }} routes.</p>
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

                <div class="grid gap-3">
                    <article
                        v-for="(expedition, index) in visibleExpeditions"
                        :key="expedition.key"
                        class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
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
                                Run
                            </button>
                        </div>
                    </article>

                    <p v-if="!visibleExpeditions.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No expeditions match.
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
import { computed, ref } from 'vue'
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

const readyCount = computed(() => props.expeditions.filter((expedition) => expedition.can_start).length)
const filters = computed(() => ['All', 'Ready', ...new Set(props.expeditions.map((expedition) => expedition.skill_label))].map((filter) => ({
    key: filter,
    label: filter,
    count: props.expeditions.filter((expedition) => filter === 'All' || (filter === 'Ready' ? expedition.can_start : expedition.skill_label === filter)).length,
})))
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const visibleExpeditions = computed(() => props.expeditions.filter((expedition) => {
    if (selectedFilter.value === 'Ready') {
        return expedition.can_start
    }

    if (selectedFilter.value === 'All') {
        return true
    }

    return expedition.skill_label === selectedFilter.value
}).filter((expedition) => searchMatches(expedition, props.searchTerm)))
const visibleGold = computed(() => visibleExpeditions.value.reduce((total, expedition) => total + expedition.gold, 0))
const visibleExperience = computed(() => visibleExpeditions.value.reduce((total, expedition) => total + expedition.experience, 0))

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

function run(expedition) {
    form.expedition = expedition
    form.post(route('evergather.expeditions.store'), {
        preserveScroll: true,
        only: expeditionReloadProps,
    })
}
</script>
