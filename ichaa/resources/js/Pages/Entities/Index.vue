<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-baseline gap-3">
                    <h1 class="text-primary text-2xl font-light tracking-wide">Entities</h1>
                    <span class="text-muted-3 text-sm font-mono">{{ entities.total }} total</span>
                </div>
                <Link :href="route('entities.create')" class="btn-primary">
                    + New Entity
                </Link>
            </div>
        </template>

        <!-- FILTERS -->
        <div class="flex items-center gap-3 mb-5 flex-wrap">

            <!-- Search -->
            <div class="relative w-full sm:flex-1 sm:min-w-48 sm:max-w-72">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-3" viewBox="0 0 16 16" fill="none">
                    <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <input
                    v-model="filterForm.q"
                    type="text"
                    placeholder="Search entities..."
                    class="input pl-8 w-full"
                    @keydown.enter="applyFilters"
                />
            </div>

            <!-- Type filter — grouped by category -->
            <select v-model="filterForm.type" class="input w-full sm:w-auto" @change="applyFilters">
                <option value="">All types</option>
                <template v-for="(types, category) in entityTypes" :key="category">
                    <optgroup :label="formatLabel(category)">
                        <option v-for="t in types" :key="t" :value="t">{{ formatLabel(t) }}</option>
                    </optgroup>
                </template>
            </select>

            <!-- Status filter -->
            <select v-model="filterForm.status" class="input w-full sm:w-auto" @change="applyFilters">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ formatLabel(s) }}</option>
            </select>

            <!-- Universe filter -->
            <select v-model="filterForm.universe" class="input w-full sm:w-auto" @change="applyFilters">
                <option value="">All universes</option>
                <option v-for="u in universes" :key="u" :value="u">{{ u }}</option>
            </select>

            <!-- Active filters indicator -->
            <button
                v-if="hasActiveFilters"
                @click="clearFilters"
                class="text-sm font-mono text-muted-3 hover:text-focus transition-colors px-3 py-1.5 border border-border rounded-md"
            >
                Clear filters ×
            </button>

        </div>

        <!-- TABLE -->
        <div class="bg-surface-2 border border-border rounded-md overflow-hidden">

            <!-- Header row -->
            <div class="hidden md:grid grid-cols-entity-list items-center px-4 py-3 border-b border-border bg-surface">
                <span class="col-label">Entity</span>
                <span class="col-label">Type</span>
                <span class="col-label">Status</span>
                <span class="col-label">Universe</span>
                <span class="col-label text-right">Complete</span>
            </div>

            <!-- Empty state -->
            <div v-if="entities.data.length === 0" class="px-4 py-16 text-center">
                <p class="text-muted-3 text-sm font-mono uppercase tracking-widest">No entities found</p>
                <Link :href="route('entities.create')" class="mt-3 inline-block text-focus text-sm font-mono hover:underline">
                    Create the first one →
                </Link>
            </div>

            <!-- Entity rows -->
            <Link
                v-for="entity in entities.data"
                :key="entity.id"
                :href="route('entities.show', entity.id)"
                class="flex flex-col gap-3 px-4 py-4 border-b border-border last:border-b-0 hover:bg-surface transition-colors group md:grid md:grid-cols-entity-list md:items-center md:gap-0 md:py-3"
            >
                <!-- Name + summary -->
                <div class="min-w-0 md:pr-4">
                    <div class="flex items-center gap-2">
                        <span class="text-primary text-base font-light group-hover:text-focus transition-colors prose-wrap">
                            {{ entity.name }}
                        </span>
                        <span v-if="entity.public_title" class="text-muted-3 text-xs font-mono prose-wrap hidden lg:block">
                            · {{ entity.public_title }}
                        </span>
                    </div>
                    <p v-if="entity.summary" class="prose-wrap text-muted-3 text-sm leading-snug mt-1">
                        {{ entity.summary }}
                    </p>
                </div>

                <!-- Type -->
                <div class="flex items-center justify-between gap-3 md:block">
                    <span class="mobile-label md:hidden">Type</span>
                    <span class="type-badge" :class="typeBadgeClass(entity.entity_type)">
                        {{ formatLabel(entity.entity_type) }}
                    </span>
                </div>

                <!-- Status -->
                <div class="flex items-center justify-between gap-3 md:block">
                    <span class="mobile-label md:hidden">Status</span>
                    <div class="flex items-center md:block">
                    <span class="status-dot" :class="statusDotClass(entity.status)" />
                    <span class="text-muted-2 text-sm font-mono">{{ formatLabel(entity.status) }}</span>
                    </div>
                </div>

                <!-- Universe -->
                <div class="flex items-center justify-between gap-3 md:block">
                    <span class="mobile-label md:hidden">Universe</span>
                    <div class="prose-wrap text-muted-3 text-sm font-mono">
                        {{ formatUniverses(entity.source_universes) }}
                    </div>
                </div>

                <!-- Completion score -->
                <div class="flex items-center justify-between gap-3 md:justify-end">
                    <span class="mobile-label md:hidden">Complete</span>
                    <div class="flex items-center gap-2">
                    <div class="w-16 h-1 bg-surface rounded-full overflow-hidden">
                        <div
                            class="h-full rounded-full transition-all"
                            :class="completionBarClass(entity.completion_score)"
                            :style="{ width: entity.completion_score + '%' }"
                        />
                    </div>
                    <span class="text-muted-3 text-xs font-mono w-8 text-right">
                        {{ entity.completion_score }}%
                    </span>
                    </div>
                </div>
            </Link>
        </div>

        <!-- PAGINATION -->
        <div v-if="entities.last_page > 1" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <span class="text-muted-3 text-sm font-mono">
                Page {{ entities.current_page }} of {{ entities.last_page }}
            </span>
            <div class="flex items-center gap-1">
                <Link
                    v-if="entities.prev_page_url"
                    :href="entities.prev_page_url"
                    class="px-3 py-2 text-sm font-mono border border-border rounded-md text-muted-2 hover:border-border-2 hover:text-primary transition-colors"
                >
                    ← Prev
                </Link>
                <Link
                    v-if="entities.next_page_url"
                    :href="entities.next_page_url"
                    class="px-3 py-2 text-sm font-mono border border-border rounded-md text-muted-2 hover:border-border-2 hover:text-primary transition-colors"
                >
                    Next →
                </Link>
            </div>
        </div>

    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    entities:    { type: Object, required: true },
    filters:     { type: Object, default: () => ({}) },
    entityTypes: { type: Array,  default: () => [] },
    statuses:    { type: Array,  default: () => [] },
    universes:   { type: Array,  default: () => [] },
})

