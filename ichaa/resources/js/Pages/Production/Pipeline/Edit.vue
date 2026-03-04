<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('pipeline.index')" class="text-muted-3 text-xs font-mono hover:text-muted-2 transition-colors">
                    Pipeline
                </Link>
                <span class="text-muted-3 text-xs font-mono">/</span>
                <Link :href="route('pipeline.show', item.id)" class="text-muted-3 text-xs font-mono hover:text-muted-2 transition-colors truncate max-w-48">
                    {{ item.title }}
                </Link>
                <span class="text-muted-3 text-xs font-mono">/</span>
                <span class="text-primary text-sm font-light">Edit</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="max-w-2xl space-y-5">

            <div v-if="Object.keys(form.errors).length" class="p-3 bg-surface-2 border border-danger rounded-md">
                <p class="text-danger text-xs font-mono mb-1">Fix the following:</p>
                <ul class="space-y-0.5">
                    <li v-for="(msg, field) in form.errors" :key="field" class="text-danger text-xs font-mono">· {{ msg }}</li>
                </ul>
            </div>

            <!-- TITLE -->
            <div class="field-group">
                <label class="field-label">Title <span class="text-danger">*</span></label>
                <input v-model="form.title" type="text" class="input w-full" :class="{ 'input--error': form.errors.title }" />
                <p v-if="form.errors.title" class="field-error">{{ form.errors.title }}</p>
            </div>

            <!-- TYPE + STAGE row -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

                <div class="field-group">
                    <label class="field-label">Type</label>
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
                </div>

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

            </div>

            <!-- CONTENT -->
            <div class="field-group">
                <label class="field-label">
                    Content
                    <span class="text-muted-3 normal-case font-normal">(prose / writing body)</span>
                </label>
                <textarea
                    v-model="form.content"
                    rows="10"
                    placeholder="Write here..."
                    class="input w-full resize-none font-mono text-xs"
                />
            </div>

            <!-- WORD COUNT (manual for now) -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="field-group">
                    <label class="field-label">Word Count</label>
                    <input v-model.number="form.word_count" type="number" min="0" class="input w-full" />
                </div>
                <div class="field-group">
                    <label class="field-label">Reading Time <span class="text-muted-3 normal-case font-normal">(minutes)</span></label>
                    <input v-model.number="form.reading_time_minutes" type="number" min="0" class="input w-full" />
                </div>
            </div>

            <!-- SCENE FIELDS -->
            <div v-if="form.pipeline_type === 'scene'" class="panel">
                <h3 class="panel-label">Scene Details</h3>
                <div class="space-y-4">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="field-group">
                            <label class="field-label">POV Character <span class="text-muted-3 normal-case font-normal">(entity ID)</span></label>
                            <input v-model.number="form.pov_character_entity_id" type="number" placeholder="Entity ID" class="input w-full" />
                        </div>
                        <div class="field-group">
                            <label class="field-label">Location <span class="text-muted-3 normal-case font-normal">(entity ID)</span></label>
                            <input v-model.number="form.location_entity_id" type="number" placeholder="Entity ID" class="input w-full" />
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
                        <textarea v-model="form.narrative_purpose" rows="2" class="input w-full resize-none" />
                    </div>

                </div>
            </div>

            <!-- CHARACTER STUDY / ARC -->
            <div v-if="form.pipeline_type === 'character_study'" class="panel">
                <h3 class="panel-label">Arc Tracker</h3>
                <div class="space-y-4">

                    <div class="field-group">
                        <label class="field-label">Tracked Entity <span class="text-muted-3 normal-case font-normal">(entity ID)</span></label>
                        <input v-model.number="form.tracked_entity_id" type="number" placeholder="Entity ID" class="input w-full" />
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
                        <textarea v-model="form.arc_notes" rows="2" class="input w-full resize-none" />
                    </div>

                </div>
            </div>

            <!-- AUTHOR NOTES -->
            <div class="field-group">
                <label class="field-label">Author Notes <span class="text-muted-3 normal-case font-normal">(private)</span></label>
                <textarea v-model="form.notes" rows="3" class="input w-full resize-none" />
            </div>

            <!-- DIRTY INDICATOR + SUBMIT -->
            <div class="flex items-center gap-3 pt-2">
                <button
                    type="submit"
                    class="btn-primary"
                    :disabled="form.processing || !form.title"
                >
                    <span v-if="form.processing">Saving...</span>
                    <span v-else>Save Changes</span>
                </button>
                <Link :href="route('pipeline.show', item.id)" class="btn-ghost">Cancel</Link>
                <span v-if="form.isDirty" class="dirty-indicator">Unsaved changes</span>
            </div>

        </form>

    </AuthenticatedLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    item:           { type: Object, required: true },
    pipelineTypes:  { type: Array, default: () => [] },
    pipelineStages: { type: Array, default: () => [] },
})

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

