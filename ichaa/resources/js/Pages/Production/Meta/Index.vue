<template>
    <ScaffoldIndexPage
        title="Meta Notes"
        :count="countRecords(notes)"
        count-label="notes"
        sync-resource="meta"
        :create-href="route('meta.create')"
        create-label="New Meta Note"
        :items="items"
        empty-title="No meta notes found"
        :empty-cta-href="route('meta.create')"
        empty-cta-label="Create the first meta note ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    notes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    noteTypes: { type: Array, default: () => [] },
})

const items = computed(() =>
    asArray(props.notes).map((note) => ({
        id: note.id,
        href: route('meta.show', note.id),
        title: note.title,
        badges: [
            badge('Category', formatLabel(note.category)),
            badge('Type', formatLabel(note.meta_note_type)),
        ],
        meta: buildMeta([
            { label: 'Priority', value: formatLabel(note.priority) },
            { label: 'Action', value: formatLabel(note.action_status) },
            { label: 'Resolved', value: note.resolved_at },
        ]),
    }))
)
</script>
