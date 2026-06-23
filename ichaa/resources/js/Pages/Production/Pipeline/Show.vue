<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-start justify-between gap-4">

                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="route('pipeline.index')" class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors">
                            Pipeline
                        </Link>
                        <span class="text-muted-3 text-sm font-ui">/</span>
                        <span v-if="item.parent" class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors">
                            <Link :href="route('pipeline.show', item.parent.id)">{{ item.parent.title }}</Link>
                        </span>
                        <span v-if="item.parent" class="text-muted-3 text-sm font-ui">/</span>
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
                    <AppButton
                        v-if="canAdvance"
                        @click="advance"
                        variant="success"
                    >
                        Advance →
                    </AppButton>
                    <AppButton
                        :href="route('pipeline.edit', item.id)"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        variant="ghost"
                    >
                        Edit
                    </AppButton>
                </div>

            </div>
        </template>

        <div class="space-y-5">

            <!-- STATS BAR (word count / reading time) -->
            <div v-if="item.word_count" class="flex items-center gap-4 p-3 bg-surface-2 border border-border rounded-md">
                <div class="metric">
                    <span class="metric-label">Words</span>
                    <span class="metric-value">{{ item.word_count.toLocaleString() }}</span>
                </div>
                <div v-if="item.reading_time_minutes" class="metric">
                    <span class="metric-label">Reading Time</span>
                    <span class="metric-value">{{ readingTime }}</span>
                </div>
                <div v-if="item.pipeline_stage" class="metric">
                    <span class="metric-label">Stage</span>
                    <span class="metric-value">{{ formatLabel(item.pipeline_stage) }}</span>
                </div>
            </div>

            <!-- CONTENT (prose/notes body) -->
            <div v-if="item.content" class="panel">
                <h3 class="panel-label">Content</h3>
                <RichDocumentValue
                    v-if="isRichDocument(item.content)"
                    :content="item.content"
                />
                <div v-else class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.content }}</div>
            </div>

            <!-- SCENE DETAILS -->
            <div v-if="item.pipeline_type === 'scene'" class="grid grid-cols-2 gap-4">

                <div class="panel">
                    <h3 class="panel-label">Scene</h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">POV</span>
                            <span v-if="item.pov_character" class="text-muted-2 text-sm">
                                <Link :href="route('entities.show', item.pov_character.id)" class="hover:text-primary transition-colors">
                                    {{ item.pov_character.name }}
                                </Link>
                            </span>
                            <span v-else class="text-muted-3 text-sm font-ui">—</span>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">Location</span>
                            <span v-if="item.location" class="text-muted-2 text-sm">
                                <Link :href="route('entities.show', item.location.id)" class="hover:text-primary transition-colors">
                                    {{ item.location.name }}
                                </Link>
                            </span>
                            <span v-else class="text-muted-3 text-sm font-ui">—</span>
                        </div>
                        <div v-if="item.emotional_beat" class="flex items-start gap-2">
                            <span class="field-label field-label--fixed">Beat</span>
                            <span class="accent-tag">{{ formatLabel(item.emotional_beat) }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="item.narrative_purpose" class="panel">
                    <h3 class="panel-label">Narrative Purpose</h3>
                    <RichDocumentValue
                        v-if="isRichDocument(item.narrative_purpose)"
                        :content="item.narrative_purpose"
                    />
                    <p v-else class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.narrative_purpose }}</p>
                </div>

            </div>

            <!-- CHARACTER STUDY / ARC TRACKER -->
            <div v-if="item.pipeline_type === 'character_study' && (item.tracked_entity || item.arc_stage || item.arc_notes)" class="panel">
                <h3 class="panel-label">Arc Tracker</h3>
                <div class="space-y-2">
                    <div v-if="item.tracked_entity" class="flex items-start gap-2">
                        <span class="field-label field-label--fixed">Character</span>
                        <Link :href="route('entities.show', item.tracked_entity.id)" class="text-muted-2 text-sm hover:text-primary transition-colors">
                            {{ item.tracked_entity.name }}
                        </Link>
                    </div>
                    <div v-if="item.arc_stage" class="flex items-start gap-2">
                        <span class="field-label field-label--fixed">Arc Stage</span>
                        <span class="accent-tag">{{ formatLabel(item.arc_stage) }}</span>
                    </div>
                    <div v-if="item.arc_notes" class="flex items-start gap-2">
                        <span class="field-label field-label--fixed">Notes</span>
                        <div class="min-w-0 flex-1">
                            <RichDocumentValue
                                v-if="isRichDocument(item.arc_notes)"
                                :content="item.arc_notes"
                            />
                            <span v-else class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.arc_notes }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AUTHOR NOTES -->
            <div v-if="item.notes" class="panel">
                <h3 class="panel-label">Author Notes</h3>
                <RichDocumentValue
                    v-if="isRichDocument(item.notes)"
                    :content="item.notes"
                />
                <p v-else class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.notes }}</p>
            </div>

            <!-- CHILDREN (sub-items: scenes under a chapter, etc.) -->
            <div v-if="item.children && item.children.length" class="space-y-2">
                <h3 class="subsection-label">Sub-Items ({{ item.children.length }})</h3>
                <Link
                    v-for="child in item.children"
                    :key="child.id"
                    :href="route('pipeline.show', child.id)"
                    class="record-card record-card--interactive"
                >
                    <div class="flex items-center gap-3">
                        <span class="type-chip" :class="'type--' + child.pipeline_type">
                            {{ formatLabel(child.pipeline_type) }}
                        </span>
                        <span class="stage-badge stage-badge--sm" :class="'stage--' + child.pipeline_stage">
                            {{ formatLabel(child.pipeline_stage) }}
                        </span>
                        <span class="text-muted-2 text-sm">{{ child.title }}</span>
                        <span v-if="child.pov_character" class="text-muted-3 text-sm font-ui ml-auto">
                            {{ child.pov_character.name }}
                        </span>
                        <span v-if="child.word_count" class="text-muted-3 text-sm font-ui ml-auto">
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
                        <span class="field-label field-label--fixed">Visibility</span>
                        <span class="text-muted-2 text-sm">{{ formatLabel(item.visibility) }}</span>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="field-label field-label--fixed">Classification</span>
                        <span class="text-muted-2 text-sm">{{ formatLabel(item.content_classification) }}</span>
                    </div>
                </div>
            </div>

            <NotionNotePanel :note="notionNote" />

            <EditPipelineItem
                v-if="editDrawer"
                embedded
                :item="item"
                v-bind="editDrawer"
            />

            <CreatePipelineItem
                v-if="createDrawer"
                embedded
                v-bind="createDrawer"
            />

            <!-- DANGER ZONE -->
            <div class="flex items-center justify-end pt-2 border-t border-border">
                <AppButton type="button" variant="danger" @click="destroy">Move to Trash</AppButton>
            </div>

        </div>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed, defineAsyncComponent } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import NotionNotePanel from '@/Components/NotionNotePanel.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import CreatePipelineItem from '@/Pages/Production/Pipeline/Create.vue'
import EditPipelineItem from '@/Pages/Production/Pipeline/Edit.vue'
import { isRichDocument } from '@/Components/scaffold/formatters'

const props = defineProps({
    item: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
    createDrawer: { type: Object, default: null },
})

const page = usePage()
const notionNote = computed(() => page.props?.notionNote ?? null)
const RichDocumentValue = defineAsyncComponent(() => import('@/Components/scaffold/RichDocumentValue.vue'))

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

