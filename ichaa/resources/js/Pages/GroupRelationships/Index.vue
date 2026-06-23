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
            create-label="New Group"
            :items="items"
            empty-title="No group relationships found"
            :empty-cta-href="route('group-relationships.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first group ->"
            
        />

        <CreateGroupRelationship
            v-if="createDrawer"
            embedded
            v-bind="createDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateGroupRelationship from '@/Pages/GroupRelationships/Create.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    groups: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    createDrawer: { type: Object, default: null },
})

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
