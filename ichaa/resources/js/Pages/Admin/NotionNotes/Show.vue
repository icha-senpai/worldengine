<template>
    <ScaffoldShowPage
        :title="`Notion Note #${note.id}`"
        :subtitle="note.record_link?.label || `Page ${note.notion_page_id}`"
        back-label="Notion Notes"
        :back-href="route('admin.notion-notes.index')"
        :badge="formatLabel(note.sync_resource)"
        :hero-meta="heroMeta"
        :sections="sections"
    >
        <NotionNotePanel :note="panelNote" />
    </ScaffoldShowPage>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import NotionNotePanel from '@/Components/NotionNotePanel.vue'

const props = defineProps({
    note: { type: Object, required: true },
})

const heroMeta = computed(() => [
    { label: 'Resource', value: formatLabel(props.note.sync_resource) },
    { label: 'Page ID', value: props.note.notion_page_id },
    { label: 'Last Synced', value: formatDate(props.note.last_synced_at) },
])

const sections = computed(() => [
    {
        title: 'Record Link',
        entries: [
            entry('Linked Record', props.note.record_link?.label || '—', props.note.record_link?.href ? { href: props.note.record_link.href } : {}),
            entry('Noteable Type', props.note.noteable_type),
            entry('Noteable ID', props.note.noteable_id ? `#${props.note.noteable_id}` : '—'),
            entry('Content Hash', props.note.content_hash || '—'),
            entry('Notion Last Edited', formatDate(props.note.notion_last_edited_at)),
        ],
    },
])

const panelNote = computed(() => ({
    label: 'Notion Body',
    content: props.note.content,
    lastSyncedAt: props.note.last_synced_at,
}))

const entry = (label, value, extra = {}) => ({
    label,
    value,
    ...extra,
})

const formatLabel = (value) =>
    value ? String(value).replace(/[_-]/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase()) : '—'

const formatDate = (value) => {
    if (!value) {
        return '—'
    }

    return new Date(value).toLocaleString()
}
</script>
