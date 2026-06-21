<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-start justify-between gap-4">

                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="route('pipeline.index')" class="text-muted-3 text-sm font-mono hover:text-muted-2 transition-colors">
                            Pipeline
                        </Link>
                        <span class="text-muted-3 text-sm font-mono">/</span>
                        <span v-if="item.parent" class="text-muted-3 text-sm font-mono hover:text-muted-2 transition-colors">
                            <Link :href="route('pipeline.show', item.parent.id)">{{ item.parent.title }}</Link>
                        </span>
                        <span v-if="item.parent" class="text-muted-3 text-sm font-mono">/</span>
                        <span class="type-chip" :class="'type--' + item.pipeline_type">
                            {{ formatLabel(item.pipeline_type) }}
                        </span>
                    </div>
                    <h1 class="text-primary text-2xl font-light tracking-wide leading-tight">
                        {{ item.title }}
                    </h1>
                </div>

                <div class="flex items-center gap-2 flex-shrink-0 pt-1">
                    <span class="stage-badge" :class="'stage--' + item.pipeline_stage">
                        {{ formatLabel(item.pipeline_stage) }}
                    </span>
                    <button
                        v-if="canAdvance"
                        @click="advance"
                        class="btn-advance"
                    >
                        Advance →
                    </button>
                    <Link :href="route('pipeline.edit', item.id)" class="btn-ghost">Edit</Link>
                </div>

            </div>
        </template>

        <div class="space-y-5">

            <!-- STATS BAR (word count / reading time) -->
            <div v-if="item.word_count" class="flex items-center gap-4 p-3 bg-surface-2 border border-border rounded-md">
                <div class="stat-block">
                    <span class="stat-label">Words</span>
                    <span class="stat-value">{{ item.word_count.toLocaleString() }}</span>
                </div>
                <div v-if="item.reading_time_minutes" class="stat-block">
                    <span class="stat-label">Reading Time</span>
                    <span class="stat-value">{{ readingTime }}</span>
                </div>
                <div v-if="item.pipeline_stage" class="stat-block">
                    <span class="stat-label">Stage</span>
                    <span class="stat-value">{{ formatLabel(item.pipeline_stage) }}</span>
                </div>
            </div>

            <!-- CONTENT (prose/notes body) -->
            <div v-if="item.content" class="panel">
                <h3 class="panel-label">Content</h3>
                <div class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.content }}</div>
            </div>

            <!-- SCENE DETAILS -->
            <div v-if="item.pipeline_type === 'scene'" class="grid grid-cols-2 gap-4">

                <div class="panel">
                    <h3 class="panel-label">Scene</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2">
                            <span class="field-label">POV</span>
                            <span v-if="item.pov_character" class="text-muted-2 text-sm">
                                <Link :href="route('entities.show', item.pov_character.id)" class="hover:text-primary transition-colors">
                                    {{ item.pov_character.name }}
                                </Link>
                            </span>
                            <span v-else class="text-muted-3 text-sm font-mono">—</span>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="field-label">Location</span>
                            <span v-if="item.location" class="text-muted-2 text-sm">
                                <Link :href="route('entities.show', item.location.id)" class="hover:text-primary transition-colors">
                                    {{ item.location.name }}
                                </Link>
                            </span>
                            <span v-else class="text-muted-3 text-sm font-mono">—</span>
                        </div>
                        <div v-if="item.emotional_beat" class="flex items-start gap-2">
                            <span class="field-label">Beat</span>
                            <span class="beat-chip">{{ formatLabel(item.emotional_beat) }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="item.narrative_purpose" class="panel">
                    <h3 class="panel-label">Narrative Purpose</h3>
                    <p class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.narrative_purpose }}</p>
                </div>

            </div>

            <!-- CHARACTER STUDY / ARC TRACKER -->
            <div v-if="item.pipeline_type === 'character_study' && (item.tracked_entity || item.arc_stage || item.arc_notes)" class="panel">
                <h3 class="panel-label">Arc Tracker</h3>
                <div class="space-y-2">
                    <div v-if="item.tracked_entity" class="flex items-start gap-2">
                        <span class="field-label">Character</span>
                        <Link :href="route('entities.show', item.tracked_entity.id)" class="text-muted-2 text-sm hover:text-primary transition-colors">
                            {{ item.tracked_entity.name }}
                        </Link>
                    </div>
                    <div v-if="item.arc_stage" class="flex items-start gap-2">
                        <span class="field-label">Arc Stage</span>
                        <span class="arc-chip">{{ formatLabel(item.arc_stage) }}</span>
                    </div>
                    <div v-if="item.arc_notes" class="flex items-start gap-2">
                        <span class="field-label">Notes</span>
                        <span class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.arc_notes }}</span>
                    </div>
                </div>
            </div>

            <!-- AUTHOR NOTES -->
            <div v-if="item.notes" class="panel">
                <h3 class="panel-label">Author Notes</h3>
                <p class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.notes }}</p>
            </div>

            <!-- CHILDREN (sub-items: scenes under a chapter, etc.) -->
            <div v-if="item.children && item.children.length" class="space-y-2">
                <h3 class="section-label">Sub-Items ({{ item.children.length }})</h3>
                <Link
                    v-for="child in item.children"
                    :key="child.id"
                    :href="route('pipeline.show', child.id)"
                    class="child-row"
                >
                    <div class="flex items-center gap-3">
                        <span class="type-chip" :class="'type--' + child.pipeline_type">
                            {{ formatLabel(child.pipeline_type) }}
                        </span>
                        <span class="stage-badge stage-badge--sm" :class="'stage--' + child.pipeline_stage">
                            {{ formatLabel(child.pipeline_stage) }}
                        </span>
                        <span class="text-muted-2 text-sm">{{ child.title }}</span>
                        <span v-if="child.pov_character" class="text-muted-3 text-sm font-mono ml-auto">
                            {{ child.pov_character.name }}
                        </span>
                        <span v-if="child.word_count" class="text-muted-3 text-sm font-mono ml-auto">
                            {{ child.word_count.toLocaleString() }}w
                        </span>
                    </div>
                </Link>
                <Link :href="route('pipeline.create', { parent: item.id })" class="add-child-btn">
                    + Add sub-item
                </Link>
            </div>

            <!-- ACCESS -->
            <div class="panel">
                <h3 class="panel-label">Access</h3>
                <div class="flex items-center gap-4">
                    <div class="flex items-start gap-2">
                        <span class="field-label">Visibility</span>
                        <span class="text-muted-2 text-sm">{{ formatLabel(item.visibility) }}</span>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="field-label">Classification</span>
                        <span class="text-muted-2 text-sm">{{ formatLabel(item.content_classification) }}</span>
                    </div>
                </div>
            </div>

            <!-- DANGER ZONE -->
            <div class="flex items-center justify-end pt-2 border-t border-border">
                <button @click="destroy" class="btn-danger">Move to Trash</button>
            </div>

        </div>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    item: { type: Object, required: true },
})

