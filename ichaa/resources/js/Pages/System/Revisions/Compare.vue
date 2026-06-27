<template>
    <div>
        <ScaffoldShowPage
            :title="`Compare Revisions #${left.id} and #${right.id}`"
            :subtitle="resourceLink?.label ?? `${left.resource_type} #${left.resource_id}`"
            back-label="Revisions"
            :back-href="route('revisions.index')"
            badge="compare"
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
                <AppButton type="button" variant="danger" @click="restoreRevision(right.id)">
                    Restore Right Revision
                </AppButton>
            </template>

            <section class="panel mt-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="panel-label mb-0!">Field Comparison</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">Left is the earlier payload snapshot; right is the later one.</p>
                    </div>
                    <span class="mini-chip">{{ rows.length }} fields</span>
                </div>

                <div class="mt-4 space-y-3">
                    <div
                        v-for="row in rows"
                        :key="row.field"
                        class="record-card"
                        :class="row.changed ? 'border-cyan/30' : ''"
                    >
                        <p class="text-xs font-ui uppercase tracking-[0.16em] text-muted-3">{{ row.field }}</p>
                        <div class="mt-2 grid gap-3 md:grid-cols-2">
                            <div>
                                <p class="text-[11px] font-ui uppercase tracking-[0.16em] text-muted-3">Left</p>
                                <pre class="json-block mt-2">{{ prettyJson(row.left) || '—' }}</pre>
                            </div>
                            <div>
                                <p class="text-[11px] font-ui uppercase tracking-[0.16em] text-muted-3">Right</p>
                                <pre class="json-block mt-2">{{ prettyJson(row.right) || '—' }}</pre>
                            </div>
                        </div>
                    </div>
                </div>
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
    left: { type: Object, required: true },
    right: { type: Object, required: true },
    resourceLink: { type: Object, default: null },
    currentRevisionId: { type: Number, default: 0 },
    rows: { type: Array, default: () => [] },
})

const sections = computed(() => [
    {
        title: 'Left Revision',
        entries: [
            sectionEntry('Revision ID', props.left.id),
            sectionEntry('Action', props.left.action),
            sectionEntry('Actor', props.left.actor?.name),
            sectionEntry('Created At', props.left.created_at),
        ],
    },
    {
        title: 'Right Revision',
        entries: [
            sectionEntry('Revision ID', props.right.id),
            sectionEntry('Action', props.right.action),
            sectionEntry('Actor', props.right.actor?.name),
            sectionEntry('Created At', props.right.created_at),
        ],
    },
])

const restoreRevision = async (revisionId) => {
    const confirmed = await confirmDialog({
        title: 'Restore Revision',
        message: 'Restore the linked record to the selected revision state?',
        confirmLabel: 'Restore Revision',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.post(route('revisions.restore', revisionId), {
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