const filterForm = ref({
    q:        props.filters.q        ?? '',
    type:     props.filters.type     ?? '',
    status:   props.filters.status   ?? '',
    universe: props.filters.universe ?? '',
})

const hasActiveFilters = computed(() =>
    Object.values(filterForm.value).some(v => v !== '')
)

const applyFilters = () => {
    const params = {}
    Object.entries(filterForm.value).forEach(([k, v]) => {
        if (v !== '') params[k] = v
    })
    router.get(route('entities.index'), params, { preserveState: true, replace: true })
}

const clearFilters = () => {
    filterForm.value = { q: '', type: '', status: '', universe: '' }
    router.get(route('entities.index'))
}

const formatLabel = (str) => str
    ? str.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    : '—'

const formatUniverses = (universes) => {
    if (!universes || universes.length === 0) return 'Native'
    return universes.slice(0, 2).join(', ') + (universes.length > 2 ? ` +${universes.length - 2}` : '')
}

const typeBadgeClass = (type) => {
    const map = {
        character:            'type--character',
        faction:              'type--faction',
        location:             'type--location',
        event:                'type--event',
        object:               'type--object',
        constructed_intel:    'type--ci',
        power_system:         'type--power',
    }
    return map[type] ?? 'type--default'
}

const statusDotClass = (status) => {
    const map = {
        active:    'dot--active',
        concept:   'dot--concept',
        archived:  'dot--archived',
        deceased:  'dot--deceased',
        destroyed: 'dot--deceased',
    }
    return map[status] ?? 'dot--default'
}

const completionBarClass = (score) => {
    if (score >= 80) return 'bg-success'
    if (score >= 50) return 'bg-focus'
    if (score >= 20) return 'bg-warn'
    return 'bg-danger'
}
</script>

<style scoped>
.grid-cols-entity-list {
    grid-template-columns: 2fr 1fr 1fr 1fr 100px;
}

.prose-wrap {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.col-label {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted-3);
}

.mobile-label {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted-3);
}

.input {
    height: 40px;
    padding: 0 12px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    font-family: ui-monospace, monospace;
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
}
.input:focus { border-color: var(--accent-cyan); }
.input option { background: var(--bg-surface); }

.btn-primary {
    display: inline-flex;
    align-items: center;
    height: 40px;
    padding: 0 18px;
    background: rgb(0 245 255 / 0.1);
    border: 1px solid rgb(0 245 255 / 0.3);
    border-radius: 6px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--accent-cyan);
    transition: background 0.15s, border-color 0.15s;
}
.btn-primary:hover {
    background: rgb(0 245 255 / 0.15);
    border-color: rgb(0 245 255 / 0.5);
}

/* Type badges */
.type-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border: 1px solid var(--border-color);
    color: var(--text-muted-3);
}
.type--character  { color: #7dd3fc; border-color: rgba(125,211,252,0.25); background: rgba(125,211,252,0.07); }
.type--faction    { color: #c4b5fd; border-color: rgba(196,181,253,0.25); background: rgba(196,181,253,0.07); }
.type--location   { color: #6ee7b7; border-color: rgba(110,231,183,0.25); background: rgba(110,231,183,0.07); }
.type--event      { color: #fcd34d; border-color: rgba(252,211,77,0.25);  background: rgba(252,211,77,0.07);  }
.type--object     { color: #fdba74; border-color: rgba(253,186,116,0.25); background: rgba(253,186,116,0.07); }
.type--ci         { color: #f9a8d4; border-color: rgba(249,168,212,0.25); background: rgba(249,168,212,0.07); }
.type--power      { color: var(--accent-cyan); border-color: rgba(0,245,255,0.25); background: rgba(0,245,255,0.07); }
.type--default    { color: var(--text-muted-3); }

/* Status dots */
.status-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    margin-right: 8px;
    flex-shrink: 0;
}
.dot--active   { background: #6ee7b7; }
.dot--concept  { background: var(--text-muted-3); }
.dot--archived { background: #475569; }
.dot--deceased { background: var(--accent-pink); }
.dot--default  { background: var(--border-color-2); }
</style>
