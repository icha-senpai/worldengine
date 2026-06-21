<template>
    <ScaffoldShowPage
        :title="reference.title"
        :subtitle="reference.universe"
        back-label="Canon References"
        :back-href="route('canon-references.index')"
        :edit-href="route('canon-references.edit', reference.id)"
        :destroy-href="route('canon-references.destroy', reference.id)"
        :badge="reference.level || 'reference'"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    reference: { type: Object, required: true },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Universe', props.reference.universe),
            sectionEntry('Level', props.reference.level),
            sectionEntry('Priority', props.reference.universe_priority),
            sectionEntry('Research Status', props.reference.research_status),
            sectionEntry('Research Confidence', props.reference.research_confidence),
            sectionEntry('Canon Disputed', props.reference.canon_disputed),
        ],
    },
    {
        title: 'Content',
        entries: [
            sectionEntry('Content', props.reference.content, { kind: 'json' }),
            sectionEntry('Research Notes', props.reference.research_notes, { kind: 'json' }),
            sectionEntry('Your Ruling', props.reference.your_ruling, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Links',
        entries: [
            sectionEntry('AU Entity', props.reference.au_entity?.name, props.reference.au_entity ? { href: route('entities.show', props.reference.au_entity.id) } : {}),
            sectionEntry(
                'Linked Entities',
                (props.reference.linked_entities ?? []).map((entity) => ({
                    label: entity.name,
                    href: route('entities.show', entity.id),
                })),
                { kind: 'list' },
            ),
            sectionEntry(
                'Child References',
                (props.reference.child_references ?? []).map((child) => ({
                    label: child.title,
                    href: route('canon-references.show', child.id),
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
