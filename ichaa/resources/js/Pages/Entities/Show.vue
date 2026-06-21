<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-start justify-between gap-4">

                <!-- Left: name + breadcrumb -->
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="route('entities.index')" class="text-muted-3 text-sm font-mono hover:text-muted-2 transition-colors">
                            Entities
                        </Link>
                        <span class="text-muted-3 text-sm font-mono">/</span>
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
                    <button type="button" class="btn-danger" @click="destroyEntity">
                        Move to Trash
                    </button>
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
                <span class="text-muted-3 text-xs font-mono uppercase tracking-widest whitespace-nowrap">
                    Completion
                </span>
                <div class="flex-1 h-1.5 bg-surface rounded-full overflow-hidden">
                    <div
                        class="h-full rounded-full transition-all duration-500"
                        :class="completionBarClass(entity.completion_score)"
                        :style="{ width: entity.completion_score + '%' }"
                    />
                </div>
                <span class="text-muted-2 text-sm font-mono w-9 text-right">
                    {{ entity.completion_score }}%
                </span>
                <div class="flex items-center gap-2 ml-2">
                    <span v-if="entity.has_attributes"       class="completion-flag">attr</span>
                    <span v-if="entity.has_relationships"    class="completion-flag">rel</span>
                    <span v-if="entity.has_timeline_entries" class="completion-flag">time</span>
                    <span v-if="entity.has_aliases"          class="completion-flag">alias</span>
                    <span v-if="entity.has_media"            class="completion-flag">media</span>
                </div>
            </div>

            <!-- Core fields grid -->
            <div class="grid grid-cols-2 gap-4">

                <!-- Summary -->
                <div v-if="entity.summary" class="col-span-2 panel">
                    <h3 class="panel-label">Summary</h3>
                    <p class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ entity.summary }}</p>
                </div>

                <!-- Public summary -->
                <div v-if="entity.public_summary" class="col-span-2 panel">
                    <h3 class="panel-label">Public Summary <span class="text-muted-3 normal-case font-normal">(visible persona)</span></h3>
                    <p class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ entity.public_summary }}</p>
                </div>

                <!-- Classification -->
                <div class="panel">
                    <h3 class="panel-label">Classification</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label">Type</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.entity_type) }}</span></div>
                        <div v-if="entity.entity_sub_type" class="flex items-start gap-2"><span class="field-label">Subtype</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.entity_sub_type) }}</span></div>
                        <div class="flex items-start gap-2">
                            <span class="field-label">Status</span>
                            <span class="status-badge status-badge--sm" :class="statusBadgeClass(entity.status)">
                                {{ formatLabel(entity.status) }}
                            </span>
                        </div>
                        <div v-if="entity.type_status" class="flex items-start gap-2"><span class="field-label">Type Status</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.type_status) }}</span></div>
                    </div>
                </div>

                <!-- Visibility & Access -->
                <div class="panel">
                    <h3 class="panel-label">Access</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label">Visibility</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.visibility) }}</span></div>
                        <div class="flex items-start gap-2"><span class="field-label">Classification</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.content_classification) }}</span></div>
                        <div v-if="entity.published_at" class="flex items-start gap-2"><span class="field-label">Published</span><span class="text-muted-2 text-sm">{{ formatDate(entity.published_at) }}</span></div>
                    </div>
                </div>

                <!-- Origin -->
                <div class="panel">
                    <h3 class="panel-label">Origin</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label">Origin Type</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.origin_type) }}</span></div>
                        <div v-if="entity.canon_deviation" class="flex items-start gap-2"><span class="field-label">Canon Deviation</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.canon_deviation) }}</span></div>
                        <div v-if="entity.origin_notes" class="flex items-start gap-2"><span class="field-label">Notes</span><span class="text-muted-2 text-sm">{{ entity.origin_notes }}</span></div>
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
                    <p v-else class="text-muted-3 text-sm font-mono">Native / Original</p>
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
                        <div class="flex items-start gap-2">
                            <span class="field-label">Control State</span>
                            <span class="text-warn text-sm font-mono">{{ formatLabel(entity.control_state) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Persona divergence — only if relevant -->
                <div v-if="entity.persona_divergence" class="panel">
                    <h3 class="panel-label">Persona Divergence</h3>
                    <p class="text-muted-2 text-sm font-mono">{{ formatLabel(entity.persona_divergence) }}</p>
                </div>

            </div>
        </div>

        <!-- TAB: ALIASES -->
        <div v-if="activeTab === 'aliases'" class="space-y-4">

            <!-- Add alias form -->
            <div class="panel">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h3 class="panel-label !mb-0">{{ isEditingAlias ? 'Edit Alias' : 'Add Alias' }}</h3>
                    <button
                        v-if="isEditingAlias"
                        type="button"
                        @click="cancelAliasEdit"
                        class="btn-ghost btn-ghost--sm"
                    >
                        Cancel
                    </button>
                </div>
                <form @submit.prevent="submitAlias" class="space-y-3">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="field-group">
                            <label class="field-label">Alias <span class="text-danger">*</span></label>
                            <input v-model="aliasForm.alias" type="text" placeholder="The alias text" class="input w-full" />
                        </div>
                        <div class="field-group">
                            <label class="field-label">Type <span class="text-danger">*</span></label>
                            <select v-model="aliasForm.alias_type" class="input w-full">
                                <option value="">Select type...</option>
                                <option value="nickname">Nickname</option>
                                <option value="title">Title</option>
                                <option value="codename">Codename</option>
                                <option value="epithet">Epithet</option>
                                <option value="birth_name">Birth Name</option>
                                <option value="alias">Alias</option>
                                <option value="honorific">Honorific</option>
                                <option value="posthumous">Posthumous</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Context <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <input v-model="aliasForm.context" type="text" placeholder="When/where this alias is used" class="input w-full" />
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="field-group">
                            <label class="field-label">Era Start <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                            <input v-model="aliasForm.era_start" type="text" placeholder="e.g. Year of the Dragon" class="input w-full" />
                        </div>
                        <div class="field-group">
                            <label class="field-label">Era End <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                            <input v-model="aliasForm.era_end" type="text" placeholder="Leave blank if still active" class="input w-full" />
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="aliasForm.is_active" class="checkbox" />
                            <span class="text-muted-2 text-sm font-mono">Currently active</span>
                        </label>
                        <button type="submit" class="btn-primary" :disabled="aliasForm.processing || !aliasForm.alias || !aliasForm.alias_type">
                            {{ isEditingAlias ? 'Save Alias' : 'Add Alias' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Alias list -->
            <div v-if="entity.aliases && entity.aliases.length" class="space-y-2">
                <div v-for="a in entity.aliases" :key="a.id" class="alias-row">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-primary text-sm">{{ a.alias }}</span>
                                <span class="alias-type-chip">{{ formatLabel(a.alias_type) }}</span>
                                <span v-if="!a.is_active" class="text-muted-3 text-xs font-mono">(inactive)</span>
                            </div>
                            <p v-if="a.context" class="text-muted-3 text-sm mt-1">{{ a.context }}</p>
                            <p v-if="a.era_start || a.era_end" class="text-muted-3 text-xs font-mono mt-1">
                                {{ a.era_start || '?' }} → {{ a.era_end || 'present' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <button
                                type="button"
                                @click="beginAliasEdit(a)"
                                class="btn-ghost btn-ghost--sm"
                            >
                                Edit
                            </button>
                            <button
                                @click="deleteAlias(a.id)"
                                class="btn-danger-sm"
                            >Delete</button>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="empty-state">No aliases recorded.</div>

        </div>

        <!-- TAB: NOTES -->
        <div v-if="activeTab === 'notes'" class="space-y-4">

            <!-- Add note form -->
            <div class="panel">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h3 class="panel-label !mb-0">{{ isEditingNote ? 'Edit Note' : 'Add Note' }}</h3>
                    <button
                        v-if="isEditingNote"
                        type="button"
                        @click="cancelNoteEdit"
                        class="btn-ghost btn-ghost--sm"
                    >
                        Cancel
                    </button>
                </div>
                <form @submit.prevent="submitNote" class="space-y-3">
                    <div class="field-group">
                        <label class="field-label">Label <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <input v-model="noteForm.note_label" type="text" placeholder="e.g. Backstory, Motivation, Arc notes..." class="input w-full" />
                    </div>
                    <div class="field-group">
                        <label class="field-label">Content <span class="text-danger">*</span></label>
                        <textarea v-model="noteForm.content" rows="4" placeholder="Note content..." class="input w-full resize-none" />
                    </div>
                    <div class="field-group">
                        <label class="field-label">Sort Order <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <input v-model="noteForm.sort_order" type="number" placeholder="Display order" class="input w-full" />
                    </div>
                    <button type="submit" class="btn-primary" :disabled="noteForm.processing || !noteForm.content">
                        {{ isEditingNote ? 'Save Note' : 'Add Note' }}
                    </button>
                </form>
            </div>

            <!-- Note list -->
            <div v-if="entity.notes && entity.notes.length" class="space-y-3">
                <div v-for="n in entity.notes" :key="n.id" class="note-card">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <span v-if="n.note_label" class="note-label">{{ n.note_label }}</span>
                        <span v-else class="note-label note-label--empty">unlabeled</span>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="text-muted-3 text-xs font-mono">{{ formatDate(n.created_at) }}</span>
                            <button type="button" @click="beginNoteEdit(n)" class="btn-ghost btn-ghost--sm">Edit</button>
                            <button @click="deleteNote(n.id)" class="btn-danger-sm">Delete</button>
                        </div>
                    </div>
                    <p class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ n.content }}</p>
                </div>
            </div>
            <div v-else class="empty-state">No notes recorded.</div>

        </div>

        <!-- TAB: QUESTIONS -->
        <div v-if="activeTab === 'questions'" class="space-y-4">

            <!-- Add question form -->
            <div class="panel">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h3 class="panel-label !mb-0">{{ isEditingQuestion ? 'Edit Question' : 'Add Question' }}</h3>
                    <button
                        v-if="isEditingQuestion"
                        type="button"
                        @click="cancelQuestionEdit"
                        class="btn-ghost btn-ghost--sm"
                    >
                        Cancel
                    </button>
                </div>
                <form @submit.prevent="submitQuestion" class="space-y-3">
                    <div class="field-group">
                        <label class="field-label">Question <span class="text-danger">*</span></label>
                        <input v-model="questionForm.question" type="text" placeholder="What needs to be resolved?" class="input w-full" />
                    </div>
                    <div class="field-group">
                        <label class="field-label">Context <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <textarea v-model="questionForm.context" rows="2" placeholder="Why does this matter? What does it affect?" class="input w-full resize-none" />
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="field-group">
                            <label class="field-label">Priority</label>
                            <div class="flex gap-1.5 flex-wrap">
                                <button
                                    v-for="p in priorityOptions"
                                    :key="p.value"
                                    type="button"
                                    @click="questionForm.priority = p.value"
                                    class="pill-btn"
                                    :class="{ 'pill-btn--selected': questionForm.priority === p.value, ['pill-btn--' + p.value]: true }"
                                >{{ p.label }}</button>
                            </div>
                        </div>
                        <div class="field-group">
                            <label class="field-label">Status</label>
                            <div class="flex gap-1.5 flex-wrap">
                                <button
                                    v-for="s in questionStatusOptions"
                                    :key="s.value"
                                    type="button"
                                    @click="questionForm.status = s.value"
                                    class="pill-btn"
                                    :class="{ 'pill-btn--selected': questionForm.status === s.value }"
                                >{{ s.label }}</button>
                            </div>
                        </div>
                    </div>
                    <div v-if="isEditingQuestion || questionForm.status === 'resolved'" class="field-group">
                        <label class="field-label">Resolution <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <textarea v-model="questionForm.resolution" rows="3" placeholder="How was this resolved?" class="input w-full resize-none" />
                    </div>
                    <button type="submit" class="btn-primary" :disabled="questionForm.processing || !questionForm.question">
                        {{ isEditingQuestion ? 'Save Question' : 'Add Question' }}
                    </button>
                </form>
            </div>

            <!-- Question list — blocking first, then by priority -->
            <div v-if="entity.questions && entity.questions.length" class="space-y-2">
                <div
                    v-for="q in entity.questions"
                    :key="q.id"
                    class="question-card"
                    :class="{ 'question-card--blocking': q.priority === 'blocking', 'question-card--resolved': q.status === 'resolved' }"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="priority-chip" :class="'priority--' + q.priority">{{ q.priority }}</span>
                                <span class="status-q-chip" :class="'qstatus--' + q.status">{{ formatLabel(q.status) }}</span>
                            </div>
                            <p class="text-primary text-sm leading-snug">{{ q.question }}</p>
                            <p v-if="q.context" class="prose-wrap text-muted-3 text-sm mt-1">{{ q.context }}</p>
                            <p v-if="q.resolution" class="prose-wrap text-muted-2 text-sm mt-2 italic">
                                ↳ {{ q.resolution }}
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                            <button type="button" @click="beginQuestionEdit(q)" class="btn-ghost btn-ghost--sm">Edit</button>
                            <button
                                v-if="q.status !== 'resolved'"
                                @click="resolveQuestion(q.id)"
                                class="btn-resolve"
                            >Resolve</button>
                            <button @click="deleteQuestion(q.id)" class="btn-danger-sm">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="empty-state">No questions recorded.</div>

        </div>

        <!-- TAB: INTELLIGENCE -->
        <div v-if="activeTab === 'intelligence'" class="space-y-4">
            <div class="panel">
                <h3 class="panel-label">Intelligence</h3>
                <p class="text-muted-3 text-sm font-mono">
                    Knowledge states and secrets involving this entity are managed from the
                    <Link :href="route('knowledge-states.index')" class="text-cyan hover:underline">Knowledge States</Link>
                    and
                    <Link :href="route('secrets.index')" class="text-cyan hover:underline">Secrets</Link>
                    domains. This tab will display a read-only summary once those pages are built.
                </p>
            </div>
        </div>

    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

// --- Props ---

const props = defineProps({
    entity: { type: Object, required: true },
})

const page = usePage()

// --- Tabs ---

const tabs = [
    { id: 'identity',     label: 'Identity'     },
    { id: 'aliases',      label: 'Aliases'      },
    { id: 'notes',        label: 'Notes'        },
    { id: 'questions',    label: 'Questions'    },
    { id: 'intelligence', label: 'Intelligence' },
]

const activeTab = ref('identity')

const editingAliasId = ref(null)
const editingNoteId = ref(null)
const editingQuestionId = ref(null)

const validTabs = tabs.map((tab) => tab.id)

const urlParams = computed(() => {
    const [, queryString = ''] = page.url.split('?')

    return new URLSearchParams(queryString)
})

// --- Alias form ---

const aliasForm = useForm({
    alias:      '',
    alias_type: '',
    context:    '',
    era_start:  '',
    era_end:    '',
    is_active:  true,
})

const isEditingAlias = computed(() => editingAliasId.value !== null)

const resetAliasForm = () => {
    aliasForm.reset()
    aliasForm.is_active = true
}

const beginAliasEdit = (alias) => {
    editingAliasId.value = alias.id
    aliasForm.alias = alias.alias ?? ''
    aliasForm.alias_type = alias.alias_type ?? ''
    aliasForm.context = alias.context ?? ''
    aliasForm.era_start = alias.era_start ?? ''
    aliasForm.era_end = alias.era_end ?? ''
    aliasForm.is_active = alias.is_active ?? false
}

const cancelAliasEdit = () => {
    editingAliasId.value = null
    aliasForm.clearErrors()
    resetAliasForm()
}

const submitAlias = () => {
    if (isEditingAlias.value) {
        aliasForm.put(route('entities.aliases.update', [props.entity.id, editingAliasId.value]), {
            onSuccess: () => cancelAliasEdit(),
        })
        return
    }

    aliasForm.post(route('entities.aliases.store', props.entity.id), {
        onSuccess: () => resetAliasForm(),
    })
}

const deleteAlias = (aliasId) => {
    router.delete(route('entities.aliases.destroy', [props.entity.id, aliasId]))
}

// --- Note form ---

const noteForm = useForm({
    note_label: '',
    content:    '',
    sort_order: '',
})

const isEditingNote = computed(() => editingNoteId.value !== null)

const resetNoteForm = () => {
    noteForm.reset()
}

const beginNoteEdit = (note) => {
    editingNoteId.value = note.id
    noteForm.note_label = note.note_label ?? ''
    noteForm.content = note.content ?? ''
    noteForm.sort_order = note.sort_order ?? ''
}

const cancelNoteEdit = () => {
    editingNoteId.value = null
    noteForm.clearErrors()
    resetNoteForm()
}

const submitNote = () => {
    if (isEditingNote.value) {
        noteForm.put(route('entities.notes.update', [props.entity.id, editingNoteId.value]), {
            onSuccess: () => cancelNoteEdit(),
        })
        return
    }

    noteForm.post(route('entities.notes.store', props.entity.id), {
        onSuccess: () => resetNoteForm(),
    })
}

const deleteNote = (noteId) => {
    router.delete(route('entities.notes.destroy', [props.entity.id, noteId]))
}

// --- Question form ---

const questionForm = useForm({
    question:   '',
    context:    '',
    priority:   'medium',
    status:     'open',
    resolution: '',
})

const isEditingQuestion = computed(() => editingQuestionId.value !== null)

const resetQuestionForm = () => {
    questionForm.reset()
    questionForm.priority = 'medium'
    questionForm.status = 'open'
}

const beginQuestionEdit = (question) => {
    editingQuestionId.value = question.id
    questionForm.question = question.question ?? ''
    questionForm.context = question.context ?? ''
    questionForm.priority = question.priority ?? 'medium'
    questionForm.status = question.status ?? 'open'
    questionForm.resolution = question.resolution ?? ''
}

const cancelQuestionEdit = () => {
    editingQuestionId.value = null
    questionForm.clearErrors()
    resetQuestionForm()
}

const submitQuestion = () => {
    if (isEditingQuestion.value) {
        questionForm.put(route('entities.questions.update', [props.entity.id, editingQuestionId.value]), {
            onSuccess: () => cancelQuestionEdit(),
        })
        return
    }

    questionForm.post(route('entities.questions.store', props.entity.id), {
        onSuccess: () => resetQuestionForm(),
    })
}

const resolveQuestion = (questionId) => {
    router.put(route('entities.questions.update', [props.entity.id, questionId]), {
        status: 'resolved',
    })
}

const deleteQuestion = (questionId) => {
    router.delete(route('entities.questions.destroy', [props.entity.id, questionId]))
}

const destroyEntity = () => {
    if (!confirm(`Move "${props.entity.name}" to trash?`)) {
        return
    }

    router.delete(route('entities.destroy', props.entity.id))
}

// --- Static options ---

const priorityOptions = [
    { value: 'blocking', label: 'Blocking' },
    { value: 'high',     label: 'High'     },
    { value: 'medium',   label: 'Medium'   },
    { value: 'low',      label: 'Low'      },
]

const questionStatusOptions = [
    { value: 'open',     label: 'Open'     },
    { value: 'deferred', label: 'Deferred' },
    { value: 'resolved', label: 'Resolved' },
]

const applyRouteState = () => {
    const requestedTab = urlParams.value.get('tab')
    const nextTab = validTabs.includes(requestedTab) ? requestedTab : 'identity'

    activeTab.value = nextTab

    const editAliasId = Number(urlParams.value.get('edit_alias'))
    const editNoteId = Number(urlParams.value.get('edit_note'))
    const editQuestionId = Number(urlParams.value.get('edit_question'))

    if (Number.isInteger(editAliasId) && editAliasId > 0) {
        const alias = props.entity.aliases?.find((record) => record.id === editAliasId)

        if (alias) {
            beginAliasEdit(alias)
        }
    } else if (editingAliasId.value !== null) {
        cancelAliasEdit()
    }

    if (Number.isInteger(editNoteId) && editNoteId > 0) {
        const note = props.entity.notes?.find((record) => record.id === editNoteId)

        if (note) {
            beginNoteEdit(note)
        }
    } else if (editingNoteId.value !== null) {
        cancelNoteEdit()
    }

    if (Number.isInteger(editQuestionId) && editQuestionId > 0) {
        const question = props.entity.questions?.find((record) => record.id === editQuestionId)

        if (question) {
            beginQuestionEdit(question)
        }
    } else if (editingQuestionId.value !== null) {
        cancelQuestionEdit()
    }
}

watch(
    () => page.url,
    () => applyRouteState(),
    { immediate: true },
)

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
    padding: 10px 18px;
    font-size: 12px;
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
    border-radius: 8px;
    padding: 18px 20px;
}
.panel-label {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    margin-bottom: 8px;
}
.field-label {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    white-space: nowrap;
    min-width: 100px;
    flex-shrink: 0;
    padding-top: 3px;
}

.prose-wrap {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}

/* --- Type chip (header) --- */
.type-chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
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
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    border: 1px solid;
}
.status-badge--sm { padding: 2px 8px; font-size: 10px; }
.status--active   { color: #6ee7b7; border-color: rgba(110,231,183,0.3); background: rgba(110,231,183,0.08); }
.status--concept  { color: var(--text-muted-3); border-color: var(--border-color); background: transparent; }
.status--archived { color: #64748b; border-color: rgba(100,116,139,0.3); background: rgba(100,116,139,0.06); }
.status--deceased { color: var(--accent-pink); border-color: rgba(255,0,128,0.3); background: rgba(255,0,128,0.06); }
.status--dormant  { color: #fcd34d; border-color: rgba(252,211,77,0.3); background: rgba(252,211,77,0.06); }
.status--default  { color: var(--text-muted-3); border-color: var(--border-color); }

/* --- Universe chips --- */
.universe-chip {
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 11px;
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
    font-size: 11px;
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
    padding: 2px 7px;
    border-radius: 3px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    color: var(--accent-cyan);
    border: 1px solid rgba(0, 245, 255, 0.2);
    background: rgba(0, 245, 255, 0.06);
}

/* --- Buttons --- */
.btn-ghost {
    display: inline-flex;
    align-items: center;
    height: 36px;
    padding: 0 16px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-2);
    transition: border-color 0.15s, color 0.15s;
}
.btn-ghost:hover {
    border-color: var(--border-color-2);
    color: var(--text-primary);
}
.btn-danger {
    display: inline-flex;
    align-items: center;
    height: 36px;
    padding: 0 16px;
    border: 1px solid rgba(255, 0, 128, 0.22);
    border-radius: 6px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--accent-pink);
    background: rgba(255, 0, 128, 0.05);
    transition: background 0.15s, border-color 0.15s;
}
.btn-danger:hover {
    background: rgba(255, 0, 128, 0.1);
    border-color: rgba(255, 0, 128, 0.4);
}
.btn-ghost--sm {
    height: 30px;
    padding: 0 12px;
    font-size: 11px;
}

/* --- Utility colors --- */
.text-warn { color: #fcd34d; }
.text-cyan  { color: var(--accent-cyan); }
.text-danger { color: var(--accent-pink); }
.bg-success { background: #6ee7b7; }
.bg-focus   { background: var(--accent-cyan); }
.bg-warn    { background: #fcd34d; }
.bg-danger  { background: var(--accent-pink); }

/* --- Forms in panels --- */
.field-group { display: flex; flex-direction: column; gap: 8px; }
.input {
    height: 40px;
    padding: 0 12px;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 14px;
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
}
.input:focus { border-color: var(--accent-cyan); }
textarea.input { height: auto; padding: 10px 12px; }
select.input option { background: var(--bg-surface); }
.checkbox { accent-color: var(--accent-cyan); width: 16px; height: 16px; }

/* --- Pill buttons (question priority/status) --- */
.pill-btn {
    padding: 5px 12px;
    border-radius: 3px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    border: 1px solid var(--border-color);
    color: var(--text-muted-3);
    background: transparent;
    transition: border-color 0.12s, color 0.12s, background 0.12s;
}
.pill-btn:hover { border-color: var(--border-color-2); color: var(--text-muted-2); }
.pill-btn--selected { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(0,245,255,0.08); }
.pill-btn--blocking.pill-btn--selected { border-color: var(--accent-pink); color: var(--accent-pink); background: rgba(255,0,128,0.08); }

/* --- Action buttons --- */
.btn-primary {
    display: inline-flex; align-items: center;
    height: 38px; padding: 0 18px;
    background: rgba(0,245,255,0.1); border: 1px solid rgba(0,245,255,0.3);
    border-radius: 6px; font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--accent-cyan); transition: background 0.15s, border-color 0.15s;
}
.btn-primary:hover:not(:disabled) { background: rgba(0,245,255,0.15); border-color: rgba(0,245,255,0.5); }
.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-resolve {
    display: inline-flex; align-items: center;
    height: 30px; padding: 0 12px;
    background: rgba(110,231,183,0.08); border: 1px solid rgba(110,231,183,0.3);
    border-radius: 4px; font-size: 11px; font-family: ui-monospace, monospace;
    color: #6ee7b7; transition: background 0.15s;
}
.btn-resolve:hover { background: rgba(110,231,183,0.15); }
.btn-danger-sm {
    display: inline-flex; align-items: center;
    height: 30px; padding: 0 12px;
    background: transparent; border: 1px solid var(--border-color);
    border-radius: 4px; font-size: 11px; font-family: ui-monospace, monospace;
    color: var(--text-muted-3); transition: border-color 0.12s, color 0.12s;
}
.btn-danger-sm:hover { border-color: var(--accent-pink); color: var(--accent-pink); }

/* --- Empty state --- */
.empty-state {
    padding: 32px 16px; text-align: center;
    font-size: 12px; font-family: ui-monospace, monospace;
    color: var(--text-muted-3); letter-spacing: 0.08em;
    border: 1px dashed var(--border-color); border-radius: 6px;
}

/* --- Alias row --- */
.alias-row {
    padding: 16px 18px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
}
.alias-type-chip {
    padding: 2px 8px; border-radius: 3px;
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--text-muted-3); border: 1px solid var(--border-color);
}

/* --- Note card --- */
.note-card {
    padding: 16px 18px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
}
.note-label {
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--accent-cyan);
}
.note-label--empty {
    color: var(--text-muted-3);
    font-style: italic;
}

/* --- Question card --- */
.question-card {
    padding: 16px 18px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    transition: border-color 0.15s;
}
.question-card--blocking { border-color: rgba(255,0,128,0.25); }
.question-card--resolved { opacity: 0.5; }
.priority-chip {
    padding: 2px 8px; border-radius: 3px;
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.06em; text-transform: uppercase;
    border: 1px solid;
}
.priority--blocking { color: var(--accent-pink); border-color: rgba(255,0,128,0.3); background: rgba(255,0,128,0.08); }
.priority--high     { color: #fcd34d; border-color: rgba(252,211,77,0.3); background: rgba(252,211,77,0.06); }
.priority--medium   { color: var(--text-muted-2); border-color: var(--border-color); background: transparent; }
.priority--low      { color: var(--text-muted-3); border-color: var(--border-color); background: transparent; }
.status-q-chip {
    padding: 2px 8px; border-radius: 3px;
    font-size: 11px; font-family: ui-monospace, monospace;
    letter-spacing: 0.06em; text-transform: uppercase;
    border: 1px solid;
}
.qstatus--open     { color: var(--accent-cyan); border-color: rgba(0,245,255,0.2); background: rgba(0,245,255,0.06); }
.qstatus--deferred { color: var(--text-muted-3); border-color: var(--border-color); background: transparent; }
.qstatus--resolved { color: #6ee7b7; border-color: rgba(110,231,183,0.2); background: rgba(110,231,183,0.06); }
</style>
