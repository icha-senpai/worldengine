<template>
    <div>
        <ScaffoldShowPage
            :title="state.snapshot_label || state.entity?.name || `Snapshot #${state.id}`"
            :subtitle="state.entity?.name"
            back-label="Character States"
            :back-href="route('character-states.index')"
            :edit-href="route('character-states.edit', state.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('character-states.show', state.id)"
            :destroy-href="route('character-states.destroy', state.id)"
            :badge="state.current_stability_level || 'snapshot'"
            :sections="sections"
        >
            <template #edit-drawer>
                <EditCharacterState
                    v-if="editDrawer"
                    embedded
                    :state="state"
                    v-bind="editDrawer"
                />
            </template>
        </ScaffoldShowPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditCharacterState from '@/Pages/Temporal/CharacterStates/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    state: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Entity', props.state.entity?.name, props.state.entity ? { href: route('entities.show', props.state.entity.id) } : {}),
            sectionEntry('Era', props.state.era?.name, props.state.era ? { href: route('entities.show', props.state.era.id) } : {}),
            sectionEntry('AU Date', props.state.au_date),
            sectionEntry('Source Date', props.state.source_date),
            sectionEntry('Significance', props.state.snapshot_significance),
            sectionEntry('Stability', props.state.current_stability_level),
            sectionEntry('Mask Integrity', props.state.mask_integrity),
            sectionEntry('Timeline Position', props.state.timeline_position),
        ],
    },
    {
        title: 'Psychology',
        entries: [
            sectionEntry('Trauma Profile', props.state.current_trauma_profile, { kind: 'json' }),
            sectionEntry('Psychological Patterns', props.state.active_psychological_patterns, { kind: 'json' }),
            sectionEntry('Core Wound', props.state.core_wound),
            sectionEntry('Current Desire', props.state.current_desire),
            sectionEntry('Current Fear', props.state.current_fear),
            sectionEntry('Shadow Self', props.state.shadow_self),
            sectionEntry('True Self', props.state.true_self),
            sectionEntry('Performed Self', props.state.performed_self),
        ],
        fullWidth: true,
    },
    {
        title: 'Links',
        entries: [
            sectionEntry(
                'Relationship Links',
                (props.state.state_relationships ?? []).map((entry) => ({
                    label: entry.relationship?.relationship_type ? `${entry.relationship.relationship_type} #${entry.relationship.id}` : `Relationship #${entry.relationship?.id ?? '?'}`,
                    href: entry.relationship?.id ? route('relationships.show', entry.relationship.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