const formatLabel = (str) => str
    ? str.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    : '—'
</script>

<style scoped>
.field-group { display: flex; flex-direction: column; gap: 6px; }
.field-label {
    font-size: 10px; font-family: ui-monospace, monospace;
    letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted-3);
}
.field-error { font-size: 11px; font-family: ui-monospace, monospace; color: var(--accent-pink); }
.input {
    height: 32px; padding: 0 10px;
    background: var(--bg-surface-2); border: 1px solid var(--border-color);
    border-radius: 4px; font-size: 12px; color: var(--text-primary);
    outline: none; transition: border-color 0.15s;
}
.input:focus { border-color: var(--accent-cyan); }
.input--error { border-color: var(--accent-pink); }
textarea.input { height: auto; padding: 8px 10px; }
.panel {
    background: var(--bg-surface-2); border: 1px solid var(--border-color);
    border-radius: 6px; padding: 14px 16px;
}
.panel-label {
    font-size: 9px; font-family: ui-monospace, monospace;
    letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--text-muted-3); margin-bottom: 12px;
}
.type-btn {
    padding: 4px 10px; border-radius: 2px;
    font-size: 10px; font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color); color: var(--text-muted-3);
    background: transparent; transition: border-color 0.12s, color 0.12s, background 0.12s;
}
.type-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.type-btn--selected { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(0,245,255,0.08); }
.stage-btn {
    padding: 4px 10px; border-radius: 2px;
    font-size: 10px; font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color); color: var(--text-muted-3);
    background: transparent; transition: border-color 0.12s, color 0.12s;
}
.stage-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.stage-btn--selected { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(0,245,255,0.08); }
.pill-btn {
    padding: 4px 10px; border-radius: 2px;
    font-size: 10px; font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color); color: var(--text-muted-3);
    background: transparent; transition: border-color 0.12s, color 0.12s, background 0.12s;
}
.pill-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.pill-btn--selected { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(0,245,255,0.08); }
.btn-primary {
    display: inline-flex; align-items: center; height: 32px; padding: 0 16px;
    background: rgba(0,245,255,0.1); border: 1px solid rgba(0,245,255,0.3);
    border-radius: 4px; font-size: 11px; font-family: ui-monospace, monospace;
    color: var(--accent-cyan); transition: background 0.15s;
}
.btn-primary:hover:not(:disabled) { background: rgba(0,245,255,0.15); }
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-ghost {
    display: inline-flex; align-items: center; height: 32px; padding: 0 12px;
    border: 1px solid var(--border-color); border-radius: 4px;
    font-size: 11px; font-family: ui-monospace, monospace;
    color: var(--text-muted-2); transition: border-color 0.15s, color 0.15s;
}
.btn-ghost:hover { border-color: var(--border-color-2); color: var(--text-primary); }
.dirty-indicator {
    font-size: 10px; font-family: ui-monospace, monospace;
    color: #fcd34d; letter-spacing: 0.06em;
}
.text-danger { color: var(--accent-pink); }
.border-danger { border-color: var(--accent-pink); }
</style>