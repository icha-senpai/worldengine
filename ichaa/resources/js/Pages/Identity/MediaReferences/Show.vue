<template>
    <ScaffoldShowPage
        :title="media.title"
        :subtitle="media.description || ''"
        back-label="Media Library"
        :back-href="route('media-references.index')"
        :edit-href="route('media-references.edit', media.id)"
        :edit-preserve-scroll="true"
        :edit-preserve-state="true"
        :edit-drawer-open="Boolean(editMediaProps)"
        :edit-close-href="route('media-references.show', media.id)"
        :destroy-href="route('media-references.destroy', media.id)"
        destroy-confirm="Move this item to trash?"
        :badge="formatLabel(media.media_type)"
        :hero-meta="mediaHeroMeta"
        :sections="sections"
    >
        <section class="panel mt-4">
            <div class="panel-heading">
                <div>
                    <h3 class="panel-label mb-1!">Preview</h3>
                    <p class="panel-copy">Best available view of the asset, with a direct path to the original file or source URL.</p>
                </div>
            </div>

            <div v-if="media.preview_url && media.media_type === 'image'" class="space-y-4">
                <div class="detail-preview-frame">
                    <img :src="media.preview_url" :alt="media.title" class="max-h-144 w-full rounded-md border border-border bg-surface object-contain">
                </div>
                <a :href="media.preview_url" target="_blank" rel="noopener noreferrer" class="text-cyan text-sm font-ui hover:underline">
                    Open full asset
                </a>
            </div>

            <div v-else class="space-y-3">
                <p class="text-muted-3 text-sm font-ui uppercase tracking-widest">No inline preview</p>
                <a
                    v-if="media.preview_url"
                    :href="media.preview_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-cyan text-sm font-ui hover:underline"
                >
                    Open source
                </a>
            </div>
        </section>

        <template #edit-drawer>
            <EditMediaReference v-if="editMediaProps" embedded v-bind="editMediaProps" />
        </template>
    </ScaffoldShowPage>
</template>

<script setup>
import { computed } from 'vue'
import EditMediaReference from '@/Pages/Identity/MediaReferences/Edit.vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { formatLabel } from '@/Pages/scaffold/pageBuilders'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    media: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const editMediaProps = computed(() => {
    if (!props.editDrawer) {
        return null
    }

    return {
        ...props.editDrawer,
        media: props.editDrawer.media ?? props.media,
    }
})

const mediaHeroMeta = computed(() => [
    { label: 'Purpose', value: formatLabel(props.media.purpose || 'reference') },
    { label: 'Visibility', value: formatLabel(props.media.visibility || 'private') },
    { label: 'Primary', value: props.media.is_primary ? 'Yes' : 'No' },
    { label: 'Attachment', value: props.media.attachment?.type ? formatLabel(props.media.attachment.type) : '—' },
])

const sections = computed(() => [
    {
        title: 'Identity',
        description: 'What the asset is for, where it is attached, and whether it represents the primary visual.',
        entries: [
            sectionEntry('Purpose', formatLabel(props.media.purpose)),
            sectionEntry('Attachment', props.media.attachment?.label || '—', {
                href: props.media.attachment?.href || '',
            }),
            sectionEntry('Attachment Type', formatLabel(props.media.attachment?.type)),
            sectionEntry('Primary', props.media.is_primary),
        ],
    },
    {
        title: 'Source',
        description: 'Where this asset came from and the file-level details needed to relocate or verify it.',
        entries: [
            sectionEntry('Source Kind', formatLabel(props.media.source_kind)),
            sectionEntry('URL', props.media.url || '—'),
            sectionEntry('Local File Path', props.media.file_path || '—'),
            sectionEntry('File Name', props.media.file_name || '—'),
            sectionEntry('MIME Type', props.media.mime_type || '—'),
        ],
    },
    {
        title: 'Display',
        description: 'Presentation settings, sizing, and visibility controls used around the app.',
        entries: [
            sectionEntry('Dimensions', dimensionLabel.value),
            sectionEntry('File Size', fileSizeLabel.value),
            sectionEntry('Sort Order', props.media.sort_order ?? '—'),
            sectionEntry('Visibility', formatLabel(props.media.visibility)),
            sectionEntry('Classification', formatLabel(props.media.content_classification)),
        ],
    },
])

const dimensionLabel = computed(() => {
    if (!props.media.width_px || !props.media.height_px) {
        return '—'
    }

    return `${props.media.width_px} × ${props.media.height_px}`
})

const fileSizeLabel = computed(() => {
    if (!props.media.file_size_bytes) {
        return '—'
    }

    return `${props.media.file_size_bytes.toLocaleString()} bytes`
})
</script>
