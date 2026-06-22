<template>
    <ScaffoldIndexPage
        title="Knowledge States"
        :count="countRecords(states)"
        count-label="states"
        sync-resource="knowledge_states"
        :create-href="route('knowledge-states.create')"
        create-label="New Knowledge State"
        :items="items"
        empty-title="No knowledge states found"
        :empty-cta-href="route('knowledge-states.create')"
        empty-cta-label="Create the first knowledge state ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    states: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
})

const items = computed(() =>
    asArray(props.states).map((state) => ({
        id: state.id,
        href: route('knowledge-states.show', state.id),
        title: state.knower?.name ?? `Knowledge State #${state.id}`,
        subtitle: state.subject_entity?.name ? `About ${state.subject_entity.name}` : 'Subject not attached to an entity record.',
        badges: [
            badge('Type', formatLabel(state.knowledge_type)),
            badge('Accuracy', formatLabel(state.accuracy)),
        ],
        meta: buildMeta([
            { label: 'Belief', value: formatLabel(state.current_belief_state) },
            { label: 'Acquired', value: formatLabel(state.acquired_through) },
            { label: 'Era', value: state.acquired_at_era },
        ]),
    }))
)
</script>
