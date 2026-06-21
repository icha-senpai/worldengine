<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-primary text-2xl font-light tracking-wide">Writing Pipeline</h1>
                    <p class="text-muted-3 text-sm font-mono mt-1">
                        {{ items.total }} item{{ items.total !== 1 ? 's' : '' }}
                        <span v-if="hasFilters"> · filtered</span>
                    </p>
                </div>
                <Link :href="route('pipeline.create')" class="btn-primary">
                    New Item
                </Link>
            </div>
        </template>

        <!-- FILTERS -->
        <div class="flex items-center gap-3 mb-5 flex-wrap">

            <div class="flex gap-1.5 flex-wrap">
                <button
                    @click="setFilter('type', '')"
                    class="filter-btn"
                    :class="{ 'filter-btn--active': !filters.type }"
                >All</button>
                <button
                    v-for="t in pipelineTypes"
                    :key="t"
                    @click="setFilter('type', t)"
                    class="filter-btn"
                    :class="{ 'filter-btn--active': filters.type === t }"
                >{{ formatLabel(t) }}</button>
            </div>

            <span class="hidden text-border text-sm font-mono sm:inline">|</span>

            <div class="flex gap-1.5 flex-wrap">
                <button
                    @click="setFilter('stage', '')"
                    class="filter-btn"
                    :class="{ 'filter-btn--active': !filters.stage }"
                >All Stages</button>
                <button
                    v-for="s in pipelineStages"
                    :key="s"
                    @click="setFilter('stage', s)"
                    class="filter-btn stage-btn"
                    :class="{ 'filter-btn--active': filters.stage === s, ['stage--' + s]: true }"
                >{{ formatLabel(s) }}</button>
            </div>

            <button v-if="hasFilters" @click="clearFilters" class="clear-btn">
                Clear
            </button>

        </div>

        <!-- LIST -->
        <div v-if="items.data.length" class="space-y-2">
            <Link
                v-for="item in items.data"
                :key="item.id"
                :href="route('pipeline.show', item.id)"
                class="pipeline-row"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                    <!-- Left: title + meta -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="type-chip" :class="'type--' + item.pipeline_type">
                                {{ formatLabel(item.pipeline_type) }}
                            </span>
                            <span class="stage-chip" :class="'stage--' + item.pipeline_stage">
                                {{ formatLabel(item.pipeline_stage) }}
                            </span>
                        </div>
                        <p class="prose-wrap text-primary text-base font-light leading-snug">{{ item.title }}</p>
                        <div class="flex flex-wrap items-center gap-3 mt-1.5">
                            <span v-if="item.pov_character" class="meta-tag">
                                POV: {{ item.pov_character.name }}
                            </span>
                            <span v-if="item.location" class="meta-tag">
                                @ {{ item.location.name }}
                            </span>
                            <span v-if="item.emotional_beat" class="meta-tag">
                                {{ formatLabel(item.emotional_beat) }}
                            </span>
                            <span v-if="item.word_count" class="meta-tag">
                                {{ item.word_count.toLocaleString() }}w
                            </span>
                        </div>
                    </div>

                    <!-- Right: children count if any -->
                    <div v-if="item.children_count > 0" class="flex-shrink-0 text-left sm:text-right">
                        <span class="children-badge">{{ item.children_count }}</span>
                    </div>

                </div>
            </Link>
        </div>

        <div v-else class="empty-state">
            <p class="text-muted-3 text-sm font-mono uppercase tracking-widest mb-2">No pipeline items found</p>
            <Link :href="route('pipeline.create')" class="text-cyan text-sm font-mono hover:underline">
                Create your first item →
            </Link>
        </div>

        <!-- PAGINATION -->
        <div v-if="items.last_page > 1" class="mt-6 flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
            <span class="text-muted-3 text-sm font-mono">
                Page {{ items.current_page }} of {{ items.last_page }}
            </span>
            <div class="flex gap-2">
                <Link
                    v-if="items.prev_page_url"
                    :href="items.prev_page_url"
                    class="btn-ghost"
                >← Prev</Link>
                <Link
                    v-if="items.next_page_url"
                    :href="items.next_page_url"
                    class="btn-ghost"
                >Next →</Link>
            </div>
        </div>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    items:          { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    pipelineTypes:  { type: Array, default: () => [] },
    pipelineStages: { type: Array, default: () => [] },
})

const hasFilters = computed(() =>
    Object.values(props.filters).some(v => v !== '' && v !== null && v !== undefined)
)

const setFilter = (key, value) => {
    router.get(route('pipeline.index'), {
        ...props.filters,
        [key]: value || undefined,
    }, { preserveState: true, replace: true })
}