const stageProgression = {
    concept:  'outlined',
    outlined: 'drafted',
    drafted:  'revised',
    revised:  'complete',
}

const canAdvance = computed(() =>
    props.item.pipeline_stage in stageProgression
)

const readingTime = computed(() => {
    const m = props.item.reading_time_minutes
    if (!m) return '—'
    const h = Math.floor(m / 60)
    const rem = m % 60
    return h > 0 ? `${h}h ${rem}m` : `${rem}m`
})

const advance = () => {
    router.post(route('pipeline.advance', props.item.id))
}

const destroy = () => {
    if (!confirm(`Move "${props.item.title}" to trash?`)) return
    router.delete(route('pipeline.destroy', props.item.id))
}

const formatLabel = (str) => str
    ? str.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    : '—'
</script>

<style scoped>
/* --- Type chip --- */
.type-chip {
    padding: 2px 8px; border-radius: 3px;
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.06em; text-transform: uppercase; border: 1px solid;
}
.type--scene              { color: #7dd3fc; border-color: rgba(125,211,252,0.3); background: rgba(125,211,252,0.06); }
.type--chapter            { color: #c4b5fd; border-color: rgba(196,181,253,0.3); background: rgba(196,181,253,0.06); }
.type--arc                { color: var(--accent-cyan); border-color: rgba(0,245,255,0.3); background: rgba(0,245,255,0.06); }
.type--interlude          { color: #fcd34d; border-color: rgba(252,211,77,0.3); background: rgba(252,211,77,0.06); }
.type--prologue           { color: #6ee7b7; border-color: rgba(110,231,183,0.3); background: rgba(110,231,183,0.06); }
.type--epilogue           { color: #6ee7b7; border-color: rgba(110,231,183,0.3); background: rgba(110,231,183,0.06); }
.type--outline            { color: var(--text-muted-2); border-color: var(--border-color-2); }
.type--note               { color: var(--text-muted-3); border-color: var(--border-color); }
.type--inspiration        { color: #fdba74; border-color: rgba(253,186,116,0.3); background: rgba(253,186,116,0.06); }
.type--character_study    { color: #f9a8d4; border-color: rgba(249,168,212,0.3); background: rgba(249,168,212,0.06); }

/* --- Stage badge --- */
.stage-badge {
    display: inline-flex; align-items: center;
    padding: 4px 12px; border-radius: 4px;
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.08em; text-transform: uppercase; border: 1px solid;
}
.stage-badge--sm { padding: 2px 8px; font-size: 10px; }
.stage--concept  { color: var(--text-muted-3); border-color: var(--border-color); background: transparent; }
.stage--outlined { color: #fcd34d; border-color: rgba(252,211,77,0.3); background: rgba(252,211,77,0.06); }
.stage--drafted  { color: #7dd3fc; border-color: rgba(125,211,252,0.3); background: rgba(125,211,252,0.06); }
.stage--revised  { color: #c4b5fd; border-color: rgba(196,181,253,0.3); background: rgba(196,181,253,0.06); }
.stage--complete { color: #6ee7b7; border-color: rgba(110,231,183,0.3); background: rgba(110,231,183,0.06); }
.stage--cut      { color: var(--accent-pink); border-color: rgba(255,0,128,0.2); background: rgba(255,0,128,0.04); }

/* --- Panels --- */
.panel {
    background: var(--bg-surface-2); border: 1px solid var(--border-color);
    border-radius: 8px; padding: 18px 20px;
}
.panel-label {
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--text-muted-3); margin-bottom: 10px;
}
.field-label {
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--text-muted-3); white-space: nowrap;
    min-width: 96px; flex-shrink: 0; padding-top: 3px;
}
.section-label {
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-muted-3);
}

.prose-block {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}

/* --- Stats bar --- */
.stat-block { display: flex; flex-direction: column; gap: 2px; }
.stat-label {
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted-3);
}
.stat-value { font-size: 16px; color: var(--text-primary); font-weight: 300; }

/* --- Beat / arc chips --- */
.beat-chip, .arc-chip {
    padding: 2px 8px; border-radius: 3px;
    font-size: 11px; font-family: ui-monospace, monospace;
    color: var(--accent-cyan);
    border: 1px solid rgba(0,245,255,0.2);
    background: rgba(0,245,255,0.06);
}

/* --- Children --- */
.child-row {
    display: block; padding: 14px 18px;
    background: var(--bg-surface-2); border: 1px solid var(--border-color);
    border-radius: 8px; transition: border-color 0.15s, background 0.15s;
}
.child-row:hover { border-color: var(--border-color-2); background: var(--bg-surface); }
.add-child-btn {
    display: block; padding: 10px 16px; margin-top: 4px;
    border: 1px dashed var(--border-color); border-radius: 8px;
    font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--text-muted-3); text-align: center;
    transition: border-color 0.15s, color 0.15s;
}
.add-child-btn:hover { border-color: var(--accent-cyan); color: var(--accent-cyan); }

/* --- Buttons --- */
.btn-advance {
    display: inline-flex; align-items: center; height: 36px; padding: 0 16px;
    background: rgba(110,231,183,0.08); border: 1px solid rgba(110,231,183,0.3);
    border-radius: 6px; font-size: 12px; font-family: ui-monospace, monospace;
    color: #6ee7b7; transition: background 0.15s;
}
.btn-advance:hover { background: rgba(110,231,183,0.15); }
.btn-ghost {
    display: inline-flex; align-items: center; height: 36px; padding: 0 16px;
    border: 1px solid var(--border-color); border-radius: 6px;
    font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--text-muted-2); transition: border-color 0.15s, color 0.15s;
}
.btn-ghost:hover { border-color: var(--border-color-2); color: var(--text-primary); }
.btn-danger {
    display: inline-flex; align-items: center; height: 36px; padding: 0 16px;
    border: 1px solid var(--border-color); border-radius: 6px;
    font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--text-muted-3); transition: border-color 0.15s, color 0.15s;
}
.btn-danger:hover { border-color: var(--accent-pink); color: var(--accent-pink); }
</style>
