<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy min-w-0">
                    <div class="page-hero__eyebrow">
                        <Link :href="route('media-references.index')" class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors">
                            Media Library
                        </Link>
                        <span>/</span>
                        <span class="chip">{{ formatLabel(media.media_type) }}</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--md">{{ media.title }}</h1>
                    <p v-if="media.description" class="page-hero__subtitle prose-wrap">{{ media.description }}</p>
                    <div v-if="mediaHeroMeta.length" class="page-hero__meta">
                        <div v-for="meta in mediaHeroMeta" :key="meta.label" class="page-hero__meta-item">
                            <span class="page-hero__meta-label">{{ meta.label }}</span>
                            <span class="page-hero__meta-value">{{ meta.value }}</span>
                        </div>
                    </div>
                </div>

                <div class="page-hero__actions">
                    <AppButton type="button" variant="danger" @click="destroyRecord">Move to Trash</AppButton>
                    <AppButton
                        :href="route('media-references.edit', media.id)"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        opens-drawer
                        variant="ghost"
                    >
                        Edit
                    </AppButton>
                </div>
            </div>
        </template>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
            <section class="panel">
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

            <div class="detail-side-stack">
                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-label mb-1!">Identity</h3>
                            <p class="panel-copy">What the asset is for, where it is attached, and whether it represents the primary visual.</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="entry-row">
                            <span class="field-label">Purpose</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ formatLabel(media.purpose) }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">Attachment</span>
                            <div class="entry-value">
                                <Link v-if="media.attachment?.href" :href="media.attachment.href" class="text-cyan hover:underline">
                                    {{ media.attachment.label }}
                                </Link>
                                <span v-else class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ media.attachment?.label || '—' }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">Attachment Type</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ formatLabel(media.attachment?.type) }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">Primary</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ media.is_primary ? 'Yes' : 'No' }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-label mb-1!">Source</h3>
                            <p class="panel-copy">Where this asset came from and the file-level details needed to relocate or verify it.</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="entry-row">
                            <span class="field-label">Source Kind</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ formatLabel(media.source_kind) }}</span>
                            </div>
                        </div>
                        <div v-if="media.url" class="entry-row">
                            <span class="field-label">URL</span>
                            <div class="entry-value">
                                <a :href="media.url" target="_blank" rel="noopener noreferrer" class="text-cyan hover:underline break-all">
                                    {{ media.url }}
                                </a>
                            </div>
                        </div>
                        <div v-if="media.file_path" class="entry-row">
                            <span class="field-label">Local File Path</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed break-all">{{ media.file_path }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">File Name</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ media.file_name || '—' }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">MIME Type</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ media.mime_type || '—' }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-label mb-1!">Display</h3>
                            <p class="panel-copy">Presentation settings, sizing, and visibility controls used around the app.</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="entry-row">
                            <span class="field-label">Dimensions</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ dimensionLabel }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">File Size</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ fileSizeLabel }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">Sort Order</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ media.sort_order }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">Visibility</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ formatLabel(media.visibility) }}</span>
                            </div>
                        </div>
                        <div class="entry-row">
                            <span class="field-label">Classification</span>
                            <div class="entry-value">
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ formatLabel(media.content_classification) }}</span>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <DrawerRouteShell
            v-if="showEditDrawer"
            :open="showEditDrawer"
            :ready="Boolean(editMediaProps)"
            title="Edit Media Reference"
            :close-href="route('media-references.show', media.id)"
            back-label="Media Library"
            :back-href="route('media-references.index')"
        >
            <EditMediaReference v-if="editMediaProps" embedded v-bind="editMediaProps" />
        </DrawerRouteShell>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import EditMediaReference from '@/Pages/Identity/MediaReferences/Edit.vue'
import { formatLabel } from '@/Components/scaffold/formatters'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

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

const showEditDrawer = computed(() =>
    Boolean(editMediaProps.value) || matchesPendingDrawerHref(route('media-references.edit', props.media.id))
)
const mediaHeroMeta = computed(() => [
    { label: 'Purpose', value: formatLabel(props.media.purpose || 'reference') },
    { label: 'Visibility', value: formatLabel(props.media.visibility || 'private') },
    { label: 'Primary', value: props.media.is_primary ? 'Yes' : 'No' },
    { label: 'Attachment', value: props.media.attachment?.type ? formatLabel(props.media.attachment.type) : '—' },
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

const destroyRecord = async () => {
    const confirmed = await confirmDialog({
        title: 'Move to Trash',
        message: 'Move this item to trash?',
        confirmLabel: 'Move to Trash',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(route('media-references.destroy', props.media.id), {
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not move media to trash',
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}
</script>
