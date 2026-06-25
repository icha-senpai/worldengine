<template>
    <div>
        <ScaffoldIndexPage
            title="Secrets"
            :count="countRecords(secrets)"
            count-label="secrets"
            sync-resource="secrets"
            :create-href="route('secrets.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('secrets.index')"
            create-label="New Secret"
            :items="items"
            empty-title="No secrets found"
            :empty-cta-href="route('secrets.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first secret ->"
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
            <CreateSecret
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
import CreateSecret from '@/Pages/Intelligence/Secrets/Create.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    secrets: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('secrets.index', {
    high_risk: Boolean(props.filters.high_risk),
    leaking: Boolean(props.filters.leaking),
})

const filterFields = [
    { key: 'high_risk', type: 'checkbox', label: 'High risk only' },
    { key: 'leaking', type: 'checkbox', label: 'Leaking only' },
]

const items = computed(() =>
    asArray(props.secrets).map((secret) => ({
        id: secret.id,
        href: route('secrets.show', secret.id),
        title: secret.title,
        badges: [badge('Type', secret.secret_type)],
        meta: buildMeta([
            { label: 'Risk', value: secret.exposure_risk },
            { label: 'Status', value: secret.status },
        ]),
    }))
)
</script>
