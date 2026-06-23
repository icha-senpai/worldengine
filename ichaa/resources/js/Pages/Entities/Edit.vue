<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Entity"
        :back-href="route('entities.show', props.entity.id)"
        back-label="Entity"
        :cancel-href="route('entities.show', props.entity.id)"
        submit-label="Save Changes"
        processing-label="Saving..."
        :destroy-href="route('entities.destroy', props.entity.id)"
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import { formatLabel } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entity: { type: Object, required: true },
    entityTypes: { type: Object, default: () => ({}) },
})

const form = useForm({
    name: props.entity.name ?? '',
    public_title: props.entity.public_title ?? '',
    entity_type: props.entity.entity_type ?? '',
    entity_sub_type: props.entity.entity_sub_type ?? '',
    status: props.entity.status ?? 'concept',
    type_status: props.entity.type_status ?? '',
    summary: props.entity.summary ?? null,
    public_summary: props.entity.public_summary ?? null,
    source_universes: props.entity.source_universes ?? [],
    origin_type: props.entity.origin_type ?? 'native',
    canon_deviation: props.entity.canon_deviation ?? '',
    origin_notes: props.entity.origin_notes ?? null,
    power_tier_ceiling: props.entity.power_tier_ceiling ?? '',
    power_tier_operating: props.entity.power_tier_operating ?? '',
    power_tier_influence: props.entity.power_tier_influence ?? '',
    persona_divergence: props.entity.persona_divergence ?? '',
    control_state: props.entity.control_state ?? '',
    visibility: props.entity.visibility ?? 'private',
    content_classification: props.entity.content_classification ?? 'restricted',
})

const entityTypeOptions = computed(() =>
    Object.entries(props.entityTypes).flatMap(([category, types]) =>
        types.map((type) => ({
            value: type,
            label: `${formatLabel(category)} · ${formatLabel(type)}`,
        })),
    ),
)

const statusOptions = [
    'concept',
    'active',
    'archived',
    'deceased',
    'destroyed',
    'dormant',
    'unknown',
]

const originTypeOptions = [
    { value: 'native', label: 'Native' },
    { value: 'canonical', label: 'Canonical' },
    { value: 'alternate', label: 'Alternate' },
    { value: 'original', label: 'Original' },
]

const canonDeviationOptions = [
    { value: 'minor', label: 'Minor - small divergence' },
    { value: 'moderate', label: 'Moderate - significant changes' },
    { value: 'major', label: 'Major - heavily AU' },
    { value: 'concept_only', label: 'Concept Only - inspired by' },
]

const visibilityOptions = [
    { value: 'private', label: 'Private' },
    { value: 'author_only', label: 'Author Only' },
    { value: 'public_knowledge', label: 'Public Knowledge' },
]

const classificationOptions = [
    { value: 'restricted', label: 'Restricted' },
    { value: 'sensitive', label: 'Sensitive' },
    { value: 'open', label: 'Open' },
]

const powerTierOptions = [
    'mundane',
    'enhanced',
    'legendary',
    'mythic',
    'divine',
    'cosmic',
    'transcendent',
]

const personaDivergenceOptions = [
    { value: 'minor', label: 'Minor - small gaps' },
    { value: 'moderate', label: 'Moderate - meaningful divergence' },
    { value: 'major', label: 'Major - fundamentally different' },
    { value: 'total', label: 'Total - complete mask' },
]

const universeOptions = [
    'Harry Potter',
    'Cosmere',
    'Warhammer 40K',
    'Dune',
    'Wheel of Time',
    'Lord of the Rings',
    'Star Wars',
    'Marvel',
    'DC',
    'Witcher',
    'Elder Scrolls',
    'Final Fantasy',
    'Mass Effect',
    'Dragon Age',
    'Mistborn',
    'Stormlight Archive',
    'First Law',
    'Malazan',
    'Kingkiller Chronicle',
    'Night Circus',
    'Original',
].map((universe) => ({
    value: universe,
    label: universe,
}))

const poweredTypes = [
    'character',
    'historical_figure',
    'constructed_intelligence',
    'deity',
    'cosmic_entity',
    'spirit',
    'creature',
    'cosmological_force',
    'void_entity',
]

const sections = computed(() => {
    const originFields = [
        {
            key: 'origin_type',
            label: 'Origin Type',
            type: 'select',
            options: originTypeOptions,
            placeholder: 'Select the origin type...',
        },
        {
            key: 'source_universes',
            label: 'Source Universes',
            type: 'multiselect',
            options: universeOptions,
            emptyMessage: 'No universes are available.',
        },
        {
            key: 'origin_notes',
            label: 'Origin Notes',
            type: 'json',
            rows: 3,
            placeholder: 'Context about how this entity came to exist in your world...',
        },
    ]

    if (form.origin_type === 'canonical') {
        originFields.splice(2, 0, {
            key: 'canon_deviation',
            label: 'Canon Deviation',
            type: 'select',
            options: canonDeviationOptions,
            placeholder: 'None - fully canonical',
        })
    }

    const sectionsList = [
        {
            title: 'Identity',
            fields: [
                { key: 'name', label: 'Name', required: true },
                { key: 'public_title', label: 'Public Title', placeholder: 'How the world knows them' },
                {
                    key: 'entity_type',
                    label: 'Type',
                    type: 'select',
                    required: true,
                    options: entityTypeOptions.value,
                    placeholder: 'Select an entity type...',
                },
            ],
        },
        {
            title: 'Status',
            fields: [
                {
                    key: 'status',
                    label: 'Status',
                    type: 'select',
                    options: statusOptions,
                    placeholder: 'Select a status...',
                },
                {
                    key: 'type_status',
                    label: 'Type Status',
                    placeholder: 'e.g. Horcrux, Shard Vessel, Champion...',
                },
            ],
        },
        {
            title: 'Origin',
            fields: originFields,
        },
    ]

    if (poweredTypes.includes(form.entity_type)) {
        sectionsList.push({
            title: 'Power Tiers',
            fields: [
                { key: 'power_tier_ceiling', label: 'Ceiling', type: 'select', options: powerTierOptions, placeholder: 'Not set' },
                { key: 'power_tier_operating', label: 'Operating', type: 'select', options: powerTierOptions, placeholder: 'Not set' },
                { key: 'power_tier_influence', label: 'Influence', type: 'select', options: powerTierOptions, placeholder: 'Not set' },
            ],
        })
    }

    sectionsList.push(
        {
            title: 'Perception',
            fields: [
                {
                    key: 'persona_divergence',
                    label: 'Persona Divergence',
                    type: 'select',
                    options: personaDivergenceOptions,
                    placeholder: 'None - public equals private',
                },
            ],
        },
        {
            title: 'Summaries',
            fields: [
                {
                    key: 'summary',
                    label: 'Private Summary',
                    type: 'json',
                    rows: 4,
                    placeholder: 'Full author-level description...',
                },
                {
                    key: 'public_summary',
                    label: 'Public Summary',
                    type: 'json',
                    rows: 3,
                    placeholder: 'How this entity presents to others...',
                },
            ],
        },
        {
            title: 'Access',
            fields: [
                { key: 'visibility', label: 'Visibility', type: 'select', options: visibilityOptions },
                {
                    key: 'content_classification',
                    label: 'Content Classification',
                    type: 'select',
                    options: classificationOptions,
                },
            ],
        },
    )

    return sectionsList
})

const submit = () => form.put(route('entities.update', props.entity.id))
</script>
