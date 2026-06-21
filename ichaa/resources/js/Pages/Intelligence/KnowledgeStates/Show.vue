<template>
    <ScaffoldShowPage
        :title="state.knower?.name ? `${state.knower.name} Knowledge` : `Knowledge State #${state.id}`"
        back-label="Knowledge States"
        :back-href="route('knowledge-states.index')"
        :edit-href="route('knowledge-states.edit', state.id)"
        :badge="formatLabel(state.knowledge_type)"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    state: { type: Object, required: true },
})

const sections = computed(() => [
    {
        title: 'Participants',
        entries: [
            sectionEntry('Knower', props.state.knower?.name, props.state.knower ? { href: route('entities.show', props.state.knower.id) } : {}),
            sectionEntry('Subject Entity', props.state.subject_entity?.name, props.state.subject_entity ? { href: route('entities.show', props.state.subject_entity.id) } : {}),
            sectionEntry('Subject Secret', props.state.subject_secret?.title),
            sectionEntry('Acquired From', props.state.acquired_from?.name, props.state.acquired_from ? { href: route('entities.show', props.state.acquired_from.id) } : {}),
        ],
    },
    {
        title: 'Assessment',
        entries: [
            sectionEntry('Knowledge Type', formatLabel(props.state.knowledge_type)),
            sectionEntry('Accuracy', formatLabel(props.state.accuracy)),
            sectionEntry('Belief State', formatLabel(props.state.current_belief_state)),
            sectionEntry('Acquired Through', formatLabel(props.state.acquired_through)),
            sectionEntry('Acquired At Era', props.state.acquired_at_era),
        ],
    },
    {
        title: 'Content',
        entries: [
            sectionEntry('Knowledge Content', props.state.knowledge_content, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
