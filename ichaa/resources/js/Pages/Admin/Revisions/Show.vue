<template>
    <ScaffoldShowPage
        :title="`Revision #${revision.id}`"
        :subtitle="revision.record_link?.label || `${formatLabel(revision.resource_type)} #${revision.resource_id}`"
        back-label="Revisions"
        :back-href="route('admin.revisions.index')"
        :badge="formatLabel(revision.action)"
        :hero-meta="heroMeta"
        :sections="sections"
    >
        <template #hero-actions>
            <div v-if="compareOptions.length" class="flex flex-col gap-2 md:min-w-[220px]">
                <SelectInput v-model="compareTargetId" class="w-full">
                    <option value="">Compare with...</option>
                    <option v-for="option in compareOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </SelectInput>
                <AppButton type="button" variant="ghost" :disabled="!compareTargetId" @click="compareRevision">
                    Compare
                </AppButton>
            </div>

            <AppButton v-if="restoreHref" type="button" variant="primary" @click="restoreRevision">
                Restore Revision
            </AppButton>
        </template>
    </ScaffoldShowPage>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import SelectInput from '@/Components/SelectInput.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'

const props = defineProps({
    revision: { type: Object, required: true },
    restoreHref: { type: String, default: '' },
    compareHref: { type: String, required: true },
    compareOptions: { type: Array, default: () => [] },
})

const compareTargetId = ref('')

const heroMeta = computed(() => [
    { label: 'Resource', value: formatLabel(props.revision.resource_type) },
    { label: 'Record', value: `#${props.revision.resource_id}` },
    { label: 'Actor', value: props.revision.actor_name || '—' },
    { label: 'Created', value: formatDate(props.revision.created_at) },
])

const sections = computed(() => {
    const builtSections = [
        {
            title: 'Summary',
            entries: [
                entry('Resource Type', formatLabel(props.revision.resource_type)),
                entry('Linked Record', props.revision.record_link?.label || `#${props.revision.resource_id}`, props.revision.record_link?.href ? { href: props.revision.record_link.href } : {}),
                entry('Action', formatLabel(props.revision.action)),
                entry('Reason', props.revision.reason || '—'),
                entry('Source', props.revision.source || '—'),
                entry('Token Name', props.revision.token_name || '—'),
                entry('Base Revision ID', props.revision.base_revision_id ? `#${props.revision.base_revision_id}` : '—'),
                entry('Restored From Revision', props.revision.restored_from_revision_id ? `#${props.revision.restored_from_revision_id}` : '—'),
                entry('Actor', props.revision.actor_name || '—'),
                entry('Created At', formatDate(props.revision.created_at)),
            ],
        },
    ]

    if (props.revision.before_payload) {
        builtSections.push({
            title: 'Before Payload',
            entries: [entry('Payload', props.revision.before_payload, { kind: 'json' })],
            fullWidth: true,
        })
    }

    if (props.revision.after_payload) {
        builtSections.push({
            title: 'After Payload',
            entries: [entry('Payload', props.revision.after_payload, { kind: 'json' })],
            fullWidth: true,
        })
    }

    if (props.revision.diff_payload && Object.keys(props.revision.diff_payload).length) {
        builtSections.push({
            title: 'Changed Fields',
            entries: Object.entries(props.revision.diff_payload).map(([field, value]) =>
                entry(formatLabel(field), value, { kind: 'json' }),
            ),
            fullWidth: true,
        })
    }

    return builtSections
})

const compareRevision = () => {
    if (!compareTargetId.value) {
        return
    }

    router.get(props.compareHref, {
        left: props.revision.id,
        right: compareTargetId.value,
    })
}

const restoreRevision = async () => {
    const confirmed = await confirmDialog({
        title: 'Restore Revision',
        message: `Restore revision #${props.revision.id} onto the live record?`,
        confirmLabel: 'Restore Revision',
        cancelLabel: 'Cancel',
        confirmVariant: 'primary',
    })

    if (!confirmed) {
        return
    }

    router.post(props.restoreHref, {}, {
        preserveScroll: true,
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not restore revision',
                message: 'The restore request did not complete.',
                details: errors,
            })
        },
    })
}

const entry = (label, value, extra = {}) => ({
    label,
    value,
    ...extra,
})

const formatLabel = (value) =>
    value ? String(value).replace(/[_-]/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase()) : '—'

const formatDate = (value) => {
    if (!value) {
        return '—'
    }

    return new Date(value).toLocaleString()
}
</script>
