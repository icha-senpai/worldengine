<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>{{ activeSurfaceLabel }}</span>
                        <span>{{ filteredEntries.length }} shown</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">Evergather Content</h1>
                </div>

                <div class="page-hero__actions">
                    <AppButton variant="primary" @click="openCreateDrawer">
                        New Entry
                    </AppButton>
                </div>
            </div>
        </template>

        <div class="content-console">
            <aside class="content-console__rail">
                <button
                    v-for="surface in surfaces"
                    :key="surface.key"
                    type="button"
                    class="surface-tab"
                    :class="{ 'surface-tab--active': surface.key === active_surface }"
                    @click="selectSurface(surface.key)"
                >
                    <span class="surface-tab__label">{{ surface.label }}</span>
                    <span class="surface-tab__key">{{ surface.key }}</span>
                </button>
            </aside>

            <section class="content-console__main">
                <div class="content-toolbar">
                    <div class="content-toolbar__search">
                        <InputLabel value="Search" />
                        <TextInput
                            v-model.trim="searchQuery"
                            class="mt-1 w-full"
                            type="search"
                            placeholder="Key, label, category, rarity"
                        />
                    </div>

                    <div class="content-toolbar__filter">
                        <InputLabel value="Source" />
                        <select v-model="sourceFilter" class="input mt-1 w-full">
                            <option value="all">All</option>
                            <option value="database">Database</option>
                            <option value="code">Code</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                </div>

                <div class="content-stats">
                    <div v-for="stat in stats" :key="stat.label" class="content-stat">
                        <span class="content-stat__value">{{ stat.value }}</span>
                        <span class="content-stat__label">{{ stat.label }}</span>
                    </div>
                </div>

                <div class="content-table-shell">
                    <table class="content-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Level</th>
                                <th>Category</th>
                                <th>Rarity</th>
                                <th>Source</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in filteredEntries" :key="`${entry.source}-${entry.entry_key}`">
                                <td>
                                    <div class="entry-name">
                                        <span class="entry-name__label">{{ entry.label }}</span>
                                        <span class="entry-name__key">{{ entry.entry_key }}</span>
                                        <span v-if="payloadSummary(entry).length" class="entry-name__summary">
                                            {{ payloadSummary(entry) }}
                                        </span>
                                    </div>
                                </td>
                                <td>{{ levelText(entry) }}</td>
                                <td>{{ entry.category ?? 'None' }}</td>
                                <td>
                                    <span class="content-pill">{{ entry.rarity ?? 'None' }}</span>
                                </td>
                                <td>
                                    <span class="source-pill" :class="`source-pill--${entry.source}`">
                                        {{ entry.source }}
                                    </span>
                                    <span v-if="!entry.enabled" class="source-pill source-pill--disabled">
                                        disabled
                                    </span>
                                </td>
                                <td class="content-table__actions">
                                    <AppButton variant="ghost" size="sm" @click="openEditDrawer(entry)">
                                        {{ entry.source === 'database' ? 'Edit' : 'Override' }}
                                    </AppButton>
                                </td>
                            </tr>

                            <tr v-if="filteredEntries.length === 0">
                                <td colspan="6" class="content-empty">
                                    No matching records.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <AppDrawer
            v-if="isDrawerOpen"
            :title="drawerTitle"
            close-label="Cancel"
            @close="closeDrawer"
        >
            <form class="space-y-6" @submit.prevent="saveEntry">
                <div class="editor-header">
                    <div>
                        <p class="editor-header__eyebrow">{{ form.surface }}</p>
                        <p class="editor-header__title">{{ form.label || form.entry_key || 'Untitled Entry' }}</p>
                    </div>
                    <span class="source-pill" :class="selectedEntrySource === 'database' ? 'source-pill--database' : 'source-pill--code'">
                        {{ selectedEntrySource === 'database' ? 'database' : 'new override' }}
                    </span>
                </div>

                <div class="form-grid-2-tight">
                    <div>
                        <InputLabel value="Surface" />
                        <select v-model="form.surface" class="input mt-1 w-full">
                            <option v-for="surface in surfaces" :key="surface.key" :value="surface.key">
                                {{ surface.label }}
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.surface" />
                    </div>

                    <div>
                        <InputLabel value="Key" />
                        <TextInput v-model="form.entry_key" class="mt-1 w-full" type="text" />
                        <InputError class="mt-2" :message="form.errors.entry_key" />
                    </div>

                    <div>
                        <InputLabel value="Label" />
                        <TextInput v-model="form.label" class="mt-1 w-full" type="text" />
                        <InputError class="mt-2" :message="form.errors.label" />
                    </div>

                    <div>
                        <InputLabel value="Category" />
                        <TextInput v-model="form.category" class="mt-1 w-full" type="text" />
                        <InputError class="mt-2" :message="form.errors.category" />
                    </div>

                    <div>
                        <InputLabel value="Required Level" />
                        <TextInput v-model="form.required_level" class="mt-1 w-full" type="number" min="1" max="100" />
                        <InputError class="mt-2" :message="form.errors.required_level" />
                    </div>

                    <div>
                        <InputLabel value="Rarity" />
                        <select v-model="form.rarity" class="input mt-1 w-full">
                            <option value="">None</option>
                            <option value="common">Common</option>
                            <option value="uncommon">Uncommon</option>
                            <option value="rare">Rare</option>
                            <option value="epic">Epic</option>
                            <option value="legendary">Legendary</option>
                            <option value="mythic">Mythic</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.rarity" />
                    </div>

                    <div>
                        <InputLabel value="Sort Order" />
                        <TextInput v-model="form.sort_order" class="mt-1 w-full" type="number" />
                        <InputError class="mt-2" :message="form.errors.sort_order" />
                    </div>

                    <label class="mt-7 inline-flex items-center gap-2 font-ui text-sm text-muted">
                        <Checkbox v-model:checked="form.enabled" />
                        <span>Enabled</span>
                    </label>
                </div>

                <div>
                    <div class="payload-label-row">
                        <InputLabel value="Payload JSON" />
                        <span>{{ payloadByteCount }} chars</span>
                    </div>
                    <TextareaInput
                        v-model="form.payload_json"
                        class="mt-1 min-h-96 w-full font-mono text-xs"
                        spellcheck="false"
                    />
                    <InputError class="mt-2" :message="form.errors.payload_json" />
                </div>

                <div class="form-actions">
                    <AppButton type="submit" variant="primary" :disabled="form.processing">
                        {{ form.processing ? 'Saving' : 'Save Entry' }}
                    </AppButton>
                    <AppButton type="button" variant="ghost" @click="closeDrawer">
                        Cancel
                    </AppButton>
                </div>
            </form>
        </AppDrawer>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Checkbox from '@/Components/Checkbox.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextareaInput from '@/Components/TextareaInput.vue'
