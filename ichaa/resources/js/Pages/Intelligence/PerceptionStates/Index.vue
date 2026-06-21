<template>
    <ScaffoldIndexPage
        title="Perception States"
        :count="countRecords(states)"
        count-label="states"
        :create-href="route('perception-states.create')"
        create-label="New Perception State"
        :items="items"
        empty-title="No perception states found"
        :empty-cta-href="route('perception-states.create')"
        empty-cta-label="Create the first perception state ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    states: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
})

const items = computed(() =>
    asArray(props.states).map((state) => ({
        id: state.id,
        href: route('perception-states.show', state.id),
        title: `${state.subject_type ?? 'subject'} #${state.subject_id ?? state.id}`,
        badges: [badge('Divergence', state.divergence_level)],
        meta: buildMeta([
            { label: 'Method', value: state.maintenance_method },
            { label: 'Effort', value: state.maintenance_effort },
            { label: 'Risk', value: state.revelation_risk },
        ]),
    }))
)
</script>
