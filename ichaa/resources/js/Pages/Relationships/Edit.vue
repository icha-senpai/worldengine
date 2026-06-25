<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Relationship"
        :back-href="route('relationships.show', relationship.id)"
        back-label="Relationship"
        :cancel-href="route('relationships.show', relationship.id)"
        submit-label="Save Relationship"
        processing-label="Saving..."
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import { toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    relationship: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    relationshipTypes: { type: Array, default: () => [] },
    tensionCharges: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    from_entity_id: props.relationship.from_entity_id ?? '',
    to_entity_id: props.relationship.to_entity_id ?? '',
    relationship_type: props.relationship.relationship_type ?? '',
    direction: props.relationship.direction ?? '',
    perspective_a: props.relationship.perspective_a ?? null,
    perspective_b: props.relationship.perspective_b ?? null,
    current_tension_charge: props.relationship.current_tension_charge ?? '',
    charge_change_reason: '',
    is_active: props.relationship.is_active ?? false,
    perceived_type: props.relationship.perceived_type ?? '',
    true_type: props.relationship.true_type ?? '',
    visibility: props.relationship.visibility ?? 'private',
    content_classification: props.relationship.content_classification ?? 'restricted',
})

const sections = computed(() => [
    {
        title: 'Participants',
        fields: [
            {
                key: 'from_entity_id',
                label: 'From Entity',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select the first entity...',
            },
            {
                key: 'to_entity_id',
                label: 'To Entity',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select the second entity...',
                help: 'This must be different from the From entity.',
            },
        ],
    },
    {
        title: 'Relationship State',
        fields: [
            { key: 'relationship_type', label: 'Relationship Type', type: 'select', options: props.relationshipTypes },
            { key: 'direction', label: 'Direction' },
            { key: 'current_tension_charge', label: 'Tension Charge', type: 'select', options: props.tensionCharges },
            { key: 'charge_change_reason', label: 'Charge Change Reason', type: 'textarea', rows: 3 },
            { key: 'is_active', label: 'Is Active', type: 'checkbox' },
            { key: 'perceived_type', label: 'Perceived Type' },
            { key: 'true_type', label: 'True Type' },
            { key: 'visibility', label: 'Visibility' },
            { key: 'content_classification', label: 'Content Classification' },
        ],
    },
    {
        title: 'Perspectives',
        fields: [
            { key: 'perspective_a', label: 'Perspective A JSON', type: 'json', jsonMode: 'document', rows: 6 },
            { key: 'perspective_b', label: 'Perspective B JSON', type: 'json', jsonMode: 'document', rows: 6 },
        ],
    },
])

const submit = () => form.put(route('relationships.update', props.relationship.id))
</script>
