<template>
    <div>
        <ScaffoldShowPage
            :title="note.title"
            back-label="Meta"
            :back-href="route('meta.index')"
            :edit-href="route('meta.edit', note.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('meta.show', note.id)"
            :destroy-href="route('meta.destroy', note.id)"
            :badge="formatLabel(note.category)"
            :sections="sections"
        >
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label !mb-0">Workflow</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Resolve or supersede this note without leaving the show page.
                        </p>
                    </div>

                    <div class="space-y-3">
                        <div class="field-group">
                            <label class="field-label" for="meta-resolution-notes">Resolution Notes</label>
                            <RichTextEditor
                                input-id="meta-resolution-notes"
                                aria-label="Resolution Notes"
                                v-model="resolveDocument"
                                placeholder="What settled this note, what changed, and what matters now."
                            />
                        </div>

                        <AppButton
                            type="button"
                            variant="success"
                            :disabled="resolveForm.processing || note.action_status === 'resolved'"
                            @click="resolveNote"
                        >
                            {{ note.action_status === 'resolved' ? 'Already Resolved' : 'Resolve Note' }}
                        </AppButton>
                    </div>

                    <div class="space-y-3 border-t border-border pt-4">
                        <div class="field-group">
                            <label class="field-label" for="meta-superseded-by">Superseded By</label>
                            <SelectInput id="meta-superseded-by" v-model="supersedeForm.superseded_by_meta_id" class="w-full">
                                <option value="">Select a newer note...</option>
                                <option v-for="option in props.supersedeOptions" :key="option.id" :value="String(option.id)">
                                    {{ option.title }}
                                </option>
                            </SelectInput>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="meta-supersession-reason">Supersession Reason</label>
                            <TextInput
                                id="meta-supersession-reason"
                                v-model="supersedeForm.supersession_reason"
                                type="text"
                                class="w-full"
                                placeholder="Why this note was superseded."
                            />
                        </div>

                        <AppButton
                            type="button"
                            variant="primary"
                            :disabled="supersedeForm.processing || !supersedeForm.superseded_by_meta_id || Boolean(note.superseded_by?.id)"
                            @click="supersedeNote"
                        >
                            {{ note.superseded_by?.id ? 'Already Superseded' : 'Supersede Note' }}
                        </AppButton>
                    </div>
                </section>

                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label !mb-0">Entity Links</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Attach this note to the entities it should follow around the site.
                        </p>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="meta-link-entity">Link Entity</label>
                        <div class="flex flex-col gap-3 md:flex-row">
                            <SelectInput id="meta-link-entity" v-model="linkForm.entity_id" class="w-full">
                                <option value="">Select an entity...</option>
                                <option v-for="option in availableEntityOptions" :key="option.id" :value="String(option.id)">
                                    {{ option.name }} ({{ formatLabel(option.entity_type) }})
                                </option>
                            </SelectInput>
                            <AppButton
                                type="button"
                                variant="primary"
                                :disabled="linkForm.processing || !linkForm.entity_id"
                                @click="linkEntity"
                            >
                                Link Entity
                            </AppButton>
                        </div>
                    </div>

                    <div v-if="note.entities?.length" class="space-y-2">
                        <div v-for="entity in note.entities" :key="entity.id" class="record-card">
                            <div class="flex items-center justify-between gap-3">
                                <Link :href="route('entities.show', entity.id)" class="text-primary text-sm hover:text-cyan transition-colors">
                                    {{ entity.name }}
                                </Link>
                                <AppButton
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="unlinkEntity(entity.id)"
                                >
                                    Unlink
                                </AppButton>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-state">No entities linked yet.</div>
                </section>
            </div>
                <template #edit-drawer>
            <EditMeta
                        v-if="editDrawer"
                        embedded
                        :note="note"
                        v-bind="editDrawer"
                    />
        </template>
    </ScaffoldShowPage>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import RichTextEditor from '@/Components/scaffold/RichTextEditor.vue'
