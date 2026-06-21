<template>
    <ScaffoldIndexPage
        title="Relationships"
        :count="countRecords(relationships)"
        count-label="relationships"
        :create-href="route('relationships.create')"
        create-label="New Relationship"
        :items="items"
        empty-title="No relationships found"
        :empty-cta-href="route('relationships.create')"
        empty-cta-label="Create the first relationship ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    relationships: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    relationshipTypes: { type: Array, default: () => [] },
    tensionCharges: { type: Array, default: () => [] },
})

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
