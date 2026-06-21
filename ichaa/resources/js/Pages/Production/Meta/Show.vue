<template>
    <ScaffoldShowPage
        :title="note.title"
        back-label="Meta"
        :back-href="route('meta.index')"
        :edit-href="route('meta.edit', note.id)"
        :badge="formatLabel(note.category)"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    note: { type: Object, required: true },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Category', formatLabel(props.note.category)),
            sectionEntry('Type', formatLabel(props.note.meta_note_type)),
            sectionEntry('Priority', formatLabel(props.note.priority)),
            sectionEntry('Action Status', formatLabel(props.note.action_status)),
            sectionEntry('Resolved At', props.note.resolved_at),
        ],
    },
    {
        title: 'Sensory and Symbolic Notes',
        entries: [
            sectionEntry('Sight', props.note.sense_sight),
            sectionEntry('Sound', props.note.sense_sound),
            sectionEntry('Smell', props.note.sense_smell),
            sectionEntry('Taste', props.note.sense_taste),
            sectionEntry('Touch', props.note.sense_touch),
            sectionEntry('Magical', props.note.sense_magical),
            sectionEntry('Emotional Register', props.note.emotional_register),
            sectionEntry('Symbol Name', props.note.symbol_name),
            sectionEntry('Symbol Scope', formatLabel(props.note.symbol_scope)),
        ],
        fullWidth: true,
    },
    {
        title: 'Content',
        entries: [
            sectionEntry('Content', props.note.content, { kind: 'json' }),
            sectionEntry('Resolution Notes', props.note.resolution_notes, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Links',
        entries: [
            sectionEntry(
                'Entities',
                (props.note.entities ?? []).map((entity) => ({
                    label: `${entity.name} (${formatLabel(entity.entity_type)})`,
                    href: route('entities.show', entity.id),
                })),
                { kind: 'list' },
            ),
            sectionEntry('Superseded By', props.note.superseded_by?.title),
        ],
    },
])
</script>
