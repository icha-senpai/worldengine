<template>
    <div>
        <ScaffoldIndexPage
            title="Canon References"
            :count="references.length"
            count-label="references"
            sync-resource="canon_references"
            :create-href="route('canon-references.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('canon-references.index')"
            create-label="New Reference"
            :items="items"
            empty-title="No canon references found"
            :empty-cta-href="route('canon-references.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first reference ->"
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
            <CreateCanonReference
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
import CreateCanonReference from '@/Pages/Lore/CanonReferences/Create.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    references: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    universes: { type: Array, default: () => [] },
    researchStatuses: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('canon-references.index', {
    universe: props.filters.universe ?? '',
    research_status: props.filters.research_status ?? '',
    visibility: props.filters.visibility ?? '',
    q: props.filters.q ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search references...' },
    { key: 'universe', type: 'select', placeholder: 'All universes', options: props.universes },
    { key: 'research_status', type: 'select', placeholder: 'All research states', options: props.researchStatuses },
    { key: 'visibility', placeholder: 'All visibility' },
])

const items = computed(() =>
    props.references.map((reference) => ({
        id: reference.id,
        href: route('canon-references.show', reference.id),
        title: reference.title,
        subtitle: reference.universe,
        badges: [badge('Level', reference.level)],
        meta: buildMeta([
            { label: 'Priority', value: reference.universe_priority },
            { label: 'Research', value: reference.research_status },
            { label: 'Children', value: reference.child_references?.length },
        ]),
    }))
)
</script>
