<template>
    <ScaffoldShowPage
        :title="timeline.name"
        back-label="Timelines"
        :back-href="route('timelines.index')"
        :edit-href="route('timelines.edit', timeline.id)"
        badge="Timeline"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    timeline: { type: Object, required: true },
    atemporal: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Name', props.timeline.name),
            sectionEntry('Status', formatLabel(props.timeline.status)),
            sectionEntry('Summary', props.timeline.brief_description),
            sectionEntry('Visibility', formatLabel(props.timeline.visibility)),
        ],
        fullWidth: true,
    },
    {
        title: 'Chronological Events',
        entries: [
            sectionEntry(
                'Events',
                (props.events ?? []).map((event) => ({
                    label: `${event.entry_label ?? event.event?.name ?? 'Event'}${event.au_date ? ` · ${event.au_date}` : ''}`,
                    href: event.event?.id ? route('entities.show', event.event.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
    },
    {
        title: 'Atemporal Events',
        entries: [
            sectionEntry(
                'Events',
                (props.atemporal ?? []).map((event) => ({
                    label: event.entry_label ?? event.event?.name ?? 'Atemporal Event',
                    href: event.event?.id ? route('entities.show', event.event.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