import EditMeta from '@/Pages/Production/Meta/Edit.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { prepareRichDocumentForSubmit, normalizeRichDocument } from '@/lib/tiptap/documents'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    note: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    supersedeOptions: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})

const resolveForm = useForm({
    resolution_notes: props.note.resolution_notes ?? null,
})

const supersedeForm = useForm({
    superseded_by_meta_id: props.note.superseded_by?.id ? String(props.note.superseded_by.id) : '',
    supersession_reason: props.note.supersession_reason ?? '',
})

const linkForm = useForm({
    entity_id: '',
})

const resolveDocument = ref(normalizeRichDocument(props.note.resolution_notes))

const availableEntityOptions = computed(() => {
    const linkedIds = new Set((props.note.entities ?? []).map((entity) => entity.id))

    return props.entities.filter((entity) => !linkedIds.has(entity.id))
})

const resolveNote = () => {
    resolveForm.transform(() => ({
        resolution_notes: prepareRichDocumentForSubmit(resolveDocument.value, null),
    })).post(route('meta.resolve', props.note.id))
}

const supersedeNote = () => {
    supersedeForm.transform((data) => ({
        superseded_by_meta_id: Number(data.superseded_by_meta_id),
        supersession_reason: data.supersession_reason || null,
    })).post(route('meta.supersede', props.note.id))
}

const linkEntity = () => {
    router.post(route('meta.entities.link', {
        meta: props.note.id,
        entity: Number(linkForm.entity_id),
    }), {}, {
        onSuccess: () => linkForm.reset(),
    })
}

const unlinkEntity = (entityId) => {
    router.delete(route('meta.entities.unlink', {
        meta: props.note.id,
        entity: entityId,
    }))
}

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Category', formatLabel(props.note.category)),
            sectionEntry('Type', formatLabel(props.note.meta_note_type)),
            sectionEntry('Priority', formatLabel(props.note.priority)),
            sectionEntry('Action Status', formatLabel(props.note.action_status)),
            sectionEntry('Resolved At', props.note.resolved_at),
            sectionEntry('Visibility', formatLabel(props.note.visibility)),
            sectionEntry('Content Classification', formatLabel(props.note.content_classification)),
        ],
    },
    {
        title: 'Sensory and Symbolic Notes',
        entries: [
            sectionEntry('Sight', props.note.sense_sight),
            sectionEntry('Sound', props.note.sense_sound),
            sectionEntry('Smell', props.note.sense_smell),
            sectionEntry('Taste', props.note.sense_taste),
            sectionEntry('Touch', props.note.sense_touch),
            sectionEntry('Magical', props.note.sense_magical),
            sectionEntry('Emotional Register', props.note.emotional_register),
            sectionEntry('Symbol Name', props.note.symbol_name),
            sectionEntry('Symbol Origin Entity', props.note.symbol_origin_entity?.name),
            sectionEntry('Symbol Usage Context', props.note.symbol_usage_context),
            sectionEntry('Symbol Scope', formatLabel(props.note.symbol_scope)),
            sectionEntry(
                'Associated Entities',
                (props.note.symbol_associated_entities ?? []).map((entity) => ({
                    label: `${entity.name} (${formatLabel(entity.entity_type)})`,
                    href: route('entities.show', entity.id),
                })),
                { kind: 'list' },
            ),
        ],
        fullWidth: true,
    },
    {
        title: 'Content',
        entries: [
            sectionEntry('Content', props.note.content, { kind: 'json' }),
            sectionEntry('Resolution Notes', props.note.resolution_notes, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Links',
        entries: [
            sectionEntry(
                'Entities',
                (props.note.entities ?? []).map((entity) => ({
                    label: `${entity.name} (${formatLabel(entity.entity_type)})`,
                    href: route('entities.show', entity.id),
                })),
                { kind: 'list' },
            ),
            sectionEntry('Superseded By', props.note.superseded_by?.title),
        ],
    },
])
</script>
