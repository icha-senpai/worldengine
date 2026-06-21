<template>
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-primary text-2xl font-light tracking-wide">Trash</h1>
                <p class="text-muted-3 text-sm font-mono mt-1">Recently removed records stay here until you restore them.</p>
            </div>
        </template>

        <form class="panel max-w-4xl mb-5" @submit.prevent="submit">
            <div class="grid gap-4 md:grid-cols-[220px_1fr_auto] md:items-end">
                <div class="field-group">
                    <label class="field-label" for="trash-type">Type</label>
                    <select id="trash-type" v-model="form.type" class="input w-full">
                        <option value="">Everything</option>
                        <option v-for="option in typeOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div class="field-group">
                    <label class="field-label" for="trash-query">Search</label>
                    <input
                        id="trash-query"
                        v-model="form.q"
                        type="text"
                        class="input w-full"
                        placeholder="Find a deleted record..."
                    >
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn-primary">Apply</button>
                    <button v-if="hasFilters" type="button" class="btn-ghost" @click="clearFilters">Clear</button>
                </div>
            </div>
        </form>

        <div v-if="items.length" class="space-y-3">
            <div v-for="item in items" :key="`${item.type}-${item.id}`" class="trash-row">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="type-chip">{{ item.resource_label }}</span>
                        <span class="deleted-at">Deleted {{ formatDate(item.deleted_at) }}</span>
                    </div>
                    <h2 class="trash-title">{{ item.title }}</h2>
                    <p v-if="item.subtitle" class="trash-subtitle">{{ item.subtitle }}</p>
                </div>

                <button type="button" class="btn-restore" @click="restoreItem(item)">
                    Restore
                </button>
            </div>
        </div>

        <div v-else class="empty-state">
            <p class="empty-title">Trash is empty.</p>
            <p class="empty-copy">Deleted items will show up here once something has been moved to trash.</p>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ type: '', q: '' }) },
    typeOptions: { type: Array, default: () => [] },
})

const form = reactive({
    type: props.filters.type ?? '',
    q: props.filters.q ?? '',
})

const hasFilters = computed(() => Boolean(form.type || form.q.trim()))

const submit = () => {
    router.get(route('trash.index'), {
        type: form.type || undefined,
        q: form.q.trim() || undefined,
    }, {
        preserveState: true,
        replace: true,
    })
}

const clearFilters = () => {
    form.type = ''
    form.q = ''
    submit()
}

const restoreItem = (item) => {
    if (!confirm(`Restore "${item.title}" from trash?`)) {
        return
    }

    router.post(route('trash.restore', {
        type: item.type,
        record: item.id,
    }), {}, {
        preserveScroll: true,
    })
}

const formatDate = (value) => {
    if (!value) {
        return 'recently'
    }

    return new Date(value).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
}
</script>

<style scoped>
.panel,
.trash-row {
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
}

.panel {
    padding: 18px 20px;
}

.trash-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 18px 20px;
}

.field-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.field-label {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted-3);
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
}

.input:focus {
    border-color: var(--accent-cyan);
}

.type-chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid rgba(255, 0, 128, 0.18);
    background: rgba(255, 0, 128, 0.06);
    color: var(--accent-pink);
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.deleted-at {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-3);
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.trash-title {
    font-size: 18px;
    line-height: 1.3;
    color: var(--text-primary);
}

.trash-subtitle {
    margin-top: 6px;
    font-size: 14px;
    line-height: 1.5;
    color: var(--text-muted-3);
}

.btn-primary,
.btn-ghost,
.btn-restore {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 40px;
    padding: 0 16px;
    border-radius: 6px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
}

.btn-primary {
    background: rgba(0, 245, 255, 0.1);
    border: 1px solid rgba(0, 245, 255, 0.3);
    color: var(--accent-cyan);
}

.btn-primary:hover {
    background: rgba(0, 245, 255, 0.15);
    border-color: rgba(0, 245, 255, 0.5);
}

.btn-ghost {
    border: 1px solid var(--border-color);
    color: var(--text-muted-2);
}

.btn-ghost:hover {
    border-color: var(--border-color-2);
    color: var(--text-primary);
}

.btn-restore {
    flex-shrink: 0;
    border: 1px solid rgba(0, 245, 255, 0.25);
    background: rgba(0, 245, 255, 0.05);
    color: var(--accent-cyan);
}

.btn-restore:hover {
    border-color: rgba(0, 245, 255, 0.45);
    background: rgba(0, 245, 255, 0.1);
}

.empty-state {
    padding: 48px 24px;
    border: 1px dashed var(--border-color);
    border-radius: 8px;
    text-align: center;
}

.empty-title {
    font-size: 18px;
    color: var(--text-primary);
}

.empty-copy {
    margin-top: 8px;
    font-size: 14px;
    color: var(--text-muted-3);
}

@media (max-width: 767px) {
    .trash-row {
        flex-direction: column;
        align-items: stretch;
    }

    .btn-restore {
        width: 100%;
    }
}
</style>
