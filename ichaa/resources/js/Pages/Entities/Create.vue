<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('entities.index')" class="text-muted-3 text-xs font-mono hover:text-muted-2 transition-colors">
                    Entities
                </Link>
                <span class="text-muted-3 text-xs font-mono">/</span>
                <span class="text-primary text-sm font-light">New Entity</span>
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

            <!-- NAME -->
            <div class="field-group">
                <label class="field-label">Name <span class="text-danger">*</span></label>
                <input
                    v-model="form.name"
                    type="text"
                    placeholder="Entity name"
                    class="input w-full"
                    :class="{ 'input--error': form.errors.name }"
                    autofocus
                />
                <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
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

            <!-- Only show remaining fields once type is selected -->
            <template v-if="form.entity_type">

                <!-- ORIGIN -->
                <div class="panel">
                    <h3 class="panel-label">Origin</h3>
                    <div class="space-y-3">

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
                            <div class="flex flex-wrap gap-1.5 mb-2">
                                <button
                                    v-for="u in selectedUniverses"
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

                    </div>
                </div>

                <!-- ACCESS -->
                <div class="panel">
                    <h3 class="panel-label">Access</h3>
                    <div class="grid grid-cols-2 gap-3">

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

                <!-- SUMMARY (optional at creation) -->
                <div class="field-group">
                    <label class="field-label">Summary <span class="text-muted-3 font-normal normal-case">(optional)</span></label>
                    <textarea
                        v-model="form.summary"
                        rows="4"
                        placeholder="Brief description of this entity..."
                        class="input w-full resize-none"
                    />
                </div>

                <!-- SUBMIT -->
                <div class="flex items-center gap-3 pt-2">
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="form.processing || !form.name || !form.entity_type"
                    >
                        <span v-if="form.processing">Creating...</span>
                        <span v-else>Create Entity</span>
                    </button>
                    <Link :href="route('entities.index')" class="btn-ghost">
                        Cancel
                    </Link>
                    <span v-if="form.processing" class="text-muted-3 text-xs font-mono">Working...</span>
                </div>

            </template>

            <!-- Prompt to select type first -->
            <div v-else class="p-6 text-center border border-dashed border-border rounded-md">
                <p class="text-muted-3 text-xs font-mono uppercase tracking-widest">Select a type to continue</p>
            </div>

        </form>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    entityTypes: { type: Object, default: () => ({}) },
})

const form = useForm({
    name:                   '',
    entity_type:            '',
    origin_type:            'native',
    source_universes:       [],
    canon_deviation:        '',
    visibility:             'private',
    content_classification: 'restricted',
    summary:                '',
})

const submit = () => {
    form.post(route('entities.store'))
}

// --- Universe helpers ---

const ALL_UNIVERSES = [
    'Harry Potter', 'Cosmere', 'Warhammer 40K', 'Dune', 'Wheel of Time',
    'Lord of the Rings', 'Star Wars', 'Marvel', 'DC', 'Witcher',
    'Elder Scrolls', 'Final Fantasy', 'Mass Effect', 'Dragon Age',
    'Mistborn', 'Stormlight Archive', 'First Law', 'Malazan',
    'Kingkiller Chronicle', 'Night Circus', 'Original',
]

const selectedUniverses = computed(() => form.source_universes)

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

const originTypes = [
    { value: 'native',    label: 'Native'    },
    { value: 'canonical', label: 'Canonical' },
    { value: 'alternate', label: 'Alternate' },
    { value: 'original',  label: 'Original'  },
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

/* Type selector grid buttons */
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
.type-btn:hover {
    border-color: var(--border-color-2);
    color: var(--text-muted-2);
}
.type-btn--selected {
    border-color: var(--accent-cyan);
    color: var(--accent-cyan);
    background: rgba(0, 245, 255, 0.08);
}

/* Pill toggle buttons */
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
.pill-btn:hover {
    border-color: var(--border-color-2);
    color: var(--text-muted-2);
}
.pill-btn--selected {
    border-color: var(--accent-cyan);
    color: var(--accent-cyan);
    background: rgba(0, 245, 255, 0.08);
}

/* Universe tags */
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
.universe-tag:hover {
    border-color: var(--accent-pink);
    color: var(--accent-pink);
}

/* Buttons */
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
.btn-primary:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
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
.btn-ghost:hover {
    border-color: var(--border-color-2);
    color: var(--text-primary);
}

.text-danger { color: var(--accent-pink); }
.border-danger { border-color: var(--accent-pink); }
</style>