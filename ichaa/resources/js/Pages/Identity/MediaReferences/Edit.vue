<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Media Reference"
        :back-href="route('media-references.show', media.id)"
        back-label="Media Reference"
        :cancel-href="route('media-references.show', media.id)"
        submit-label="Save Media"
        processing-label="Saving..."
        :destroy-href="route('media-references.destroy', media.id)"
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import {
    toCanonReferenceOptions,
    toCollectionOptions,
    toConcurrencyGroupOptions,
    toEntityOptions,
    toGroupRelationshipOptions,
    toMetaOptions,
    toTimelineEntryOptions,
} from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    media: { type: Object, required: true },
    attachmentTypes: { type: Array, default: () => [] },
    attachmentTargets: { type: Object, default: () => ({}) },
    mediaTypes: { type: Array, default: () => [] },
    purposes: { type: Array, default: () => [] },
    sourceKinds: { type: Array, default: () => [] },
    visibilityLevels: { type: Array, default: () => [] },
    contentClassifications: { type: Array, default: () => [] },
})

const form = useForm({
    title: props.media.title ?? '',
    description: props.media.description ?? '',
    media_type: props.media.media_type ?? 'image',
    purpose: props.media.purpose ?? 'reference',
    source_kind: props.media.source_kind ?? 'external',
    attachment_type: props.media.attachment_type ?? 'entity',
    attachment_id: props.media.attachment_id ?? '',
    url: props.media.url ?? '',
    file_path: props.media.file_path ?? '',
    upload_file: null,
    file_name: props.media.file_name ?? '',
    file_extension: props.media.file_extension ?? '',
    file_size_bytes: props.media.file_size_bytes ?? '',
    mime_type: props.media.mime_type ?? '',
    width_px: props.media.width_px ?? '',
    height_px: props.media.height_px ?? '',
    sort_order: props.media.sort_order ?? 0,
    is_primary: Boolean(props.media.is_primary),
    visibility: props.media.visibility ?? 'private',
    content_classification: props.media.content_classification ?? 'restricted',
})

const attachmentOptionMap = computed(() => ({
    entity: toEntityOptions(props.attachmentTargets.entity ?? []),
    group_relationship: toGroupRelationshipOptions(props.attachmentTargets.group_relationship ?? []),
    collection: toCollectionOptions(props.attachmentTargets.collection ?? []),
    meta: toMetaOptions(props.attachmentTargets.meta ?? []),
    timeline_entry: toTimelineEntryOptions(props.attachmentTargets.timeline_entry ?? []),
    concurrency_group: toConcurrencyGroupOptions(props.attachmentTargets.concurrency_group ?? []),
    source_canon_reference: toCanonReferenceOptions(props.attachmentTargets.source_canon_reference ?? []),
}))

const currentAttachmentOptions = computed(() =>
    attachmentOptionMap.value[form.attachment_type] ?? [],
)

const sections = computed(() => {
    const sourceFields = form.source_kind === 'upload'
        ? [
            {
                key: 'upload_file',
                label: 'Upload File',
                type: 'file',
                required: false,
                accept: form.media_type === 'image' ? 'image/*' : null,
                help: 'Leave this empty to keep the current stored upload. Uploading a new file replaces the stored media reference path and metadata.',
            },
        ]
        : form.source_kind === 'local'
        ? [
            { key: 'file_path', label: 'Local File Path', required: true, placeholder: 'C:\\path\\to\\image.png' },
            { key: 'file_name', label: 'File Name', placeholder: 'Optional override' },
            { key: 'file_extension', label: 'File Extension', placeholder: 'png' },
        ]
        : [
            { key: 'url', label: 'External URL', type: 'url', required: true, placeholder: 'https://example.com/reference.png' },
            { key: 'file_name', label: 'Display File Name', placeholder: 'Optional label' },
            { key: 'file_extension', label: 'File Extension', placeholder: 'png' },
        ]

    return [
        {
            title: 'Identity',
            fields: [
                { key: 'title', label: 'Title', required: true },
                { key: 'description', label: 'Description', type: 'textarea', rows: 3 },
                { key: 'media_type', label: 'Media Type', type: 'select', required: true, options: props.mediaTypes },
                { key: 'purpose', label: 'Purpose', type: 'select', required: true, options: props.purposes },
            ],
        },
        {
            title: 'Attachment',
            fields: [
                { key: 'attachment_type', label: 'Attachment Type', type: 'select', required: true, options: props.attachmentTypes },
                {
                    key: 'attachment_id',
                    label: 'Attachment Target',
                    type: 'select',
                    required: true,
                    options: currentAttachmentOptions.value,
                    placeholder: currentAttachmentOptions.value.length ? 'Select a target...' : 'No targets available',
                },
            ],
        },
        {
            title: 'Source',
            fields: [
                { key: 'source_kind', label: 'Source Kind', type: 'select', required: true, options: props.sourceKinds },
                ...sourceFields,
                ...(form.source_kind === 'upload'
                    ? []
                    : [
                        { key: 'mime_type', label: 'MIME Type', placeholder: 'image/png' },
                        { key: 'file_size_bytes', label: 'File Size (Bytes)', type: 'number' },
                        { key: 'width_px', label: 'Width (px)', type: 'number' },
                        { key: 'height_px', label: 'Height (px)', type: 'number' },
                    ]),
            ],
        },
        {
            title: 'Display',
            fields: [
                { key: 'sort_order', label: 'Sort Order', type: 'number' },
                { key: 'is_primary', label: 'Primary Media', type: 'checkbox' },
                { key: 'visibility', label: 'Visibility', type: 'select', required: true, options: props.visibilityLevels },
                { key: 'content_classification', label: 'Content Classification', type: 'select', required: true, options: props.contentClassifications },
            ],
        },
    ]
})

const submit = () => form.put(route('media-references.update', props.media.id), {
    forceFormData: form.source_kind === 'upload',
})
</script>
