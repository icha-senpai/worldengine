<template>
    <ScaffoldShowPage
        title="Revision Compare"
        :subtitle="comparison.record_link?.label || `${formatLabel(comparison.resource_type)} #${comparison.resource_id}`"
        back-label="Revisions"
        :back-href="route('admin.revisions.index')"
        badge="Compare"
        :hero-meta="heroMeta"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'

const props = defineProps({
    comparison: { type: Object, required: true },
})

const heroMeta = computed(() => [
    { label: 'Resource', value: formatLabel(props.comparison.resource_type) },
    { label: 'Record', value: `#${props.comparison.resource_id}` },
    { label: 'Left Revision', value: `#${props.comparison.left.id}` },
    { label: 'Right Revision', value: `#${props.comparison.right.id}` },
])

const sections = computed(() => [
    {
        title: 'Left Revision',
        entries: [
            entry('Revision', `#${props.comparison.left.id}`, { href: props.comparison.left.href }),
            entry('Action', formatLabel(props.comparison.left.action)),
            entry('Actor', props.comparison.left.actor_name || '—'),
            entry('Created At', formatDate(props.comparison.left.created_at)),
            entry('Reason', props.comparison.left.reason || '—'),
        ],
    },
    {
        title: 'Right Revision',
        entries: [
            entry('Revision', `#${props.comparison.right.id}`, { href: props.comparison.right.href }),
            entry('Action', formatLabel(props.comparison.right.action)),
            entry('Actor', props.comparison.right.actor_name || '—'),
            entry('Created At', formatDate(props.comparison.right.created_at)),
            entry('Reason', props.comparison.right.reason || '—'),
        ],
    },
    {
        title: 'Changed Fields',
        entries: Object.entries(props.comparison.diff ?? {}).map(([field, value]) =>
            entry(formatLabel(field), value, { kind: 'json' }),
        ),
        fullWidth: true,
    },
    {
        title: 'Payloads',
        entries: [
            entry('Left After Payload', props.comparison.before, { kind: 'json' }),
            entry('Right After Payload', props.comparison.after, { kind: 'json' }),
        ],
        fullWidth: true,
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
