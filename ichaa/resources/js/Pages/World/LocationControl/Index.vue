<template>
    <div>
        <ScaffoldIndexPage
            title="Location Control"
            :count="records.length"
            count-label="control records"
            sync-resource="location_control"
            :create-href="route('location-control.create')"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('location-control.index')"
            create-label="New Control Record"
            :items="items"
            empty-title="No control records found"
            :empty-cta-href="route('location-control.create')"
            empty-cta-label="Create the first control record ->"
        >
            <template #create-drawer>
                <CreateLocationControl
                    v-if="createDrawer"
                    embedded
                    v-bind="createDrawer"
                />
            </template>
        </ScaffoldIndexPage>

        <DrawerRouteShell
            v-if="showEditDrawer"
            :open="showEditDrawer"
            :ready="Boolean(editDrawer)"
            title="Edit Location Control"
            :close-href="route('location-control.index')"
            back-label="Location Control"
            :back-href="route('location-control.index')"
        >
            <EditLocationControl
                v-if="editDrawer"
                embedded
                v-bind="editDrawer"
            />
        </DrawerRouteShell>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import CreateLocationControl from '@/Pages/World/LocationControl/Create.vue'
import EditLocationControl from '@/Pages/World/LocationControl/Edit.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const props = defineProps({
    records: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
    createDrawer: { type: Object, default: null },
})

const showEditDrawer = computed(() =>
    Boolean(props.editDrawer)
    || props.records.some((record) => matchesPendingDrawerHref(route('location-control.edit', record.id)))
)

const items = computed(() =>
    props.records.map((record) => ({
        id: record.id,
        href: route('location-control.edit', record.id),
        preserveScroll: true,
        preserveState: true,
        title: `${record.location?.name ?? 'Unknown'} -> ${record.controlling_entity?.name ?? 'Unknown'}`,
        badges: [badge('Type', record.control_type)],
        meta: buildMeta([
            { label: 'Resistance', value: record.resistance_level },
            { label: 'Start', value: record.control_start_era },
            { label: 'End', value: record.control_end_era },
        ]),
    }))
)
</script>
