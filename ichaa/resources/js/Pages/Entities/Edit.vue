<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('entities.index')" class="text-muted-3 text-xs font-mono hover:text-muted-2 transition-colors">
                    Entities
                </Link>
                <span class="text-muted-3 text-xs font-mono">/</span>
                <Link :href="route('entities.show', entity.id)" class="text-muted-3 text-xs font-mono hover:text-muted-2 transition-colors truncate max-w-48">
                    {{ entity.name }}
                </Link>
                <span class="text-muted-3 text-xs font-mono">/</span>
                <span class="text-primary text-sm font-light">Edit</span>
            </div>
        </template>

        <form @submit.prevent="submit" class="max-w-2xl space-y-5">

            <!-- ERRORS -->
            <div v-if="Object.keys(form.errors).length" class="p-3 bg-surface-2 border border-danger rounded-md">
                <p class="text-danger text-xs font-mono mb-1">Fix the following:</p>
                <ul class="space-y-0.5">
                    <li v-for="(msg, field) in form.errors" :key="field" class="text-danger text-xs font-mono">
                        · {{ msg }}
                    </li>
                </ul>
            </div>

            <!-- NAME + PUBLIC TITLE -->
            <div class="grid grid-cols-2 gap-4">
                <div class="field-group">
                    <label class="field-label">Name <span class="text-danger">*</span></label>
                    <input
                        v-model="form.name"
                        type="text"
                        class="input w-full"
                        :class="{ 'input--error': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
                </div>
                <div class="field-group">
                    <label class="field-label">Public Title <span class="text-muted-3 normal-case font-normal">(alias / in-world name)</span></label>
                    <input
                        v-model="form.public_title"
                        type="text"
                        placeholder="How the world knows them"
                        class="input w-full"
                    />
                </div>
            </div>

            <!-- ENTITY TYPE -->
            <div class="field-group">
                <label class="field-label">Type <span class="text-danger">*</span></label>
                <div class="grid grid-cols-3 gap-1.5">
                    <template v-for="(types, category) in entityTypes" :key="category">
                        <div class="col-span-3 mt-2 first:mt-0">
                            <span class="category-label">{{ formatLabel(category) }}</span>
                        </div>
                        <button
                            v-for="t in types"
                            :key="t"
                            type="button"
                            @click="form.entity_type = t"
                            class="type-btn"
                            :class="{ 'type-btn--selected': form.entity_type === t }"
                        >
                            {{ formatLabel(t) }}
                        </button>
                    </template>
                </div>
                <p v-if="form.errors.entity_type" class="field-error">{{ form.errors.entity_type }}</p>
            </div>

            <!-- STATUS + TYPE STATUS -->
            <div class="panel">
                <h3 class="panel-label">Status</h3>
                <div class="grid grid-cols-2 gap-4">

                    <div class="field-group">
                        <label class="field-label">Status</label>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="s in statusOptions"
                                :key="s.value"
                                type="button"
                                @click="form.status = s.value"
                                class="pill-btn"
                                :class="{ 'pill-btn--selected': form.status === s.value }"
                            >
                                {{ s.label }}
                            </button>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Type Status <span class="text-muted-3 normal-case font-normal">(type-specific state)</span></label>
                        <input
                            v-model="form.type_status"
                            type="text"
                            placeholder="e.g. Horcrux, Shard Vessel, Champion..."
                            class="input w-full"
                        />
                    </div>

                </div>
            </div>

            <!-- ORIGIN -->
            <div class="panel">
                <h3 class="panel-label">Origin</h3>
                <div class="space-y-4">

                    <div class="field-group">
                        <label class="field-label">Origin Type</label>
                        <div class="flex gap-2 flex-wrap">
                            <button
                                v-for="o in originTypes"
                                :key="o.value"
                                type="button"
                                @click="form.origin_type = o.value"
                                class="pill-btn"
                                :class="{ 'pill-btn--selected': form.origin_type === o.value }"
                            >
                                {{ o.label }}
                            </button>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Source Universes</label>
                        <div v-if="form.source_universes.length" class="flex flex-wrap gap-1.5 mb-2">
                            <button
                                v-for="u in form.source_universes"
                                :key="u"
                                type="button"
                                @click="removeUniverse(u)"
                                class="universe-tag"
                            >
                                {{ u }} ×
                            </button>
                        </div>
                        <select @change="addUniverse($event)" class="input w-full">
                            <option value="">Add a universe...</option>
                            <option
                                v-for="u in availableUniverses"
                                :key="u"
                                :value="u"
                            >{{ u }}</option>
                        </select>
                    </div>

                    <div v-if="form.origin_type === 'canonical'" class="field-group">
                        <label class="field-label">Canon Deviation</label>
                        <select v-model="form.canon_deviation" class="input w-full">
                            <option value="">None — fully canonical</option>
                            <option value="minor">Minor — small divergence</option>
                            <option value="moderate">Moderate — significant changes</option>
                            <option value="major">Major — heavily AU</option>
                            <option value="concept_only">Concept only — inspired by</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Origin Notes</label>
                        <textarea
                            v-model="form.origin_notes"
                            rows="2"
                            placeholder="Context about how this entity came to exist in your world..."
                            class="input w-full resize-none"
                        />
                    </div>

                </div>
            </div>

            <!-- POWER TIERS — only show for powered types -->
            <div v-if="isPoweredType" class="panel">
                <h3 class="panel-label">Power Tiers</h3>
                <div class="grid grid-cols-3 gap-4">

                    <div class="field-group">
                        <label class="field-label">Ceiling</label>
                        <select v-model="form.power_tier_ceiling" class="input w-full">
                            <option value="">Not set</option>
                            <option v-for="t in powerTiers" :key="t" :value="t">{{ formatLabel(t) }}</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Operating</label>
                        <select v-model="form.power_tier_operating" class="input w-full">
                            <option value="">Not set</option>
                            <option v-for="t in powerTiers" :key="t" :value="t">{{ formatLabel(t) }}</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Influence</label>
                        <select v-model="form.power_tier_influence" class="input w-full">
                            <option value="">Not set</option>
                            <option v-for="t in powerTiers" :key="t" :value="t">{{ formatLabel(t) }}</option>
                        </select>
                    </div>

                </div>
            </div>

            <!-- PERCEPTION -->
            <div class="panel">
                <h3 class="panel-label">Perception</h3>
                <div class="field-group">
                    <label class="field-label">Persona Divergence <span class="text-muted-3 normal-case font-normal">(how public image differs from truth)</span></label>
                    <select v-model="form.persona_divergence" class="input w-full">
                        <option value="">None — public = private</option>
                        <option value="minor">Minor — small gaps</option>
                        <option value="moderate">Moderate — meaningful divergence</option>
                        <option value="major">Major — fundamentally different</option>
                        <option value="total">Total — complete mask</option>
                    </select>
                </div>
            </div>

            <!-- SUMMARIES -->
            <div class="panel">
                <h3 class="panel-label">Summaries</h3>
                <div class="space-y-4">

                    <div class="field-group">
                        <label class="field-label">Private Summary <span class="text-muted-3 normal-case font-normal">(author view)</span></label>
                        <textarea
                            v-model="form.summary"
                            rows="4"
                            placeholder="Full author-level description..."
                            class="input w-full resize-none"
                        />
                    </div>

                    <div class="field-group">
                        <label class="field-label">Public Summary <span class="text-muted-3 normal-case font-normal">(what the world sees)</span></label>
                        <textarea
                            v-model="form.public_summary"
                            rows="3"
                            placeholder="How this entity presents to others..."
                            class="input w-full resize-none"
                        />
                    </div>

                </div>
            </div>

            <!-- ACCESS -->
            <div class="panel">
                <h3 class="panel-label">Access</h3>
                <div class="grid grid-cols-2 gap-4">

                    <div class="field-group">
                        <label class="field-label">Visibility</label>
                        <div class="flex gap-2 flex-wrap">
                            <button
                                v-for="v in visibilityOptions"
                                :key="v.value"
                                type="button"
                                @click="form.visibility = v.value"
                                class="pill-btn"
                                :class="{ 'pill-btn--selected': form.visibility === v.value }"
                            >
                                {{ v.label }}
                            </button>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Content Classification</label>
                        <div class="flex gap-2 flex-wrap">
                            <button
                                v-for="c in classificationOptions"
                                :key="c.value"
                                type="button"
                                @click="form.content_classification = c.value"
                                class="pill-btn"
                                :class="{ 'pill-btn--selected': form.content_classification === c.value }"
                            >
                                {{ c.label }}
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            <!-- SUBMIT -->
            <div class="flex items-center gap-3 pt-2">
                <button
                    type="submit"
                    class="btn-primary"
                    :disabled="form.processing || !form.name || !form.entity_type"
                >
                    <span v-if="form.processing">Saving...</span>
                    <span v-else>Save Changes</span>
                </button>
                <Link :href="route('entities.show', entity.id)" class="btn-ghost">
                    Cancel
                </Link>
                <span v-if="form.isDirty" class="text-muted-3 text-xs font-mono">Unsaved changes</span>
            </div>

        </form>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    entity:      { type: Object, required: true },
    entityTypes: { type: Object, default: () => ({}) },
})

