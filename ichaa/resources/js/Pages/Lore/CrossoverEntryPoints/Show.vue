<template>
    <div>
        <ScaffoldShowPage
            :title="entryPoint.source_universe"
            back-label="Crossover Entry Points"
            :back-href="route('crossover-entry-points.index')"
            :edit-href="route('crossover-entry-points.edit', entryPoint.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :destroy-href="route('crossover-entry-points.destroy', entryPoint.id)"
            :badge="entryPoint.status || 'entry'"
            :sections="sections"
        />

        <EditCrossoverEntryPoint
            v-if="editDrawer"
            embedded
            :entry-point="entryPoint"
            v-bind="editDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditCrossoverEntryPoint from '@/Pages/Lore/CrossoverEntryPoints/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    entryPoint: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Source Universe', props.entryPoint.source_universe),
            sectionEntry('Status', props.entryPoint.status),
            sectionEntry('First Documented Crossing Event', props.entryPoint.first_documented_crossing_event?.name, props.entryPoint.first_documented_crossing_event ? { href: route('entities.show', props.entryPoint.first_documented_crossing_event.id) } : {}),
        ],
    },
    {
        title: 'Transition Rules',
        entries: [
            sectionEntry('Entry Mechanism', props.entryPoint.entry_mechanism, { kind: 'json' }),
            sectionEntry('Power Transition Rules', props.entryPoint.power_transition_rules, { kind: 'json' }),
            sectionEntry('Physical Transition Rules', props.entryPoint.physical_transition_rules, { kind: 'json' }),
            sectionEntry('Memory and Identity Rules', props.entryPoint.memory_and_identity_rules, { kind: 'json' }),
            sectionEntry('Psychological Transition Rules', props.entryPoint.psychological_transition_rules, { kind: 'json' }),
            sectionEntry('Return Rules', props.entryPoint.return_rules, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
