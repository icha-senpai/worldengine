<template>
    <div>
        <ScaffoldIndexPage
            title="Relationships"
            :count="countRecords(relationships)"
            count-label="relationships"
            sync-resource="relationships"
            :create-href="route('relationships.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('relationships.index')"
            create-label="New Relationship"
            :items="items"
            empty-title="No relationships found"
            :empty-cta-href="route('relationships.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first relationship ->"
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
            <CreateRelationship
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
import CreateRelationship from '@/Pages/Relationships/Create.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    relationships: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    relationshipTypes: { type: Array, default: () => [] },
    tensionCharges: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('relationships.index', {
    type: props.filters.type ?? '',
    charge: props.filters.charge ?? '',
    volatile: Boolean(props.filters.volatile),
    masked: Boolean(props.filters.masked),
})

const filterFields = computed(() => [
    { key: 'type', type: 'select', placeholder: 'All types', options: props.relationshipTypes },
    { key: 'charge', type: 'select', placeholder: 'All charges', options: props.tensionCharges },
    { key: 'volatile', type: 'checkbox', label: 'Volatile only' },
    { key: 'masked', type: 'checkbox', label: 'Masked only' },
])

const items = computed(() =>
    asArray(props.relationships).map((relationship) => ({
        id: relationship.id,
        href: route('relationships.show', relationship.id),
        title: `${relationship.from_entity?.name ?? 'Unknown'} -> ${relationship.to_entity?.name ?? 'Unknown'}`,
        subtitle: `${formatLabel(relationship.from_entity?.entity_type)} to ${formatLabel(relationship.to_entity?.entity_type)}`,
        badges: [badge('Type', formatLabel(relationship.relationship_type))],
        meta: buildMeta([
            { label: 'Charge', value: formatLabel(relationship.current_tension_charge) },
            { label: 'Direction', value: formatLabel(relationship.direction) },
            { label: 'Active', value: relationship.is_active },
        ]),
    }))
)
</script>
