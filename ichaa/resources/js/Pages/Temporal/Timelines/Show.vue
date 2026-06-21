<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="route('timelines.index')" class="text-muted-3 text-sm font-mono hover:text-muted-2 transition-colors">
                            Timelines
                        </Link>
                        <span class="text-muted-3 text-sm font-mono">/</span>
                        <span class="chip">Timeline</span>
                    </div>
                    <h1 class="text-primary text-2xl font-light tracking-wide leading-tight">
                        {{ timeline.name }}
                    </h1>
                    <p v-if="timeline.summary" class="prose-wrap text-muted-3 text-base mt-2">
                        {{ timeline.summary }}
                    </p>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button type="button" class="btn-danger" @click="destroyTimeline">
                        Move to Trash
                    </button>
                    <Link :href="route('timelines.edit', timeline.id)" class="btn-ghost">
                        Edit
                    </Link>
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
                        <h3 class="panel-label !mb-1">Place Event</h3>
                        <p class="panel-copy">Add an event entity directly onto this timeline without leaving the page.</p>
                    </div>
                </div>

                <form data-test="timeline-placement-form" class="space-y-4" @submit.prevent="submitPlacement">
                    <div class="field-group">
                        <label class="field-label" for="event_entity_id">Event Entity</label>
                        <select
                            id="event_entity_id"
                            v-model="placementForm.event_entity_id"
                            class="input w-full"
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
                        </select>
                        <p class="field-help">
                            {{ availableEventOptions.length ? 'Events already on this timeline are hidden from this picker.' : 'Create another event entity to place something new here.' }}
                        </p>
                        <p v-if="placementForm.errors.event_entity_id" class="field-error">{{ placementForm.errors.event_entity_id }}</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="field-group">
                            <label class="field-label" for="entry_label">Entry Label</label>
                            <input
                                id="entry_label"
                                v-model="placementForm.entry_label"
                                type="text"
                                class="input w-full"
                                placeholder="How this moment should be labeled on the timeline"
                            >
                            <p v-if="placementForm.errors.entry_label" class="field-error">{{ placementForm.errors.entry_label }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="au_date">AU Date</label>
                            <input
                                id="au_date"
                                v-model="placementForm.au_date"
                                type="text"
                                class="input w-full"
                                placeholder="Year 0, Cycle 12, Post-Fall..."
                            >
                            <p v-if="placementForm.errors.au_date" class="field-error">{{ placementForm.errors.au_date }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="source_date">Source Date</label>
                            <input
                                id="source_date"
                                v-model="placementForm.source_date"
                                type="text"
                                class="input w-full"
                                placeholder="Optional canon/source date"
                            >
                            <p v-if="placementForm.errors.source_date" class="field-error">{{ placementForm.errors.source_date }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="timeline_position">Timeline Position</label>
                            <input
                                id="timeline_position"
                                v-model="placementForm.timeline_position"
                                type="number"
                                class="input w-full"
                                min="0"
                                placeholder="Leave blank to auto-place"
                            >
                            <p v-if="placementForm.errors.timeline_position" class="field-error">{{ placementForm.errors.timeline_position }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="concurrency_group_id">Concurrency Group</label>
                            <select id="concurrency_group_id" v-model="placementForm.concurrency_group_id" class="input w-full">
                                <option value="">Optional grouping...</option>
                                <option
                                    v-for="group in concurrencyGroupOptions"
                                    :key="group.value"
                                    :value="group.value"
                                >
                                    {{ group.label }}
                                </option>
                            </select>
                            <p v-if="placementForm.errors.concurrency_group_id" class="field-error">{{ placementForm.errors.concurrency_group_id }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="event_significance">Event Significance</label>
                            <select id="event_significance" v-model="placementForm.event_significance" class="input w-full">
                                <option value="">Optional significance...</option>
                                <option
                                    v-for="level in eventSignificanceLevels"
                                    :key="level"
                                    :value="level"
                                >
                                    {{ formatLabel(level) }}
                                </option>
                            </select>
                            <p v-if="placementForm.errors.event_significance" class="field-error">{{ placementForm.errors.event_significance }}</p>
                        </div>
                    </div>

                    <label class="checkbox-row">
                        <input v-model="placementForm.is_atemporal" type="checkbox" class="checkbox">
                        <span>
                            <span class="field-label field-label--inline">Atemporal Event</span>
                            <span class="field-help field-help--inline">Keep this out of the chronological lane and list it as timeless/contextual.</span>
                        </span>
                    </label>

                    <div class="flex items-center gap-3 pt-1">
                        <button
                            type="submit"
                            class="btn-primary"
                            :disabled="placementForm.processing || !placementForm.event_entity_id"
                        >
                            <span v-if="placementForm.processing">Placing...</span>
                            <span v-else>Place Event</span>
                        </button>
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

                        <button
                            type="button"
                            class="btn-ghost btn-ghost--sm"
                            :data-test="`remove-entry-${entry.id}`"
                            @click="removeEntry(entry)"
                        >
                            Remove
                        </button>
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

                        <button
                            type="button"
                            class="btn-ghost btn-ghost--sm"
                            :data-test="`remove-entry-${entry.id}`"
                            @click="removeEntry(entry)"
                        >
                            Remove
                        </button>
                    </li>
                </ul>

                <p v-else class="empty-copy">No atemporal entries yet.</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { formatLabel, toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    timeline: { type: Object, required: true },
    atemporal: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    availableEvents: { type: Array, default: () => [] },
    concurrencyGroups: { type: Array, default: () => [] },
    eventSignificanceLevels: { type: Array, default: () => [] },
})

const chronologicalEntries = computed(() => props.events ?? [])
const atemporalEntries = computed(() => props.atemporal ?? [])
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

const destroyTimeline = () => {
    if (!confirm(`Move "${props.timeline.name}" to trash?`)) {
        return
    }

    router.delete(route('timelines.destroy', props.timeline.id))
}
</script>

<style scoped>
.panel {
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 18px 20px;
}

.panel-heading {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.panel-label {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    margin-bottom: 12px;
}

.panel-copy {
    font-size: 12px;
    color: var(--text-muted-3);
    line-height: 1.5;
}

.entry-row {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.field-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.field-label {
    min-width: 110px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-muted-3);
}

.field-label--inline {
    display: block;
    min-width: auto;
}

.entry-value {
    min-width: 0;
    flex: 1;
    font-size: 14px;
    color: var(--text-muted-2);
    line-height: 1.5;
}

.prose-wrap {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.field-help {
    font-size: 12px;
    color: var(--text-muted-3);
    line-height: 1.45;
}

.field-help--inline {
    display: block;
    margin-top: 2px;
}

.field-error {
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--accent-pink);
}

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

.input:focus {
    border-color: var(--accent-cyan);
}

.checkbox-row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.checkbox {
    accent-color: var(--accent-cyan);
    width: 16px;
    height: 16px;
    margin-top: 2px;
}

.entry-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.entry-card {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-surface);
}

.entry-card__body {
    min-width: 0;
    flex: 1;
}

.entry-card__title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.entry-link {
    font-size: 14px;
    color: var(--accent-cyan);
    text-decoration: none;
}

.entry-link:hover {
    text-decoration: underline;
}

.entry-link--static {
    color: var(--text-primary);
}

.entry-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 6px;
    font-size: 12px;
    color: var(--text-muted-3);
    font-family: ui-monospace, monospace;
}

.mini-chip {
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid rgba(0, 245, 255, 0.22);
    background: rgba(0, 245, 255, 0.06);
    color: var(--accent-cyan);
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.chip {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--accent-cyan);
    border: 1px solid rgba(0, 245, 255, 0.22);
    background: rgba(0, 245, 255, 0.06);
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    height: 40px;
    padding: 0 18px;
    background: rgba(0, 245, 255, 0.1);
    border: 1px solid rgba(0, 245, 255, 0.3);
    border-radius: 6px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--accent-cyan);
    transition: background 0.15s, border-color 0.15s;
}

.btn-primary:hover:not(:disabled) {
    background: rgba(0, 245, 255, 0.15);
    border-color: rgba(0, 245, 255, 0.5);
}

.btn-primary:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.btn-ghost {
    display: inline-flex;
    align-items: center;
    height: 40px;
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
    height: 40px;
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
    height: 32px;
}

.empty-copy {
    font-size: 14px;
    color: var(--text-muted-3);
}

@media (max-width: 639px) {
    .entry-row {
        flex-direction: column;
        gap: 4px;
    }

    .field-label {
        min-width: 0;
    }

    .entry-card {
        flex-direction: column;
    }

    .btn-ghost--sm {
        width: 100%;
        justify-content: center;
    }
}
</style>
