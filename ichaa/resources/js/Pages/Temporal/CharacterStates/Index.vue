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
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateCharacterState from '@/Pages/Temporal/CharacterStates/Create.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    states: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    createDrawer: { type: Object, default: null },
})

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
