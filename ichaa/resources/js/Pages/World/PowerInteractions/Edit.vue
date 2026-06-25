<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Power Interaction"
        :back-href="route('power-interactions.show', interaction.id)"
        back-label="Power Interaction"
        :cancel-href="route('power-interactions.show', interaction.id)"
        submit-label="Save Interaction"
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

const props = defineProps({
    embedded: { type: Boolean, default: false },
    interaction: { type: Object, required: true },
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
    system_a_entity_id: props.interaction.system_a_entity_id ?? '',
    system_b_entity_id: props.interaction.system_b_entity_id ?? '',
    interaction_name: props.interaction.interaction_name ?? '',
    description: props.interaction.description ?? null,
    directionality: props.interaction.directionality ?? '',
    effects: props.interaction.effects ?? null,
    interaction_scale: props.interaction.interaction_scale ?? '',
    knowledge_state: props.interaction.knowledge_state ?? '',
    danger_rating: props.interaction.danger_rating ?? '',
    proximity_required: props.interaction.proximity_required ?? false,
    source_universe_a: props.interaction.source_universe_a ?? '',
    source_universe_b: props.interaction.source_universe_b ?? '',
    unresolved_flag: props.interaction.unresolved_flag ?? false,
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
            },
            {
                key: 'system_b_entity_id',
                label: 'System B',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select the second system/entity...',
            },
            { key: 'interaction_name', label: 'Interaction Name', required: true },
            { key: 'directionality', label: 'Directionality', type: 'select', options: props.directionalityTypes },
            { key: 'interaction_scale', label: 'Interaction Scale', type: 'select', options: props.scaleTypes },
            { key: 'knowledge_state', label: 'Knowledge State', type: 'select', options: props.knowledgeStates },
            { key: 'danger_rating', label: 'Danger Rating', type: 'select', options: props.dangerRatings },
            { key: 'proximity_required', label: 'Proximity Required', type: 'checkbox' },
            { key: 'source_universe_a', label: 'Source Universe A' },
            { key: 'source_universe_b', label: 'Source Universe B' },
            { key: 'unresolved_flag', label: 'Unresolved', type: 'checkbox' },
        ],
    },
    {
        title: 'Model',
        fields: [
            { key: 'description', label: 'Description JSON', type: 'json', jsonMode: 'document', rows: 8 },
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

const submit = () => form.put(route('power-interactions.update', props.interaction.id))
</script>
