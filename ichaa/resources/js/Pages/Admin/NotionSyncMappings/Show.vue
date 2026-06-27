<template>
    <ScaffoldShowPage
        :title="`Notion Mapping #${mapping.id}`"
        :subtitle="mapping.record_link?.label || `Page ${mapping.notion_page_id}`"
        back-label="Notion Sync Mappings"
        :back-href="route('admin.notion-sync-mappings.index')"
        :badge="formatLabel(mapping.sync_resource)"
        :hero-meta="heroMeta"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'

const props = defineProps({
    mapping: { type: Object, required: true },
})

const heroMeta = computed(() => [
    { label: 'Resource', value: formatLabel(props.mapping.sync_resource) },
    { label: 'Page ID', value: props.mapping.notion_page_id },
    { label: 'Last Synced', value: formatDate(props.mapping.last_synced_at) },
])

const sections = computed(() => [
    {
        title: 'Mapping',
        entries: [
            entry('Linked Record', props.mapping.record_link?.label || '—', props.mapping.record_link?.href ? { href: props.mapping.record_link.href } : {}),
            entry('Local Model Type', props.mapping.local_model_type || '—'),
            entry('Local Model ID', props.mapping.local_model_id ? `#${props.mapping.local_model_id}` : '—'),
            entry('Parent Database ID', props.mapping.notion_parent_database_id || '—'),
            entry('Notion Last Edited', formatDate(props.mapping.notion_last_edited_at)),
            entry('Last Payload Hash', props.mapping.last_payload_hash || '—'),
        ],
    },
])

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
