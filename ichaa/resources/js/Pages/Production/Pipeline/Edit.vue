<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Pipeline Item"
        :back-href="route('pipeline.show', props.item.id)"
        back-label="Pipeline"
        :cancel-href="route('pipeline.show', props.item.id)"
        submit-label="Save Changes"
        processing-label="Saving..."
        :destroy-href="route('pipeline.destroy', props.item.id)"
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import { formatLabel, toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    item: { type: Object, required: true },
    characterEntities: { type: Array, default: () => [] },
    locationEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    pipelineTypes: { type: Array, default: () => [] },
    pipelineStages: { type: Array, default: () => [] },
})

const form = useForm({
    title: props.item.title ?? '',
    pipeline_type: props.item.pipeline_type ?? '',
    pipeline_stage: props.item.pipeline_stage ?? 'concept',
    content: props.item.content ?? null,
    word_count: props.item.word_count ?? 0,
    reading_time_minutes: props.item.reading_time_minutes ?? 0,
    pov_character_entity_id: props.item.pov_character_entity_id ?? '',
    location_entity_id: props.item.location_entity_id ?? '',
    tracked_entity_id: props.item.tracked_entity_id ?? '',
    emotional_beat: props.item.emotional_beat ?? '',
    narrative_purpose: props.item.narrative_purpose ?? null,
    arc_stage: props.item.arc_stage ?? '',
    arc_notes: props.item.arc_notes ?? null,
    notes: props.item.notes ?? null,
})

const pipelineTypeOptions = computed(() => props.pipelineTypes.map((value) => ({ value, label: formatLabel(value) })))
const pipelineStageOptions = computed(() => props.pipelineStages.map((value) => ({ value, label: formatLabel(value) })))
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
                { key: 'title', label: 'Title', required: true },
                {
                    key: 'pipeline_type',
                    label: 'Type',
                    type: 'select',
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
                { key: 'word_count', label: 'Word Count', type: 'number' },
                { key: 'reading_time_minutes', label: 'Reading Time Minutes', type: 'number' },
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
                },
            ],
        })
    }

    sectionsList.push({
        title: 'Notes',
        fields: [
            {
                key: 'notes',
                label: 'Author Notes',
                type: 'json',
                rows: 3,
            },
        ],
    })

    return sectionsList
})

const submit = () => form.put(route('pipeline.update', props.item.id))
</script>
