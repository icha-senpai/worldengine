<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Collection"
        :back-href="route('collections.show', collection.id)"
        back-label="Collection"
        :cancel-href="route('collections.show', collection.id)"
        submit-label="Save Collection"
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
    collection: { type: Object, required: true },
    types: { type: Array, default: () => [] },
    modes: { type: Array, default: () => [] },
    completionStates: { type: Array, default: () => [] },
})

const normalizeCompletionState = (value) =>
    props.completionStates.includes(value) ? value : 'not_started'

const form = useForm({
    name: props.collection.name ?? '',
    collection_type: props.collection.collection_type ?? '',
    collection_mode: props.collection.collection_mode ?? '',
    rules: props.collection.rules ?? null,
    completion_state: normalizeCompletionState(props.collection.completion_state),
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'name', label: 'Name', required: true },
            { key: 'collection_type', label: 'Collection Type', type: 'select', options: props.types },
            { key: 'collection_mode', label: 'Collection Mode', type: 'select', options: props.modes },
            { key: 'completion_state', label: 'Completion State', type: 'select', options: props.completionStates },
        ],
    },
    {
        title: 'Rules',
        fields: [
            { key: 'rules', label: 'Rules JSON', type: 'json', jsonMode: 'collection-rules', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('collections.update', props.collection.id))
</script>
