<template>
    <ScaffoldFormPage
        title="New Relationship"
        :back-href="route('relationships.index')"
        back-label="Relationships"
        :cancel-href="route('relationships.index')"
        submit-label="Create Relationship"
        processing-label="Creating..."
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
    entities: { type: Array, default: () => [] },
    relationshipTypes: { type: Array, default: () => [] },
    tensionCharges: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    from_entity_id: '',
    to_entity_id: '',
    relationship_type: '',
    direction: '',
    perspective_a: null,
    perspective_b: null,
    current_tension_charge: '',
    is_active: true,
    perceived_type: '',
    true_type: '',
    visibility: '',
    content_classification: '',
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
            { key: 'relationship_type', label: 'Relationship Type', type: 'select', required: true, options: props.relationshipTypes },
            { key: 'direction', label: 'Direction' },
            { key: 'current_tension_charge', label: 'Tension Charge', type: 'select', options: props.tensionCharges },
            { key: 'is_active', label: 'Is Active', type: 'checkbox' },
        ],
    },
    {
        title: 'Interpretations',
        fields: [
            { key: 'perceived_type', label: 'Perceived Type' },
            { key: 'true_type', label: 'True Type' },
            { key: 'visibility', label: 'Visibility' },
            { key: 'content_classification', label: 'Content Classification' },
            { key: 'perspective_a', label: 'Perspective A JSON', type: 'json', rows: 6 },
            { key: 'perspective_b', label: 'Perspective B JSON', type: 'json', rows: 6 },
        ],
    },
])

const submit = () => form.post(route('relationships.store'))
</script>
