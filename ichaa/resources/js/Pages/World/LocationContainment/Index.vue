<template>
    <div>
        <ScaffoldIndexPage
            title="Location Containment"
            :count="containments.length"
            count-label="containments"
            sync-resource="location_containment"
            :create-href="route('location-containment.create')"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('location-containment.index')"
            create-label="New Containment"
            :items="items"
            empty-title="No containment records found"
            :empty-cta-href="route('location-containment.create')"
            empty-cta-label="Create the first containment ->"
        >
            <template #create-drawer>
                <CreateLocationContainment
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
            title="Edit Location Containment"
            :close-href="route('location-containment.index')"
            back-label="Location Containment"
            :back-href="route('location-containment.index')"
        >
            <EditLocationContainment
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
import CreateLocationContainment from '@/Pages/World/LocationContainment/Create.vue'
import EditLocationContainment from '@/Pages/World/LocationContainment/Edit.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const props = defineProps({
    containments: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
    createDrawer: { type: Object, default: null },
})

const showEditDrawer = computed(() =>
    Boolean(props.editDrawer)
    || props.containments.some((containment) => matchesPendingDrawerHref(route('location-containment.edit', containment.id)))
)

const items = computed(() =>
    props.containments.map((containment) => ({
        id: containment.id,
        href: route('location-containment.edit', containment.id),
        preserveScroll: true,
        preserveState: true,
        title: `${containment.child_location?.name ?? 'Unknown'} -> ${containment.parent_location?.name ?? 'Unknown'}`,
        badges: [badge('Type', containment.containment_type)],
        meta: buildMeta([
            { label: 'Era Start', value: containment.era_start },
            { label: 'Era End', value: containment.era_end },
            { label: 'Active', value: containment.is_active },
        ]),
    }))
)
</script>
