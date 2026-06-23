<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Power Interaction"
        :back-href="route('power-interactions.index')"
        back-label="Power Interactions"
        :cancel-href="route('power-interactions.index')"
        submit-label="Create Interaction"
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

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entities: { type: Array, default: () => [] },
    effectTypes: { type: Array, default: () => [] },
    scaleTypes: { type: Array, default: () => [] },
    dangerRatings: { type: Array, default: () => [] },
    knowledgeStates: { type: Array, default: () => [] },
    directionalityTypes: { type: Array, default: () => [] },
})

const entityOptions = computed(() =>
    props.entities.map((entity) => ({
        value: entity.id,
        label: `${entity.name} (#${entity.id}${entity.entity_type ? ` · ${entity.entity_type}` : ''})`,
    }))
)

const form = useForm({
    system_a_entity_id: '',
    system_b_entity_id: '',
    interaction_name: '',
    description: null,
    directionality: '',
    effects: null,
    interaction_scale: '',
    knowledge_state: '',
    danger_rating: '',
    proximity_required: false,
    source_universe_a: '',
    source_universe_b: '',
})

const sections = computed(() => [
    {
        title: 'Systems',
        fields: [
            {
                key: 'system_a_entity_id',
                label: 'System A',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select the first system/entity...',
                help: 'Pick one side of the interaction pair.',
            },
            {
                key: 'system_b_entity_id',
                label: 'System B',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select the second system/entity...',
                help: props.entities.length < 2
                    ? 'You need at least two entities in the database before a power interaction can be created.'
                    : 'Pick the other side of the interaction pair. It must be different from System A.',
            },
            { key: 'interaction_name', label: 'Interaction Name', required: true },
            { key: 'directionality', label: 'Directionality', type: 'select', options: props.directionalityTypes },
            { key: 'interaction_scale', label: 'Interaction Scale', type: 'select', options: props.scaleTypes },
            { key: 'knowledge_state', label: 'Knowledge State', type: 'select', options: props.knowledgeStates },
            { key: 'danger_rating', label: 'Danger Rating', type: 'select', options: props.dangerRatings },
            { key: 'proximity_required', label: 'Proximity Required', type: 'checkbox' },
            { key: 'source_universe_a', label: 'Source Universe A' },
            { key: 'source_universe_b', label: 'Source Universe B' },
        ],
    },
    {
        title: 'Model',
        fields: [
            { key: 'description', label: 'Description JSON', type: 'json', rows: 8 },
            {
                key: 'effects',
                label: 'Effects JSON',
                type: 'json',
                jsonMode: 'object-list',
                jsonObjectFields: ['effect_type', 'affected_aspect', 'magnitude', 'notes'],
                emptyValue: [],
                rows: 8,
                help: props.effectTypes.length ? `Common effect types: ${props.effectTypes.join(', ')}` : '',
            },
        ],
    },
])

const submit = () => form.post(route('power-interactions.store'))
</script>
