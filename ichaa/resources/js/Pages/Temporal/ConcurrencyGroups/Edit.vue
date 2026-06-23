<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Concurrency Group"
        :back-href="route('concurrency-groups.index')"
        back-label="Concurrency Groups"
        :cancel-href="route('concurrency-groups.index')"
        submit-label="Save Group"
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
    group: { type: Object, required: true },
    significanceLevels: { type: Array, default: () => [] },
})

const form = useForm({
    name: props.group.name ?? '',
    au_date: props.group.au_date ?? '',
    description: props.group.description ?? null,
    narrative_significance: props.group.narrative_significance ?? '',
})

const sections = computed(() => [
    {
        title: 'Group',
        fields: [
            { key: 'name', label: 'Name', required: true },
            { key: 'au_date', label: 'AU Date' },
            { key: 'narrative_significance', label: 'Narrative Significance', type: 'select', options: props.significanceLevels },
            { key: 'description', label: 'Description JSON', type: 'json', rows: 8 },
        ],
    },
])

const submit = () => form.put(route('concurrency-groups.update', props.group.id))
</script>
