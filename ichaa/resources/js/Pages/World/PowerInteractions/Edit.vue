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
    dangerRatings: { type: Array, default: () => [] },
    knowledgeStates: { type: Array, default: () => [] },
})

const form = useForm({
    interaction_name: props.interaction.interaction_name ?? '',
    description: props.interaction.description ?? null,
    effects: props.interaction.effects ?? null,
    knowledge_state: props.interaction.knowledge_state ?? '',
    danger_rating: props.interaction.danger_rating ?? '',
    unresolved_flag: props.interaction.unresolved_flag ?? false,
})

const sections = computed(() => [
    {
        title: 'Interaction State',
        fields: [
            { key: 'interaction_name', label: 'Interaction Name', required: true },
            { key: 'knowledge_state', label: 'Knowledge State', type: 'select', options: props.knowledgeStates },
            { key: 'danger_rating', label: 'Danger Rating', type: 'select', options: props.dangerRatings },
            { key: 'unresolved_flag', label: 'Unresolved', type: 'checkbox' },
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
            },
        ],
    },
])

const submit = () => form.put(route('power-interactions.update', props.interaction.id))
</script>
