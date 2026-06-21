<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="backHref" class="text-muted-3 text-xs font-mono hover:text-muted-2 transition-colors">
                            {{ backLabel }}
                        </Link>
                        <span class="text-muted-3 text-xs font-mono">/</span>
                        <span v-if="badge" class="chip">{{ badge }}</span>
                    </div>
                    <h1 class="text-primary text-2xl font-light tracking-wide leading-tight">
                        {{ title }}
                    </h1>
                    <p v-if="subtitle" class="text-muted-3 text-sm mt-1 whitespace-pre-wrap">
                        {{ subtitle }}
                    </p>
                </div>

                <Link v-if="editHref" :href="editHref" class="btn-ghost">
                    {{ editLabel }}
                </Link>
            </div>
        </template>

        <div class="grid gap-4 md:grid-cols-2">
            <section
                v-for="section in sections"
                :key="section.title"
                class="panel"
                :class="{ 'md:col-span-2': section.fullWidth }"
            >
                <h3 class="panel-label">{{ section.title }}</h3>

                <div v-if="section.entries?.length" class="space-y-3">
                    <div v-for="entry in section.entries" :key="section.title + entry.label" class="entry-row">
                        <span class="field-label">{{ entry.label }}</span>
                        <div class="entry-value">
                            <template v-if="entry.kind === 'list'">
                                <ul v-if="entry.value?.length" class="space-y-1">
                                    <li v-for="item in entry.value" :key="item.label ?? item.value ?? item" class="text-muted-2 text-xs leading-relaxed">
                                        <template v-if="item.href">
                                            <Link :href="item.href" class="text-cyan hover:underline">
                                                {{ item.label ?? item.value }}
                                            </Link>
                                        </template>
                                        <template v-else>
                                            {{ item.label ?? item.value ?? item }}
                                        </template>
                                    </li>
                                </ul>
                                <span v-else class="text-muted-3 text-xs font-mono">—</span>
                            </template>

                            <template v-else-if="entry.kind === 'json'">
                                <pre class="json-block">{{ prettyJson(entry.value) || '—' }}</pre>
                            </template>

                            <template v-else-if="entry.href">
                                <Link :href="entry.href" class="text-cyan hover:underline">
                                    {{ summarizeValue(entry.value) }}
                                </Link>
                            </template>

                            <template v-else>
                                <span class="text-muted-2 text-xs leading-relaxed whitespace-pre-wrap">
                                    {{ summarizeValue(entry.value) }}
                                </span>
                            </template>
                        </div>
                    </div>
                </div>

                <p v-else class="text-muted-3 text-xs font-mono">No data in this section yet.</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { prettyJson, summarizeValue } from '@/Components/scaffold/formatters'

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    backHref: { type: String, required: true },
    backLabel: { type: String, required: true },
    editHref: { type: String, default: '' },
    editLabel: { type: String, default: 'Edit' },
    badge: { type: String, default: '' },
    sections: { type: Array, default: () => [] },
})
</script>

<style scoped>
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

.entry-row {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.field-label {
    min-width: 110px;
    font-size: 9px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    padding-top: 2px;
    flex-shrink: 0;
}

.entry-value {
    min-width: 0;
    flex: 1;
}

.json-block {
    margin: 0;
    padding: 10px 12px;
    border-radius: 4px;
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
    color: var(--text-muted-2);
    font-size: 11px;
    font-family: ui-monospace, monospace;
    white-space: pre-wrap;
    word-break: break-word;
}

.chip {
    padding: 1px 6px;
    border-radius: 2px;
    font-size: 9px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--accent-cyan);
    border: 1px solid rgba(0, 245, 255, 0.22);
    background: rgba(0, 245, 255, 0.06);
}

.btn-ghost {
    display: inline-flex;
    align-items: center;
    height: 28px;
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

.text-cyan {
    color: var(--accent-cyan);
}
</style>
