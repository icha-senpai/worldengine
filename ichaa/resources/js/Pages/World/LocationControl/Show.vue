<template>
    <ScaffoldShowPage
        :title="`${record.location?.name ?? 'Unknown'} -> ${record.controlling_entity?.name ?? 'Unknown'}`"
        back-label="Location Control"
        :back-href="route('location-control.index')"
        :edit-href="route('location-control.edit', record.id)"
        :edit-preserve-scroll="true"
        :edit-preserve-state="true"
        :edit-drawer-open="Boolean(editDrawer)"
        :edit-close-href="route('location-control.show', record.id)"
        :destroy-href="route('location-control.destroy', record.id)"
        :badge="record.control_type || 'control'"
        :sections="sections"
    >
        <template #edit-drawer>
            <EditLocationControl
                v-if="editDrawer"
                embedded
                :record="record"
                v-bind="editDrawer"
            />
        </template>
    </ScaffoldShowPage>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditLocationControl from '@/Pages/World/LocationControl/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    record: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Control',
        entries: [
            sectionEntry('Location', props.record.location?.name, props.record.location ? { href: route('entities.show', props.record.location.id) } : {}),
            sectionEntry('Controlling Entity', props.record.controlling_entity?.name, props.record.controlling_entity ? { href: route('entities.show', props.record.controlling_entity.id) } : {}),
            sectionEntry('Control Type', props.record.control_type),
            sectionEntry('Start Era', props.record.control_start_era),
            sectionEntry('End Era', props.record.control_end_era),
            sectionEntry('Current', props.record.is_current),
            sectionEntry('Resistance Level', props.record.resistance_level),
            sectionEntry(
                'Resistance Entities',
                (props.record.resistance_entities ?? []).map((entity) => ({
                    label: entity.name,
                    href: route('entities.show', entity.id),
                })),
                { kind: 'list' },
            ),
            sectionEntry('Visibility', props.record.visibility),
            sectionEntry('Content Classification', props.record.content_classification),
        ],
    },
    {
        title: 'Narrative',
        entries: [
            sectionEntry('How Control Was Established', props.record.how_control_was_established, { kind: 'json' }),
            sectionEntry('How Control Ended', props.record.how_control_ended, { kind: 'json' }),
            sectionEntry('Notes', props.record.notes, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
