<template>
    <div>
        <ScaffoldIndexPage
            title="Concurrency Groups"
            :count="groups.length"
            count-label="groups"
            sync-resource="concurrency_groups"
            :create-href="route('concurrency-groups.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            create-label="New Group"
            :items="items"
            empty-title="No concurrency groups found"
            :empty-cta-href="route('concurrency-groups.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first group ->"
            
        />

        <CreateConcurrencyGroup
            v-if="createDrawer"
            embedded
            v-bind="createDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateConcurrencyGroup from '@/Pages/Temporal/ConcurrencyGroups/Create.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    groups: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const items = computed(() =>
    props.groups.map((group) => ({
        id: group.id,
        href: route('concurrency-groups.show', group.id),
        title: group.name,
        badges: [badge('Significance', group.narrative_significance)],
        meta: buildMeta([
            { label: 'AU Date', value: group.au_date },
        ]),
        stats: group.timeline_entries_count ? [{ label: 'Entries', value: group.timeline_entries_count }] : [],
    }))
)
</script>
