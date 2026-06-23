<template>
    <div>
        <ScaffoldIndexPage
            title="Collections"
            :count="countRecords(collections)"
            count-label="collections"
            sync-resource="collections"
            :create-href="route('collections.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            create-label="New Collection"
            :items="items"
            empty-title="No collections found"
            :empty-cta-href="route('collections.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first collection ->"
            
        />

        <CreateCollection
            v-if="createDrawer"
            embedded
            v-bind="createDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateCollection from '@/Pages/Collections/Create.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    collections: { type: [Array, Object], required: true },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const items = computed(() =>
    asArray(props.collections).map((collection) => ({
        id: collection.id,
        href: route('collections.show', collection.id),
        title: collection.name,
        badges: [
            badge('Type', formatLabel(collection.collection_type)),
            badge('Mode', formatLabel(collection.collection_mode)),
        ],
        meta: buildMeta([
            { label: 'State', value: formatLabel(collection.completion_state) },
            { label: 'Visibility', value: formatLabel(collection.visibility) },
        ]),
        stats: collection.entities_count ? [{ label: 'Entities', value: collection.entities_count }] : [],
    }))
)
</script>
