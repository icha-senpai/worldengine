<template>
    <ScaffoldFormPage
        title="New Concurrency Group"
        :back-href="route('concurrency-groups.index')"
        back-label="Concurrency Groups"
        :cancel-href="route('concurrency-groups.index')"
        submit-label="Create Group"
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
    significanceLevels: { type: Array, default: () => [] },
})

const form = useForm({
    name: '',
    au_date: '',
    description: null,
    narrative_significance: '',
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

const submit = () => form.post(route('concurrency-groups.store'))
</script>
