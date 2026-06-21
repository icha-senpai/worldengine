<template>
    <ScaffoldIndexPage
        title="Session Logs"
        :count="countRecords(sessions)"
        count-label="sessions"
        :create-href="route('session-logs.create')"
        create-label="New Session"
        :items="items"
        empty-title="No sessions logged yet"
        :empty-cta-href="route('session-logs.create')"
        empty-cta-label="Log the first session ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    sessions: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
})

const items = computed(() =>
    asArray(props.sessions).map((session) => ({
        id: session.id,
        href: route('session-logs.show', session.id),
        title: session.title,
        badges: [badge('Tool', session.external_tool)],
        meta: buildMeta([
            { label: 'Date', value: session.session_date },
            { label: 'Significance', value: session.session_significance },
            { label: 'Focus', value: session.focus_description },
        ]),
    }))
)
</script>