const clearFilters = () => {
    router.get(route('pipeline.index'), {}, { replace: true })
}

const formatLabel = (str) => str
    ? str.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    : '—'
</script>

<style scoped>
/* --- Filter buttons --- */
.filter-btn {
    padding: 5px 12px;
    border-radius: 3px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.04em;
    border: 1px solid var(--border-color);
    color: var(--text-muted-3);
    background: transparent;
    transition: border-color 0.12s, color 0.12s, background 0.12s;
    white-space: nowrap;
}
.filter-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.filter-btn--active { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(0,245,255,0.08); }
.clear-btn {
    padding: 5px 12px;
    border-radius: 3px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color);
    color: var(--text-muted-3);
    background: transparent;
    transition: border-color 0.12s, color 0.12s;
}
.clear-btn:hover { border-color: var(--accent-pink); color: var(--accent-pink); }

/* --- Pipeline row --- */
.pipeline-row {
    display: block;
    padding: 16px 18px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    transition: border-color 0.15s, background 0.15s;
}
.pipeline-row:hover {
    border-color: var(--border-color-2);
    background: var(--bg-surface);
}

.prose-wrap {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}

/* --- Type chips --- */
.type-chip {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border: 1px solid;
}
.type--scene              { color: #7dd3fc; border-color: rgba(125,211,252,0.3); background: rgba(125,211,252,0.06); }
.type--chapter            { color: #c4b5fd; border-color: rgba(196,181,253,0.3); background: rgba(196,181,253,0.06); }
.type--arc                { color: var(--accent-cyan); border-color: rgba(0,245,255,0.3); background: rgba(0,245,255,0.06); }
.type--interlude          { color: #fcd34d; border-color: rgba(252,211,77,0.3); background: rgba(252,211,77,0.06); }
.type--prologue           { color: #6ee7b7; border-color: rgba(110,231,183,0.3); background: rgba(110,231,183,0.06); }
.type--epilogue           { color: #6ee7b7; border-color: rgba(110,231,183,0.3); background: rgba(110,231,183,0.06); }
.type--outline            { color: var(--text-muted-2); border-color: var(--border-color-2); background: transparent; }
.type--note               { color: var(--text-muted-3); border-color: var(--border-color); background: transparent; }
.type--inspiration        { color: #fdba74; border-color: rgba(253,186,116,0.3); background: rgba(253,186,116,0.06); }
.type--character_study    { color: #f9a8d4; border-color: rgba(249,168,212,0.3); background: rgba(249,168,212,0.06); }

/* --- Stage chips --- */
.stage-chip {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border: 1px solid;
}
.stage--concept   { color: var(--text-muted-3); border-color: var(--border-color); background: transparent; }
.stage--outlined  { color: #fcd34d; border-color: rgba(252,211,77,0.25); background: rgba(252,211,77,0.05); }
.stage--drafted   { color: #7dd3fc; border-color: rgba(125,211,252,0.25); background: rgba(125,211,252,0.05); }
.stage--revised   { color: #c4b5fd; border-color: rgba(196,181,253,0.25); background: rgba(196,181,253,0.05); }
.stage--complete  { color: #6ee7b7; border-color: rgba(110,231,183,0.25); background: rgba(110,231,183,0.05); }
.stage--cut       { color: var(--accent-pink); border-color: rgba(255,0,128,0.2); background: rgba(255,0,128,0.04); text-decoration: line-through; }

/* --- Meta tags --- */
.meta-tag {
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-3);
}

/* --- Children badge --- */
.children-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 4px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-3);
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
}

/* --- Buttons --- */
.btn-primary {
    display: inline-flex; align-items: center;
    height: 40px; padding: 0 18px;
    background: rgba(0,245,255,0.1); border: 1px solid rgba(0,245,255,0.3);
    border-radius: 6px; font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--accent-cyan); transition: background 0.15s;
}
.btn-primary:hover { background: rgba(0,245,255,0.15); }
.btn-ghost {
    display: inline-flex; align-items: center;
    height: 36px; padding: 0 16px;
    border: 1px solid var(--border-color); border-radius: 6px;
    font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--text-muted-2); transition: border-color 0.15s, color 0.15s;
}
.btn-ghost:hover { border-color: var(--border-color-2); color: var(--text-primary); }

/* --- Empty state --- */
.empty-state {
    padding: 48px 16px; text-align: center;
    border: 1px dashed var(--border-color); border-radius: 8px;
}

/* --- Utility --- */
.text-cyan { color: var(--accent-cyan); }
.text-border { color: var(--border-color); }
</style>
