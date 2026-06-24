import { formatLabel } from '@/Components/scaffold/formatters'

export const pipelineEmotionalBeatOptions = [
    'tension_building',
    'release',
    'revelation',
    'quiet_moment',
    'confrontation',
    'turning_point',
    'aftermath',
]

export const pipelineArcStageOptions = [
    'inciting_event',
    'rising_pressure',
    'threshold_moment',
    'transformation',
    'integration',
    'aftermath',
]

export function buildPipelineFormState(initial = {}, { includeParentField = false, includeMetrics = false, initialParentItemId = null } = {}) {
    return {
        title: initial.title ?? '',
        pipeline_type: initial.pipeline_type ?? '',
        pipeline_stage: initial.pipeline_stage ?? 'concept',
        ...(includeParentField ? { parent_pipeline_item_id: initial.parent_pipeline_item_id ?? initialParentItemId ?? '' } : {}),
        content: initial.content ?? null,
        ...(includeMetrics ? {
            word_count: initial.word_count ?? 0,
            reading_time_minutes: initial.reading_time_minutes ?? 0,
        } : {}),
        pov_character_entity_id: initial.pov_character_entity_id ?? '',
        location_entity_id: initial.location_entity_id ?? '',
        tracked_entity_id: initial.tracked_entity_id ?? '',
        emotional_beat: initial.emotional_beat ?? '',
        narrative_purpose: initial.narrative_purpose ?? null,
        arc_stage: initial.arc_stage ?? '',
        arc_notes: initial.arc_notes ?? null,
        notes: initial.notes ?? null,
    }
}

export function buildPipelineSections({
    form,
    pipelineTypes = [],
    pipelineStages = [],
    parentItemOptions = [],
    characterOptions = [],
    locationOptions = [],
    entityOptions = [],
    includeParentField = false,
    includeMetrics = false,
    contentPlaceholder = 'Write here...',
    narrativePurposePlaceholder = '',
    arcNotesPlaceholder = '',
    notesLabel = 'Notes',
    notesPlaceholder = '',
}) {
    const identityFields = [
        { key: 'title', label: 'Title', required: true, placeholder: 'Item title' },
        {
            key: 'pipeline_type',
            label: 'Type',
            type: 'select',
            required: true,
            options: pipelineTypes.map((value) => ({ value, label: formatLabel(value) })),
            placeholder: 'Select a pipeline type...',
        },
        {
            key: 'pipeline_stage',
            label: 'Stage',
            type: 'select',
            options: pipelineStages.map((value) => ({ value, label: formatLabel(value) })),
            placeholder: 'Select a stage...',
        },
    ]

    if (includeParentField) {
        identityFields.push({
            key: 'parent_pipeline_item_id',
            label: 'Parent Item',
            type: 'select',
            options: parentItemOptions,
            placeholder: 'Top-level item',
        })
    }

    const contentFields = [
        {
            key: 'content',
            label: 'Content',
            type: 'json',
            jsonMode: 'document',
            rows: 10,
            placeholder: contentPlaceholder,
        },
    ]

    if (includeMetrics) {
        contentFields.push(
            { key: 'word_count', label: 'Word Count', type: 'number' },
            { key: 'reading_time_minutes', label: 'Reading Time Minutes', type: 'number' },
        )
    }

    const sections = [
        {
            title: 'Identity',
            fields: identityFields,
        },
        {
            title: 'Content',
            fields: contentFields,
        },
    ]

    if (form.pipeline_type === 'scene') {
        sections.push({
            title: 'Scene Details',
            fields: [
                {
                    key: 'pov_character_entity_id',
                    label: 'POV Character',
                    type: 'select',
                    options: characterOptions,
                    placeholder: 'Select a POV character...',
                },
                {
                    key: 'location_entity_id',
                    label: 'Location',
                    type: 'select',
                    options: locationOptions,
                    placeholder: 'Select a location...',
                },
                {
                    key: 'emotional_beat',
                    label: 'Emotional Beat',
                    type: 'select',
                    options: pipelineEmotionalBeatOptions,
                    placeholder: 'Select an emotional beat...',
                },
                {
                    key: 'narrative_purpose',
                    label: 'Narrative Purpose',
                    type: 'json',
                    jsonMode: 'document',
                    rows: 3,
                    placeholder: narrativePurposePlaceholder,
                },
            ],
        })
    }

    if (form.pipeline_type === 'character_study') {
        sections.push({
            title: 'Arc Tracker',
            fields: [
                {
                    key: 'tracked_entity_id',
                    label: 'Tracked Entity',
                    type: 'select',
                    options: entityOptions,
                    placeholder: 'Select an entity to track...',
                },
                {
                    key: 'arc_stage',
                    label: 'Arc Stage',
                    type: 'select',
                    options: pipelineArcStageOptions,
                    placeholder: 'Select an arc stage...',
                },
                {
                    key: 'arc_notes',
                    label: 'Arc Notes',
                    type: 'json',
                    jsonMode: 'document',
                    rows: 3,
                    placeholder: arcNotesPlaceholder,
                },
            ],
        })
    }

    sections.push({
        title: 'Notes',
        fields: [
            {
                key: 'notes',
                label: notesLabel,
                type: 'json',
                jsonMode: 'document',
                rows: 3,
                placeholder: notesPlaceholder,
            },
        ],
    })

    return sections
}
