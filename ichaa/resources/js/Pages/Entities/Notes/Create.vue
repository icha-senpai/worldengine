<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Note"
        :back-href="backHref"
        back-label="Entity"
        :cancel-href="backHref"
        submit-label="Create Note"
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
    entity: { type: Object, required: true },
})

const backHref = computed(() => route('entities.show', { entity: props.entity.id, tab: 'notes' }))

const form = useForm({
    note_label: '',
    content: '',
    sort_order: '',
})

const sections = computed(() => [
    {
        title: 'Note',
        fields: [
            {
                key: 'note_label',
                label: 'Label',
                placeholder: 'e.g. Backstory, Motivation, Arc notes...',
            },
            {
                key: 'content',
                label: 'Content',
                type: 'textarea',
                rows: 6,
                required: true,
                placeholder: 'Note content...',
            },
            { key: 'sort_order', label: 'Sort Order', type: 'number', placeholder: 'Display order' },
        ],
    },
])

const submit = () => form.post(route('entities.notes.store', {
    entity: props.entity.id,
    tab: 'notes',
}))
</script>
