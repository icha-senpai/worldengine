<template>
    <div>
        <ScaffoldShowPage
            :title="session.title"
            :subtitle="session.session_date"
            back-label="Session Logs"
            :back-href="route('session-logs.index')"
            :edit-href="route('session-logs.edit', session.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('session-logs.show', session.id)"
            :destroy-href="route('session-logs.destroy', session.id)"
            :badge="session.external_tool || 'session'"
            :sections="sections"
        >
            <template #edit-drawer>
                <EditSessionLog
                    v-if="editDrawer"
                    embedded
                    :session="session"
                    v-bind="editDrawer"
                />
            </template>
        </ScaffoldShowPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditSessionLog from '@/Pages/Production/Sessions/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    session: { type: Object, required: true },
    focusEntities: { type: Array, default: () => [] },
    focusGroupRelationships: { type: Array, default: () => [] },
    focusCollections: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Session Date', props.session.session_date),
            sectionEntry('External Tool', props.session.external_tool),
            sectionEntry('Focus Description', props.session.focus_description),
            sectionEntry('Session Significance', props.session.session_significance),
        ],
    },
    {
        title: 'Focus Links',
        entries: [
            sectionEntry('Focus Entities', props.focusEntities, { kind: 'list' }),
            sectionEntry('Focus Group Relationships', props.focusGroupRelationships, { kind: 'list' }),
            sectionEntry('Focus Collections', props.focusCollections, { kind: 'list' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Working Notes',
        entries: [
            sectionEntry('Decisions Made', props.session.decisions_made, { kind: 'json' }),
            sectionEntry('Changes Applied', props.session.changes_applied, { kind: 'json' }),
            sectionEntry('Open Threads', props.session.open_threads, { kind: 'json' }),
            sectionEntry('Notes', props.session.notes, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Question Links',
        entries: [
            sectionEntry(
                'Entity Questions',
                (props.session.entity_questions ?? []).map((question) => ({
                    label: `${question.entity?.name ?? 'Unknown'} · ${question.question ?? `Question #${question.id}`}`,
                    href: question.entity?.id ? route('entities.show', question.entity.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
