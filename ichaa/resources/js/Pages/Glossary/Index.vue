<template>
    <ScaffoldIndexPage
        title="Glossary"
        :count="countRecords(terms)"
        count-label="terms"
        :create-href="route('glossary.create')"
        create-label="New Term"
        :items="items"
        empty-title="No glossary terms found"
        :empty-cta-href="route('glossary.create')"
        empty-cta-label="Create the first term ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    terms: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
})

const items = computed(() =>
    asArray(props.terms).map((term) => ({
        id: term.id,
        href: route('glossary.show', term.id),
        title: term.term,
        subtitle: term.usage_context,
        meta: buildMeta([
            { label: 'Origin', value: term.origin_universe },
            { label: 'Era', value: term.era_introduced },
            { label: 'Status', value: term.term_status },
        ]),
    }))
)
</script>
