<template>
    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-primary text-2xl font-light tracking-wide">Search</h1>
                <p class="text-muted-3 text-sm font-mono mt-1">Cross-domain lookup for entities, documents, secrets, and glossary terms.</p>
            </div>
        </template>

        <form @submit.prevent="submit" class="panel max-w-3xl mb-5">
            <label class="field-label">Query</label>
            <div class="flex gap-3 mt-2">
                <input v-model="query" type="text" class="input flex-1" placeholder="Search the archive..." />
                <button type="submit" class="btn-primary">Search</button>
            </div>
        </form>

        <div v-if="!term" class="empty-state">
            <p class="text-muted-3 text-sm font-mono uppercase tracking-widest">Enter a term to start searching</p>
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <section v-for="group in groups" :key="group.title" class="panel">
                <h3 class="panel-label">{{ group.title }}</h3>

                <ul v-if="group.items.length" class="space-y-2">
                    <li v-for="item in group.items" :key="item.label" class="result-row">
                        <Link v-if="item.href" :href="item.href" class="result-link text-primary hover:text-cyan transition-colors">
                            {{ item.label }}
                        </Link>
                        <span v-else class="result-link text-primary">{{ item.label }}</span>
                        <p v-if="item.meta" class="prose-wrap text-muted-3 text-sm mt-1.5">{{ item.meta }}</p>
                    </li>
                </ul>

                <p v-else class="text-muted-3 text-sm font-mono">No matches.</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    results: { type: Object, default: () => ({}) },
    term: { type: String, default: '' },
})

const query = ref(props.term)

watch(() => props.term, (value) => {
    query.value = value
})

const submit = () => {
    router.get(route('search'), query.value ? { q: query.value } : {}, { preserveState: true, replace: true })
}

const groups = computed(() => [
    {
        title: 'Entities',
        items: (props.results.entities ?? []).map((entity) => ({
            label: entity.name,
            meta: `${formatLabel(entity.entity_type)} · ${formatLabel(entity.status)}`,
            href: route('entities.show', entity.id),
        })),
    },
    {
        title: 'Documents',
        items: (props.results.documents ?? []).map((document) => ({
            label: document.title,
            meta: `${formatLabel(document.document_type)} · ${formatLabel(document.document_status)}`,
            href: route('documents.show', document.id),
        })),
    },
    {
        title: 'Secrets',
        items: (props.results.secrets ?? []).map((secret) => ({
            label: secret.title,
            meta: `${formatLabel(secret.secret_type)} · ${formatLabel(secret.exposure_risk)}`,
        })),
    },
    {
        title: 'Glossary',
        items: (props.results.glossary ?? []).map((term) => ({
            label: term.term,
            meta: term.usage_context,
        })),
    },
])
</script>

<style scoped>
.panel {
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 18px 20px;
}

.panel-label,
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
}

.result-link,
.prose-wrap {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.result-link {
    display: block;
    line-height: 1.4;
}

.result-row {
    padding: 14px 0;
    border-bottom: 1px solid var(--border-color);
}

.result-row:last-child {
    border-bottom: 0;
    padding-bottom: 0;
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
