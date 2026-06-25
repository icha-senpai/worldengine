<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="page-hero">

                <div class="page-hero__copy min-w-0">
                    <div class="page-hero__eyebrow">
                        <Link :href="route('pipeline.index')" class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors">
                            Pipeline
                        </Link>
                        <span>/</span>
                        <span v-if="item.parent" class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors">
                            <Link :href="route('pipeline.show', item.parent.id)">{{ item.parent.title }}</Link>
                        </span>
                        <span v-if="item.parent">/</span>
                        <span class="type-chip" :class="'type--' + item.pipeline_type">
                            {{ formatLabel(item.pipeline_type) }}
                        </span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--md">
                        {{ item.title }}
                    </h1>
                    <p v-if="pipelineSubtitle" class="page-hero__subtitle prose-wrap">
                        {{ pipelineSubtitle }}
                    </p>
                    <div v-if="pipelineHeroMeta.length" class="page-hero__meta">
                        <div
                            v-for="meta in pipelineHeroMeta"
                            :key="meta.label"
                            class="page-hero__meta-item"
                        >
                            <span class="page-hero__meta-label">{{ meta.label }}</span>
                            <span class="page-hero__meta-value">{{ meta.value }}</span>
                        </div>
                    </div>
                </div>

                <div class="page-hero__actions">
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
                        opens-drawer
                        variant="ghost"
                    >
                        Edit
                    </AppButton>
                </div>

            </div>
        </template>

        <div class="detail-shell">

            <div v-if="showMetricStrip" class="dashboard-metric-strip">
                <div class="dashboard-metric">
                    <span class="dashboard-metric__label">Stage</span>
                    <span class="dashboard-metric__value">{{ formatLabel(item.pipeline_stage || 'concept') }}</span>
                </div>
                <div class="dashboard-metric">
                    <span class="dashboard-metric__label">Words</span>
                    <span class="dashboard-metric__value">{{ item.word_count ? item.word_count.toLocaleString() : '—' }}</span>
                </div>
                <div class="dashboard-metric">
                    <span class="dashboard-metric__label">Reading Time</span>
                    <span class="dashboard-metric__value">{{ readingTime }}</span>
                </div>
                <div class="dashboard-metric">
                    <span class="dashboard-metric__label">Sub-Items</span>
                    <span class="dashboard-metric__value">{{ item.children?.length ?? 0 }}</span>
                </div>
            </div>

            <!-- CONTENT (prose/notes body) -->
            <div v-if="item.content" class="panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-label mb-1!">Content</h3>
                        <p class="panel-copy">Primary draft material or core writing payload for this pipeline item.</p>
                    </div>
                </div>
                <RichDocumentValue
                    v-if="isRichDocument(item.content)"
                    :content="item.content"
                />
                <div v-else class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.content }}</div>
            </div>

            <!-- SCENE DETAILS -->
            <div v-if="item.pipeline_type === 'scene'" class="grid gap-4 md:grid-cols-2">

                <div class="panel">
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-label mb-1!">Scene</h3>
                            <p class="panel-copy">Point-of-view anchors and scene-level framing details.</p>
                        </div>
                    </div>
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
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-label mb-1!">Narrative Purpose</h3>
                            <p class="panel-copy">Why this scene exists and what it needs to move in the story.</p>
                        </div>
                    </div>
                    <RichDocumentValue
                        v-if="isRichDocument(item.narrative_purpose)"
                        :content="item.narrative_purpose"
                    />
                    <p v-else class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.narrative_purpose }}</p>
                </div>

            </div>

            <!-- CHARACTER STUDY / ARC TRACKER -->
            <div v-if="item.pipeline_type === 'character_study' && (item.tracked_entity || item.arc_stage || item.arc_notes)" class="panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-label mb-1!">Arc Tracker</h3>
                        <p class="panel-copy">Track the character focus, their current stage, and any notes shaping the arc.</p>
                    </div>
                </div>
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
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-label mb-1!">Author Notes</h3>
                        <p class="panel-copy">Private notes for revisions, constraints, and decisions around this item.</p>
                    </div>
                </div>
                <RichDocumentValue
                    v-if="isRichDocument(item.notes)"
                    :content="item.notes"
                />
                <p v-else class="prose-block text-muted-2 text-sm leading-relaxed">{{ item.notes }}</p>
            </div>

            <!-- CHILDREN (sub-items: scenes under a chapter, etc.) -->
            <div v-if="item.children && item.children.length" class="panel space-y-4">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-label mb-1!">Sub-Items</h3>
                        <p class="panel-copy">Nested scenes, chapters, and supporting pieces attached to this parent item.</p>
                    </div>
                    <span class="mini-chip">{{ item.children.length }} linked</span>
                </div>
                <div class="space-y-3">
                    <Link
                        v-for="child in item.children"
                        :key="child.id"
                        :href="route('pipeline.show', child.id)"
                        class="record-card record-card--interactive"
                    >
                        <div class="flex flex-wrap items-center gap-3">
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
                            <span v-if="child.word_count" class="text-muted-3 text-sm font-ui">
                                {{ child.word_count.toLocaleString() }}w
                            </span>
                        </div>
                    </Link>
                </div>
                <DrawerLink
                    :href="route('pipeline.create', { parent: item.id })"
                    opens-drawer
                    title="New Pipeline Sub-Item"
                    class="add-child-btn"
                >
                    + Add sub-item
                </DrawerLink>
            </div>

            <!-- ACCESS -->
            <div class="panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-label mb-1!">Access</h3>
                        <p class="panel-copy">Visibility and classification settings controlling who should treat this item as readable or sensitive.</p>
                    </div>
                </div>
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

            <DrawerRouteShell
                v-if="showEditDrawer"
                :open="showEditDrawer"
                :ready="Boolean(editDrawer)"
                title="Edit Pipeline Item"
                :close-href="route('pipeline.show', item.id)"
                back-label="Writing Pipeline"
                :back-href="route('pipeline.index')"
            >
                <EditPipelineItem
                    v-if="editDrawer"
                    embedded
                    :item="item"
                    v-bind="editDrawer"
                />
            </DrawerRouteShell>

            <DrawerRouteShell
                v-if="showCreateDrawer"
                :open="showCreateDrawer"
                :ready="Boolean(createDrawer)"
                title="New Pipeline Sub-Item"
                :close-href="route('pipeline.show', item.id)"
                back-label="Pipeline Item"
                :back-href="route('pipeline.show', item.id)"
            >
                <CreatePipelineItem
                    v-if="createDrawer"
                    embedded
                    v-bind="createDrawer"
                />
            </DrawerRouteShell>

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
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import DrawerLink from '@/Components/ui/DrawerLink.vue'
import CreatePipelineItem from '@/Pages/Production/Pipeline/Create.vue'
import EditPipelineItem from '@/Pages/Production/Pipeline/Edit.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { formatLabel, isRichDocument } from '@/Components/scaffold/formatters'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const props = defineProps({
    item: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
    createDrawer: { type: Object, default: null },
})

