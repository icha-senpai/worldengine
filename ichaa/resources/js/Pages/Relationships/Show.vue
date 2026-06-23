<template>
    <div>
        <ScaffoldShowPage
            :title="title"
            back-label="Relationships"
            :back-href="route('relationships.index')"
            :edit-href="route('relationships.edit', relationship.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :destroy-href="route('relationships.destroy', relationship.id)"
            :badge="formatLabel(relationship.relationship_type)"
            :sections="sections"
        />

        <EditRelationship
            v-if="editDrawer"
            embedded
            :relationship="relationship"
            v-bind="editDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditRelationship from '@/Pages/Relationships/Edit.vue'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    relationship: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const title = computed(() =>
    `${props.relationship.from_entity?.name ?? 'Unknown'} -> ${props.relationship.to_entity?.name ?? 'Unknown'}`
)

const sections = computed(() => [
    {
        title: 'Participants',
        entries: [
            sectionEntry('From', props.relationship.from_entity?.name, props.relationship.from_entity ? { href: route('entities.show', props.relationship.from_entity.id) } : {}),
            sectionEntry('To', props.relationship.to_entity?.name, props.relationship.to_entity ? { href: route('entities.show', props.relationship.to_entity.id) } : {}),
            sectionEntry('Direction', formatLabel(props.relationship.direction)),
        ],
    },
    {
        title: 'State',
        entries: [
            sectionEntry('Type', formatLabel(props.relationship.relationship_type)),
            sectionEntry('Perceived Type', formatLabel(props.relationship.perceived_type)),
            sectionEntry('True Type', formatLabel(props.relationship.true_type)),
            sectionEntry('Tension Charge', formatLabel(props.relationship.current_tension_charge)),
            sectionEntry('Active', props.relationship.is_active),
        ],
    },
    {
        title: 'Perspectives',
        entries: [
            sectionEntry('Perspective A', props.relationship.perspective_a, { kind: 'json' }),
            sectionEntry('Perspective B', props.relationship.perspective_b, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'State Links',
        entries: [
            sectionEntry(
                'Character States',
                (props.relationship.state_relationships ?? []).map((entry) => ({
                    label: entry.character_state?.snapshot_label ?? `State #${entry.character_state?.id ?? '?'}`,
                    href: entry.character_state?.id ? route('character-states.show', entry.character_state.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
