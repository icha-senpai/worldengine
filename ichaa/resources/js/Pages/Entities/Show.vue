<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-start justify-between gap-4">

                <!-- Left: name + breadcrumb -->
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="route('entities.index')" class="text-muted-3 text-xs font-mono hover:text-muted-2 transition-colors">
                            Entities
                        </Link>
                        <span class="text-muted-3 text-xs font-mono">/</span>
                        <span class="type-chip" :class="typeBadgeClass(entity.entity_type)">
                            {{ formatLabel(entity.entity_type) }}
                        </span>
                    </div>
                    <h1 class="text-primary text-2xl font-light tracking-wide leading-tight">
                        {{ entity.name }}
                    </h1>
                    <p v-if="entity.public_title" class="text-muted-3 text-sm font-mono mt-0.5 italic">
                        "{{ entity.public_title }}"
                    </p>
                </div>

                <!-- Right: actions -->
                <div class="flex items-center gap-2 flex-shrink-0 pt-1">
                    <span class="status-badge" :class="statusBadgeClass(entity.status)">
                        {{ formatLabel(entity.status) }}
                    </span>
                    <Link :href="route('entities.edit', entity.id)" class="btn-ghost">
                        Edit
                    </Link>
                </div>

            </div>
        </template>

        <!-- TABS -->
        <div class="border-b border-border mb-6">
            <nav class="flex items-end gap-0">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="tab-btn"
                    :class="{ 'tab-btn--active': activeTab === tab.id }"
                >
                    {{ tab.label }}
                </button>
            </nav>
        </div>

        <!-- TAB: IDENTITY -->
        <div v-if="activeTab === 'identity'" class="space-y-5">

            <!-- Completion bar -->
            <div class="flex items-center gap-3 p-3 bg-surface-2 border border-border rounded-md">
                <span class="text-muted-3 text-[10px] font-mono uppercase tracking-widest whitespace-nowrap">
                    Completion
                </span>
                <div class="flex-1 h-1.5 bg-surface rounded-full overflow-hidden">
                    <div
                        class="h-full rounded-full transition-all duration-500"
                        :class="completionBarClass(entity.completion_score)"
                        :style="{ width: entity.completion_score + '%' }"
                    />
                </div>
                <span class="text-muted-2 text-xs font-mono w-8 text-right">
                    {{ entity.completion_score }}%
                </span>
                <div class="flex items-center gap-2 ml-2">
                    <CompletionFlag v-if="entity.has_attributes"       label="attr"   />
                    <CompletionFlag v-if="entity.has_relationships"    label="rel"    />
                    <CompletionFlag v-if="entity.has_timeline_entries" label="time"   />
                    <CompletionFlag v-if="entity.has_aliases"          label="alias"  />
                    <CompletionFlag v-if="entity.has_media"            label="media"  />
                </div>
            </div>

            <!-- Core fields grid -->
            <div class="grid grid-cols-2 gap-4">

                <!-- Summary -->
                <div v-if="entity.summary" class="col-span-2 panel">
                    <h3 class="panel-label">Summary</h3>
                    <p class="text-muted-2 text-sm leading-relaxed whitespace-pre-wrap">{{ entity.summary }}</p>
                </div>

                <!-- Public summary -->
                <div v-if="entity.public_summary" class="col-span-2 panel">
                    <h3 class="panel-label">Public Summary <span class="text-muted-3 normal-case font-normal">(visible persona)</span></h3>
                    <p class="text-muted-2 text-sm leading-relaxed whitespace-pre-wrap">{{ entity.public_summary }}</p>
                </div>

                <!-- Classification -->
                <div class="panel">
                    <h3 class="panel-label">Classification</h3>
                    <div class="space-y-2">
                        <FieldRow label="Type">{{ formatLabel(entity.entity_type) }}</FieldRow>
                        <FieldRow v-if="entity.entity_sub_type" label="Subtype">{{ formatLabel(entity.entity_sub_type) }}</FieldRow>
                        <FieldRow label="Status">
                            <span class="status-badge status-badge--sm" :class="statusBadgeClass(entity.status)">
                                {{ formatLabel(entity.status) }}
                            </span>
                        </FieldRow>
                        <FieldRow v-if="entity.type_status" label="Type Status">{{ formatLabel(entity.type_status) }}</FieldRow>
                    </div>
                </div>

                <!-- Visibility & Access -->
                <div class="panel">
                    <h3 class="panel-label">Access</h3>
                    <div class="space-y-2">
                        <FieldRow label="Visibility">{{ formatLabel(entity.visibility) }}</FieldRow>
                        <FieldRow label="Classification">{{ formatLabel(entity.content_classification) }}</FieldRow>
                        <FieldRow v-if="entity.published_at" label="Published">
                            {{ formatDate(entity.published_at) }}
                        </FieldRow>
                    </div>
                </div>

                <!-- Origin -->
                <div class="panel">
                    <h3 class="panel-label">Origin</h3>
                    <div class="space-y-2">
                        <FieldRow label="Origin Type">{{ formatLabel(entity.origin_type) }}</FieldRow>
                        <FieldRow v-if="entity.canon_deviation" label="Canon Deviation">{{ formatLabel(entity.canon_deviation) }}</FieldRow>
                        <FieldRow v-if="entity.origin_notes" label="Notes">{{ entity.origin_notes }}</FieldRow>
                    </div>
                </div>

                <!-- Source Universes -->
                <div class="panel">
                    <h3 class="panel-label">Source Universes</h3>
                    <div v-if="entity.source_universes && entity.source_universes.length" class="flex flex-wrap gap-1.5 mt-1">
                        <span
                            v-for="u in entity.source_universes"
                            :key="u"
                            class="universe-chip"
                        >
                            {{ u }}
                        </span>
                    </div>
                    <p v-else class="text-muted-3 text-xs font-mono">Native / Original</p>
                </div>

                <!-- Power Tiers — only if any are set -->
                <div v-if="hasPowerTiers" class="col-span-2 panel">
                    <h3 class="panel-label">Power Tiers</h3>
                    <div class="grid grid-cols-3 gap-4 mt-2">
                        <div v-if="entity.power_tier_ceiling" class="tier-block">
                            <span class="tier-label">Ceiling</span>
                            <span class="tier-value">{{ formatLabel(entity.power_tier_ceiling) }}</span>
                        </div>
                        <div v-if="entity.power_tier_operating" class="tier-block">
                            <span class="tier-label">Operating</span>
                            <span class="tier-value">{{ formatLabel(entity.power_tier_operating) }}</span>
                        </div>
                        <div v-if="entity.power_tier_influence" class="tier-block">
                            <span class="tier-label">Influence</span>
                            <span class="tier-value">{{ formatLabel(entity.power_tier_influence) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Control State — only if set -->
                <div v-if="entity.control_state" class="panel">
                    <h3 class="panel-label">Control</h3>
                    <div class="space-y-2">
                        <FieldRow label="Control State">
                            <span class="text-warn text-xs font-mono">{{ formatLabel(entity.control_state) }}</span>
                        </FieldRow>
                    </div>
                </div>

                <!-- Persona divergence — only if relevant -->
                <div v-if="entity.persona_divergence" class="panel">
                    <h3 class="panel-label">Persona Divergence</h3>
                    <p class="text-muted-2 text-xs font-mono">{{ formatLabel(entity.persona_divergence) }}</p>
                </div>

            </div>
        </div>

        <!-- FUTURE TABS: rendered empty for now, will be built out -->
        <div v-if="activeTab === 'relationships'" class="panel">
            <p class="text-muted-3 text-xs font-mono text-center py-8">Relationships panel — coming next.</p>
        </div>

        <div v-if="activeTab === 'notes'" class="panel">
            <p class="text-muted-3 text-xs font-mono text-center py-8">Notes panel — coming next.</p>
        </div>

        <div v-if="activeTab === 'questions'" class="panel">
            <p class="text-muted-3 text-xs font-mono text-center py-8">Questions panel — coming next.</p>
        </div>

        <div v-if="activeTab === 'intelligence'" class="panel">
            <p class="text-muted-3 text-xs font-mono text-center py-8">Intelligence panel — coming next.</p>
        </div>

    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

// --- Small inline components ---

const CompletionFlag = {
    props: ['label'],
    template: `<span class="completion-flag">{{ label }}</span>`,
}

const FieldRow = {
    props: ['label'],
    template: `
        <div class="flex items-start gap-2">
            <span class="field-label">{{ label }}</span>
            <span class="text-muted-2 text-xs leading-relaxed"><slot /></span>
        </div>
    `,
}

// --- Props ---

const props = defineProps({
    entity: { type: Object, required: true },
})

// --- Tabs ---

const tabs = [
    { id: 'identity',      label: 'Identity'       },
    { id: 'relationships', label: 'Relationships'   },
    { id: 'notes',         label: 'Notes'           },
    { id: 'questions',     label: 'Questions'       },
    { id: 'intelligence',  label: 'Intelligence'    },
]

const activeTab = ref('identity')

// --- Computed ---

const hasPowerTiers = computed(() =>
    props.entity.power_tier_ceiling ||
    props.entity.power_tier_operating ||
    props.entity.power_tier_influence
)

// --- Formatters ---

const formatLabel = (str) => str
    ? str.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    : '—'

const formatDate = (dt) => {
    if (!dt) return '—'
    return new Date(dt).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// --- Style helpers ---

const typeBadgeClass = (type) => {
    const map = {
        character:             'type--character',
        historical_figure:     'type--character',
        faction:               'type--faction',
        organization:          'type--faction',
        government:            'type--faction',
        movement:              'type--faction',
        location:              'type--location',
        dimension:             'type--location',
        plane:                 'type--location',
        realm:                 'type--location',
        event:                 'type--event',
        conflict:              'type--event',
        ritual:                'type--event',
        phenomenon:            'type--event',
        artifact:              'type--object',
        weapon:                'type--object',
        relic:                 'type--object',
        vehicle:               'type--object',
        constructed_intelligence: 'type--ci',
        deity:                 'type--power',
        cosmic_entity:         'type--power',
        cosmological_force:    'type--power',
    }
    return map[type] ?? 'type--default'
}

const statusBadgeClass = (status) => {
    const map = {
        active:    'status--active',
        concept:   'status--concept',
        archived:  'status--archived',
        deceased:  'status--deceased',
        destroyed: 'status--deceased',
        dormant:   'status--dormant',
    }
    return map[status] ?? 'status--default'
}

const completionBarClass = (score) => {
    if (score >= 80) return 'bg-success'
    if (score >= 50) return 'bg-focus'
    if (score >= 20) return 'bg-warn'
    return 'bg-danger'
}
</script>

<style scoped>
/* --- Tabs --- */
.tab-btn {
    padding: 8px 16px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.06em;
    color: var(--text-muted-3);
    border-bottom: 2px solid transparent;
    transition: color 0.15s, border-color 0.15s;
    white-space: nowrap;
}
.tab-btn:hover { color: var(--text-muted-2); }
.tab-btn--active {
    color: var(--accent-cyan);
    border-bottom-color: var(--accent-cyan);
}

/* --- Panels --- */
.panel {
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 14px 16px;
}
.panel-label {
    font-size: 9px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    margin-bottom: 8px;
}
.field-label {
    font-size: 9px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    white-space: nowrap;
    min-width: 90px;
    flex-shrink: 0;
    padding-top: 1px;
}

/* --- Type chip (header) --- */
.type-chip {
    display: inline-flex;
    align-items: center;
    padding: 1px 7px;
    border-radius: 2px;
    font-size: 9px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border: 1px solid;
}
.type--character  { color: #7dd3fc; border-color: rgba(125,211,252,0.3); background: rgba(125,211,252,0.08); }
.type--faction    { color: #c4b5fd; border-color: rgba(196,181,253,0.3); background: rgba(196,181,253,0.08); }
.type--location   { color: #6ee7b7; border-color: rgba(110,231,183,0.3); background: rgba(110,231,183,0.08); }
.type--event      { color: #fcd34d; border-color: rgba(252,211,77,0.3);  background: rgba(252,211,77,0.08);  }
.type--object     { color: #fdba74; border-color: rgba(253,186,116,0.3); background: rgba(253,186,116,0.08); }
.type--ci         { color: #f9a8d4; border-color: rgba(249,168,212,0.3); background: rgba(249,168,212,0.08); }
.type--power      { color: var(--accent-cyan); border-color: rgba(0,245,255,0.3); background: rgba(0,245,255,0.08); }
.type--default    { color: var(--text-muted-3); border-color: var(--border-color); }

/* --- Status badge --- */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 3px;
    font-size: 10px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    border: 1px solid;
}
.status-badge--sm { padding: 1px 7px; font-size: 9px; }
.status--active   { color: #6ee7b7; border-color: rgba(110,231,183,0.3); background: rgba(110,231,183,0.08); }
.status--concept  { color: var(--text-muted-3); border-color: var(--border-color); background: transparent; }
.status--archived { color: #64748b; border-color: rgba(100,116,139,0.3); background: rgba(100,116,139,0.06); }
.status--deceased { color: var(--accent-pink); border-color: rgba(255,0,128,0.3); background: rgba(255,0,128,0.06); }
.status--dormant  { color: #fcd34d; border-color: rgba(252,211,77,0.3); background: rgba(252,211,77,0.06); }
.status--default  { color: var(--text-muted-3); border-color: var(--border-color); }

/* --- Universe chips --- */
.universe-chip {
    padding: 2px 8px;
    border-radius: 2px;
    font-size: 10px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-2);
    border: 1px solid var(--border-color-2);
    background: var(--bg-surface);
}

/* --- Power tier block --- */
.tier-block {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 12px;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 4px;
}
.tier-label {
    font-size: 9px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-muted-3);
}
.tier-value {
    font-size: 13px;
    color: var(--text-primary);
    font-weight: 300;
}

/* --- Completion flags --- */
.completion-flag {
    padding: 1px 5px;
    border-radius: 2px;
    font-size: 9px;
    font-family: ui-monospace, monospace;
    color: var(--accent-cyan);
    border: 1px solid rgba(0, 245, 255, 0.2);
    background: rgba(0, 245, 255, 0.06);
}

/* --- Buttons --- */
.btn-ghost {
    display: inline-flex;
    align-items: center;
    height: 28px;
    padding: 0 12px;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-2);
    transition: border-color 0.15s, color 0.15s;
}
.btn-ghost:hover {
    border-color: var(--border-color-2);
    color: var(--text-primary);
}

/* --- Utility colors --- */
.text-warn { color: #fcd34d; }
.bg-success { background: #6ee7b7; }
.bg-focus   { background: var(--accent-cyan); }
.bg-warn    { background: #fcd34d; }
.bg-danger  { background: var(--accent-pink); }
</style>