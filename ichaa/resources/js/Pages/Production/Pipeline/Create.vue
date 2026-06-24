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
import { toEntityOptions, toPipelineItemOptions } from '@/Components/scaffold/formatters'
import { buildPipelineFormState, buildPipelineSections } from '@/Pages/Production/Pipeline/form'

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

const form = useForm(buildPipelineFormState({}, {
    includeParentField: true,
    initialParentItemId: props.initialParentItemId,
}))

const backHref = computed(() => props.closeHref || route('pipeline.index'))
const parentItemOptions = computed(() => toPipelineItemOptions(props.parentItems))
const characterOptions = computed(() => toEntityOptions(props.characterEntities))
const locationOptions = computed(() => toEntityOptions(props.locationEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))

const sections = computed(() =>
    buildPipelineSections({
        form,
        pipelineTypes: props.pipelineTypes,
        pipelineStages: props.pipelineStages,
        parentItemOptions: parentItemOptions.value,
        characterOptions: characterOptions.value,
        locationOptions: locationOptions.value,
        entityOptions: entityOptions.value,
        includeParentField: true,
        contentPlaceholder: 'Write here...',
        narrativePurposePlaceholder: 'What this scene accomplishes in the arc...',
        arcNotesPlaceholder: "What's happening in this character's arc here...",
        notesLabel: 'Notes',
        notesPlaceholder: 'Author notes, ideas, reminders...',
    })
)

const submit = () => form.post(route('pipeline.store'))
</script>
