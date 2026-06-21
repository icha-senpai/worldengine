<template>
    <ScaffoldShowPage
        :title="secret.title"
        back-label="Secrets"
        :back-href="route('secrets.index')"
        :edit-href="route('secrets.edit', secret.id)"
        :destroy-href="route('secrets.destroy', secret.id)"
        :badge="secret.secret_type || 'secret'"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    secret: { type: Object, required: true },
    subjectEntities: { type: Array, default: () => [] },
    holderEntities: { type: Array, default: () => [] },
    knownByEntities: { type: Array, default: () => [] },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Secret Type', props.secret.secret_type),
            sectionEntry('Exposure Risk', props.secret.exposure_risk),
            sectionEntry('Status', props.secret.status),
            sectionEntry('Revelation Trigger', props.secret.revelation_trigger),
        ],
    },
    {
        title: 'Content',
        entries: [
            sectionEntry('Secret Content', props.secret.secret_content, { kind: 'json' }),
            sectionEntry('Subject Entities', props.subjectEntities, { kind: 'list' }),
            sectionEntry('Holder Entities', props.holderEntities, { kind: 'list' }),
            sectionEntry('Known By Entities', props.knownByEntities, { kind: 'list' }),
        ],
        fullWidth: true,
    },
])
</script>
