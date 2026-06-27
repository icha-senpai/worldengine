<template>
    <div>
        <ScaffoldShowPage
            :title="`${state.subject_type || 'subject'} perception gap`"
            :subtitle="subjectDisplay?.label || `Subject ID ${state.subject_id ?? 'unknown'}`"
            back-label="Perception States"
            :back-href="route('perception-states.index')"
            :edit-href="route('perception-states.edit', state.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('perception-states.show', state.id)"
            :destroy-href="route('perception-states.destroy', state.id)"
            :badge="state.divergence_level || 'gap'"
            :sections="sections"
        >
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label mb-0!">Immune Entities</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Add or remove the people or powers who can see through this perception state.
                        </p>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="perception-immune-entity">Entity</label>
                        <div class="flex flex-col gap-3 md:flex-row">
                            <SelectInput id="perception-immune-entity" v-model="immuneForm.entity_id" class="w-full">
                                <option value="">Select an entity...</option>
                                <option v-for="option in availableImmuneOptions" :key="option.id" :value="String(option.id)">
                                    {{ option.name }} ({{ option.entity_type }})
                                </option>
                            </SelectInput>
                            <AppButton
                                type="button"
                                variant="primary"
                                :disabled="immuneForm.processing || !immuneForm.entity_id"
                                @click="addImmune"
                            >
                                Add Immune
                            </AppButton>
                        </div>
                    </div>

                    <div v-if="immuneEntities.length" class="space-y-2">
                        <div v-for="entity in immuneEntities" :key="`immune-${entity.id ?? entity.label}`" class="record-card">
                            <div class="flex items-center justify-between gap-3">
                                <Link v-if="entity.href" :href="entity.href" class="text-primary text-sm hover:text-cyan transition-colors">
                                    {{ entity.label }}
                                </Link>
                                <span v-else class="text-primary text-sm">{{ entity.label }}</span>
                                <AppButton
                                    v-if="entity.id"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="removeImmune(entity)"
                                >
                                    Remove
                                </AppButton>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-state">No immune entities recorded yet.</div>
                </section>

                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label mb-0!">Collapse State</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Close the gap once the false perception breaks and the truth becomes inevitable.
                        </p>
                    </div>

                    <div v-if="!state.is_current" class="empty-state">
                        This perception state has already collapsed.
                    </div>

                    <div v-else class="space-y-3">
                        <div class="field-group">
                            <label class="field-label" for="perception-collapse-era">Revealed At Era</label>
                            <TextInput
                                id="perception-collapse-era"
                                v-model="collapseForm.era"
                                type="text"
                                class="w-full"
                                placeholder="When the illusion or misunderstanding finally broke."
                            />
                        </div>

                        <AppButton
                            type="button"
                            variant="warn"
                            :disabled="collapseForm.processing || !collapseForm.era"
                            @click="collapseState"
                        >
                            Collapse Perception State
                        </AppButton>
                    </div>
                </section>
            </div>
            <template #edit-drawer>
                <EditPerceptionState
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
import { computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditPerceptionState from '@/Pages/Intelligence/PerceptionStates/Edit.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    state: { type: Object, required: true },
    subjectDisplay: { type: Object, default: () => ({}) },
    maintainedByEntities: { type: Array, default: () => [] },
    immuneEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})

const immuneForm = useForm({
    entity_id: '',
})

const collapseForm = useForm({
    era: props.state.revealed_at_era ?? '',
})

const availableImmuneOptions = computed(() => {
    const immuneIds = new Set(props.immuneEntities.map((entity) => Number(entity.id)).filter(Boolean))

    return props.entities.filter((entity) => !immuneIds.has(Number(entity.id)))
})

const addImmune = () => {
    router.post(route('perception-states.immune.add', {
        perceptionState: props.state.id,
        entity: Number(immuneForm.entity_id),
    }), {}, {
        preserveScroll: true,
        onSuccess: () => immuneForm.reset(),
    })
}

const removeImmune = async (entity) => {
    const confirmed = await confirmDialog({
        title: 'Remove Immune Entity',
        message: `Remove ${entity.label} from the immune list?`,
        confirmLabel: 'Remove Entity',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(route('perception-states.immune.remove', {
        perceptionState: props.state.id,
        entity: entity.id,
    }), {
        preserveScroll: true,
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not remove immune entity',
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}

const collapseState = () => {
    collapseForm.post(route('perception-states.collapse', props.state.id))
}

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Subject Type', props.state.subject_type),
            sectionEntry('Subject', props.subjectDisplay?.label || props.state.subject_id, {
                href: props.subjectDisplay?.href || '',
            }),
            sectionEntry('Divergence Level', props.state.divergence_level),
            sectionEntry('Maintenance Method', props.state.maintenance_method),
            sectionEntry('Maintenance Effort', props.state.maintenance_effort),
            sectionEntry('Revelation Risk', props.state.revelation_risk),
            sectionEntry('Current', props.state.is_current ? 'Yes' : 'No'),
            sectionEntry('Revealed At Era', props.state.revealed_at_era),
        ],
    },
    {
        title: 'States',
        entries: [
            sectionEntry('True State', props.state.true_state, { kind: 'json' }),
            sectionEntry('Perceived State', props.state.perceived_state, { kind: 'json' }),
            sectionEntry('Maintained By Entities', props.maintainedByEntities, { kind: 'list' }),
            sectionEntry('Immune Entities', props.immuneEntities, { kind: 'list' }),
        ],
        fullWidth: true,
    },
])
</script>
