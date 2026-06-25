<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>Cross-domain index</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">Search</h1>
                    <p class="page-hero__subtitle">Cross-domain lookup for entities, documents, secrets, glossary terms, and synced Notion notes.</p>
                </div>
            </div>
        </template>

        <form @submit.prevent="submit" class="index-panel max-w-3xl">
            <label class="field-label">Query</label>
            <div class="flex gap-3 mt-3 flex-col sm:flex-row">
                <TextInput v-model="query" type="text" class="flex-1" placeholder="Search the archive..." />
                <AppButton type="submit" variant="primary">Search</AppButton>
            </div>
        </form>

        <div v-if="!term" class="empty-state">
            <p class="text-muted-3 text-sm font-ui uppercase tracking-widest">Enter a term to start searching</p>
        </div>

        <div v-else class="grid gap-4 xl:grid-cols-2">
            <section v-for="group in groups" :key="group.title" class="surface-section">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h3 class="surface-section__title">{{ group.title }}</h3>
                        <p class="surface-section__subtitle">{{ group.items.length }} result{{ group.items.length === 1 ? '' : 's' }}</p>
                    </div>
                </div>

                <div v-if="group.items.length" class="index-surface index-surface--nested">
                    <div v-for="item in group.items" :key="item.label" class="index-record">
                        <Link v-if="item.href" :href="item.href" class="index-record__title prose-wrap hover:text-focus transition-colors">
                            {{ item.label }}
                        </Link>
                        <span v-else class="index-record__title prose-wrap">{{ item.label }}</span>
                        <p v-if="item.meta" class="index-record__subtitle prose-wrap">{{ item.meta }}</p>
                        <p v-if="item.noteExcerpt" class="prose-wrap text-muted-2 text-sm mt-3">
                            <span class="note-label mr-2">Notion note</span>
                            {{ item.noteExcerpt }}
                        </p>
                    </div>
                </div>

                <div v-else class="empty-state-panel">
                    <p class="text-muted-3 text-sm font-ui">No matches.</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import TextInput from '@/Components/TextInput.vue'
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
            noteExcerpt: entity.notion_note_excerpt,
        })),
    },
    {
        title: 'Documents',
        items: (props.results.documents ?? []).map((document) => ({
            label: document.title,
            meta: `${formatLabel(document.document_type)} · ${formatLabel(document.document_status)}`,
            href: route('documents.show', document.id),
            noteExcerpt: document.notion_note_excerpt,
        })),
    },
    {
        title: 'Secrets',
        items: (props.results.secrets ?? []).map((secret) => ({
            label: secret.title,
            meta: `${formatLabel(secret.secret_type)} · ${formatLabel(secret.exposure_risk)}`,
            href: route('secrets.show', secret.id),
            noteExcerpt: secret.notion_note_excerpt,
        })),
    },
    {
        title: 'Glossary',
        items: (props.results.glossary ?? []).map((term) => ({
            label: term.term,
            meta: term.usage_context,
            href: route('glossary.show', term.id),
            noteExcerpt: term.notion_note_excerpt,
        })),
    },
])
</script>