const form = useForm({
    name:                   props.entity.name                   ?? '',
    public_title:           props.entity.public_title           ?? '',
    entity_type:            props.entity.entity_type            ?? '',
    entity_sub_type:        props.entity.entity_sub_type        ?? '',
    status:                 props.entity.status                 ?? 'concept',
    type_status:            props.entity.type_status            ?? '',
    summary:                props.entity.summary                ?? '',
    public_summary:         props.entity.public_summary         ?? '',
    source_universes:       props.entity.source_universes       ?? [],
    origin_type:            props.entity.origin_type            ?? 'native',
    canon_deviation:        props.entity.canon_deviation        ?? '',
    origin_notes:           props.entity.origin_notes           ?? '',
    power_tier_ceiling:     props.entity.power_tier_ceiling     ?? '',
    power_tier_operating:   props.entity.power_tier_operating   ?? '',
    power_tier_influence:   props.entity.power_tier_influence   ?? '',
    persona_divergence:     props.entity.persona_divergence     ?? '',
    control_state:          props.entity.control_state          ?? '',
    visibility:             props.entity.visibility             ?? 'private',
    content_classification: props.entity.content_classification ?? 'restricted',
})

const submit = () => {
    form.put(route('entities.update', props.entity.id))
}

// --- Power tier awareness ---

