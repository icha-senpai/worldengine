<template>
    <div>
        <ScaffoldShowPage
            :title="state.knower?.name ? `${state.knower.name} Knowledge` : `Knowledge State #${state.id}`"
            back-label="Knowledge States"
            :back-href="route('knowledge-states.index')"
            :edit-href="route('knowledge-states.edit', state.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('knowledge-states.show', state.id)"
            :destroy-href="route('knowledge-states.destroy', state.id)"
            :badge="formatLabel(state.knowledge_type)"
            :sections="sections"
        >
            <div class="mt-4">
                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label mb-0!">Act On Knowledge</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Mark this state as acted on once the knowledge starts changing behavior or outcomes.
                        </p>
                    </div>

                    <div v-if="state.acted_on" class="empty-state">
                        This knowledge state has already been acted on.
                    </div>

                    <div v-else class="space-y-3">
                        <div class="field-group">
                            <label class="field-label" for="knowledge-action-notes">Action Notes</label>
                            <RichTextEditor
                                input-id="knowledge-action-notes"
                                aria-label="Action Notes"
                                v-model="actionNotesDocument"
                                placeholder="What was done with this knowledge, and what changed because of it?"
                            />
                        </div>

                        <AppButton
                            type="button"
                            variant="success"
                            :disabled="actionForm.processing"
                            @click="markActedOn"
                        >
                            Mark Acted On
                        </AppButton>
                    </div>
                </section>
            </div>
            <template #edit-drawer>
                <EditKnowledgeState
                    v-if="editDrawer"
                    embedded
                    :state="state"
                    v-bind="editDrawer"
                />
            </template>
        </ScaffoldShowPage>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import RichTextEditor from '@/Components/scaffold/RichTextEditor.vue'
import EditKnowledgeState from '@/Pages/Intelligence/KnowledgeStates/Edit.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import { normalizeRichDocument, prepareRichDocumentForSubmit } from '@/lib/tiptap/documents'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    state: { type: Object, required: true },
    subjectDisplay: { type: Object, default: () => ({}) },
    editDrawer: { type: Object, default: null },
})

const actionForm = useForm({
    action_notes: props.state.action_notes ?? null,
})

const actionNotesDocument = ref(normalizeRichDocument(props.state.action_notes))

const markActedOn = () => {
    actionForm.transform(() => ({
        action_notes: prepareRichDocumentForSubmit(actionNotesDocument.value, null),
    })).post(route('knowledge-states.act-on', props.state.id))
}

const sections = computed(() => [
    {
        title: 'Participants',
        entries: [
            sectionEntry('Knower', props.state.knower?.name, props.state.knower ? { href: route('entities.show', props.state.knower.id) } : {}),
            sectionEntry('Subject Type', props.subjectDisplay?.typeLabel),
            sectionEntry('Subject', props.subjectDisplay?.label || `Subject #${props.state.id}`, props.subjectDisplay?.href ? { href: props.subjectDisplay.href } : {}),
            sectionEntry('Acquired From', props.state.acquired_from?.name, props.state.acquired_from ? { href: route('entities.show', props.state.acquired_from.id) } : {}),
        ],
    },
    {
        title: 'Assessment',
        entries: [
            sectionEntry('Knowledge Type', formatLabel(props.state.knowledge_type)),
            sectionEntry('Accuracy', formatLabel(props.state.accuracy)),
            sectionEntry('Belief State', formatLabel(props.state.current_belief_state)),
            sectionEntry('Acquired Through', formatLabel(props.state.acquired_through)),
            sectionEntry('Acquired At Era', props.state.acquired_at_era),
            sectionEntry('Acted On', props.state.acted_on ? 'Yes' : 'No'),
        ],
    },
    {
        title: 'Content',
        entries: [
            sectionEntry('Knowledge Content', props.state.knowledge_content, { kind: 'json' }),
            sectionEntry('Action Notes', props.state.action_notes, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
