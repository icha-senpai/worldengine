<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Canon Reference"
        :back-href="route('canon-references.index')"
        back-label="Canon References"
        :cancel-href="route('canon-references.index')"
        submit-label="Create Reference"
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
import { formatLabel, toEntityOptions } from '@/Components/scaffold/formatters'
import { buildCanonReferenceSections } from '@/Pages/Lore/CanonReferences/form'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    parentReferences: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    categoryTypes: { type: Array, default: () => [] },
    elementTypes: { type: Array, default: () => [] },
    researchStatuses: { type: Array, default: () => [] },
    researchConfidences: { type: Array, default: () => [] },
    universePriorities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
})

const parentReferenceOptions = computed(() =>
    props.parentReferences.map((reference) => ({
        value: reference.id,
        label: `${reference.title} (#${reference.id}${reference.level ? ` · ${formatLabel(reference.level)}` : ''}${reference.universe ? ` · ${reference.universe}` : ''})`,
    }))
)
const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    universe: '',
    level: '',
    title: '',
    content: null,
    parent_reference_id: '',
    universe_priority: '',
    research_status: '',
    research_confidence: '',
    category_type: '',
    element_type: '',
    canon_disputed: false,
    au_entity_id: '',
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

const submit = () => form.post(route('canon-references.store'))
</script>
