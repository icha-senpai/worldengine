<template>
    <ScaffoldIndexPage
        title="Entities"
        :count="countRecords(entities)"
        count-label="entities"
        sync-resource="entities"
        :create-href="route('entities.create')"
        create-label="+ New Entity"
        :create-preserve-scroll="true"
        :create-preserve-state="true"
        :create-drawer-open="Boolean(createDrawer)"
        create-drawer-title="New Entity"
        :create-close-href="route('entities.index')"
        :items="items"
        :pagination="entities"
        empty-title="No entities found"
        :empty-cta-href="route('entities.create')"
        empty-cta-label="Create the first one ->"
        :empty-cta-preserve-scroll="true"
        :empty-cta-preserve-state="true"
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
            <CreateEntity
                v-if="createDrawer"
                embedded
                :entity-types="createDrawer.entityTypes"
            />
        </template>
    </ScaffoldIndexPage>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateEntity from '@/Pages/Entities/Create.vue'
import { richDocumentToPlainText } from '@/Components/scaffold/formatters'
import { badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    entities: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    entityTypes: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    universes: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('entities.index', {
    q: props.filters.q ?? '',
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
    universe: props.filters.universe ?? '',
    visibility: props.filters.visibility ?? '',
    incomplete: Boolean(props.filters.incomplete),
})

const typeCategoryValue = (category) => `category:${category}`

const typeOptions = computed(() =>
    Object.entries(props.entityTypes ?? {}).flatMap(([category, types]) => ([
        { value: typeCategoryValue(category), label: `${formatLabel(category)} (All)` },
        ...types.map((type) => ({
            value: type,
            label: `- ${formatLabel(type)}`,
        })),
    ]))
)

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search entities...' },
    { key: 'type', type: 'select', placeholder: 'All types', options: typeOptions.value },
    { key: 'status', type: 'select', placeholder: 'All statuses', options: props.statuses },
    { key: 'universe', type: 'select', placeholder: 'All universes', options: props.universes },
    { key: 'visibility', placeholder: 'All visibility' },
    { key: 'incomplete', type: 'checkbox', label: 'Incomplete only' },
])

const items = computed(() =>
    (props.entities.data ?? []).map((entity) => ({
        id: entity.id,
        href: route('entities.show', entity.id),
        title: entity.name,
        subtitle: entity.public_title || richDocumentToPlainText(entity.summary) || 'No summary recorded yet.',
        badges: [
            badge('Type', formatLabel(entity.entity_type)),
            badge('Status', formatLabel(entity.status || 'concept')),
        ],
        meta: buildMeta([
            { label: 'Universe', value: formatUniverses(entity.source_universes) },
            { label: 'Visibility', value: formatLabel(entity.visibility || 'private') },
        ]),
        stats: [
            { label: 'Complete', value: `${entity.completion_score ?? 0}%` },
        ],
    }))
)

const formatUniverses = (universes) => {
    if (!universes || universes.length === 0) {
        return 'Native'
    }

    return universes.slice(0, 2).join(', ') + (universes.length > 2 ? ` +${universes.length - 2}` : '')
}
</script>
