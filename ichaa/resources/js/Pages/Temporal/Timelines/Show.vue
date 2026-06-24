<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="route('timelines.index')" class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors">
                            Timelines
                        </Link>
                        <span class="text-muted-3 text-sm font-ui">/</span>
                        <span class="chip">Timeline</span>
                    </div>
                    <h1 class="text-primary text-2xl font-light tracking-wide leading-tight">
                        {{ timeline.name }}
                    </h1>
                    <div v-if="timeline.summary" class="mt-2">
                        <RichDocumentValue
                            v-if="isRichDocument(timeline.summary)"
                            :content="timeline.summary"
                        />
                        <p v-else class="prose-wrap text-muted-3 text-base">
                            {{ timeline.summary }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <AppButton type="button" variant="danger" @click="destroyTimeline">
                        Move to Trash
                    </AppButton>
                    <AppButton
                        :href="route('timelines.edit', timeline.id)"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        variant="ghost"
                    >
                        Edit
                    </AppButton>
                </div>
            </div>
        </template>

        <div class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
            <section class="panel">
                <h3 class="panel-label">Overview</h3>

                <dl class="space-y-3">
                    <div class="entry-row">
                        <dt class="field-label">Status</dt>
                        <dd class="entry-value">{{ formatLabel(timeline.status) }}</dd>
                    </div>
                    <div class="entry-row">
                        <dt class="field-label">Visibility</dt>
                        <dd class="entry-value">{{ formatLabel(timeline.visibility) }}</dd>
                    </div>
                    <div class="entry-row">
                        <dt class="field-label">Entries</dt>
                        <dd class="entry-value">{{ chronologicalEntries.length }}</dd>
                    </div>
                    <div class="entry-row">
                        <dt class="field-label">Atemporal</dt>
                        <dd class="entry-value">{{ atemporalEntries.length }}</dd>
                    </div>
                </dl>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <h3 class="panel-label mb-1!">Place Event</h3>
                        <p class="panel-copy">Add an event entity directly onto this timeline without leaving the page.</p>
                    </div>
                </div>

                <form data-test="timeline-placement-form" class="space-y-4" @submit.prevent="submitPlacement">
                    <div class="field-group">
                        <label class="field-label" for="event_entity_id">Event Entity</label>
                        <SelectInput
                            id="event_entity_id"
                            v-model="placementForm.event_entity_id"
                            class="w-full"
                            :disabled="!availableEventOptions.length"
                        >
                            <option value="">{{ availableEventOptions.length ? 'Choose an event...' : 'No unplaced event entities available' }}</option>
                            <option
                                v-for="option in availableEventOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </SelectInput>
                        <p class="field-help">
                            {{ availableEventOptions.length ? 'Events already on this timeline are hidden from this picker.' : 'Create another event entity to place something new here.' }}
                        </p>
                        <p v-if="placementForm.errors.event_entity_id" class="field-error">{{ placementForm.errors.event_entity_id }}</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="field-group">
                            <label class="field-label" for="entry_label">Entry Label</label>
                            <TextInput
                                id="entry_label"
                                v-model="placementForm.entry_label"
                                type="text"
                                class="w-full"
                                placeholder="How this moment should be labeled on the timeline"
                            />
                            <p v-if="placementForm.errors.entry_label" class="field-error">{{ placementForm.errors.entry_label }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="au_date">AU Date</label>
                            <TextInput
                                id="au_date"
                                v-model="placementForm.au_date"
                                type="text"
                                class="w-full"
                                placeholder="Year 0, Cycle 12, Post-Fall..."
                            />
                            <p v-if="placementForm.errors.au_date" class="field-error">{{ placementForm.errors.au_date }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="source_date">Source Date</label>
                            <TextInput
                                id="source_date"
                                v-model="placementForm.source_date"
                                type="text"
                                class="w-full"
                                placeholder="Optional canon/source date"
                            />
                            <p v-if="placementForm.errors.source_date" class="field-error">{{ placementForm.errors.source_date }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="timeline_position">Timeline Position</label>
                            <TextInput
                                id="timeline_position"
                                v-model.number="placementForm.timeline_position"
                                type="number"
                                class="w-full"
                                min="0"
                                placeholder="Leave blank to auto-place"
                            />
                            <p v-if="placementForm.errors.timeline_position" class="field-error">{{ placementForm.errors.timeline_position }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="concurrency_group_id">Concurrency Group</label>
                            <SelectInput id="concurrency_group_id" v-model="placementForm.concurrency_group_id" class="w-full">
                                <option value="">Optional grouping...</option>
                                <option
                                    v-for="group in concurrencyGroupOptions"
                                    :key="group.value"
                                    :value="group.value"
                                >
                                    {{ group.label }}
                                </option>
                            </SelectInput>
                            <p v-if="placementForm.errors.concurrency_group_id" class="field-error">{{ placementForm.errors.concurrency_group_id }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="event_significance">Event Significance</label>
                            <SelectInput id="event_significance" v-model="placementForm.event_significance" class="w-full">
                                <option value="">Optional significance...</option>
                                <option
                                    v-for="level in eventSignificanceLevels"
                                    :key="level"
                                    :value="level"
                                >
                                    {{ formatLabel(level) }}
                                </option>
                            </SelectInput>
                            <p v-if="placementForm.errors.event_significance" class="field-error">{{ placementForm.errors.event_significance }}</p>
                        </div>
                    </div>

                    <label class="checkbox-row">
                        <Checkbox v-model:checked="placementForm.is_atemporal" />
                        <span>
                            <span class="field-label field-label--inline">Atemporal Event</span>
                            <span class="field-help field-help--inline">Keep this out of the chronological lane and list it as timeless/contextual.</span>
                        </span>
                    </label>

                    <div class="flex items-center gap-3 pt-1">
                        <AppButton
                            type="submit"
                            variant="primary"
                            :disabled="placementForm.processing || !placementForm.event_entity_id"
                        >
                            <span v-if="placementForm.processing">Placing...</span>
                            <span v-else>Place Event</span>
                        </AppButton>
                    </div>
                </form>
            </section>

            <section class="panel">
                <h3 class="panel-label">Chronological Events</h3>

                <ul v-if="chronologicalEntries.length" class="entry-list">
                    <li v-for="entry in chronologicalEntries" :key="entry.id" class="entry-card">
                        <div class="entry-card__body">
                            <div class="entry-card__title-row">
                                <Link
                                    v-if="entry.event_entity?.id"
                                    :href="route('entities.show', entry.event_entity.id)"
                                    class="entry-link"
                                >
                                    {{ entry.entry_label || entry.event_entity?.name || `Event #${entry.id}` }}
                                </Link>
                                <span v-else class="entry-link entry-link--static">
                                    {{ entry.entry_label || entry.event_entity?.name || `Event #${entry.id}` }}
                                </span>
                                <span v-if="entry.event_significance" class="mini-chip">
                                    {{ formatLabel(entry.event_significance) }}
                                </span>
                            </div>

                            <div class="entry-meta">
                                <span v-if="entry.au_date">{{ entry.au_date }}</span>
                                <span v-if="entry.timeline_position !== null && entry.timeline_position !== undefined">pos {{ entry.timeline_position }}</span>
                                <span v-if="entry.concurrency_group?.name">group {{ entry.concurrency_group.name }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <AppButton
                                :href="route('timelines.events.edit', { timeline: timeline.id, entry: entry.id })"
                                :preserve-scroll="true"
                                :preserve-state="true"
                                variant="ghost"
                                size="sm"
                                :data-test="`edit-entry-${entry.id}`"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                type="button"
                                variant="ghost"
                                size="sm"
                                :data-test="`remove-entry-${entry.id}`"
                                @click="removeEntry(entry)"
                            >
                                Remove
                            </AppButton>
                        </div>
                    </li>
                </ul>

                <p v-else class="empty-copy">No chronological entries yet.</p>
            </section>

            <section class="panel">
                <h3 class="panel-label">Atemporal Events</h3>

                <ul v-if="atemporalEntries.length" class="entry-list">
                    <li v-for="entry in atemporalEntries" :key="entry.id" class="entry-card">
                        <div class="entry-card__body">
                            <div class="entry-card__title-row">
                                <Link
                                    v-if="entry.event_entity?.id"
                                    :href="route('entities.show', entry.event_entity.id)"
                                    class="entry-link"
                                >
                                    {{ entry.entry_label || entry.event_entity?.name || `Event #${entry.id}` }}
                                </Link>
                                <span v-else class="entry-link entry-link--static">
                                    {{ entry.entry_label || entry.event_entity?.name || `Event #${entry.id}` }}
                                </span>
                                <span class="mini-chip">Atemporal</span>
                            </div>

                            <div class="entry-meta">
                                <span v-if="entry.source_date">source {{ entry.source_date }}</span>
                                <span v-if="entry.concurrency_group?.name">group {{ entry.concurrency_group.name }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <AppButton
                                :href="route('timelines.events.edit', { timeline: timeline.id, entry: entry.id })"
                                :preserve-scroll="true"
                                :preserve-state="true"
                                variant="ghost"
                                size="sm"
                                :data-test="`edit-entry-${entry.id}`"
                            >
                                Edit
                            </AppButton>
                            <AppButton
                                type="button"
                                variant="ghost"
                                size="sm"
                                :data-test="`remove-entry-${entry.id}`"
                                @click="removeEntry(entry)"
                            >
                                Remove
                            </AppButton>
                        </div>
                    </li>
                </ul>

                <p v-else class="empty-copy">No atemporal entries yet.</p>
            </section>
        </div>

        <NotionNotePanel :note="notionNote" />

        <DrawerRouteShell
            v-if="showEditDrawer"
            :open="showEditDrawer"
            :ready="Boolean(editDrawer)"
            title="Edit Timeline"
            :close-href="route('timelines.show', timeline.id)"
            back-label="Timelines"
            :back-href="route('timelines.index')"
        >
            <EditTimeline
                v-if="editDrawer"
                embedded
                :timeline="timeline"
                v-bind="editDrawer"
            />
        </DrawerRouteShell>

        <DrawerRouteShell
            v-if="showEventEditDrawer"
            :open="showEventEditDrawer"
            :ready="Boolean(eventEditDrawer)"
            title="Edit Timeline Event"
            :close-href="route('timelines.show', timeline.id)"
            back-label="Timeline"
            :back-href="route('timelines.show', timeline.id)"
        >
            <EditTimelineEvent
                v-if="eventEditDrawer"
                embedded
                :timeline="timeline"
                :entry="eventEditDrawer.entry"
                :concurrency-groups="eventEditDrawer.concurrencyGroups"
                :event-significance-levels="eventEditDrawer.eventSignificanceLevels"
            />
        </DrawerRouteShell>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, defineAsyncComponent } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Checkbox from '@/Components/Checkbox.vue'
import NotionNotePanel from '@/Components/NotionNotePanel.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import EditTimeline from '@/Pages/Temporal/Timelines/Edit.vue'
import EditTimelineEvent from '@/Pages/Temporal/Timelines/Events/Edit.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { formatLabel, isRichDocument, toEntityOptions } from '@/Components/scaffold/formatters'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const props = defineProps({
    timeline: { type: Object, required: true },
    atemporal: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    availableEvents: { type: Array, default: () => [] },
    concurrencyGroups: { type: Array, default: () => [] },
    eventSignificanceLevels: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
    eventEditDrawer: { type: Object, default: null },
})

const page = usePage()
const notionNote = computed(() => page.props?.notionNote ?? null)
const RichDocumentValue = defineAsyncComponent(() => import('@/Components/scaffold/RichDocumentValue.vue'))

const chronologicalEntries = computed(() => props.events ?? [])
const atemporalEntries = computed(() => props.atemporal ?? [])
const showEditDrawer = computed(() =>
    Boolean(props.editDrawer) || matchesPendingDrawerHref(route('timelines.edit', props.timeline.id))
)
const showEventEditDrawer = computed(() =>
    Boolean(props.eventEditDrawer)
    || chronologicalEntries.value.some((entry) => matchesPendingDrawerHref(route('timelines.events.edit', { timeline: props.timeline.id, entry: entry.id })))
    || atemporalEntries.value.some((entry) => matchesPendingDrawerHref(route('timelines.events.edit', { timeline: props.timeline.id, entry: entry.id })))
)
const availableEventOptions = computed(() => toEntityOptions(props.availableEvents))
const concurrencyGroupOptions = computed(() =>
    (props.concurrencyGroups ?? []).map((group) => ({
        value: group.id,
        label: [group.name, group.au_date].filter(Boolean).join(' · '),
    }))
)

const placementForm = useForm({
    event_entity_id: '',
    entry_label: '',
    au_date: '',
    source_date: '',
    timeline_position: '',
    concurrency_group_id: '',
    event_significance: '',
    is_atemporal: false,
})

const resetPlacementForm = () => {
    placementForm.reset()
    placementForm.is_atemporal = false
    placementForm.clearErrors()
}

const submitPlacement = () => {
    if (!placementForm.event_entity_id) {
        return
    }

    placementForm.post(
        route('timelines.events.place', {
            timeline: props.timeline.id,
            event: placementForm.event_entity_id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => resetPlacementForm(),
        },
    )
}

const removeEntry = (entry) => {
    router.delete(
        route('timelines.events.remove', {
            timeline: props.timeline.id,
            entry: entry.id,
        }),
        {
            preserveScroll: true,
        },
    )
}

const destroyTimeline = async () => {
    const confirmed = await confirmDialog({
        title: 'Move to Trash',
        message: `Move "${props.timeline.name}" to trash?`,
        confirmLabel: 'Move to Trash',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(route('timelines.destroy', props.timeline.id), {
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not move timeline to trash',
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}
</script>
