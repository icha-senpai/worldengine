<template>
    <ScaffoldIndexPage
        title="Writing Pipeline"
        :count="countRecords(itemsPage)"
        count-label="items"
        sync-resource="pipeline_items"
        :create-href="route('pipeline.create')"
        create-label="New Item"
        :create-preserve-scroll="true"
        :create-preserve-state="true"
        :create-drawer-open="Boolean(createDrawer)"
        create-drawer-title="New Pipeline Item"
        :create-close-href="route('pipeline.index')"
        :items="items"
        :pagination="itemsPage"
        empty-title="No pipeline items found"
        :empty-cta-href="route('pipeline.create')"
        empty-cta-label="Create your first item ->"
        :empty-cta-preserve-scroll="true"
        :empty-cta-preserve-state="true"
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
            <CreatePipelineItem
                v-if="createDrawer"
                embedded
                v-bind="createDrawer"
            />
        </template>
    </ScaffoldIndexPage>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreatePipelineItem from '@/Pages/Production/Pipeline/Create.vue'
import { badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    pipelineTypes: { type: Array, default: () => [] },
    pipelineStages: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const itemsPage = computed(() => props.items)

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('pipeline.index', {
    q: props.filters.q ?? '',
    type: props.filters.type ?? '',
    stage: props.filters.stage ?? '',
})

const filterFields = computed(() => [
    { key: 'q', type: 'text', placeholder: 'Search pipeline items...' },
    { key: 'type', type: 'select', placeholder: 'All types', options: props.pipelineTypes },
    { key: 'stage', type: 'select', placeholder: 'All stages', options: props.pipelineStages },
])

const items = computed(() =>
    (props.items.data ?? []).map((item) => ({
        id: item.id,
        href: route('pipeline.show', item.id),
        title: item.title,
        subtitle: pipelineSubtitle(item),
        badges: [
            badge('Type', formatLabel(item.pipeline_type)),
            badge('Stage', formatLabel(item.pipeline_stage)),
        ],
        meta: buildMeta([
            { label: 'POV', value: item.pov_character?.name },
            { label: 'Location', value: item.location?.name },
            { label: 'Beat', value: item.emotional_beat ? formatLabel(item.emotional_beat) : '' },
        ]),
        stats: [
            ...(item.word_count ? [{ label: 'Words', value: item.word_count.toLocaleString() }] : []),
            ...(item.children_count ? [{ label: 'Children', value: item.children_count }] : []),
        ],
    }))
)

const pipelineSubtitle = (item) => {
    if (item.parent?.title) {
        return `Nested under ${item.parent.title}`
    }

    return 'Writing item in the shared pipeline'
}
</script>
