<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Knowledge State"
        :back-href="route('knowledge-states.show', state.id)"
        back-label="Knowledge State"
        :cancel-href="route('knowledge-states.show', state.id)"
        submit-label="Save Knowledge State"
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
    state: { type: Object, required: true },
    beliefStates: { type: Array, default: () => [] },
    accuracyLevels: { type: Array, default: () => [] },
})

const form = useForm({
    knowledge_content: props.state.knowledge_content ?? null,
    accuracy: props.state.accuracy ?? '',
    current_belief_state: props.state.current_belief_state ?? '',
})

const sections = computed(() => [
    {
        title: 'Knowledge Model',
        fields: [
            { key: 'accuracy', label: 'Accuracy', type: 'select', options: props.accuracyLevels },
            { key: 'current_belief_state', label: 'Belief State', type: 'select', options: props.beliefStates },
            { key: 'knowledge_content', label: 'Knowledge Content JSON', type: 'json', jsonMode: 'document', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('knowledge-states.update', props.state.id))
</script>
