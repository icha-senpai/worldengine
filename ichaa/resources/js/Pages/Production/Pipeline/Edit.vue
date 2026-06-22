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
                <TextInput v-model="form.title" type="text" class="w-full" :class="{ 'input--error': form.errors.title }" />
                <p v-if="form.errors.title" class="field-error">{{ form.errors.title }}</p>
            </div>

            <!-- TYPE + STAGE row -->
            <div class="form-grid-2">

                <div class="field-group">
                    <label class="field-label">Type</label>
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
                </div>

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

            </div>

            <!-- CONTENT -->
            <div class="field-group">
                <label class="field-label">
                    Content
                    <span class="text-muted-3 normal-case font-normal">(prose / writing body)</span>
                </label>
                <TextareaInput
                    v-model="form.content"
                    rows="10"
                    placeholder="Write here..."
                    class="input w-full resize-none font-ui text-sm"
                />
            </div>

            <!-- WORD COUNT (manual for now) -->
            <div class="form-grid-2-tight">
                <div class="field-group">
                    <label class="field-label">Word Count</label>
                    <TextInput v-model.number="form.word_count" type="number" min="0" class="w-full" />
                </div>
                <div class="field-group">
                    <label class="field-label">Reading Time <span class="text-muted-3 normal-case font-normal">(minutes)</span></label>
                    <TextInput v-model.number="form.reading_time_minutes" type="number" min="0" class="w-full" />
                </div>
            </div>

            <!-- SCENE FIELDS -->
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
                        <TextareaInput v-model="form.narrative_purpose" rows="2" class="input w-full resize-none" />
                    </div>

                </div>
            </div>

            <!-- CHARACTER STUDY / ARC -->
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
                        <TextareaInput v-model="form.arc_notes" rows="2" class="input w-full resize-none" />
                    </div>

                </div>
            </div>

            <!-- AUTHOR NOTES -->
            <div class="field-group">
                <label class="field-label">Author Notes <span class="text-muted-3 normal-case font-normal">(private)</span></label>
                <TextareaInput v-model="form.notes" rows="3" class="input w-full resize-none" />
            </div>

            <!-- DIRTY INDICATOR + SUBMIT -->
            <div class="flex items-center gap-3 pt-2">
                <AppButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing || !form.title"
                >
                    <span v-if="form.processing">Saving...</span>
                    <span v-else>Save Changes</span>
                </AppButton>
                <AppButton :href="route('pipeline.show', item.id)" variant="ghost">Cancel</AppButton>
                <span v-if="form.isDirty" class="dirty-indicator">Unsaved changes</span>
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
import { formatLabel, toEntityOptions } from '@/Components/scaffold/formatters'
import FormErrorSummary from '@/Components/ui/FormErrorSummary.vue'
import PageHeaderTrail from '@/Components/ui/PageHeaderTrail.vue'

const props = defineProps({
    item:              { type: Object, required: true },
    characterEntities: { type: Array, default: () => [] },
    locationEntities:  { type: Array, default: () => [] },
    entities:          { type: Array, default: () => [] },
    pipelineTypes:     { type: Array, default: () => [] },
    pipelineStages:    { type: Array, default: () => [] },
})

const characterOptions = computed(() => toEntityOptions(props.characterEntities))
const locationOptions = computed(() => toEntityOptions(props.locationEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    title:                   props.item.title            ?? '',
    pipeline_type:           props.item.pipeline_type    ?? '',
    pipeline_stage:          props.item.pipeline_stage   ?? 'concept',
    content:                 props.item.content          ?? '',
    word_count:              props.item.word_count        ?? 0,
    reading_time_minutes:    props.item.reading_time_minutes ?? 0,
    pov_character_entity_id: props.item.pov_character_entity_id ?? null,
    location_entity_id:      props.item.location_entity_id      ?? null,
    tracked_entity_id:       props.item.tracked_entity_id       ?? null,
    emotional_beat:          props.item.emotional_beat   ?? '',
    narrative_purpose:       props.item.narrative_purpose ?? '',
    arc_stage:               props.item.arc_stage        ?? '',
    arc_notes:               props.item.arc_notes        ?? '',
    notes:                   props.item.notes            ?? '',
})

const headerItems = computed(() => [
    { label: 'Pipeline', href: route('pipeline.index') },
    { label: props.item.title, href: route('pipeline.show', props.item.id), truncate: true },
    { label: 'Edit' },
])

const submit = () => {
    form.put(route('pipeline.update', props.item.id))
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