const POWERED_TYPES = [
    'character', 'historical_figure', 'constructed_intelligence',
    'deity', 'cosmic_entity', 'spirit', 'creature',
    'cosmological_force', 'void_entity',
]

const isPoweredType = computed(() => POWERED_TYPES.includes(form.entity_type))

// --- Universe helpers ---

const ALL_UNIVERSES = [
    'Harry Potter', 'Cosmere', 'Warhammer 40K', 'Dune', 'Wheel of Time',
    'Lord of the Rings', 'Star Wars', 'Marvel', 'DC', 'Witcher',
    'Elder Scrolls', 'Final Fantasy', 'Mass Effect', 'Dragon Age',
    'Mistborn', 'Stormlight Archive', 'First Law', 'Malazan',
    'Kingkiller Chronicle', 'Night Circus', 'Original',
]

const availableUniverses = computed(() =>
    ALL_UNIVERSES.filter(u => !form.source_universes.includes(u))
)

const addUniverse = (event) => {
    const val = event.target.value
    if (val && !form.source_universes.includes(val)) {
        form.source_universes = [...form.source_universes, val]
    }
    event.target.value = ''
}

const removeUniverse = (u) => {
    form.source_universes = form.source_universes.filter(x => x !== u)
}

// --- Static options ---

const statusOptions = [
    { value: 'concept',   label: 'Concept'   },
    { value: 'active',    label: 'Active'     },
    { value: 'dormant',   label: 'Dormant'    },
    { value: 'deceased',  label: 'Deceased'   },
    { value: 'destroyed', label: 'Destroyed'  },
    { value: 'archived',  label: 'Archived'   },
    { value: 'unknown',   label: 'Unknown'    },
]

