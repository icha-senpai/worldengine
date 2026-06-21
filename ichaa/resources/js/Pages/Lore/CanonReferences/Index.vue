<template>
    <ScaffoldIndexPage
        title="Canon References"
        :count="references.length"
        count-label="references"
        :create-href="route('canon-references.create')"
        create-label="New Reference"
        :items="items"
        empty-title="No canon references found"
        :empty-cta-href="route('canon-references.create')"
        empty-cta-label="Create the first reference ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    references: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
})

const items = computed(() =>
    props.references.map((reference) => ({
        id: reference.id,
        href: route('canon-references.show', reference.id),
        title: reference.title,
        subtitle: reference.universe,
        badges: [badge('Level', reference.level)],
        meta: buildMeta([
            { label: 'Priority', value: reference.universe_priority },
            { label: 'Research', value: reference.research_status },
            { label: 'Children', value: reference.child_references?.length },
        ]),
    }))
)
</script>
