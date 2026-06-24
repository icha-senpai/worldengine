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
import { toEntityOptions } from '@/Components/scaffold/formatters'
import { buildPipelineFormState, buildPipelineSections } from '@/Pages/Production/Pipeline/form'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    item: { type: Object, required: true },
    characterEntities: { type: Array, default: () => [] },
    locationEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    pipelineTypes: { type: Array, default: () => [] },
    pipelineStages: { type: Array, default: () => [] },
})

const form = useForm(buildPipelineFormState(props.item, {
    includeMetrics: true,
}))

const characterOptions = computed(() => toEntityOptions(props.characterEntities))
const locationOptions = computed(() => toEntityOptions(props.locationEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))

const sections = computed(() =>
    buildPipelineSections({
        form,
        pipelineTypes: props.pipelineTypes,
        pipelineStages: props.pipelineStages,
        characterOptions: characterOptions.value,
        locationOptions: locationOptions.value,
        entityOptions: entityOptions.value,
        includeMetrics: true,
        contentPlaceholder: 'Write here...',
        notesLabel: 'Author Notes',
    })
)

const submit = () => form.put(route('pipeline.update', props.item.id))
</script>
