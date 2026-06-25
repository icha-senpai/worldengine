<template>
    <ScaffoldShowPage
        :title="timeline.name"
        :subtitle="timelineSubtitle"
        back-label="Timelines"
        :back-href="route('timelines.index')"
        :edit-href="route('timelines.edit', timeline.id)"
        :edit-preserve-scroll="true"
        :edit-preserve-state="true"
        :edit-drawer-open="Boolean(editDrawer)"
        :edit-close-href="route('timelines.show', timeline.id)"
        :destroy-href="route('timelines.destroy', timeline.id)"
        :destroy-confirm="destroyConfirm"
        badge="Timeline"
        :hero-meta="timelineHeroMeta"
        :sections="sections"
    >
        <div class="mt-4 space-y-4">
            <div class="dashboard-metric-strip">
                <div class="dashboard-metric">
                    <span class="dashboard-metric__label">Status</span>
                    <span class="dashboard-metric__value">{{ formatLabel(timeline.status || 'concept') }}</span>
                </div>
                <div class="dashboard-metric">
                    <span class="dashboard-metric__label">Visibility</span>
                    <span class="dashboard-metric__value">{{ formatLabel(timeline.visibility || 'private') }}</span>
                </div>
                <div class="dashboard-metric">
                    <span class="dashboard-metric__label">Chronological</span>
                    <span class="dashboard-metric__value">{{ chronologicalEntries.length }}</span>
                </div>
                <div class="dashboard-metric">
                    <span class="dashboard-metric__label">Atemporal</span>
                    <span class="dashboard-metric__value">{{ atemporalEntries.length }}</span>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
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
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-label mb-1!">Chronological Events</h3>
                            <p class="panel-copy">Ordered moments anchored to dated positions inside the timeline.</p>
                        </div>
                        <span class="mini-chip">{{ chronologicalEntries.length }} entries</span>
                    </div>

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
                                    opens-drawer
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
                    <div class="panel-heading">
                        <div>
                            <h3 class="panel-label mb-1!">Atemporal Events</h3>
                            <p class="panel-copy">Timeless, contextual, or out-of-sequence events that still belong beside this timeline.</p>
                        </div>
                        <span class="mini-chip">{{ atemporalEntries.length }} entries</span>
                    </div>

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
                                    opens-drawer
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
        </div>

        <template #edit-drawer>
            <EditTimeline
                v-if="editDrawer"
                embedded
                :timeline="timeline"
                v-bind="editDrawer"
            />
        </template>

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
    </ScaffoldShowPage>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import Checkbox from '@/Components/Checkbox.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import EditTimeline from '@/Pages/Temporal/Timelines/Edit.vue'
import EditTimelineEvent from '@/Pages/Temporal/Timelines/Events/Edit.vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { formatLabel, richDocumentToPlainText, toEntityOptions } from '@/Components/scaffold/formatters'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'
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

const chronologicalEntries = computed(() => props.events ?? [])
const atemporalEntries = computed(() => props.atemporal ?? [])
const timelineSubtitle = computed(() => richDocumentToPlainText(props.timeline.summary) || '')
const timelineHeroMeta = computed(() => [
    { label: 'Status', value: formatLabel(props.timeline.status || 'concept') },
    { label: 'Visibility', value: formatLabel(props.timeline.visibility || 'private') },
    { label: 'Chronological', value: String(chronologicalEntries.value.length) },
    { label: 'Atemporal', value: String(atemporalEntries.value.length) },
])
const destroyConfirm = computed(() => `Move "${props.timeline.name}" to trash?`)
const sections = computed(() => [
    {
        title: 'Overview',
        description: 'High-level state for this timeline and the shape of the entries currently placed on it.',
        entries: [
            sectionEntry('Timeline Name', props.timeline.name),
            sectionEntry('Status', formatLabel(props.timeline.status)),
            sectionEntry('Visibility', formatLabel(props.timeline.visibility)),
            sectionEntry('Summary', props.timeline.summary, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
const availableEventOptions = computed(() => toEntityOptions(props.availableEvents))
const concurrencyGroupOptions = computed(() =>
    (props.concurrencyGroups ?? []).map((group) => ({
        value: group.id,
        label: [group.name, group.au_date].filter(Boolean).join(' · '),
    }))
)
const showEventEditDrawer = computed(() =>
    Boolean(props.eventEditDrawer)
    || chronologicalEntries.value.some((entry) => matchesPendingDrawerHref(route('timelines.events.edit', { timeline: props.timeline.id, entry: entry.id })))
    || atemporalEntries.value.some((entry) => matchesPendingDrawerHref(route('timelines.events.edit', { timeline: props.timeline.id, entry: entry.id })))
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
</script>
