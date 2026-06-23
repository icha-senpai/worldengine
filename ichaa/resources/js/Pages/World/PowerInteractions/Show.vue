<template>
    <div>
        <ScaffoldShowPage
            :title="interaction.interaction_name"
            back-label="Power Interactions"
            :back-href="route('power-interactions.index')"
            :edit-href="route('power-interactions.edit', interaction.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('power-interactions.show', interaction.id)"
            :destroy-href="route('power-interactions.destroy', interaction.id)"
            :badge="formatLabel(interaction.knowledge_state)"
            :sections="sections"
        >
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label !mb-0">Resolve Interaction</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Close the unresolved flag once the rule is understood again.
                        </p>
                    </div>

                    <div v-if="!interaction.unresolved_flag" class="empty-state">
                        This interaction is currently resolved.
                    </div>

                    <div v-else class="space-y-3">
                        <div class="field-group">
                            <label class="field-label" for="power-knowledge-state">Knowledge State</label>
                            <SelectInput id="power-knowledge-state" v-model="resolveForm.knowledge_state" class="w-full">
                                <option value="">Keep current state...</option>
                                <option v-for="option in props.knowledgeStates" :key="option" :value="option">
                                    {{ formatLabel(option) }}
                                </option>
                            </SelectInput>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="power-resolution-notes">Resolution Notes</label>
                            <RichTextEditor
                                input-id="power-resolution-notes"
                                aria-label="Resolution Notes"
                                v-model="resolutionDocument"
                                placeholder="What clarified the interaction and removed the contradiction?"
                            />
                        </div>

                        <AppButton
                            type="button"
                            variant="success"
                            :disabled="resolveForm.processing"
                            @click="resolveInteraction"
                        >
                            Resolve Interaction
                        </AppButton>
                    </div>
                </section>

                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label !mb-0">Record Instance</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Capture observed examples of this interaction out in the world.
                        </p>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="power-event-entity">Event Entity</label>
                        <SelectInput id="power-event-entity" v-model="instanceForm.event_entity_id" class="w-full">
                            <option value="">Select an entity...</option>
                            <option v-for="option in props.entities" :key="option.id" :value="String(option.id)">
                                {{ option.name }} ({{ option.entity_type }})
                            </option>
                        </SelectInput>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="power-outcome-match">Outcome Match</label>
                        <SelectInput id="power-outcome-match" v-model="instanceForm.outcome_match" class="w-full">
                            <option value="">Select an outcome...</option>
                            <option v-for="option in props.instanceOutcomeMatches" :key="option" :value="option">
                                {{ formatLabel(option) }}
                            </option>
                        </SelectInput>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="power-observed-era">Observed At Era</label>
                        <TextInput
                            id="power-observed-era"
                            v-model="instanceForm.observed_at_era"
                            type="text"
                            class="w-full"
                            placeholder="When this example happened."
                        />
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="power-outcome-notes">Outcome Notes</label>
                        <RichTextEditor
                            input-id="power-outcome-notes"
                            aria-label="Outcome Notes"
                            v-model="outcomeDocument"
                            placeholder="What actually happened in this observed case?"
                        />
                    </div>

                    <AppButton
                        type="button"
                        variant="primary"
                        :disabled="instanceForm.processing || !instanceForm.event_entity_id || !instanceForm.outcome_match"
                        @click="recordInstance"
                    >
                        Record Instance
                    </AppButton>
                </section>
            </div>
                <template #edit-drawer>
            <EditPowerInteraction
                        v-if="editDrawer"
                        embedded
                        :interaction="interaction"
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
import EditPowerInteraction from '@/Pages/World/PowerInteractions/Edit.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { normalizeRichDocument, prepareRichDocumentForSubmit } from '@/lib/tiptap/documents'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    interaction: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    knowledgeStates: { type: Array, default: () => [] },
    instanceOutcomeMatches: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})

const resolveForm = useForm({
    knowledge_state: props.interaction.knowledge_state ?? '',
    resolution_notes: props.interaction.resolution_notes ?? null,
})

const instanceForm = useForm({
    event_entity_id: '',
    outcome_match: '',
    observed_at_era: '',
    outcome_notes: null,
})

const resolutionDocument = ref(normalizeRichDocument(props.interaction.resolution_notes))
const outcomeDocument = ref(normalizeRichDocument(null))

const resolveInteraction = () => {
    resolveForm.transform((data) => ({
        knowledge_state: data.knowledge_state || null,
        resolution_notes: prepareRichDocumentForSubmit(resolutionDocument.value, null),
    })).post(route('power-interactions.resolve', props.interaction.id))
}

const recordInstance = () => {
    instanceForm.transform((data) => ({
        ...data,
        event_entity_id: Number(data.event_entity_id),
        outcome_notes: prepareRichDocumentForSubmit(outcomeDocument.value, null),
    })).post(route('power-interactions.instances.store', props.interaction.id), {
        onSuccess: () => {
            instanceForm.reset()
            outcomeDocument.value = normalizeRichDocument(null)
        },
    })
}

const sections = computed(() => [
    {
        title: 'Systems',
        entries: [
            sectionEntry('System A', props.interaction.system_a?.name, props.interaction.system_a ? { href: route('entities.show', props.interaction.system_a.id) } : {}),
            sectionEntry('System B', props.interaction.system_b?.name, props.interaction.system_b ? { href: route('entities.show', props.interaction.system_b.id) } : {}),
            sectionEntry('Directionality', formatLabel(props.interaction.directionality)),
            sectionEntry('Scale', formatLabel(props.interaction.interaction_scale)),
        ],
    },
    {
        title: 'Risk and Resolution',
        entries: [
            sectionEntry('Knowledge State', formatLabel(props.interaction.knowledge_state)),
            sectionEntry('Danger Rating', formatLabel(props.interaction.danger_rating)),
            sectionEntry('Proximity Required', props.interaction.proximity_required),
            sectionEntry('Unresolved', props.interaction.unresolved_flag),
            sectionEntry('Resolution Notes', props.interaction.resolution_notes, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Effect Model',
        entries: [
            sectionEntry('Description', props.interaction.description, { kind: 'json' }),
            sectionEntry('Effects', props.interaction.effects, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Observed Instances',
        entries: [
            sectionEntry(
                'Instances',
                (props.interaction.instances ?? []).map((instance) => ({
                    label: `${instance.event_entity?.name ?? 'Unknown event'} (${formatLabel(instance.outcome_match)})${instance.observed_at_era ? ` — ${instance.observed_at_era}` : ''}`,
                    href: instance.event_entity?.id ? route('entities.show', instance.event_entity.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