const page = usePage()
const notionNote = computed(() => page.props?.notionNote ?? null)
const RichDocumentValue = defineAsyncComponent(() => import('@/Components/scaffold/RichDocumentValue.vue'))
const showEditDrawer = computed(() =>
    Boolean(props.editDrawer) || matchesPendingDrawerHref(route('pipeline.edit', props.item.id))
)
const showCreateDrawer = computed(() =>
    Boolean(props.createDrawer) || matchesPendingDrawerHref(route('pipeline.create', { parent: props.item.id }))
)

const stageProgression = {
    concept:  'outlined',
    outlined: 'drafted',
    drafted:  'revised',
    revised:  'complete',
}

const canAdvance = computed(() =>
    props.item.pipeline_stage in stageProgression
)
const pipelineSubtitle = computed(() => {
    if (props.item.parent?.title) {
        return `Nested under ${props.item.parent.title}, this ${formatLabel(props.item.pipeline_type).toLowerCase()} tracks its own writing stage and attached notes.`
    }

    return `${formatLabel(props.item.pipeline_type)} item in the writing pipeline with stage tracking, notes, and linked sub-items.`
})
const pipelineHeroMeta = computed(() => [
    { label: 'Stage', value: formatLabel(props.item.pipeline_stage || 'concept') },
    { label: 'Visibility', value: formatLabel(props.item.visibility || 'private') },
    { label: 'Words', value: props.item.word_count ? props.item.word_count.toLocaleString() : '—' },
    { label: 'Reading', value: readingTime.value },
])
const showMetricStrip = computed(() =>
    Boolean(props.item.pipeline_stage || props.item.word_count || props.item.reading_time_minutes || props.item.children?.length)
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

const destroy = async () => {
    const confirmed = await confirmDialog({
        title: 'Move to Trash',
        message: `Move "${props.item.title}" to trash?`,
        confirmLabel: 'Move to Trash',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(route('pipeline.destroy', props.item.id), {
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not move pipeline item to trash',
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}

</script>

