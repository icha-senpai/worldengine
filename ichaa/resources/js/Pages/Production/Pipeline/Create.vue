<template>
    <AuthenticatedLayout>

        <template #header>
            <PageHeaderTrail :items="headerItems" />
        </template>

        <form @submit.prevent="submit" class="max-w-2xl space-y-5">

            <FormErrorSummary :errors="form.errors" />

            <!-- TITLE -->
            <div class="field-group">
                <label class="field-label">Title <span class="text-danger">*</span></label>
                <TextInput
                    v-model="form.title"
                    type="text"
                    placeholder="Item title"
                    class="w-full"
                    :class="{ 'input--error': form.errors.title }"
                    autofocus
                />
                <p v-if="form.errors.title" class="field-error">{{ form.errors.title }}</p>
            </div>

            <!-- TYPE -->
            <div class="field-group">
                <label class="field-label">Type <span class="text-danger">*</span></label>
                <div class="flex flex-wrap gap-1.5">
                    <AppButton
                        v-for="t in pipelineTypes"
                        :key="t"
                        type="button"
                        @click="form.pipeline_type = t"
                        variant="select-solid"
                        :selected="form.pipeline_type === t"
                    >{{ formatLabel(t) }}</AppButton>
                </div>
                <p v-if="form.errors.pipeline_type" class="field-error">{{ form.errors.pipeline_type }}</p>
            </div>

            <template v-if="form.pipeline_type">

                <!-- STAGE -->
                <div class="field-group">
                    <label class="field-label">Stage</label>
                    <div class="flex flex-wrap gap-1.5">
                        <AppButton
                            v-for="s in pipelineStages"
                            :key="s"
                            type="button"
                            @click="form.pipeline_stage = s"
                            variant="select"
                            :selected="form.pipeline_stage === s"
                        >{{ formatLabel(s) }}</AppButton>
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label">Parent Item</label>
                    <SelectInput v-model="form.parent_pipeline_item_id" class="w-full">
                        <option :value="null">Top-level item</option>
                        <option
                            v-for="option in parentItemOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </SelectInput>
                </div>

                <!-- SCENE FIELDS — only for scene type -->
                <div v-if="form.pipeline_type === 'scene'" class="panel">
                    <h3 class="panel-label">Scene Details</h3>
                    <div class="space-y-4">

                        <div class="form-grid-2-tight">
                            <div class="field-group">
                                <label class="field-label">POV Character</label>
                                <SelectInput v-model="form.pov_character_entity_id" class="w-full">
                                    <option :value="null">Select a POV character...</option>
                                    <option v-for="option in characterOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </SelectInput>
                            </div>
                            <div class="field-group">
                                <label class="field-label">Location</label>
                                <SelectInput v-model="form.location_entity_id" class="w-full">
                                    <option :value="null">Select a location...</option>
                                    <option v-for="option in locationOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </SelectInput>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Emotional Beat</label>
                            <div class="flex flex-wrap gap-1.5">
                                <AppButton
                                    v-for="b in emotionalBeats"
                                    :key="b"
                                    type="button"
                                    @click="form.emotional_beat = form.emotional_beat === b ? '' : b"
                                    variant="select"
                                    :selected="form.emotional_beat === b"
                                >{{ formatLabel(b) }}</AppButton>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Narrative Purpose</label>
                            <TextareaInput
                                v-model="form.narrative_purpose"
                                rows="2"
                                placeholder="What this scene accomplishes in the arc..."
                                class="input w-full resize-none"
                            />
                        </div>

                    </div>
                </div>

                <!-- ARC TRACKER FIELDS -->
                <div v-if="form.pipeline_type === 'character_study'" class="panel">
                    <h3 class="panel-label">Arc Tracker</h3>
                    <div class="space-y-4">

                    <div class="field-group">
                        <label class="field-label">Tracked Entity</label>
                        <SelectInput v-model="form.tracked_entity_id" class="w-full">
                            <option :value="null">Select an entity to track...</option>
                            <option v-for="option in entityOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </SelectInput>
                    </div>

                        <div class="field-group">
                            <label class="field-label">Arc Stage</label>
                            <div class="flex flex-wrap gap-1.5">
                                <AppButton
                                    v-for="s in arcStages"
                                    :key="s"
                                    type="button"
                                    @click="form.arc_stage = form.arc_stage === s ? '' : s"
                                    variant="select"
                                    :selected="form.arc_stage === s"
                                >{{ formatLabel(s) }}</AppButton>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Arc Notes</label>
                            <TextareaInput v-model="form.arc_notes" rows="2" placeholder="What's happening in this character's arc here..." class="input w-full resize-none" />
                        </div>

                    </div>
                </div>

                <!-- NOTES (all types) -->
                <div class="field-group">
                    <label class="field-label">Notes <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                    <TextareaInput
                        v-model="form.notes"
                        rows="3"
                        placeholder="Author notes, ideas, reminders..."
                        class="input w-full resize-none"
                    />
                </div>

                <!-- SUBMIT -->
                <div class="flex items-center gap-3 pt-2">
                    <AppButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing || !form.title || !form.pipeline_type"
                    >
                        <span v-if="form.processing">Creating...</span>
                        <span v-else>Create Item</span>
                    </AppButton>
                    <AppButton :href="route('pipeline.index')" variant="ghost">Cancel</AppButton>
                </div>

            </template>

            <div v-else class="empty-state-panel">
                <p class="text-muted-3 text-sm font-ui uppercase tracking-widest">Select a type to continue</p>
            </div>

        </form>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextareaInput from '@/Components/TextareaInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { formatLabel, toEntityOptions, toPipelineItemOptions } from '@/Components/scaffold/formatters'
import FormErrorSummary from '@/Components/ui/FormErrorSummary.vue'
import PageHeaderTrail from '@/Components/ui/PageHeaderTrail.vue'

const props = defineProps({
    parentItems:       { type: Array, default: () => [] },
    characterEntities: { type: Array, default: () => [] },
    locationEntities:  { type: Array, default: () => [] },
    entities:          { type: Array, default: () => [] },
    pipelineTypes:     { type: Array, default: () => [] },
    pipelineStages:    { type: Array, default: () => [] },
})

const parentItemOptions = computed(() => toPipelineItemOptions(props.parentItems))
const characterOptions = computed(() => toEntityOptions(props.characterEntities))
const locationOptions = computed(() => toEntityOptions(props.locationEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    title:                   '',
    pipeline_type:           '',
    pipeline_stage:          'concept',
    parent_pipeline_item_id: null,
    pov_character_entity_id: null,
    location_entity_id:      null,
    tracked_entity_id:       null,
    emotional_beat:          '',
    narrative_purpose:       '',
    arc_stage:               '',
    arc_notes:               '',
    notes:                   '',
})

const headerItems = [
    { label: 'Pipeline', href: route('pipeline.index') },
    { label: 'New Item' },
]

const submit = () => {
    form.post(route('pipeline.store'))
}

const emotionalBeats = [
    'tension_building', 'release', 'revelation',
    'quiet_moment', 'confrontation', 'turning_point', 'aftermath',
]

const arcStages = [
    'inciting_event', 'rising_pressure', 'threshold_moment',
    'transformation', 'integration', 'aftermath',
]

</script>
