<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Canon Reference"
        :back-href="route('canon-references.show', reference.id)"
        back-label="Reference"
        :cancel-href="route('canon-references.show', reference.id)"
        submit-label="Save Reference"
        processing-label="Saving..."
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
import { buildCanonReferenceSections } from '@/Pages/Lore/CanonReferences/form'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    reference: { type: Object, required: true },
    parentReferences: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    categoryTypes: { type: Array, default: () => [] },
    elementTypes: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    researchStatuses: { type: Array, default: () => [] },
    researchConfidences: { type: Array, default: () => [] },
    universePriorities: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))
const parentReferenceOptions = computed(() =>
    props.parentReferences
        .filter((reference) => reference.id !== props.reference.id)
        .map((reference) => ({
            value: reference.id,
            label: `${reference.title} (#${reference.id}${reference.level ? ` · ${formatLabel(reference.level)}` : ''}${reference.universe ? ` · ${reference.universe}` : ''})`,
        }))
)

const form = useForm({
    universe: props.reference.universe ?? '',
    level: props.reference.level ?? '',
    title: props.reference.title ?? '',
    parent_reference_id: props.reference.parent_reference_id ?? '',
    universe_priority: props.reference.universe_priority ?? '',
    content: props.reference.content ?? null,
    research_status: props.reference.research_status ?? '',
    research_confidence: props.reference.research_confidence ?? '',
    category_type: props.reference.category_type ?? '',
    element_type: props.reference.element_type ?? '',
    canon_disputed: props.reference.canon_disputed ?? false,
    au_entity_id: props.reference.au_entity_id ?? '',
})

const sections = computed(() =>
    buildCanonReferenceSections({
        levels: props.levels,
        universePriorities: props.universePriorities,
        researchStatuses: props.researchStatuses,
        researchConfidences: props.researchConfidences,
        categoryTypes: props.categoryTypes,
        elementTypes: props.elementTypes,
        parentReferenceOptions: parentReferenceOptions.value,
        entityOptions: entityOptions.value,
    })
)

const submit = () => form.put(route('canon-references.update', props.reference.id))
</script>
