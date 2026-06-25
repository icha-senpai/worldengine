<template>
    <div>
        <ScaffoldIndexPage
            title="Perception States"
            :count="countRecords(states)"
            count-label="states"
            sync-resource="perception_states"
            :create-href="route('perception-states.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('perception-states.index')"
            create-label="New Perception State"
            :items="items"
            empty-title="No perception states found"
            :empty-cta-href="route('perception-states.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first perception state ->"
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
            <CreatePerceptionState
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
import CreatePerceptionState from '@/Pages/Intelligence/PerceptionStates/Create.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    states: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('perception-states.index', {
    high_risk: Boolean(props.filters.high_risk),
    critical_maintenance: Boolean(props.filters.critical_maintenance),
})

const filterFields = [
    { key: 'high_risk', type: 'checkbox', label: 'High risk only' },
    { key: 'critical_maintenance', type: 'checkbox', label: 'Critical maintenance only' },
]

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
