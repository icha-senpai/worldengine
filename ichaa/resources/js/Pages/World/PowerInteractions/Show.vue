<template>
    <div>
        <ScaffoldShowPage
            :title="interaction.interaction_name"
            back-label="Power Interactions"
            :back-href="route('power-interactions.index')"
            :edit-href="route('power-interactions.edit', interaction.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :destroy-href="route('power-interactions.destroy', interaction.id)"
            :badge="formatLabel(interaction.knowledge_state)"
            :sections="sections"
        />

        <EditPowerInteraction
            v-if="editDrawer"
            embedded
            :interaction="interaction"
            v-bind="editDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditPowerInteraction from '@/Pages/World/PowerInteractions/Edit.vue'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    interaction: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Systems',
        entries: [
            sectionEntry('System A', props.interaction.system_a?.name, props.interaction.system_a ? { href: route('entities.show', props.interaction.system_a.id) } : {}),
            sectionEntry('System B', props.interaction.system_b?.name, props.interaction.system_b ? { href: route('entities.show', props.interaction.system_b.id) } : {}),
            sectionEntry('Directionality', formatLabel(props.interaction.directionality)),
            sectionEntry('Scale', formatLabel(props.interaction.interaction_scale)),
        ],
    },
    {
        title: 'Risk and Resolution',
        entries: [
            sectionEntry('Knowledge State', formatLabel(props.interaction.knowledge_state)),
            sectionEntry('Danger Rating', formatLabel(props.interaction.danger_rating)),
            sectionEntry('Proximity Required', props.interaction.proximity_required),
            sectionEntry('Unresolved', props.interaction.unresolved_flag),
            sectionEntry('Resolution Notes', props.interaction.resolution_notes, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Effect Model',
        entries: [
            sectionEntry('Description', props.interaction.description, { kind: 'json' }),
            sectionEntry('Effects', props.interaction.effects, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Observed Instances',
        entries: [
            sectionEntry(
                'Instances',
                (props.interaction.instances ?? []).map((instance) => ({
                    label: `${instance.event_entity?.name ?? 'Unknown event'} (${formatLabel(instance.outcome_match)})`,
                    href: instance.event_entity?.id ? route('entities.show', instance.event_entity.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
