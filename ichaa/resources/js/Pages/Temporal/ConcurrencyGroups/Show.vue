<template>
    <div>
        <ScaffoldShowPage
            :title="group.name"
            :subtitle="group.au_date || ''"
            back-label="Concurrency Groups"
            :back-href="route('concurrency-groups.index')"
            :edit-href="route('concurrency-groups.edit', group.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :destroy-href="route('concurrency-groups.destroy', group.id)"
            :badge="group.narrative_significance || 'group'"
            :sections="sections"
        />

        <EditConcurrencyGroup
            v-if="editDrawer"
            embedded
            :group="group"
            v-bind="editDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditConcurrencyGroup from '@/Pages/Temporal/ConcurrencyGroups/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    group: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('AU Date', props.group.au_date),
            sectionEntry('Significance', props.group.narrative_significance),
            sectionEntry('Description', props.group.description, { kind: 'json' }),
        ],
    },
    {
        title: 'Timeline Entries',
        entries: [
            sectionEntry(
                'Entries',
                (props.group.timeline_entries ?? []).map((entry) => ({
                    label: [
                        entry.entry_label || entry.event_entity?.name || `Entry #${entry.id}`,
                        entry.timeline?.name ? `on ${entry.timeline.name}` : null,
                        entry.au_date || null,
                    ].filter(Boolean).join(' · '),
                    href: entry.event_entity?.id ? route('entities.show', entry.event_entity.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
        fullWidth: true,
    },
])
</script>
