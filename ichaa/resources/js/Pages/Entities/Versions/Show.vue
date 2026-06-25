<template>
    <ScaffoldShowPage
        :title="version.version_label || `Version ${version.version_number ?? version.id}`"
        :subtitle="subtitle"
        back-label="Versions"
        :back-href="route('entities.versions.index', entity.id)"
        :badge="formatLabel(version.version_state || version.version_type || 'version')"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    entity: { type: Object, required: true },
    version: { type: Object, required: true },
})

const subtitle = computed(() => {
    if (props.version.is_version_zero) {
        return `${props.entity.name} source-canon capture and grounding notes.`
    }

    if (props.version.version_type === 'hard_iteration') {
        return `${props.entity.name} iteration record, retention trail, and termination history.`
    }

    return `${props.entity.name} version snapshot and change record.`
})

const sections = computed(() => [
    {
        title: 'Version Metadata',
        description: 'When this version applies, how it was triggered, and whether it still anchors the active continuity.',
        entries: [
            sectionEntry('Entity', props.entity.name, { href: route('entities.show', props.entity.id) }),
            sectionEntry('Version Type', props.version.version_type),
            sectionEntry('Version Number', props.version.version_number),
            sectionEntry('Version Label', props.version.version_label),
            sectionEntry('Version State', props.version.version_state),
            sectionEntry('Current', props.version.is_current),
            sectionEntry('Version Zero', props.version.is_version_zero),
            sectionEntry('Trigger Type', props.version.trigger_type),
            sectionEntry('Triggered By Field', props.version.triggered_by_field),
            sectionEntry('Valid From', props.version.valid_from_era),
            sectionEntry('Valid Until', props.version.valid_until_era),
            sectionEntry('Created', props.version.created_at),
        ],
    },
    {
        title: 'Access and Canon Position',
        description: 'Visibility, classification, and lineage details that tell you how this record should be treated.',
        entries: [
            sectionEntry('Visibility', props.version.visibility),
            sectionEntry('Content Classification', props.version.content_classification),
            sectionEntry('Version Zero Confidence', props.version.version_zero_confidence),
            sectionEntry('Source Entity', props.version.source_entity?.name, props.version.source_entity?.id
                ? { href: route('entities.show', props.version.source_entity.id) }
                : {}),
            sectionEntry('Iteration Number', props.version.iteration_number),
            sectionEntry('Terminated By', props.version.terminated_by?.name, props.version.terminated_by?.id
                ? { href: route('entities.show', props.version.terminated_by.id) }
                : {}),
            sectionEntry(
                'Superseded By',
                props.version.superseded_by
                    ? props.version.superseded_by.version_label || `Version ${props.version.superseded_by.version_number}`
                    : null,
                props.version.superseded_by?.id
                    ? { href: route('entities.versions.show', [props.entity.id, props.version.superseded_by.id]) }
                    : {},
            ),
            sectionEntry('Deprecated At', props.version.deprecated_at),
        ],
    },
    {
        title: 'Narrative Record',
        description: 'The story-level explanation for the shift, including canon-zero notes and any retained iteration carryover.',
        entries: [
            sectionEntry('What Changed', props.version.what_changed, { kind: 'json' }),
            sectionEntry('Why Changed', props.version.why_changed, { kind: 'json' }),
            sectionEntry('Version Zero Notes', props.version.version_zero_notes),
            sectionEntry('Retained From Previous', props.version.retained_from_previous, { kind: 'json' }),
            sectionEntry('What Failed', props.version.what_failed, { kind: 'json' }),
            sectionEntry('Failure Era', props.version.failure_era),
            sectionEntry('Deprecation Reason', props.version.deprecation_reason, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Entity Snapshot',
        description: 'Full serialized entity state preserved at the moment this version was captured.',
        entries: [
            sectionEntry('Snapshot Payload', props.version.entity_snapshot, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