import TextInput from '@/Components/TextInput.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import AppDrawer from '@/Components/ui/AppDrawer.vue'

const props = defineProps({
    surfaces: { type: Array, default: () => [] },
    active_surface: { type: String, default: 'tiers' },
    entries: { type: Array, default: () => [] },
})

const editingEntryId = ref(null)
const selectedEntrySource = ref('code')
const isDrawerOpen = ref(false)
const searchQuery = ref('')
const sourceFilter = ref('all')

const form = useForm({
    surface: props.active_surface,
    entry_key: '',
    label: '',
    category: '',
    required_level: '',
    rarity: '',
    enabled: true,
    sort_order: 0,
    payload_json: '{}',
})

const activeSurfaceLabel = computed(() => props.surfaces.find((surface) => surface.key === props.active_surface)?.label ?? 'Tiers')
const drawerTitle = computed(() => {
    if (editingEntryId.value) {
        return 'Edit Content'
    }

    return selectedEntrySource.value === 'code' && form.entry_key ? 'Override Code Entry' : 'New Content'
})
const databaseCount = computed(() => props.entries.filter((entry) => entry.source === 'database').length)
const codeCount = computed(() => props.entries.filter((entry) => entry.source === 'code').length)
const disabledCount = computed(() => props.entries.filter((entry) => !entry.enabled).length)
const payloadByteCount = computed(() => form.payload_json.length)
const stats = computed(() => [
    { label: 'Total', value: props.entries.length },
    { label: 'Database', value: databaseCount.value },
    { label: 'Code', value: codeCount.value },
    { label: 'Disabled', value: disabledCount.value },
])

const filteredEntries = computed(() => {
    const query = searchQuery.value.toLowerCase()

    return props.entries.filter((entry) => {
        const matchesSource = sourceFilter.value === 'all'
            || (sourceFilter.value === 'disabled' ? !entry.enabled : entry.source === sourceFilter.value)

        if (!matchesSource) {
            return false
        }

        if (!query) {
            return true
        }

        return [
            entry.entry_key,
            entry.label,
            entry.category,
            entry.rarity,
            entry.source,
        ].some((value) => String(value ?? '').toLowerCase().includes(query))
    })
})

function selectSurface(surface) {
    router.get(route('admin.evergather-content.index'), { surface }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            searchQuery.value = ''
            sourceFilter.value = 'all'
        },
    })
}

function openCreateDrawer() {
    editingEntryId.value = null
    selectedEntrySource.value = 'new'
    form.clearErrors()
    form.reset()
    form.surface = props.active_surface
    form.payload_json = '{}'
    isDrawerOpen.value = true
}

function openEditDrawer(entry) {
    editingEntryId.value = entry.id
    selectedEntrySource.value = entry.source
    form.clearErrors()
    form.surface = entry.surface
    form.entry_key = entry.entry_key
    form.label = entry.label ?? ''
    form.category = entry.category ?? ''
    form.required_level = entry.required_level ?? ''
    form.rarity = entry.rarity ?? ''
    form.enabled = entry.enabled
    form.sort_order = entry.sort_order ?? 0
    form.payload_json = prettyPayload(entry.payload)
    isDrawerOpen.value = true
}

