<template>
    <div>
        <ScaffoldShowPage
            :title="collection.name"
            back-label="Collections"
            :back-href="route('collections.index')"
            :edit-href="route('collections.edit', collection.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :destroy-href="route('collections.destroy', collection.id)"
            :badge="formatLabel(collection.collection_type)"
            :subtitle="collection.rules ? 'Rules and members are attached below.' : ''"
            :sections="sections"
        />

        <EditCollection
            v-if="editDrawer"
            embedded
            :collection="collection"
            v-bind="editDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditCollection from '@/Pages/Collections/Edit.vue'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    collection: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Type', formatLabel(props.collection.collection_type)),
            sectionEntry('Mode', formatLabel(props.collection.collection_mode)),
            sectionEntry('State', formatLabel(props.collection.completion_state)),
            sectionEntry('Visibility', formatLabel(props.collection.visibility)),
            sectionEntry('Classification', formatLabel(props.collection.content_classification)),
        ],
    },
    {
        title: 'Rules',
        entries: [
            sectionEntry('Rules', props.collection.rules, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Entities',
        entries: [
            sectionEntry(
                'Members',
                (props.collection.entities ?? []).map((entity) => ({
                    label: `${entity.name} (${formatLabel(entity.entity_type)})`,
                    href: route('entities.show', entity.id),
                })),
                { kind: 'list' },
            ),
        ],
    },
    {
        title: 'Children',
        entries: [
            sectionEntry(
                'Child Collections',
                (props.collection.child_collections ?? []).map((child) => ({
                    label: `${child.name} (${formatLabel(child.collection_type)})`,
                    href: route('collections.show', child.id),
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
