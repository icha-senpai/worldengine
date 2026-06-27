<template>
    <div>
        <ScaffoldShowPage
            :title="`Revision #${revision.id}`"
            :subtitle="resourceLink?.label ?? `${revision.resource_type} #${revision.resource_id}`"
            back-label="Revisions"
            :back-href="route('revisions.index')"
            badge="revision"
            :sections="sections"
        >
            <template #hero-actions>
                <AppButton
                    v-if="resourceLink"
                    :href="resourceLink.href"
                    variant="ghost"
                >
                    Open Record
                </AppButton>
                <AppButton
                    v-if="compareCandidates.length"
                    :href="compareHref"
                    variant="ghost"
                >
                    Compare
                </AppButton>
                <AppButton
                    type="button"
                    variant="danger"
                    @click="restoreRevision"
                >
                    Restore Revision
                </AppButton>
            </template>

            <section class="panel mt-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="panel-label mb-0!">Changed Fields</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">Stable diff output for this revision.</p>
                    </div>
                    <span class="mini-chip">{{ diffRows.length }} fields</span>
                </div>

                <div v-if="diffRows.length" class="mt-4 space-y-3">
                    <div v-for="row in diffRows" :key="row.field" class="record-card">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-xs font-ui uppercase tracking-[0.16em] text-muted-3">{{ row.field }}</p>
                                <div class="mt-2 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <p class="text-[11px] font-ui uppercase tracking-[0.16em] text-muted-3">Before</p>
                                        <pre class="json-block mt-2">{{ prettyJson(row.before) || '—' }}</pre>
                                    </div>
                                    <div>
                                        <p class="text-[11px] font-ui uppercase tracking-[0.16em] text-muted-3">After</p>
                                        <pre class="json-block mt-2">{{ prettyJson(row.after) || '—' }}</pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <p v-else class="text-muted-3 text-sm font-ui mt-4">This revision did not record any field-level diff entries.</p>
            </section>
        </ScaffoldShowPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import { prettyJson } from '@/Components/scaffold/formatters'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    revision: { type: Object, required: true },
    resourceLink: { type: Object, default: null },
    compareCandidates: { type: Array, default: () => [] },
    currentRevisionId: { type: Number, default: 0 },
    diffRows: { type: Array, default: () => [] },
})

const compareHref = computed(() =>
    props.compareCandidates.length
        ? route('revisions.compare', { left: props.compareCandidates[0].id, right: props.revision.id })
        : null,
)

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Resource Type', props.revision.resource_type),
            sectionEntry('Resource ID', props.revision.resource_id),
            sectionEntry('Action', props.revision.action),
            sectionEntry('Actor', props.revision.actor?.name),
            sectionEntry('Source', props.revision.source),
            sectionEntry('Reason', props.revision.reason),
            sectionEntry('Base Revision', props.revision.base_revision_id),
            sectionEntry('Restored From', props.revision.restored_from_revision_id),
            sectionEntry('Created At', props.revision.created_at),
        ],
    },
    {
        title: 'Payload',
        entries: [
            sectionEntry('Before Payload', props.revision.before_payload, { kind: 'json' }),
            sectionEntry('After Payload', props.revision.after_payload, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])

const restoreRevision = async () => {
    const confirmed = await confirmDialog({
        title: 'Restore Revision',
        message: 'Restore the linked record to this revision state?',
        confirmLabel: 'Restore Revision',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.post(route('revisions.restore', props.revision.id), {
        base_revision_id: props.currentRevisionId,
    }, {
        preserveScroll: true,
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not restore revision',
                message: 'The restore did not complete.',
                details: errors,
            })
        },
    })
}
</script>
