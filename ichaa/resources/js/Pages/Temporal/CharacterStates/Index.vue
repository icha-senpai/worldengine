<template>
    <div>
        <ScaffoldIndexPage
            title="Character States"
            :count="countRecords(states)"
            count-label="snapshots"
            sync-resource="character_states"
            :create-href="route('character-states.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('character-states.index')"
            create-label="New Snapshot"
            :items="items"
            empty-title="No character states found"
            :empty-cta-href="route('character-states.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first snapshot ->"
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
            <CreateCharacterState
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
import CreateCharacterState from '@/Pages/Temporal/CharacterStates/Create.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'
import { toEntityOptions } from '@/Components/scaffold/formatters'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    states: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    entities: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('character-states.index', {
    entity: props.filters.entity ? String(props.filters.entity) : '',
    breaking: Boolean(props.filters.breaking),
})

const filterFields = computed(() => [
    { key: 'entity', type: 'select', placeholder: 'All entities', options: entityOptions.value },
    { key: 'breaking', type: 'checkbox', label: 'Breaking only' },
])

const items = computed(() =>
    asArray(props.states).map((state) => ({
        id: state.id,
        href: route('character-states.show', state.id),
        title: state.snapshot_label || state.entity?.name || `Snapshot #${state.id}`,
        badges: [badge('Stability', state.current_stability_level)],
        meta: buildMeta([
            { label: 'Entity', value: state.entity?.name },
            { label: 'Date', value: state.au_date || state.source_date },
            { label: 'Significance', value: state.snapshot_significance },
            { label: 'Mask', value: state.mask_integrity },
        ]),
    }))
)
</script>
