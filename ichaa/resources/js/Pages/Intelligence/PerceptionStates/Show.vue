<template>
    <div>
        <ScaffoldShowPage
            :title="`${state.subject_type || 'subject'} perception gap`"
            :subtitle="subjectDisplay?.label || `Subject ID ${state.subject_id ?? 'unknown'}`"
            back-label="Perception States"
            :back-href="route('perception-states.index')"
            :edit-href="route('perception-states.edit', state.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :destroy-href="route('perception-states.destroy', state.id)"
            :badge="state.divergence_level || 'gap'"
            :sections="sections"
        />

        <EditPerceptionState
            v-if="editDrawer"
            embedded
            :state="state"
            v-bind="editDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditPerceptionState from '@/Pages/Intelligence/PerceptionStates/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    state: { type: Object, required: true },
    subjectDisplay: { type: Object, default: () => ({}) },
    maintainedByEntities: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Subject Type', props.state.subject_type),
            sectionEntry('Subject', props.subjectDisplay?.label || props.state.subject_id, {
                href: props.subjectDisplay?.href || '',
            }),
            sectionEntry('Divergence Level', props.state.divergence_level),
            sectionEntry('Maintenance Method', props.state.maintenance_method),
            sectionEntry('Maintenance Effort', props.state.maintenance_effort),
            sectionEntry('Revelation Risk', props.state.revelation_risk),
        ],
    },
    {
        title: 'States',
        entries: [
            sectionEntry('True State', props.state.true_state, { kind: 'json' }),
            sectionEntry('Perceived State', props.state.perceived_state, { kind: 'json' }),
            sectionEntry('Maintained By Entities', props.maintainedByEntities, { kind: 'list' }),
        ],
        fullWidth: true,
    },
])
</script>
