<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-primary text-3xl font-light tracking-wide">{{ title }}</h1>
                    <p class="text-muted-3 text-sm font-mono mt-1">
                        {{ count }} {{ countLabel }}
                    </p>
                </div>
                <Link v-if="createHref" :href="createHref" class="btn-primary">
                    {{ createLabel }}
                </Link>
            </div>
        </template>

        <div v-if="items.length" class="space-y-3">
            <component
                :is="item.href ? Link : 'div'"
                v-for="item in items"
                :key="item.id ?? item.title"
                v-bind="item.href ? { href: item.href } : {}"
                class="resource-row"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <span class="prose-wrap text-primary text-base font-light leading-snug">{{ item.title }}</span>
                            <span
                                v-for="badge in item.badges ?? []"
                                :key="badge.label + badge.value"
                                class="chip"
                            >
                                {{ badge.label }}: {{ badge.value }}
                            </span>
                        </div>

                        <p v-if="item.subtitle" class="prose-wrap text-muted-3 text-sm leading-relaxed mb-3">
                            {{ item.subtitle }}
                        </p>

                        <div v-if="item.meta?.length" class="flex flex-wrap gap-3">
                            <span
                                v-for="meta in item.meta"
                                :key="meta.label + meta.value"
                                class="meta-tag"
                            >
                                {{ meta.label }}: {{ meta.value }}
                            </span>
                        </div>
                    </div>

                    <div v-if="item.stats?.length" class="flex flex-col items-end gap-1 flex-shrink-0">
                        <span
                            v-for="stat in item.stats"
                            :key="stat.label + stat.value"
                            class="stat-pill"
                        >
                            {{ stat.label }} {{ stat.value }}
                        </span>
                    </div>
                </div>
            </component>
        </div>

        <div v-else class="empty-state">
            <p class="text-muted-3 text-sm font-mono uppercase tracking-widest mb-3">{{ emptyTitle }}</p>
            <Link v-if="emptyCtaHref" :href="emptyCtaHref" class="text-cyan text-sm font-mono hover:underline">
                {{ emptyCtaLabel }}
            </Link>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

defineProps({
    title: { type: String, required: true },
    count: { type: Number, default: 0 },
    countLabel: { type: String, default: 'records' },
    createHref: { type: String, default: '' },
    createLabel: { type: String, default: 'Create' },
    items: { type: Array, default: () => [] },
    emptyTitle: { type: String, default: 'No records found' },
    emptyCtaHref: { type: String, default: '' },
    emptyCtaLabel: { type: String, default: 'Create one ->' },
})
</script>

<style scoped>
.resource-row {
    display: block;
    padding: 16px 18px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    transition: border-color 0.15s, background 0.15s;
}

.resource-row:hover {
    border-color: var(--border-color-2);
    background: var(--bg-surface);
}

.prose-wrap {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
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

.meta-tag {
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-3);
}

.stat-pill {
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-2);
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    height: 38px;
    padding: 0 18px;
    background: rgba(0, 245, 255, 0.1);
    border: 1px solid rgba(0, 245, 255, 0.3);
    border-radius: 6px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--accent-cyan);
    transition: background 0.15s, border-color 0.15s;
}

.btn-primary:hover {
    background: rgba(0, 245, 255, 0.15);
    border-color: rgba(0, 245, 255, 0.5);
}

.empty-state {
    padding: 48px 16px;
    text-align: center;
    border: 1px dashed var(--border-color);
    border-radius: 8px;
}

.text-cyan {
    color: var(--accent-cyan);
}
</style>
