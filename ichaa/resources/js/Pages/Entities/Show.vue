<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-start justify-between gap-4">

                <!-- Left: name + breadcrumb -->
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="route('entities.index')" class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors">
                            Entities
                        </Link>
                        <span class="text-muted-3 text-sm font-ui">/</span>
                        <span class="type-chip" :class="typeBadgeClass(entity.entity_type)">
                            {{ formatLabel(entity.entity_type) }}
                        </span>
                    </div>
                    <h1 class="text-primary text-2xl font-light tracking-wide leading-tight">
                        {{ entity.name }}
                    </h1>
                    <p v-if="entity.public_title" class="text-muted-3 text-sm font-ui mt-0.5 italic">
                        "{{ entity.public_title }}"
                    </p>
                </div>

                <!-- Right: actions -->
                <div class="flex items-center gap-2 flex-shrink-0 pt-1">
                    <span class="status-badge" :class="statusBadgeClass(entity.status)">
                        {{ formatLabel(entity.status) }}
                    </span>
                    <AppButton type="button" variant="danger" @click="destroyEntity">
                        Move to Trash
                    </AppButton>
                    <AppButton
                        :href="route('entities.edit', entity.id)"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        variant="ghost"
                    >
                        Edit
                    </AppButton>
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
                <span class="text-muted-3 text-xs font-ui uppercase tracking-widest whitespace-nowrap">
                    Completion
                </span>
                <div class="flex-1 h-1.5 bg-surface rounded-full overflow-hidden">
                    <div
                        class="h-full rounded-full transition-all duration-500"
                        :class="completionBarClass(entity.completion_score)"
                        :style="{ width: entity.completion_score + '%' }"
                    />
                </div>
                <span class="text-muted-2 text-sm font-ui w-9 text-right">
                    {{ entity.completion_score }}%
                </span>
                <div class="flex items-center gap-2 ml-2">
                    <span v-if="entity.has_attributes"       class="accent-tag">attr</span>
                    <span v-if="entity.has_relationships"    class="accent-tag">rel</span>
                    <span v-if="entity.has_timeline_entries" class="accent-tag">time</span>
                    <span v-if="entity.has_aliases"          class="accent-tag">alias</span>
                    <span v-if="entity.has_media"            class="accent-tag">media</span>
                </div>
            </div>

            <!-- Core fields grid -->
            <div class="grid grid-cols-2 gap-4">

                <!-- Summary -->
                <div v-if="entity.summary" class="col-span-2 panel">
                    <h3 class="panel-label">Summary</h3>
                    <RichDocumentValue
                        v-if="isRichDocument(entity.summary)"
                        :content="entity.summary"
                    />
                    <p v-else class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ entity.summary }}</p>
                </div>

                <!-- Public summary -->
                <div v-if="entity.public_summary" class="col-span-2 panel">
                    <h3 class="panel-label">Public Summary <span class="text-muted-3 normal-case font-normal">(visible persona)</span></h3>
                    <RichDocumentValue
                        v-if="isRichDocument(entity.public_summary)"
                        :content="entity.public_summary"
                    />
                    <p v-else class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ entity.public_summary }}</p>
                </div>

                <!-- Classification -->
                <div class="panel">
                    <h3 class="panel-label">Classification</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label field-label--fixed">Type</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.entity_type) }}</span></div>
                        <div v-if="entity.entity_sub_type" class="flex items-start gap-2"><span class="field-label field-label--fixed">Subtype</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.entity_sub_type) }}</span></div>
                        <div class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">Status</span>
                            <span class="status-badge status-badge--sm" :class="statusBadgeClass(entity.status)">
                                {{ formatLabel(entity.status) }}
                            </span>
                        </div>
                        <div v-if="entity.type_status" class="flex items-start gap-2"><span class="field-label field-label--fixed">Type Status</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.type_status) }}</span></div>
                    </div>
                </div>

                <!-- Visibility & Access -->
                <div class="panel">
                    <h3 class="panel-label">Access</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label field-label--fixed">Visibility</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.visibility) }}</span></div>
                        <div class="flex items-start gap-2"><span class="field-label field-label--fixed">Classification</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.content_classification) }}</span></div>
                        <div v-if="entity.published_at" class="flex items-start gap-2"><span class="field-label field-label--fixed">Published</span><span class="text-muted-2 text-sm">{{ formatDate(entity.published_at) }}</span></div>
                    </div>
                </div>

                <!-- Origin -->
                <div class="panel">
                    <h3 class="panel-label">Origin</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2"><span class="field-label field-label--fixed">Origin Type</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.origin_type) }}</span></div>
                        <div v-if="entity.canon_deviation" class="flex items-start gap-2"><span class="field-label field-label--fixed">Canon Deviation</span><span class="text-muted-2 text-sm">{{ formatLabel(entity.canon_deviation) }}</span></div>
                        <div v-if="entity.origin_notes" class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">Notes</span>
                            <div class="min-w-0 flex-1">
                                <RichDocumentValue
                                    v-if="isRichDocument(entity.origin_notes)"
                                    :content="entity.origin_notes"
                                />
                                <span v-else class="text-muted-2 text-sm">{{ entity.origin_notes }}</span>
                            </div>
                        </div>
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
                    <p v-else class="text-muted-3 text-sm font-ui">Native / Original</p>
                </div>

                <!-- Power Tiers — only if any are set -->
                <div v-if="hasPowerTiers" class="col-span-2 panel">
                    <h3 class="panel-label">Power Tiers</h3>
                    <div class="grid grid-cols-3 gap-4 mt-2">
                        <div v-if="entity.power_tier_ceiling" class="info-box">
                            <span class="info-box-label">Ceiling</span>
                            <span class="info-box-value">{{ formatLabel(entity.power_tier_ceiling) }}</span>
                        </div>
                        <div v-if="entity.power_tier_operating" class="info-box">
                            <span class="info-box-label">Operating</span>
                            <span class="info-box-value">{{ formatLabel(entity.power_tier_operating) }}</span>
                        </div>
                        <div v-if="entity.power_tier_influence" class="info-box">
                            <span class="info-box-label">Influence</span>
                            <span class="info-box-value">{{ formatLabel(entity.power_tier_influence) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Control State — only if set -->
                <div v-if="entity.control_state" class="panel">
                    <h3 class="panel-label">Control</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">Control State</span>
                            <span class="text-warn text-sm font-ui">{{ formatLabel(entity.control_state) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Persona divergence — only if relevant -->
                <div v-if="entity.persona_divergence" class="panel">
                    <h3 class="panel-label">Persona Divergence</h3>
                    <p class="text-muted-2 text-sm font-ui">{{ formatLabel(entity.persona_divergence) }}</p>
                </div>

            </div>
        </div>

        <!-- TAB: ALIASES -->
        <div v-if="activeTab === 'aliases'" class="space-y-4">

            <!-- Add alias form -->
            <div class="panel">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h3 class="panel-label !mb-0">{{ isEditingAlias ? 'Edit Alias' : 'Add Alias' }}</h3>
                    <AppButton
                        v-if="isEditingAlias"
                        type="button"
                        @click="cancelAliasEdit"
                        variant="ghost"
                        size="sm"
                    >
                        Cancel
                    </AppButton>
                </div>
                <form @submit.prevent="submitAlias" class="space-y-3">
                    <div class="form-grid-2-tight">
                        <div class="field-group">
                            <label class="field-label">Alias <span class="text-danger">*</span></label>
                            <TextInput v-model="aliasForm.alias" type="text" placeholder="The alias text" class="w-full" />
                        </div>
                        <div class="field-group">
                            <label class="field-label">Type <span class="text-danger">*</span></label>
                            <SelectInput v-model="aliasForm.alias_type" class="w-full">
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
                            </SelectInput>
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Context <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <TextInput v-model="aliasForm.context" type="text" placeholder="When/where this alias is used" class="w-full" />
                    </div>
                    <div class="form-grid-2-tight">
                        <div class="field-group">
                            <label class="field-label">Era Start <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                            <TextInput v-model="aliasForm.era_start" type="text" placeholder="e.g. Year of the Dragon" class="w-full" />
                        </div>
                        <div class="field-group">
                            <label class="field-label">Era End <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                            <TextInput v-model="aliasForm.era_end" type="text" placeholder="Leave blank if still active" class="w-full" />
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <Checkbox v-model:checked="aliasForm.is_active" />
                            <span class="text-muted-2 text-sm font-ui">Currently active</span>
                        </label>
                        <AppButton type="submit" variant="primary" :disabled="aliasForm.processing || !aliasForm.alias || !aliasForm.alias_type">
                            {{ isEditingAlias ? 'Save Alias' : 'Add Alias' }}
                        </AppButton>
                    </div>
                </form>
            </div>

            <!-- Alias list -->
            <div v-if="entity.aliases && entity.aliases.length" class="space-y-2">
                <div v-for="a in entity.aliases" :key="a.id" class="record-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-primary text-sm">{{ a.alias }}</span>
                                <span class="alias-type-chip">{{ formatLabel(a.alias_type) }}</span>
                                <span v-if="!a.is_active" class="text-muted-3 text-xs font-ui">(inactive)</span>
                            </div>
                            <p v-if="a.context" class="text-muted-3 text-sm mt-1">{{ a.context }}</p>
                            <p v-if="a.era_start || a.era_end" class="text-muted-3 text-xs font-ui mt-1">
                                {{ a.era_start || '?' }} → {{ a.era_end || 'present' }}
                            </p>
                            <NotionNotePanel :note="a.notion_note" class="mt-3" />
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <AppButton
                                type="button"
                                @click="beginAliasEdit(a)"
                                variant="ghost"
                                size="sm"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                type="button"
                                @click="deleteAlias(a.id)"
                                variant="danger"
                                size="sm"
                            >Delete</AppButton>
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
                    <AppButton
                        v-if="isEditingNote"
                        type="button"
                        @click="cancelNoteEdit"
                        variant="ghost"
                        size="sm"
                    >
                        Cancel
                    </AppButton>
                </div>
                <form @submit.prevent="submitNote" class="space-y-3">
                    <div class="field-group">
                        <label class="field-label">Label <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <TextInput v-model="noteForm.note_label" type="text" placeholder="e.g. Backstory, Motivation, Arc notes..." class="w-full" />
                    </div>
                    <div class="field-group">
                        <label class="field-label">Content <span class="text-danger">*</span></label>
                        <TextareaInput v-model="noteForm.content" rows="4" placeholder="Note content..." class="input w-full resize-none" />
                    </div>
                    <div class="field-group">
                        <label class="field-label">Sort Order <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <TextInput v-model.number="noteForm.sort_order" type="number" placeholder="Display order" class="w-full" />
                    </div>
                    <AppButton type="submit" variant="primary" :disabled="noteForm.processing || !noteForm.content">
                        {{ isEditingNote ? 'Save Note' : 'Add Note' }}
                    </AppButton>
                </form>
            </div>

            <!-- Note list -->
            <div v-if="entity.notes && entity.notes.length" class="space-y-3">
                <div v-for="n in entity.notes" :key="n.id" class="record-card">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <span v-if="n.note_label" class="note-label">{{ n.note_label }}</span>
                        <span v-else class="note-label note-label--empty">unlabeled</span>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="text-muted-3 text-xs font-ui">{{ formatDate(n.created_at) }}</span>
                            <AppButton type="button" variant="ghost" size="sm" @click="beginNoteEdit(n)">Edit</AppButton>
                            <AppButton type="button" variant="danger" size="sm" @click="deleteNote(n.id)">Delete</AppButton>
                        </div>
                    </div>
                    <p class="prose-wrap text-muted-2 text-sm leading-relaxed">{{ n.content }}</p>
                    <NotionNotePanel :note="n.notion_note" class="mt-3" />
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
                    <AppButton
                        v-if="isEditingQuestion"
                        type="button"
                        @click="cancelQuestionEdit"
                        variant="ghost"
                        size="sm"
                    >
                        Cancel
                    </AppButton>
                </div>
                <form @submit.prevent="submitQuestion" class="space-y-3">
                    <div class="field-group">
                        <label class="field-label">Question <span class="text-danger">*</span></label>
                        <TextInput v-model="questionForm.question" type="text" placeholder="What needs to be resolved?" class="w-full" />
                    </div>
                    <div class="field-group">
                        <label class="field-label">Context <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <TextareaInput v-model="questionForm.context" rows="2" placeholder="Why does this matter? What does it affect?" class="input w-full resize-none" />
                    </div>
                    <div class="form-grid-2-tight">
                        <div class="field-group">
                            <label class="field-label">Priority</label>
                            <div class="flex gap-1.5 flex-wrap">
                                <AppButton
                                    v-for="p in priorityOptions"
                                    :key="p.value"
                                    type="button"
                                    @click="questionForm.priority = p.value"
                                    variant="select"
                                    :selected="questionForm.priority === p.value"
                                    :selected-tone="p.value === 'blocking' ? 'danger' : 'accent'"
                                >{{ p.label }}</AppButton>
                            </div>
                        </div>
                        <div class="field-group">
                            <label class="field-label">Status</label>
                            <div class="flex gap-1.5 flex-wrap">
                                <AppButton
                                    v-for="s in questionStatusOptions"
                                    :key="s.value"
                                    type="button"
                                    @click="questionForm.status = s.value"
                                    variant="select"
                                    :selected="questionForm.status === s.value"
                                >{{ s.label }}</AppButton>
                            </div>
                        </div>
                    </div>
                    <div v-if="isEditingQuestion || questionForm.status === 'resolved'" class="field-group">
                        <label class="field-label">Resolution <span class="text-muted-3 normal-case font-normal">(optional)</span></label>
                        <TextareaInput v-model="questionForm.resolution" rows="3" placeholder="How was this resolved?" class="input w-full resize-none" />
                    </div>
                    <AppButton type="submit" variant="primary" :disabled="questionForm.processing || !questionForm.question">
                        {{ isEditingQuestion ? 'Save Question' : 'Add Question' }}
                    </AppButton>
                </form>
            </div>

            <!-- Question list — blocking first, then by priority -->
            <div v-if="entity.questions && entity.questions.length" class="space-y-2">
                <div
                    v-for="q in entity.questions"
                    :key="q.id"
                    class="record-card"
                    :class="{ 'is-blocking': q.priority === 'blocking', 'is-dimmed': q.status === 'resolved' }"
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
                            <NotionNotePanel :note="q.notion_note" class="mt-3" />
                        </div>
                        <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                            <AppButton type="button" variant="ghost" size="sm" @click="beginQuestionEdit(q)">Edit</AppButton>
                            <AppButton
                                v-if="q.status !== 'resolved'"
                                type="button"
                                @click="resolveQuestion(q.id)"
                                variant="success"
                                size="sm"
                            >Resolve</AppButton>
                            <AppButton type="button" variant="danger" size="sm" @click="deleteQuestion(q.id)">Delete</AppButton>
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
                <p class="text-muted-3 text-sm font-ui">
                    Knowledge states and secrets involving this entity are managed from the
                    <Link :href="route('knowledge-states.index')" class="text-cyan hover:underline">Knowledge States</Link>
                    and
                    <Link :href="route('secrets.index')" class="text-cyan hover:underline">Secrets</Link>
                    domains. This tab will display a read-only summary once those pages are built.
                </p>
            </div>
        </div>

        <NotionNotePanel :note="notionNote" />

        <EditEntity
            v-if="editDrawer"
            embedded
            :entity="entity"
            :entity-types="editDrawer.entityTypes"
        />

        <EditFactionMembership
            v-if="factionMembershipEditDrawer"
            embedded
            :membership="factionMembershipEditDrawer.membership"
            :entities="factionMembershipEditDrawer.entities"
        />

        <CreateFactionMembership
            v-if="factionMembershipCreateDrawer"
            embedded
            v-bind="factionMembershipCreateDrawer"
        />

    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Checkbox from '@/Components/Checkbox.vue'
import NotionNotePanel from '@/Components/NotionNotePanel.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import CreateFactionMembership from '@/Pages/FactionMemberships/Create.vue'
import EditEntity from '@/Pages/Entities/Edit.vue'
import EditFactionMembership from '@/Pages/FactionMemberships/Edit.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextareaInput from '@/Components/TextareaInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { isRichDocument } from '@/Components/scaffold/formatters'

// --- Props ---

const props = defineProps({
    entity: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
    factionMembershipEditDrawer: { type: Object, default: null },
    factionMembershipCreateDrawer: { type: Object, default: null },
})

const page = usePage()
const notionNote = computed(() => page.props?.notionNote ?? null)
const RichDocumentValue = defineAsyncComponent(() => import('@/Components/scaffold/RichDocumentValue.vue'))

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

