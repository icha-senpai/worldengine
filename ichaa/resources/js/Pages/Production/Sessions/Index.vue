<template>
    <div>
        <ScaffoldIndexPage
            title="Session Logs"
            :count="countRecords(sessions)"
            count-label="sessions"
            sync-resource="session_logs"
            :create-href="route('session-logs.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('session-logs.index')"
            create-label="New Session"
            :items="items"
            empty-title="No sessions logged yet"
            :empty-cta-href="route('session-logs.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Log the first session ->"
        >
        <template #create-drawer>
            <CreateSession
                v-if="createDrawer"
                embedded
                v-bind="createDrawer"
            />
        </template>
        </ScaffoldIndexPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateSession from '@/Pages/Production/Sessions/Create.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    sessions: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    createDrawer: { type: Object, default: null },
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
