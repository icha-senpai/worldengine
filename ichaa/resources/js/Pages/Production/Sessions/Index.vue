<template>
    <div>
        <div class="dashboard-metric-strip mb-6">
            <div class="dashboard-metric">
                <span class="dashboard-metric__value">{{ stats.session_count ?? 0 }}</span>
                <span class="dashboard-metric__label">Sessions / 30d</span>
            </div>
            <div class="dashboard-metric">
                <span class="dashboard-metric__value">{{ stats.major_count ?? 0 }}</span>
                <span class="dashboard-metric__label">Major sessions</span>
            </div>
        </div>

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
            <template #toolbar>
                <ScaffoldFilterBar
                    :fields="filterFields"
                    :form="filterForm"
                    :has-active-filters="hasActiveFilters"
                    :on-apply="applyFilters"
                    :on-clear="clearFilters"
                />
            </template>
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
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateSession from '@/Pages/Production/Sessions/Create.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    sessions: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    externalTools: { type: Array, default: () => [] },
    significanceLevels: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: () => null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('session-logs.index', {
    q: props.filters.q ?? '',
    external_tool: props.filters.external_tool ?? '',
    significance: props.filters.significance ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search sessions...' },
    { key: 'external_tool', type: 'select', placeholder: 'All tools', options: props.externalTools },
    { key: 'significance', type: 'select', placeholder: 'All significance', options: props.significanceLevels },
])

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
