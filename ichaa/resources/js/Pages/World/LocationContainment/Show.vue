<template>
    <ScaffoldShowPage
        :title="`${containment.child_location?.name ?? 'Unknown'} -> ${containment.parent_location?.name ?? 'Unknown'}`"
        back-label="Location Containment"
        :back-href="route('location-containment.index')"
        :edit-href="route('location-containment.edit', containment.id)"
        :edit-preserve-scroll="true"
        :edit-preserve-state="true"
        :edit-drawer-open="Boolean(editDrawer)"
        :edit-close-href="route('location-containment.show', containment.id)"
        :destroy-href="route('location-containment.destroy', containment.id)"
        :badge="containment.containment_type || 'containment'"
        :sections="sections"
    >
        <template #edit-drawer>
            <EditLocationContainment
                v-if="editDrawer"
                embedded
                :containment="containment"
                v-bind="editDrawer"
            />
        </template>
    </ScaffoldShowPage>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditLocationContainment from '@/Pages/World/LocationContainment/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    containment: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Containment',
        entries: [
            sectionEntry('Child Location', props.containment.child_location?.name, props.containment.child_location ? { href: route('entities.show', props.containment.child_location.id) } : {}),
            sectionEntry('Parent Location', props.containment.parent_location?.name, props.containment.parent_location ? { href: route('entities.show', props.containment.parent_location.id) } : {}),
            sectionEntry('Containment Type', props.containment.containment_type),
            sectionEntry('Era Start', props.containment.era_start),
            sectionEntry('Era End', props.containment.era_end),
            sectionEntry('Active', props.containment.is_active),
        ],
    },
    {
        title: 'Notes',
        entries: [
            sectionEntry('Notes', props.containment.notes, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
