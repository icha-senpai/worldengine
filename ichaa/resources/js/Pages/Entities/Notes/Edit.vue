<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Note"
        :back-href="backHref"
        back-label="Entity"
        :cancel-href="backHref"
        submit-label="Save Note"
        processing-label="Saving..."
        :destroy-href="route('entities.notes.destroy', { entity: props.entity.id, note: props.note.id, tab: 'notes' })"
        destroy-label="Delete Note"
        destroy-confirm="Delete this note from the entity?"
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
    note: { type: Object, required: true },
})

const backHref = computed(() => route('entities.show', { entity: props.entity.id, tab: 'notes' }))

const form = useForm({
    note_label: props.note.note_label ?? '',
    content: props.note.content ?? '',
    sort_order: props.note.sort_order ?? '',
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

const submit = () => form.put(route('entities.notes.update', {
    entity: props.entity.id,
    note: props.note.id,
    tab: 'notes',
}))
</script>
