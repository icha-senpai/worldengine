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
        <template #toolbar>
            <ScaffoldFilterBar
                :fields="filterFields"
                :form="filterForm"
                :has-active-filters="hasActiveFilters"
                :on-apply="applyFilters"
                :on-clear="clearFilters"
            />
        </template>
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
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateKnowledgeState from '@/Pages/Intelligence/KnowledgeStates/Create.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'
import { toEntityOptions } from '@/Components/scaffold/formatters'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    states: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    entities: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('knowledge-states.index', {
    knower: props.filters.knower ? String(props.filters.knower) : '',
    about: props.filters.about ? String(props.filters.about) : '',
    latent: Boolean(props.filters.latent),
    compartmentalizing: Boolean(props.filters.compartmentalizing),
})

const filterFields = computed(() => [
    { key: 'knower', type: 'select', placeholder: 'All knowers', options: entityOptions.value },
    { key: 'about', type: 'select', placeholder: 'All subjects', options: entityOptions.value },
    { key: 'latent', type: 'checkbox', label: 'Latent only' },
    { key: 'compartmentalizing', type: 'checkbox', label: 'Compartmentalizing only' },
])

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