function closeDrawer() {
    isDrawerOpen.value = false
    editingEntryId.value = null
    selectedEntrySource.value = 'code'
    form.reset()
    form.clearErrors()
}

function saveEntry() {
    const options = {
        preserveScroll: true,
        onSuccess: closeDrawer,
    }

    if (editingEntryId.value) {
        form.put(route('admin.evergather-content.update', editingEntryId.value), options)
        return
    }

    form.post(route('admin.evergather-content.store'), options)
}

function levelText(entry) {
    if (entry.required_level === null || entry.required_level === undefined) {
        return 'Any'
    }

    return `Lv ${entry.required_level}`
}

function payloadSummary(entry) {
    const payload = entry.payload ?? {}
    const values = [
        payload.skill,
        payload.region,
        payload.location,
        payload.kind,
        payload.type,
        payload.track,
        payload.band,
    ].filter(Boolean)

    return values.slice(0, 3).join(' / ')
}

function prettyPayload(payload) {
    return JSON.stringify(payload ?? {}, null, 2)
}
</script>

<style scoped>
.content-console {
    display: grid;
    grid-template-columns: minmax(190px, 240px) minmax(0, 1fr);
    gap: 18px;
}

.content-console__rail {
    align-self: start;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.72);
    overflow: hidden;
}

.surface-tab {
    display: flex;
    width: 100%;
    flex-direction: column;
    gap: 3px;
    border: 0;
    border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.58);
    background: transparent;
    padding: 12px 14px;
    text-align: left;
    transition: background 0.15s ease, color 0.15s ease;
}

.surface-tab:last-child {
    border-bottom: 0;
}

.surface-tab:hover,
.surface-tab--active {
    background: rgb(var(--accent-cyan-rgb) / 0.1);
}

.surface-tab--active {
    box-shadow: inset 3px 0 0 var(--accent-cyan);
}

.surface-tab__label {
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 13px;
}

.surface-tab__key {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
}

.content-console__main {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.content-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(170px, 220px);
    gap: 14px;
    align-items: end;
}

.content-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.content-stat {
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.68);
    padding: 12px 14px;
}

.content-stat__value {
    display: block;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 24px;
    line-height: 1;
}

.content-stat__label {
    display: block;
    margin-top: 5px;
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.12em;
}

.content-table-shell {
    overflow: hidden;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.7);
}

.content-table {
    width: 100%;
    border-collapse: collapse;
}

.content-table th {
    border-bottom: 1px solid var(--border-color);
    background: rgb(var(--bg-surface-2-rgb) / 0.72);
    padding: 11px 14px;
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.12em;
    text-align: left;
    text-transform: uppercase;
}

.content-table td {
    border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.5);
    padding: 13px 14px;
    color: var(--text-muted);
    font-family: var(--font-ui);
    font-size: 13px;
    vertical-align: middle;
}

.content-table tr:last-child td {
    border-bottom: 0;
}

.content-table__actions {
    width: 1%;
    white-space: nowrap;
    text-align: right;
}

.entry-name {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 4px;
}

.entry-name__label {
    color: var(--text-primary);
    font-size: 14px;
}

.entry-name__key,
.entry-name__summary {
    color: var(--text-muted-3);
    font-size: 11px;
}

.entry-name__summary {
    max-width: 52rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.content-pill,
.source-pill {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    border-radius: 4px;
    border: 1px solid var(--border-color);
    padding: 0 8px;
    color: var(--text-muted-2);
    font-family: var(--font-ui);
    font-size: 11px;
    text-transform: capitalize;
    white-space: nowrap;
}

.source-pill--database {
    border-color: rgb(var(--success-rgb) / 0.32);
    background: rgb(var(--success-rgb) / 0.08);
    color: var(--success);
}

.source-pill--code {
    border-color: rgb(var(--accent-cyan-rgb) / 0.28);
    background: rgb(var(--accent-cyan-rgb) / 0.08);
    color: var(--accent-cyan);
}

.source-pill--disabled {
    margin-left: 6px;
    border-color: rgb(var(--accent-pink-rgb) / 0.28);
    background: rgb(var(--accent-pink-rgb) / 0.08);
    color: var(--accent-pink);
}

.content-empty {
    padding: 34px 14px;
    text-align: center;
}

.editor-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.66);
    padding: 14px 16px;
}

.editor-header__eyebrow {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.editor-header__title {
    margin-top: 4px;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 16px;
}

.payload-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.payload-label-row span {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
}

@media (max-width: 980px) {
    .content-console {
        grid-template-columns: 1fr;
    }

    .content-console__rail {
        display: flex;
        overflow-x: auto;
    }

    .surface-tab {
        min-width: 170px;
        border-right: 1px solid rgb(var(--border-color-rgb) / 0.58);
        border-bottom: 0;
    }

    .surface-tab--active {
        box-shadow: inset 0 -3px 0 var(--accent-cyan);
    }
}

@media (max-width: 720px) {
    .content-toolbar,
    .content-stats {
        grid-template-columns: 1fr;
    }

    .content-table-shell {
        overflow-x: auto;
    }

    .content-table {
        min-width: 760px;
    }
}
</style>
