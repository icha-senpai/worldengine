<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Perception State"
        :back-href="route('perception-states.show', state.id)"
        back-label="Perception State"
        :cancel-href="route('perception-states.show', state.id)"
        submit-label="Save Perception State"
        processing-label="Saving..."
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import {
    toDocumentOptions,
    toEntityOptions,
    toGroupRelationshipOptions,
    toRelationshipOptions,
    toTimelineEntryOptions,
} from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    state: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    factionEntities: { type: Array, default: () => [] },
    locationEntities: { type: Array, default: () => [] },
    relationships: { type: Array, default: () => [] },
    groupRelationships: { type: Array, default: () => [] },
    eventEntries: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
    subjectTypes: { type: Array, default: () => [] },
    divergenceLevels: { type: Array, default: () => [] },
    maintenanceMethods: { type: Array, default: () => [] },
    maintenanceEfforts: { type: Array, default: () => [] },
    revelationRisks: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))
const factionOptions = computed(() => toEntityOptions(props.factionEntities))
const locationOptions = computed(() => toEntityOptions(props.locationEntities))
const relationshipOptions = computed(() => toRelationshipOptions(props.relationships))
const groupRelationshipOptions = computed(() => toGroupRelationshipOptions(props.groupRelationships))
const eventEntryOptions = computed(() => toTimelineEntryOptions(props.eventEntries))
const documentOptions = computed(() => toDocumentOptions(props.documents))

const form = useForm({
    subject_type: props.state.subject_type ?? '',
    subject_id: props.state.subject_id ?? '',
    true_state: props.state.true_state ?? null,
    perceived_state: props.state.perceived_state ?? null,
    divergence_level: props.state.divergence_level ?? '',
    maintained_by_entity_ids: props.state.maintained_by_entity_ids ?? [],
    maintenance_method: props.state.maintenance_method ?? '',
    maintenance_effort: props.state.maintenance_effort ?? '',
    revelation_risk: props.state.revelation_risk ?? '',
})

const subjectOptionsMap = computed(() => ({
    entity: entityOptions.value,
    faction: factionOptions.value,
    location: locationOptions.value,
    relationship: relationshipOptions.value,
    group_relationship: groupRelationshipOptions.value,
    event: eventEntryOptions.value,
    document: documentOptions.value,
}))

const subjectOptions = computed(() => subjectOptionsMap.value[form.subject_type] ?? [])

const subjectPlaceholder = computed(() => {
    if (!form.subject_type) {
        return 'Choose a subject type first...'
    }

    return `Select the ${form.subject_type.replaceAll('_', ' ')} subject...`
})

const subjectHelp = computed(() => {
    if (form.subject_type === 'event') {
        return 'Event subjects use timeline entries, not plain entity rows.'
    }

    if (form.subject_type === 'relationship' || form.subject_type === 'group_relationship') {
        return 'Pick the exact relationship record this false perception is about.'
    }

    return ''
})

watch(() => form.subject_type, (nextType, previousType) => {
    if (previousType && nextType !== previousType) {
        form.subject_id = ''
    }
})

const sections = computed(() => [
    {
        title: 'Subject',
        fields: [
            { key: 'subject_type', label: 'Subject Type', type: 'select', required: true, options: props.subjectTypes },
            {
                key: 'subject_id',
                label: 'Subject',
                type: 'select',
                required: true,
                options: subjectOptions.value,
                placeholder: subjectPlaceholder.value,
                help: subjectHelp.value,
            },
            { key: 'divergence_level', label: 'Divergence Level', type: 'select', options: props.divergenceLevels },
            { key: 'maintenance_method', label: 'Maintenance Method', type: 'select', options: props.maintenanceMethods },
            { key: 'maintenance_effort', label: 'Maintenance Effort', type: 'select', options: props.maintenanceEfforts },
            { key: 'revelation_risk', label: 'Revelation Risk', type: 'select', options: props.revelationRisks },
        ],
    },
    {
        title: 'States',
        fields: [
            { key: 'true_state', label: 'True State JSON', type: 'json', jsonMode: 'document', rows: 8 },
            { key: 'perceived_state', label: 'Perceived State JSON', type: 'json', jsonMode: 'document', rows: 8 },
            {
                key: 'maintained_by_entity_ids',
                label: 'Maintained By Entities',
                type: 'multiselect',
                options: entityOptions.value,
                emptyMessage: 'No entities exist yet to act as maintainers.',
            },
        ],
    },
])

const submit = () => form.put(route('perception-states.update', props.state.id))
</script>
