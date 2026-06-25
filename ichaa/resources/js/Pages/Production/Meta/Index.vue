<template>
    <div>
        <ScaffoldIndexPage
            title="Meta Notes"
            :count="countRecords(notes)"
            count-label="notes"
            sync-resource="meta"
            :create-href="route('meta.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('meta.index')"
            create-label="New Meta Note"
            :items="items"
            empty-title="No meta notes found"
            :empty-cta-href="route('meta.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first meta note ->"
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
            <CreateMeta
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
import CreateMeta from '@/Pages/Production/Meta/Create.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    notes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    noteTypes: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('meta.index', {
    category: props.filters.category ?? '',
    type: props.filters.type ?? '',
    unresolved: Boolean(props.filters.unresolved),
    blocking: Boolean(props.filters.blocking),
})

const filterFields = computed(() => [
    { key: 'category', type: 'select', placeholder: 'All categories', options: props.categories },
    { key: 'type', type: 'select', placeholder: 'All note types', options: props.noteTypes },
    { key: 'unresolved', type: 'checkbox', label: 'Unresolved only' },
    { key: 'blocking', type: 'checkbox', label: 'Blocking only' },
])

const items = computed(() =>
    asArray(props.notes).map((note) => ({
        id: note.id,
        href: route('meta.show', note.id),
        title: note.title,
        badges: [
            badge('Category', formatLabel(note.category)),
            badge('Type', formatLabel(note.meta_note_type)),
        ],
        meta: buildMeta([
            { label: 'Priority', value: formatLabel(note.priority) },
            { label: 'Action', value: formatLabel(note.action_status) },
            { label: 'Resolved', value: note.resolved_at },
        ]),
    }))
)
</script>
