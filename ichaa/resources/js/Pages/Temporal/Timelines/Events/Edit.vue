<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Timeline Event"
        :back-href="route('timelines.show', timeline.id)"
        :back-label="timeline.name ?? 'Timeline'"
        :cancel-href="route('timelines.show', timeline.id)"
        submit-label="Save Timeline Event"
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
    timeline: { type: Object, required: true },
    entry: { type: Object, required: true },
    concurrencyGroups: { type: Array, default: () => [] },
    eventSignificanceLevels: { type: Array, default: () => [] },
})

const form = useForm({
    entry_label: props.entry.entry_label ?? '',
    au_date: props.entry.au_date ?? '',
    source_date: props.entry.source_date ?? '',
    timeline_position: props.entry.timeline_position ?? '',
    concurrency_group_id: props.entry.concurrency_group_id ?? '',
    event_significance: props.entry.event_significance ?? '',
    is_atemporal: Boolean(props.entry.is_atemporal),
})

const concurrencyGroupOptions = computed(() =>
    (props.concurrencyGroups ?? []).map((group) => ({
        value: group.id,
        label: [group.name, group.au_date].filter(Boolean).join(' · '),
    }))
)

const eventLabel = computed(() =>
    props.entry.event_entity?.name
    || props.entry.eventEntity?.name
    || `Event #${props.entry.id}`
)

const sections = computed(() => [
    {
        title: 'Placement',
        fields: [
            {
                key: 'entry_label',
                label: 'Entry Label',
                help: `Editing ${eventLabel.value} on ${props.timeline.name}.`,
            },
            { key: 'au_date', label: 'AU Date' },
            { key: 'source_date', label: 'Source Date' },
            { key: 'timeline_position', label: 'Timeline Position', type: 'number' },
            {
                key: 'concurrency_group_id',
                label: 'Concurrency Group',
                type: 'select',
                options: concurrencyGroupOptions.value,
                placeholder: 'Optional grouping...',
            },
            {
                key: 'event_significance',
                label: 'Event Significance',
                type: 'select',
                options: props.eventSignificanceLevels,
                placeholder: 'Optional significance...',
            },
            { key: 'is_atemporal', label: 'Atemporal Event', type: 'checkbox' },
        ],
    },
])

const submit = () => form.put(
    route('timelines.events.update', {
        timeline: props.timeline.id,
        entry: props.entry.id,
    })
)
</script>
