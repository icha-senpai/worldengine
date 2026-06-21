<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('pipeline.index')" class="text-muted-3 text-sm font-mono hover:text-muted-2 transition-colors">
                    Pipeline
                </Link>
                <span class="text-muted-3 text-sm font-mono">/</span>
                <span class="text-primary text-base font-light">New Item</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="max-w-2xl space-y-5">

            <!-- ERRORS -->
            <div v-if="Object.keys(form.errors).length" class="p-3 bg-surface-2 border border-danger rounded-md">
                <p class="text-danger text-sm font-mono mb-2">Fix the following:</p>
                <ul class="space-y-1">
                    <li v-for="(msg, field) in form.errors" :key="field" class="text-danger text-sm font-mono">
                        · {{ msg }}
                    </li>
                </ul>
            </div>

            <!-- TITLE -->
            <div class="field-group">
                <label class="field-label">Title <span class="text-danger">*</span></label>
                <input
                    v-model="form.title"
                    type="text"
                    placeholder="Item title"
                    class="input w-full"
                    :class="{ 'input--error': form.errors.title }"
                    autofocus
                />
                <p v-if="form.errors.title" class="field-error">{{ form.errors.title }}</p>
            </div>

            <!-- TYPE -->
            <div class="field-group">
                <label class="field-label">Type <span class="text-danger">*</span></label>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="t in pipelineTypes"
                        :key="t"
                        type="button"
                        @click="form.pipeline_type = t"
                        class="type-btn"
                        :class="{ 'type-btn--selected': form.pipeline_type === t }"
                    >{{ formatLabel(t) }}</button>
                </div>
                <p v-if="form.errors.pipeline_type" class="field-error">{{ form.errors.pipeline_type }}</p>
            </div>

            <template v-if="form.pipeline_type">

                <!-- STAGE -->
                <div class="field-group">
                    <label class="field-label">Stage</label>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="s in pipelineStages"
                            :key="s"
                            type="button"
                            @click="form.pipeline_stage = s"
                            class="stage-btn"
                            :class="{ 'stage-btn--selected': form.pipeline_stage === s }"
                        >{{ formatLabel(s) }}</button>
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label">Parent Item</label>
                    <select v-model="form.parent_pipeline_item_id" class="input w-full">
                        <option :value="null">Top-level item</option>
                        <option
                            v-for="option in parentItemOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <!-- SCENE FIELDS — only for scene type -->
                <div v-if="form.pipeline_type === 'scene'" class="panel">
                    <h3 class="panel-label">Scene Details</h3>
                    <div class="space-y-4">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="field-group">
                                <label class="field-label">POV Character</label>
                                <select v-model="form.pov_character_entity_id" class="input w-full">
                                    <option :value="null">Select a POV character...</option>
                                    <option v-for="option in characterOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="field-group">
                                <label class="field-label">Location</label>
                                <select v-model="form.location_entity_id" class="input w-full">
                                    <option :value="null">Select a location...</option>
                                    <option v-for="option in locationOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Emotional Beat</label>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="b in emotionalBeats"
                                    :key="b"
                                    type="button"
                                    @click="form.emotional_beat = form.emotional_beat === b ? '' : b"
                                    class="pill-btn"
                                    :class="{ 'pill-btn--selected': form.emotional_beat === b }"
                                >{{ formatLabel(b) }}</button>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Narrative Purpose</label>
                            <textarea
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
                        <select v-model="form.tracked_entity_id" class="input w-full">
                            <option :value="null">Select an entity to track...</option>
                            <option v-for="option in entityOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                        <div class="field-group">
                            <label class="field-label">Arc Stage</label>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="s in arcStages"
                                    :key="s"
                                    type="button"
                                    @click="form.arc_stage = form.arc_stage === s ? '' : s"
                                    class="pill-btn"
                                    :class="{ 'pill-btn--selected': form.arc_stage === s }"
                                >{{ formatLabel(s) }}</button>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Arc Notes</label>
                            <textarea v-model="form.arc_notes" rows="2" placeholder="What's happening in this character's arc here..." class="input w-full resize-none" />
                        </div>

                    </div>
                </div>

                <!-- NOTES (all types) -->
                <div class="field-group">
                    <label class="field-label">Notes <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        placeholder="Author notes, ideas, reminders..."
                        class="input w-full resize-none"
                    />
                </div>

                <!-- SUBMIT -->
                <div class="flex items-center gap-3 pt-2">
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="form.processing || !form.title || !form.pipeline_type"
                    >
                        <span v-if="form.processing">Creating...</span>
                        <span v-else>Create Item</span>
                    </button>
                    <Link :href="route('pipeline.index')" class="btn-ghost">Cancel</Link>
                </div>

            </template>

            <div v-else class="p-6 text-center border border-dashed border-border rounded-md">
                <p class="text-muted-3 text-sm font-mono uppercase tracking-widest">Select a type to continue</p>
            </div>

        </form>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { formatLabel, toEntityOptions, toPipelineItemOptions } from '@/Components/scaffold/formatters'

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

<style scoped>
.field-group { display: flex; flex-direction: column; gap: 8px; }
.field-label {
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted-3);
}
.field-error { font-size: 12px; font-family: ui-monospace, monospace; color: var(--accent-pink); }
.input {
    height: 40px; padding: 0 12px;
    background: var(--bg-surface-2); border: 1px solid var(--border-color);
    border-radius: 6px; font-size: 14px; color: var(--text-primary);
    outline: none; transition: border-color 0.15s;
}
.input:focus { border-color: var(--accent-cyan); }
.input--error { border-color: var(--accent-pink); }
textarea.input { height: auto; padding: 10px 12px; }
.panel {
    background: var(--bg-surface-2); border: 1px solid var(--border-color);
    border-radius: 8px; padding: 18px 20px;
}
.panel-label {
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--text-muted-3); margin-bottom: 12px;
}
.type-btn {
    padding: 8px 14px; border-radius: 4px;
    font-size: 11px; font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color); color: var(--text-muted-3);
    background: var(--bg-surface-2); transition: border-color 0.12s, color 0.12s, background 0.12s;
}
.type-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.type-btn--selected { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(0,245,255,0.08); }
.stage-btn {
    padding: 6px 12px; border-radius: 3px;
    font-size: 11px; font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color); color: var(--text-muted-3);
    background: transparent; transition: border-color 0.12s, color 0.12s;
}
.stage-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.stage-btn--selected { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(0,245,255,0.08); }
.pill-btn {
    padding: 6px 12px; border-radius: 3px;
    font-size: 11px; font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color); color: var(--text-muted-3);
    background: transparent; transition: border-color 0.12s, color 0.12s, background 0.12s;
}
.pill-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.pill-btn--selected { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(0,245,255,0.08); }
.btn-primary {
    display: inline-flex; align-items: center; height: 40px; padding: 0 18px;
    background: rgba(0,245,255,0.1); border: 1px solid rgba(0,245,255,0.3);
    border-radius: 6px; font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--accent-cyan); transition: background 0.15s;
}
.btn-primary:hover:not(:disabled) { background: rgba(0,245,255,0.15); }
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-ghost {
    display: inline-flex; align-items: center; height: 40px; padding: 0 16px;
    border: 1px solid var(--border-color); border-radius: 6px;
    font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--text-muted-2); transition: border-color 0.15s, color 0.15s;
}
.btn-ghost:hover { border-color: var(--border-color-2); color: var(--text-primary); }
.text-danger { color: var(--accent-pink); }
.border-danger { border-color: var(--accent-pink); }
</style>
