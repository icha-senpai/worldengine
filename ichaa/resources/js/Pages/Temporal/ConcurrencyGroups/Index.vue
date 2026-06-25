<template>
    <div>
        <ScaffoldIndexPage
            title="Concurrency Groups"
            :count="groups.length"
            count-label="groups"
            sync-resource="concurrency_groups"
            :create-href="route('concurrency-groups.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('concurrency-groups.index')"
            create-label="New Group"
            :items="items"
            empty-title="No concurrency groups found"
            :empty-cta-href="route('concurrency-groups.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first group ->"
        >
        <template #toolbar>
            <ScaffoldFilterBar
                :fields="filterFields"
                :form="filterForm"
                :has-active-filters="hasActiveFilters"
                :on-apply="applyFilters"
                :on-clear="clearFilters"
            />
        </template>
        <template #create-drawer>
            <CreateConcurrencyGroup
                v-if="createDrawer"
                embedded
                v-bind="createDrawer"
            />
        </template>
        </ScaffoldIndexPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateConcurrencyGroup from '@/Pages/Temporal/ConcurrencyGroups/Create.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    groups: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    significanceLevels: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('concurrency-groups.index', {
    q: props.filters.q ?? '',
    significance: props.filters.significance ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search groups or dates...' },
    { key: 'significance', type: 'select', placeholder: 'All significance', options: props.significanceLevels },
])

const items = computed(() =>
    props.groups.map((group) => ({
        id: group.id,
        href: route('concurrency-groups.show', group.id),
        title: group.name,
        badges: [badge('Significance', group.narrative_significance)],
        meta: buildMeta([
            { label: 'AU Date', value: group.au_date },
        ]),
        stats: group.timeline_entries_count ? [{ label: 'Entries', value: group.timeline_entries_count }] : [],
    }))
)
</script>
