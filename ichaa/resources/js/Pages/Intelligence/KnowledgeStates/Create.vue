<template>
    <ScaffoldFormPage
        title="New Knowledge State"
        :back-href="route('knowledge-states.index')"
        back-label="Knowledge States"
        :cancel-href="route('knowledge-states.index')"
        submit-label="Create Knowledge State"
        processing-label="Creating..."
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import {
    toEntityOptions,
    toGroupRelationshipOptions,
    toRelationshipOptions,
    toSecretOptions,
    toTimelineEntryOptions,
} from '@/Components/scaffold/formatters'

const props = defineProps({
    entities: { type: Array, default: () => [] },
    secrets: { type: Array, default: () => [] },
    relationships: { type: Array, default: () => [] },
    groupRelationships: { type: Array, default: () => [] },
    eventEntries: { type: Array, default: () => [] },
    knowledgeTypes: { type: Array, default: () => [] },
    accuracyLevels: { type: Array, default: () => [] },
    beliefStates: { type: Array, default: () => [] },
    acquisitionMethods: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))
const secretOptions = computed(() => toSecretOptions(props.secrets))
const relationshipOptions = computed(() => toRelationshipOptions(props.relationships))
const groupRelationshipOptions = computed(() => toGroupRelationshipOptions(props.groupRelationships))
const eventEntryOptions = computed(() => toTimelineEntryOptions(props.eventEntries))

const form = useForm({
    knower_entity_id: '',
    subject_entity_id: '',
    subject_secret_id: '',
    subject_relationship_id: '',
    subject_group_relationship_id: '',
    subject_event_id: '',
    knowledge_type: '',
    knowledge_content: null,
    accuracy: '',
    current_belief_state: '',
    acquired_through: '',
    acquired_from_entity_id: '',
    acquired_at_era: '',
})

const sections = computed(() => [
    {
        title: 'Participants',
        fields: [
            {
                key: 'knower_entity_id',
                label: 'Knower',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select who knows this...',
            },
            {
                key: 'subject_entity_id',
                label: 'Subject Entity',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional direct entity subject...',
            },
            {
                key: 'subject_secret_id',
                label: 'Subject Secret',
                type: 'select',
                options: secretOptions.value,
                placeholder: 'Optional secret subject...',
            },
            {
                key: 'subject_relationship_id',
                label: 'Subject Relationship',
                type: 'select',
                options: relationshipOptions.value,
                placeholder: 'Optional relationship subject...',
            },
            {
                key: 'subject_group_relationship_id',
                label: 'Subject Group Relationship',
                type: 'select',
                options: groupRelationshipOptions.value,
                placeholder: 'Optional group relationship subject...',
            },
            {
                key: 'subject_event_id',
                label: 'Subject Event Entry',
                type: 'select',
                options: eventEntryOptions.value,
                placeholder: 'Optional timeline event entry...',
                help: 'This links to a timeline entry, not a plain entity row.',
            },
            {
                key: 'acquired_from_entity_id',
                label: 'Acquired From',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional source of the knowledge...',
            },
        ],
    },
    {
        title: 'Knowledge Model',
        fields: [
            { key: 'knowledge_type', label: 'Knowledge Type', type: 'select', required: true, options: props.knowledgeTypes },
            { key: 'accuracy', label: 'Accuracy', type: 'select', required: true, options: props.accuracyLevels },
            { key: 'current_belief_state', label: 'Belief State', type: 'select', required: true, options: props.beliefStates },
            { key: 'acquired_through', label: 'Acquired Through', type: 'select', required: true, options: props.acquisitionMethods },
            { key: 'acquired_at_era', label: 'Acquired At Era' },
            { key: 'knowledge_content', label: 'Knowledge Content JSON', type: 'json', rows: 8 },
        ],
    },
])

const submit = () => form.post(route('knowledge-states.store'))
</script>
