<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Alias"
        :back-href="backHref"
        back-label="Entity"
        :cancel-href="backHref"
        submit-label="Save Alias"
        processing-label="Saving..."
        :destroy-href="route('entities.aliases.destroy', { entity: props.entity.id, alias: props.alias.id, tab: 'aliases' })"
        destroy-label="Delete Alias"
        destroy-confirm="Delete this alias from the entity?"
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import { entityAliasTypeOptions } from '@/Pages/Entities/aliasTypes'
import { toEntityOptions } from '@/Components/scaffold/formatters'
import { contentClassificationOptions, visibilityLevelOptions } from '@/Pages/Entities/entityFieldOptions'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entity: { type: Object, required: true },
    alias: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
})

const backHref = computed(() => route('entities.show', { entity: props.entity.id, tab: 'aliases' }))
const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    alias: props.alias.alias ?? '',
    alias_type: props.alias.alias_type ?? '',
    context: props.alias.context ?? '',
    era_start: props.alias.era_start ?? '',
    era_end: props.alias.era_end ?? '',
    is_active: props.alias.is_active ?? false,
    known_by_entity_ids: props.alias.known_by_entity_ids ?? [],
    visibility: props.alias.visibility ?? 'private',
    content_classification: props.alias.content_classification ?? 'restricted',
})

const sections = computed(() => [
    {
        title: 'Alias',
        fields: [
            { key: 'alias', label: 'Alias', required: true, placeholder: 'The alias text' },
            {
                key: 'alias_type',
                label: 'Type',
                type: 'select',
                required: true,
                options: entityAliasTypeOptions,
                placeholder: 'Select alias type...',
            },
            {
                key: 'context',
                label: 'Context',
                type: 'textarea',
                rows: 3,
                placeholder: 'When, where, or by whom this alias is used.',
            },
            { key: 'era_start', label: 'Era Start', placeholder: 'e.g. Year of the Dragon' },
            { key: 'era_end', label: 'Era End', placeholder: 'Leave blank if still active' },
            { key: 'is_active', label: 'Currently Active', type: 'checkbox' },
        ],
    },
    {
        title: 'Access',
        fields: [
            {
                key: 'known_by_entity_ids',
                label: 'Known By Specific Entities',
                type: 'multiselect',
                options: entityOptions.value,
                emptyMessage: 'No entities are available yet.',
                help: 'Leave empty if this alias is broadly or publicly known.',
            },
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
])

const submit = () => form.put(route('entities.aliases.update', {
    entity: props.entity.id,
    alias: props.alias.id,
    tab: 'aliases',
}))
</script>
