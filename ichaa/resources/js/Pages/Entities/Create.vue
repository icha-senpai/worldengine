<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Entity"
        :back-href="route('entities.index')"
        back-label="Entities"
        :cancel-href="route('entities.index')"
        submit-label="Create Entity"
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
import { formatLabel } from '@/Components/scaffold/formatters'
import {
    contentClassificationOptions,
    entityStatusOptions,
    visibilityLevelOptions,
} from '@/Pages/Entities/entityFieldOptions'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entityTypes: { type: Object, default: () => ({}) },
})

const form = useForm({
    name: '',
    public_title: '',
    entity_type: '',
    status: 'concept',
    type_status: '',
    origin_type: 'native',
    source_universes: [],
    canon_deviation: '',
    visibility: 'private',
    content_classification: 'restricted',
    origin_notes: null,
    summary: null,
})

const entityTypeOptions = computed(() =>
    Object.entries(props.entityTypes).flatMap(([category, types]) =>
        types.map((type) => ({
            value: type,
            label: `${formatLabel(category)} · ${formatLabel(type)}`,
        })),
    ),
)

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
            jsonMode: 'document',
            rows: 4,
            placeholder: 'Context about how this entity came to exist in your world...',
        },
    ]

    if (form.origin_type === 'canonical') {
        originFields.push({
            key: 'canon_deviation',
            label: 'Canon Deviation',
            type: 'select',
            options: canonDeviationOptions,
            placeholder: 'None - fully canonical',
        })
    }

    return [
        {
            title: 'Identity',
            fields: [
                { key: 'name', label: 'Name', required: true, placeholder: 'Entity name' },
                { key: 'public_title', label: 'Public-Facing Title', placeholder: 'How the world knows them' },
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
                    options: entityStatusOptions,
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
        {
            title: 'Summary',
            fields: [
                {
                    key: 'summary',
                    label: 'Summary',
                    type: 'json',
                    jsonMode: 'document',
                    rows: 4,
                    placeholder: 'Brief description of this entity...',
                },
            ],
        },
        {
            title: 'Access',
            fields: [
                {
                    key: 'visibility',
                    label: 'Visibility',
                    type: 'select',
                    options: visibilityLevelOptions,
                },
                {
                    key: 'content_classification',
                    label: 'Content Classification',
                    type: 'select',
                    options: contentClassificationOptions,
                },
            ],
        },
    ]
})

const submit = () => form.post(route('entities.store'))
</script>
