<template>
    <div>
        <ScaffoldIndexPage
            title="Glossary"
            :count="countRecords(terms)"
            count-label="terms"
            sync-resource="glossary"
            :create-href="route('glossary.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('glossary.index')"
            create-label="New Term"
            :items="items"
            empty-title="No glossary terms found"
            :empty-cta-href="route('glossary.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first term ->"
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
            <CreateGlossary
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
import CreateGlossary from '@/Pages/Glossary/Create.vue'
import { asArray, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    terms: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    usageContexts: { type: Array, default: () => [] },
    originUniverses: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('glossary.index', {
    universe: props.filters.universe ?? '',
    context: props.filters.context ?? '',
})

const filterFields = computed(() => [
    { key: 'universe', type: 'select', placeholder: 'All universes', options: props.originUniverses },
    { key: 'context', type: 'select', placeholder: 'All contexts', options: props.usageContexts },
])

const items = computed(() =>
    asArray(props.terms).map((term) => ({
        id: term.id,
        href: route('glossary.show', term.id),
        title: term.term,
        subtitle: term.usage_context,
        meta: buildMeta([
            { label: 'Origin', value: term.origin_universe },
            { label: 'Era', value: term.era_introduced },
            { label: 'Status', value: term.term_status },
        ]),
    }))
)
</script>
