<template>
    <div>
        <ScaffoldIndexPage
            title="Media Library"
            :count="countRecords(media)"
            count-label="media references"
            :create-href="route('media-references.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('media-references.index')"
            create-label="New Media"
            :items="items"
            empty-title="No media references found"
            :empty-cta-href="route('media-references.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first media reference ->"
        >
        <template #create-drawer>
            <CreateMediaReference
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
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateMediaReference from '@/Pages/Identity/MediaReferences/Create.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    media: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    mediaTypes: { type: Array, default: () => [] },
    purposes: { type: Array, default: () => [] },
    attachmentTypes: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const items = computed(() =>
    asArray(props.media).map((item) => ({
        id: item.id,
        href: route('media-references.show', item.id),
        title: item.title,
        subtitle: item.description,
        badges: [
            badge('Type', formatLabel(item.media_type)),
            badge('Purpose', formatLabel(item.purpose)),
        ],
        meta: buildMeta([
            { label: 'Source', value: item.url ? 'External' : 'Local' },
            { label: 'Primary', value: item.is_primary ? 'Yes' : 'No' },
            { label: 'Visibility', value: formatLabel(item.visibility) },
            { label: 'Classification', value: formatLabel(item.content_classification) },
        ]),
    })),
)
</script>
