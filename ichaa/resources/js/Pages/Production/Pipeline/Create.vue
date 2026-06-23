<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Pipeline Item"
        :back-href="backHref"
        back-label="Pipeline"
        :cancel-href="backHref"
        submit-label="Create Item"
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
import { formatLabel, toEntityOptions, toPipelineItemOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    closeHref: { type: String, default: '' },
    initialParentItemId: { type: Number, default: null },
    parentItems: { type: Array, default: () => [] },
    characterEntities: { type: Array, default: () => [] },
    locationEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    pipelineTypes: { type: Array, default: () => [] },
    pipelineStages: { type: Array, default: () => [] },
})

const form = useForm({
    title: '',
    pipeline_type: '',
    pipeline_stage: 'concept',
    parent_pipeline_item_id: props.initialParentItemId ?? '',
    content: null,
    pov_character_entity_id: '',
    location_entity_id: '',
    tracked_entity_id: '',
    emotional_beat: '',
    narrative_purpose: null,
    arc_stage: '',
    arc_notes: null,
    notes: null,
})

const backHref = computed(() => props.closeHref || route('pipeline.index'))
const pipelineTypeOptions = computed(() => props.pipelineTypes.map((value) => ({ value, label: formatLabel(value) })))
const pipelineStageOptions = computed(() => props.pipelineStages.map((value) => ({ value, label: formatLabel(value) })))
const parentItemOptions = computed(() => toPipelineItemOptions(props.parentItems))
const characterOptions = computed(() => toEntityOptions(props.characterEntities))
const locationOptions = computed(() => toEntityOptions(props.locationEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))

const emotionalBeatOptions = [
    'tension_building',
    'release',
    'revelation',
    'quiet_moment',
    'confrontation',
    'turning_point',
    'aftermath',
]

const arcStageOptions = [
    'inciting_event',
    'rising_pressure',
    'threshold_moment',
    'transformation',
    'integration',
    'aftermath',
]

const sections = computed(() => {
    const sectionsList = [
        {
            title: 'Identity',
            fields: [
                { key: 'title', label: 'Title', required: true, placeholder: 'Item title' },
                {
                    key: 'pipeline_type',
                    label: 'Type',
                    type: 'select',
                    required: true,
                    options: pipelineTypeOptions.value,
                    placeholder: 'Select a pipeline type...',
                },
                {
                    key: 'pipeline_stage',
                    label: 'Stage',
                    type: 'select',
                    options: pipelineStageOptions.value,
                    placeholder: 'Select a stage...',
                },
                {
                    key: 'parent_pipeline_item_id',
                    label: 'Parent Item',
                    type: 'select',
                    options: parentItemOptions.value,
                    placeholder: 'Top-level item',
                },
            ],
        },
        {
            title: 'Content',
            fields: [
                {
                    key: 'content',
                    label: 'Content',
                    type: 'json',
                    rows: 10,
                    placeholder: 'Write here...',
                },
            ],
        },
    ]

    if (form.pipeline_type === 'scene') {
        sectionsList.push({
            title: 'Scene Details',
            fields: [
                {
                    key: 'pov_character_entity_id',
                    label: 'POV Character',
                    type: 'select',
                    options: characterOptions.value,
                    placeholder: 'Select a POV character...',
                },
                {
                    key: 'location_entity_id',
                    label: 'Location',
                    type: 'select',
                    options: locationOptions.value,
                    placeholder: 'Select a location...',
                },
                {
                    key: 'emotional_beat',
                    label: 'Emotional Beat',
                    type: 'select',
                    options: emotionalBeatOptions,
                    placeholder: 'Select an emotional beat...',
                },
                {
                    key: 'narrative_purpose',
                    label: 'Narrative Purpose',
                    type: 'json',
                    rows: 3,
                    placeholder: 'What this scene accomplishes in the arc...',
                },
            ],
        })
    }

    if (form.pipeline_type === 'character_study') {
        sectionsList.push({
            title: 'Arc Tracker',
            fields: [
                {
                    key: 'tracked_entity_id',
                    label: 'Tracked Entity',
                    type: 'select',
                    options: entityOptions.value,
                    placeholder: 'Select an entity to track...',
                },
                {
                    key: 'arc_stage',
                    label: 'Arc Stage',
                    type: 'select',
                    options: arcStageOptions,
                    placeholder: 'Select an arc stage...',
                },
                {
                    key: 'arc_notes',
                    label: 'Arc Notes',
                    type: 'json',
                    rows: 3,
                    placeholder: "What's happening in this character's arc here...",
                },
            ],
        })
    }

    sectionsList.push({
        title: 'Notes',
        fields: [
            {
                key: 'notes',
                label: 'Notes',
                type: 'json',
                rows: 3,
                placeholder: 'Author notes, ideas, reminders...',
            },
        ],
    })

    return sectionsList
})

const submit = () => form.post(route('pipeline.store'))
</script>
