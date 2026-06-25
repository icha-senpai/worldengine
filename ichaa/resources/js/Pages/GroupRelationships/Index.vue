<template>
    <div>
        <ScaffoldIndexPage
            title="Group Relationships"
            :count="countRecords(groups)"
            count-label="groups"
            sync-resource="group_relationships"
            :create-href="route('group-relationships.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('group-relationships.index')"
            create-label="New Group"
            :items="items"
            empty-title="No group relationships found"
            :empty-cta-href="route('group-relationships.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first group ->"
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
            <CreateGroupRelationship
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
import CreateGroupRelationship from '@/Pages/GroupRelationships/Create.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    groups: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('group-relationships.index', {
    volatile: Boolean(props.filters.volatile),
    masked: Boolean(props.filters.masked),
})

const filterFields = [
    { key: 'volatile', type: 'checkbox', label: 'Volatile only' },
    { key: 'masked', type: 'checkbox', label: 'Masked only' },
]

const items = computed(() =>
    asArray(props.groups).map((group) => ({
        id: group.id,
        href: route('group-relationships.show', group.id),
        title: group.name,
        badges: [badge('Type', group.relationship_type)],
        meta: buildMeta([
            { label: 'Tension', value: group.current_tension_charge },
            { label: 'Active', value: group.is_active },
        ]),
        stats: group.active_members_count ? [{ label: 'Members', value: group.active_members_count }] : [],
    }))
)
</script>
