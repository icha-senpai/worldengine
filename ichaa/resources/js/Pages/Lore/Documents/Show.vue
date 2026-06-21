<template>
    <ScaffoldShowPage
        :title="document.title"
        back-label="Documents"
        :back-href="route('documents.index')"
        :edit-href="route('documents.edit', document.id)"
        :badge="formatLabel(document.document_type)"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    document: { type: Object, required: true },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Type', formatLabel(props.document.document_type)),
            sectionEntry('Status', formatLabel(props.document.document_status)),
            sectionEntry('Authenticity', formatLabel(props.document.document_authenticity)),
            sectionEntry('Era', props.document.era_created),
        ],
    },
    {
        title: 'Authorship',
        entries: [
            sectionEntry('Official Author', props.document.official_author?.name, props.document.official_author ? { href: route('entities.show', props.document.official_author.id) } : {}),
            sectionEntry('True Author', props.document.true_author?.name, props.document.true_author ? { href: route('entities.show', props.document.true_author.id) } : {}),
            sectionEntry('Owner', props.document.owner?.name, props.document.owner ? { href: route('entities.show', props.document.owner.id) } : {}),
        ],
    },
    {
        title: 'Official Narrative',
        entries: [sectionEntry('Content', props.document.official_narrative, { kind: 'json' })],
        fullWidth: true,
    },
    {
        title: 'True Content',
        entries: [sectionEntry('Content', props.document.true_content, { kind: 'json' })],
        fullWidth: true,
    },
])
</script>
