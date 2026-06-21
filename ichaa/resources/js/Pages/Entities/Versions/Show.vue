<template>
    <ScaffoldShowPage
        :title="version.version_label || `Version ${version.version_number ?? version.id}`"
        :subtitle="entity.name"
        back-label="Versions"
        :back-href="route('entities.versions.index', entity.id)"
        :badge="version.version_type || 'version'"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    entity: { type: Object, required: true },
    version: { type: Object, required: true },
})

const sections = computed(() => [
    {
        title: 'Version Metadata',
        entries: [
            sectionEntry('Entity', props.entity.name, { href: route('entities.show', props.entity.id) }),
            sectionEntry('Version Type', props.version.version_type),
            sectionEntry('Version Number', props.version.version_number),
            sectionEntry('Version State', props.version.version_state),
            sectionEntry('Current', props.version.is_current),
            sectionEntry('Version Zero', props.version.is_version_zero),
            sectionEntry('Valid From', props.version.valid_from_era),
            sectionEntry('Valid Until', props.version.valid_until_era),
        ],
    },
    {
        title: 'Narrative',
        entries: [
            sectionEntry('What Changed', props.version.what_changed, { kind: 'json' }),
            sectionEntry('Why Changed', props.version.why_changed, { kind: 'json' }),
            sectionEntry('Entity Snapshot', props.version.entity_snapshot, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Iteration Details',
        entries: [
            sectionEntry('Trigger Type', props.version.trigger_type),
            sectionEntry('Triggered By Field', props.version.triggered_by_field),
            sectionEntry('Iteration Number', props.version.iteration_number),
            sectionEntry('Failure Era', props.version.failure_era),
            sectionEntry('What Failed', props.version.what_failed),
            sectionEntry('Deprecation Reason', props.version.deprecation_reason, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