const originTypes = [
    { value: 'native',    label: 'Native'    },
    { value: 'canonical', label: 'Canonical' },
    { value: 'alternate', label: 'Alternate' },
    { value: 'original',  label: 'Original'  },
]

const powerTiers = [
    'mundane', 'trained', 'enhanced', 'exceptional',
    'superhuman', 'meta', 'cosmic', 'transcendent', 'absolute',
]

const visibilityOptions = [
    { value: 'private',          label: 'Private'   },
    { value: 'author_only',      label: 'Author'    },
    { value: 'public_knowledge', label: 'Public'    },
]

const classificationOptions = [
    { value: 'restricted', label: 'Restricted' },
    { value: 'sensitive',  label: 'Sensitive'  },
    { value: 'open',       label: 'Open'       },
]

// --- Formatters ---

const formatLabel = (str) => str
    ? str.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    : '—'
</script>

<style scoped>
.field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.field-label {
    font-size: 10px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-muted-3);
}
.field-error {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    color: var(--accent-pink);
}
.category-label {
    font-size: 9px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    opacity: 0.6;
    display: block;
    margin-bottom: 4px;
}
.input {
    height: 32px;
    padding: 0 10px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 12px;
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
}
.input:focus { border-color: var(--accent-cyan); }
.input--error { border-color: var(--accent-pink); }
textarea.input { height: auto; padding: 8px 10px; }
select.input option { background: var(--bg-surface); }
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
    margin-bottom: 12px;
}
.type-btn {
    padding: 5px 8px;
    border-radius: 3px;
    font-size: 10px;
    font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color);
    color: var(--text-muted-3);
    background: var(--bg-surface-2);
    text-align: left;
    transition: border-color 0.12s, color 0.12s, background 0.12s;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.type-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.type-btn--selected {
    border-color: var(--accent-cyan);
    color: var(--accent-cyan);
    background: rgba(0, 245, 255, 0.08);
}
.pill-btn {
    padding: 4px 12px;
    border-radius: 2px;
    font-size: 10px;
    font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color);
    color: var(--text-muted-3);
    background: transparent;
    transition: border-color 0.12s, color 0.12s, background 0.12s;
}
.pill-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.pill-btn--selected {
    border-color: var(--accent-cyan);
    color: var(--accent-cyan);
    background: rgba(0, 245, 255, 0.08);
}
.universe-tag {
    padding: 2px 8px;
    border-radius: 2px;
    font-size: 10px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-2);
    border: 1px solid var(--border-color-2);
    background: var(--bg-surface);
    transition: border-color 0.12s, color 0.12s;
}
.universe-tag:hover { border-color: var(--accent-pink); color: var(--accent-pink); }
.btn-primary {
    display: inline-flex;
    align-items: center;
    height: 32px;
    padding: 0 16px;
    background: rgba(0, 245, 255, 0.1);
    border: 1px solid rgba(0, 245, 255, 0.3);
    border-radius: 4px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    color: var(--accent-cyan);
    transition: background 0.15s, border-color 0.15s;
}
.btn-primary:hover:not(:disabled) {
    background: rgba(0, 245, 255, 0.15);
    border-color: rgba(0, 245, 255, 0.5);
}
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-ghost {
    display: inline-flex;
    align-items: center;
    height: 32px;
    padding: 0 12px;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-2);
    transition: border-color 0.15s, color 0.15s;
}
.btn-ghost:hover { border-color: var(--border-color-2); color: var(--text-primary); }
.text-danger { color: var(--accent-pink); }
.border-danger { border-color: var(--accent-pink); }
</style>