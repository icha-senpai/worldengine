<template>
    <div>
        <ScaffoldIndexPage
            title="Knowledge States"
            :count="countRecords(states)"
            count-label="states"
            sync-resource="knowledge_states"
            :create-href="route('knowledge-states.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('knowledge-states.index')"
            create-label="New Knowledge State"
            :items="items"
            empty-title="No knowledge states found"
            :empty-cta-href="route('knowledge-states.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first knowledge state ->"
        >
        <template #create-drawer>
            <CreateKnowledgeState
                v-if="createDrawer"
                embedded
                v-bind="createDrawer"
            />
        </template>
        </ScaffoldIndexPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateKnowledgeState from '@/Pages/Intelligence/KnowledgeStates/Create.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    states: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    createDrawer: { type: Object, default: null },
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
